<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit, Invoice, InvoiceTransaction, Notification};
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\{ConnectionException, RequestException};

trait ReceiptReturns
{
    private static bool $receiptReturnSchemaReady = false;
    private bool $receiptStripeUnavailable = false;

    private function ensureReceiptReturnSchema(): void
    {
        if (self::$receiptReturnSchemaReady) return;
        if (!\Illuminate\Support\Facades\Schema::hasTable('ae_stripe_receipt_returns')) {
            // MySQL DDL must never commit an enclosing caller/payment transaction.
            abort_unless(DB::transactionLevel() === 0, 409);
            $this->upgraded();
        }
        self::$receiptReturnSchemaReady = true;
    }

    private function receiptRows()
    {
        return DB::table('ae_stripe_receipt_returns');
    }

    public function receiptReturnStatus(Invoice $invoice, string $paymentIntentId, string $allocationKey): ?array
    {
        $this->ensureReceiptReturnSchema();
        return $this->withPaymentLock($invoice, function ($user, $current) use ($paymentIntentId, $allocationKey) {
            $receipt = $this->readReceipt($user, $paymentIntentId);
            abort_unless(!$receipt || $receipt['invoice_id'] === $current->id, 409);
            return $receipt['allocations'][$allocationKey] ?? null;
        });
    }

    public function allocateReceiptReturn(Invoice $invoice, string $paymentIntentId, string $allocationKey, int $amountMinor): array
    {
        $this->ensureReceiptReturnSchema();
        abort_unless(preg_match('/^pi_[a-zA-Z0-9]+$/D', $paymentIntentId) && $allocationKey !== 'excess' && !$this->isReceiptObservation($allocationKey) && strlen($allocationKey) <= 180 && $allocationKey !== '' && $amountMinor > 0, 409);
        $this->withPaymentLock($invoice, function ($user, $current) use ($paymentIntentId, $allocationKey, $amountMinor) {
            $receipt = $this->readReceipt($user, $paymentIntentId);
            if (!$receipt) {
                $payment = InvoiceTransaction::where('transaction_id', $paymentIntentId)->where('status', 'succeeded')->lockForUpdate()->first();
                abort_unless($payment && $payment->invoice->id === $current->id && ($payment->gateway->extension ?? null) === 'AeStripe', 409);
                $intent = $this->request('get', '/payment_intents/' . $paymentIntentId);
                abort_unless(($intent->id ?? null) === $paymentIntentId && ($intent->status ?? null) === 'succeeded' && !empty($intent->customer)
                    && ($intent->customer ?? null) === $user->properties()->where('key', 'stripe_id')->first()?->value
                    && strtolower($intent->currency ?? '') === strtolower($current->currency_code)
                    && $this->toMinorUnits($payment->amount, $current->currency_code) === ($intent->amount_received ?? null), 409);
                $receipt = $this->recordReceipt($current, $intent, 0);
            }
            abort_unless($receipt['invoice_id'] === $current->id, 409);
            $this->reserveReceiptReturn($receipt, $allocationKey, $amountMinor);
            $this->writeReceipt($user, $paymentIntentId, $receipt, $allocationKey);
            DB::afterCommit(fn () => $this->processReceiptReturns($current, $paymentIntentId, $allocationKey));
        });
        return $this->receiptReturnStatus($invoice, $paymentIntentId, $allocationKey);
    }

    private function readReceipt($user, string $id): ?array
    {
        $receipt = null;
        foreach ($this->receiptRows()->where('payment_intent_id', $id)->lockForUpdate()->get() as $row) {
            abort_unless((int) $row->user_id === (int) $user->id, 409);
            $binding = ['invoice_id' => (int) $row->invoice_id, 'payment_intent_id' => $id,
                'customer' => $row->customer, 'currency' => $row->currency, 'received_minor' => (int) $row->received_minor,
                'closed_invoice' => (bool) $row->closed_invoice, 'subscription_invoice_id' => $row->subscription_invoice_id];
            if ($receipt === null) $receipt = $binding + ['allocations' => []];
            abort_unless(array_intersect_key($receipt, $binding) === $binding, 409);
            if ($this->isReceiptObservation($row->allocation_key)) continue;
            $receipt['allocations'][$row->allocation_key] = ['amount_minor' => (int) $row->amount_minor,
                'state' => $row->state, 'refund_id' => $row->refund_id, 'credit_id' => $row->credit_id,
                'key' => $row->return_key, 'sent_at' => $row->sent_at === null ? null : (int) $row->sent_at,
                'reason' => $row->reason, 'notified' => $row->notified, 'recheck' => (bool) $row->recheck];
        }
        return $receipt;
    }

    private function receiptNotice(array $a): string
    {
        return $a['state'] . ':' . ($a['reason'] ?? '');
    }

    private function writeReceipt($user, string $id, array $receipt, string $key): void
    {
        $a = $receipt['allocations'][$key];
        $this->receiptRows()->updateOrInsert(['payment_intent_id' => $id, 'allocation_key' => $key], [
            'user_id' => $user->id, 'invoice_id' => $receipt['invoice_id'], 'customer' => $receipt['customer'],
            'currency' => $receipt['currency'], 'received_minor' => $receipt['received_minor'], 'closed_invoice' => $receipt['closed_invoice'],
            'subscription_invoice_id' => $receipt['subscription_invoice_id'] ?? null,
            'amount_minor' => $a['amount_minor'], 'return_key' => $a['key'], 'state' => $a['state'],
            'refund_id' => $a['refund_id'], 'credit_id' => $a['credit_id'] ?? null, 'sent_at' => $a['sent_at'],
            'reason' => $a['reason'] ?? null, 'notified' => $a['notified'] ?? null, 'recheck' => $a['recheck'] ?? false,
            'retry' => !in_array($a['state'], ['refunded', 'credited'], true) || !empty($a['recheck'])
                || ($a['notified'] ?? null) !== $this->receiptNotice($a), 'updated_at' => time(),
        ]);
    }

    private function reserveReceiptReturn(array &$receipt, string $key, int $amount): void
    {
        if (isset($receipt['allocations'][$key])) {
            abort_unless($receipt['allocations'][$key]['amount_minor'] === $amount, 409);
            return;
        }
        $allocated = array_sum(array_column($receipt['allocations'], 'amount_minor'));
        abort_unless($amount > 0 && $amount <= $receipt['received_minor'] - $allocated, 409);
        $receipt['allocations'][$key] = ['amount_minor' => $amount, 'state' => 'reserved', 'refund_id' => null,
            'key' => 'ae-return-' . hash('sha256', $receipt['payment_intent_id'] . ':' . $key . ':' . $amount), 'sent_at' => null];
    }

    // Ordinary settlement reserves excess with its native receipt. Closed receipts are held
    // here until both native receipt and return can be posted together without activation.
    private function recordReceipt($invoice, $intent, int $excess, bool $closed = false, ?string $subscriptionInvoiceId = null): array
    {
        $receipt = $this->readReceipt($invoice->user, $intent->id);
        if (!$receipt) {
            $receipt = ['invoice_id' => $invoice->id, 'payment_intent_id' => $intent->id,
                'customer' => $intent->customer ?? null, 'currency' => strtolower($intent->currency),
                'received_minor' => $intent->amount_received, 'closed_invoice' => $closed,
                'subscription_invoice_id' => $subscriptionInvoiceId, 'allocations' => []];
            if ($excess > 0) {
                $this->reserveReceiptReturn($receipt, 'excess', $excess);
                $this->writeReceipt($invoice->user, $intent->id, $receipt, 'excess');
            }
        }
        abort_unless($receipt['invoice_id'] === $invoice->id, 409);
        if ($excess > 0) DB::afterCommit(fn () => $this->processReceiptReturns($invoice, $intent->id, 'excess'));
        return $receipt;
    }

    public function retryReceiptReturns(): void
    {
        if (!$this->gatewayEnabled()) return;
        $this->ensureReceiptReturnSchema();
        $this->receiptStripeUnavailable = false;
        foreach ($this->receiptRows()->whereIn('state', ['reserved', 'pending', 'review', 'refunded', 'credited'])
            ->where('retry', true)->orderBy('updated_at')->limit(100)->get() as $row) {
            try {
                $invoice = Invoice::findOrFail($row->invoice_id);
                abort_unless((int) $row->user_id === (int) $invoice->user_id, 409);
                $this->processReceiptReturns($invoice, $row->payment_intent_id, $row->allocation_key);
            } catch (\Throwable $e) {
                report($e);
                // Unusable native bindings cannot send money; keep them off the due queue.
                $update = ['retry' => false];
                if (!in_array($row->state, ['refunded', 'credited'], true)) $update += ['state' => 'review',
                    'reason' => 'Original invoice or owner unavailable. Restore the invoice binding before returning money.'];
                $this->receiptRows()->where('return_key', $row->return_key)->where('state', $row->state)->update($update);
            }
            if ($this->receiptStripeUnavailable) break;
        }
    }

    private function recordReceiptRefundEvent($refund): bool
    {
        $this->ensureReceiptReturnSchema();
        $row = $this->receiptRows()->where('return_key', $refund->metadata->ae_stripe_return ?? '')->first();
        if (!$row || ($refund->payment_intent ?? null) !== $row->payment_intent_id || ($refund->amount ?? null) !== (int) $row->amount_minor
            || strtolower($refund->currency ?? '') !== $row->currency || empty($refund->id)
            || $row->refund_id !== null && $row->refund_id !== $refund->id) return false;
        $invoice = Invoice::findOrFail($row->invoice_id);
        $this->withPaymentLock($invoice, function ($user, $current) use ($row) {
            $receipt = $this->readReceipt($user, $row->payment_intent_id);
            $a = &$receipt['allocations'][$row->allocation_key];
            if ($a['state'] === 'credited') return;
            // Commit due work before HTTP, so an outage or worker crash cannot lose this event.
            $a['recheck'] = true;
            $this->writeReceipt($user, $row->payment_intent_id, $receipt, $row->allocation_key);
            DB::afterCommit(fn () => $this->processReceiptReturns($current, $row->payment_intent_id, $row->allocation_key));
        });
        return true;
    }

    private function processReceiptReturns($invoice, string $id, ?string $onlyKey = null): void
    {
        if (!$this->gatewayEnabled()) return;
        $this->receiptStripeUnavailable = false;
        try {
            $keys = $onlyKey !== null ? [$onlyKey] : $this->withPaymentLock($invoice, fn ($user) => array_keys($this->readReceipt($user, $id)['allocations'] ?? []));
            foreach ($keys as $key) {
                // Rotate checked receipts, including failures, so one outage cannot starve the queue.
                $this->withPaymentLock($invoice, function ($user) use ($id, $key) {
                    $receipt = $this->readReceipt($user, $id);
                    $this->writeReceipt($user, $id, $receipt, $key);
                });
                try {
                    $process = function ($user, $current) use ($id, $key) {
                        $receipt = $this->readReceipt($user, $id);
                        $a = &$receipt['allocations'][$key];
                        if ($a['state'] === 'credited' || $a['state'] === 'refunded' && empty($a['recheck'])) return;
                        $payment = InvoiceTransaction::where('transaction_id', $id)->where('status', 'succeeded')->lockForUpdate()->first();
                        abort_unless(strtolower($current->currency_code) === $receipt['currency'] && ($receipt['closed_invoice'] || ($payment
                            && $payment->invoice->id === $current->id && ($payment->gateway->extension ?? null) === 'AeStripe'
                            && $this->toMinorUnits($payment->amount, $receipt['currency']) === $receipt['received_minor'])), 409);
                        $intent = $this->request('get', '/payment_intents/' . $id, ['expand' => ['latest_charge']]);
                        abort_unless(($intent->id ?? null) === $id && ($intent->status ?? null) === 'succeeded'
                            && !empty($receipt['customer']) && $receipt['customer'] === $user->properties()->where('key', 'stripe_id')->first()?->value
                            && ($intent->customer ?? null) === $receipt['customer'] && strtolower($intent->currency ?? '') === $receipt['currency']
                            && ($intent->amount_received ?? null) === $receipt['received_minor']
                            && $this->receiptInvoiceMatches($receipt, $intent), 409);
                        $charge = $intent->latest_charge ?? null;
                        if (!is_object($charge) || !isset($charge->disputed) || $charge->disputed || $this->receiptHasReversalHold($id)) {
                            $a['state'] = 'review'; $a['reason'] = 'Check the original payment and any dispute in Stripe before returning money.';
                        } else {
                            $refund = $this->findReceiptRefund($receipt, $a);
                            if (!$refund && $a['sent_at'] === null) {
                                // Commit a send reservation only after reads prove a refund can run.
                                $a['sent_at'] = time();
                                $this->writeReceipt($user, $id, $receipt, $key);
                                return true;
                            }
                            if (!$refund && time() - $a['sent_at'] >= 23 * 3600) {
                                $a['state'] = 'review'; $a['reason'] = 'Refund outcome missing. Check this payment in Stripe and its return key in the invoice Audit Log; do not send another refund or credit.';
                            } else {
                                $refund ??= $this->request('post', '/refunds', ['payment_intent' => $id, 'amount' => $a['amount_minor'],
                                    'metadata' => ['ae_stripe_return' => $a['key']]], $a['key']);
                                abort_unless(($refund->payment_intent ?? null) === $id && ($refund->amount ?? null) === $a['amount_minor']
                                    && strtolower($refund->currency ?? '') === $receipt['currency'] && !empty($refund->id)
                                    && ($refund->metadata->ae_stripe_return ?? null) === $a['key'], 409);
                                abort_unless($a['refund_id'] === null || $a['refund_id'] === $refund->id, 409);
                                $a['refund_id'] = $refund->id;
                                $a['recheck'] = false;
                                $a['state'] = match ($refund->status ?? '') {
                                    'succeeded' => 'refunded', 'failed', 'canceled' => 'credited', default => 'pending',
                                };
                                if (($refund->failure_reason ?? '') === 'charge_for_pending_refund_disputed') $a['state'] = 'review';
                                if ($a['state'] === 'credited') {
                                    $credit = Credit::where('user_id', $user->id)->where('currency_code', $current->currency_code)->lockForUpdate()->first();
                                    if (!$credit) $credit = Credit::create(['user_id' => $user->id, 'currency_code' => $current->currency_code, 'amount' => 0]);
                                    $a['credit_id'] = $credit->id;
                                    $credit->update(['amount' => $this->fromMinorUnits($this->toMinorUnits($credit->amount, $receipt['currency']) + $a['amount_minor'], $receipt['currency'])]);
                                }
                                if (in_array($a['state'], ['refunded', 'credited'], true)) {
                                    $this->postReceiptReturn($current, $receipt, $a);
                                    unset($a['reason']);
                                } else {
                                    $a['reason'] = $a['state'] === 'review' ? 'Check the dispute in Stripe before returning money.'
                                        : (($refund->status ?? '') === 'requires_action' ? 'Complete the refund instructions in Stripe. Do not issue account credit while this refund is open.' : 'Stripe is processing the refund. We will check it automatically.');
                                }
                            }
                        }
                        $this->writeReceipt($user, $id, $receipt, $key);
                        return false;
                    };
                    if ($this->withPaymentLock($invoice, $process)) $this->withPaymentLock($invoice, $process);
                } catch (\Throwable $e) {
                    report($e);
                    if ($e instanceof ConnectionException || $e instanceof RequestException && in_array($e->response->status(), [401, 403], true)) $this->receiptStripeUnavailable = true;
                    $this->withPaymentLock($invoice, function ($user) use ($id, $key) {
                        $receipt = $this->readReceipt($user, $id);
                        // A lost commit acknowledgement can follow a successful local commit.
                        if (in_array($receipt['allocations'][$key]['state'], ['refunded', 'credited'], true) && empty($receipt['allocations'][$key]['recheck'])) return;
                        $receipt['allocations'][$key]['state'] = 'review';
                        $receipt['allocations'][$key]['reason'] = 'Could not verify the return. Check Stripe access and this payment in the invoice Audit Log. Automatic checks will continue; do not issue another refund or credit.';
                        $this->writeReceipt($user, $id, $receipt, $key);
                    });
                }
                $this->notifyReceiptReturn($invoice, $id, $key);
                if ($this->receiptStripeUnavailable) break;
            }
        } catch (\Throwable $e) { report($e); } // Settlement is committed; the scheduler retries.
    }

    private function receiptInvoiceMatches(array $receipt, $intent): bool
    {
        if (!$receipt['closed_invoice']) return true; // The succeeded native receipt proves ordinary settlement.
        if ($receipt['subscription_invoice_id'] === null) return (int) ($intent->metadata->invoice_id ?? 0) === $receipt['invoice_id'];
        // Subscription intents need no checkout metadata. Revalidate their stored invoice and exact paid receipt.
        $invoice = $this->request('get', '/invoices/' . $receipt['subscription_invoice_id']);
        if (($invoice->id ?? null) !== $receipt['subscription_invoice_id'] || ($invoice->status ?? null) !== 'paid'
            || (int) ($invoice->metadata->ae_stripe_invoice_id ?? 0) !== $receipt['invoice_id']
            || ($invoice->customer ?? null) !== $receipt['customer'] || strtolower($invoice->currency ?? '') !== $receipt['currency']
            || ($invoice->amount_paid ?? null) !== $receipt['received_minor']
            || ($invoice->parent->type ?? null) !== 'subscription_details' || empty($invoice->parent->subscription_details->subscription)) return false;
        $payments = $this->request('get', '/invoice_payments', ['invoice' => $invoice->id, 'limit' => 100]);
        $paid = array_values(array_filter($payments->data ?? [], fn ($p) => ($p->status ?? null) === 'paid'));
        return ($payments->has_more ?? true) === false && count($paid) === 1
            && ($paid[0]->amount_paid ?? null) === $receipt['received_minor']
            && ($paid[0]->payment->type ?? null) === 'payment_intent'
            && ($paid[0]->payment->payment_intent ?? null) === $receipt['payment_intent_id'];
    }

    private function postReceiptReturn($invoice, array $receipt, array $allocation): void
    {
        if ($allocation['state'] === 'credited') {
            $return = InvoiceTransaction::where('transaction_id', $allocation['key'])->lockForUpdate()->first();
            if ($return) {
                abort_unless($return->invoice->id === $invoice->id && ($return->gateway->extension ?? null) === 'AeStripe'
                    && ($return->status->value ?? $return->status) === 'succeeded'
                    && $this->toMinorUnits($return->amount, $receipt['currency']) === -$allocation['amount_minor'], 409);
                // The ledger owns this exact reversal. Change only its classification; native
                // financial guards reject general edits, and no paid-status event is needed.
                DB::table('invoice_transactions')->where('id', $return->id)->update(['is_credit_transaction' => true]);
                return;
            }
        }
        $post = function () use ($invoice, $receipt, $allocation) {
            if ($receipt['closed_invoice']) ExtensionHelper::addPayment($invoice, 'AeStripe', $this->fromMinorUnits($receipt['received_minor'], $receipt['currency']), null, $receipt['payment_intent_id']);
            ExtensionHelper::addPayment($invoice, 'AeStripe', -$this->fromMinorUnits($allocation['amount_minor'], $receipt['currency']), null, $allocation['key'], isCreditTransaction: $allocation['state'] === 'credited');
        };
        if ($receipt['closed_invoice'] || !in_array($invoice->status, ['pending', 'paid'], true)) InvoiceTransaction::withoutEvents($post);
        else $post();
    }

    private function findReceiptRefund(array $receipt, array $allocation): ?object
    {
        $cursor = null; $found = null;
        for ($page = 0; $page < 10; $page++) {
            $list = $this->request('get', '/refunds', array_filter(['payment_intent' => $receipt['payment_intent_id'], 'limit' => 100, 'starting_after' => $cursor]));
            abort_unless(is_array($list->data ?? null) && is_bool($list->has_more ?? null), 409);
            foreach ($list->data as $refund) {
                // Foreign returns need finance reconciliation, never an extra credit.
                $known = array_filter($receipt['allocations'], fn ($a) => $a['key'] === ($refund->metadata->ae_stripe_return ?? null)
                    && $a['amount_minor'] === ($refund->amount ?? null));
                abort_unless(count($known) === 1, 409);
                if (($refund->metadata->ae_stripe_return ?? null) === $allocation['key']) {
                    abort_unless($found === null, 409); $found = $refund;
                }
            }
            if (!$list->has_more) return $found;
            $next = end($list->data)->id ?? null;
            abort_unless($next && $next !== $cursor, 409); $cursor = $next;
        }
        abort(409); // Truncated history cannot prove it is safe to send.
    }

    private function notifyReceiptReturn($invoice, string $id, string $key): void
    {
        $this->withPaymentLock($invoice, function ($user, $current) use ($id, $key) {
            $receipt = $this->readReceipt($user, $id);
            $a = &$receipt['allocations'][$key];
            $notice = $this->receiptNotice($a);
            if (($a['notified'] ?? null) === $notice) return;
            Audit::create(['event' => 'ae_stripe_receipt_return', 'auditable_type' => $current->getMorphClass(), 'auditable_id' => $current->id,
                'old_values' => [], 'new_values' => ['payment_intent_id' => $id, 'allocation' => $key] + $a]);
            Notification::create(['user_id' => $user->id, 'title' => 'Payment return', 'url' => route('invoices.show', $current),
                'body' => match ($a['state']) {
                    'refunded' => 'A payment of ' . number_format($this->fromMinorUnits($a['amount_minor'], $receipt['currency']), $this->currencyDecimals($receipt['currency']), '.', '') . ' ' . strtoupper($receipt['currency']) . ' has been returned to your original payment method.',
                    'credited' => 'Stripe could not refund your original payment method. The returned amount is now account credit. Open the invoice or contact support for help.',
                    'pending' => 'Your payment return is processing. We are checking it automatically. Open the invoice or contact support for help.',
                    default => 'Your payment return needs attention. We are checking it automatically. Contact support with invoice ' . $current->id . ' for help.',
                }]);
            if ($a['state'] === 'review' || str_contains($a['reason'] ?? '', 'instructions')) {
                foreach (\App\Models\User::whereNotNull('role_id')->get() as $staff) Notification::create(['user_id' => $staff->id,
                    'title' => 'Stripe payment return needs attention', 'url' => url('/admin/invoices/' . $current->id . '/edit'),
                    'body' => 'Invoice ' . $current->id . ', payment ' . $id . ': ' . $a['reason']]);
            }
            $a['notified'] = $notice;
            $this->writeReceipt($user, $id, $receipt, $key);
        });
    }
}
