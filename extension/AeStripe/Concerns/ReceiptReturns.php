<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit, Invoice, InvoiceTransaction, Notification, Property};
use Illuminate\Support\Facades\DB;

trait ReceiptReturns
{
    public function receiptReturnStatus(Invoice $invoice, string $paymentIntentId, string $allocationKey): ?array
    {
        return $this->withPaymentLock($invoice, function ($user, $current) use ($paymentIntentId, $allocationKey) {
            $receipt = $this->readReceipt($user, $paymentIntentId);
            abort_unless(!$receipt || $receipt['invoice_id'] === $current->id, 409);
            return $receipt['allocations'][$allocationKey] ?? null;
        });
    }

    public function allocateReceiptReturn(Invoice $invoice, string $paymentIntentId, string $allocationKey, int $amountMinor): array
    {
        abort_unless(preg_match('/^pi_[a-zA-Z0-9]+$/D', $paymentIntentId) && $allocationKey !== 'excess' && strlen($allocationKey) <= 180 && $allocationKey !== '' && $amountMinor > 0, 409);
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
                $this->recordReceipt($current, $intent, 0);
                $receipt = $this->readReceipt($user, $paymentIntentId);
            }
            abort_unless($receipt['invoice_id'] === $current->id, 409);
            $this->reserveReceiptReturn($receipt, $allocationKey, $amountMinor);
            $this->writeReceipt($user, $paymentIntentId, $receipt);
            DB::afterCommit(fn () => $this->processReceiptReturns($current, $paymentIntentId));
        });
        return $this->receiptReturnStatus($invoice, $paymentIntentId, $allocationKey);
    }

    private function readReceipt($user, string $id): ?array
    {
        $value = $user->properties()->where('key', 'ae_stripe_receipt_' . $id)->lockForUpdate()->first()?->value;
        return $value ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;
    }

    private function writeReceipt($user, string $id, array $receipt): void
    {
        $receipt['retry'] = (bool) array_filter($receipt['allocations'], fn ($a) => !in_array($a['state'], ['refunded', 'credited'], true) || empty($a['notified']));
        $user->properties()->updateOrCreate(['key' => 'ae_stripe_receipt_' . $id], ['value' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
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

    // The native receipt and excess reservation commit together, before any refund I/O.
    private function recordReceipt($invoice, $intent, int $excess): void
    {
        if (!$this->readReceipt($invoice->user, $intent->id)) {
            $receipt = ['version' => 1, 'invoice_id' => $invoice->id, 'payment_intent_id' => $intent->id,
                'customer' => $intent->customer ?? null, 'currency' => strtolower($intent->currency),
                'received_minor' => $intent->amount_received, 'allocations' => []];
            if ($excess > 0) $this->reserveReceiptReturn($receipt, 'excess', $excess);
            $this->writeReceipt($invoice->user, $intent->id, $receipt);
        }
        DB::afterCommit(fn () => $this->processReceiptReturns($invoice, $intent->id));
    }

    public function retryReceiptReturns(): void
    {
        if (!$this->gatewayEnabled()) return;
        foreach (Property::where('key', 'like', 'ae_stripe_receipt_%')->where('value', 'like', '%"retry":true%')->orderBy('updated_at')->limit(100)->get() as $property) {
            try {
                $receipt = json_decode($property->value, true, flags: JSON_THROW_ON_ERROR);
                $invoice = Invoice::findOrFail($receipt['invoice_id']);
                if ($property->model_id != $invoice->user_id || $property->model_type !== $invoice->user->getMorphClass()) continue;
                $this->processReceiptReturns($invoice, $receipt['payment_intent_id']);
            } catch (\Throwable $e) { report($e); }
        }
    }

    private function processReceiptReturns($invoice, string $id): void
    {
        if (!$this->gatewayEnabled()) return;
        try {
            $keys = $this->withPaymentLock($invoice, fn ($user) => array_keys($this->readReceipt($user, $id)['allocations'] ?? []));
            foreach ($keys as $key) {
                // Rotate checked receipts, including failures, so one outage cannot starve the queue.
                $this->withPaymentLock($invoice, function ($user) use ($id, $key) {
                    $receipt = $this->readReceipt($user, $id);
                    $receipt['checked_at'] = time();
                    $this->writeReceipt($user, $id, $receipt);
                });
                try {
                    $process = function ($user, $current) use ($id, $key) {
                        $receipt = $this->readReceipt($user, $id);
                        $a = &$receipt['allocations'][$key];
                        if (in_array($a['state'], ['refunded', 'credited'], true)) return;
                        $payment = InvoiceTransaction::where('transaction_id', $id)->where('status', 'succeeded')->lockForUpdate()->first();
                        abort_unless($payment && $payment->invoice->id === $current->id && ($payment->gateway->extension ?? null) === 'AeStripe'
                            && strtolower($current->currency_code) === $receipt['currency']
                            && $this->toMinorUnits($payment->amount, $receipt['currency']) === $receipt['received_minor'], 409);
                        $intent = $this->request('get', '/payment_intents/' . $id, ['expand' => ['latest_charge']]);
                        abort_unless(($intent->id ?? null) === $id && ($intent->status ?? null) === 'succeeded'
                            && !empty($receipt['customer']) && $receipt['customer'] === $user->properties()->where('key', 'stripe_id')->first()?->value
                            && ($intent->customer ?? null) === $receipt['customer'] && strtolower($intent->currency ?? '') === $receipt['currency']
                            && ($intent->amount_received ?? null) === $receipt['received_minor'], 409);
                        $charge = $intent->latest_charge ?? null;
                        if (!is_object($charge) || !isset($charge->disputed) || $charge->disputed) {
                            $a['state'] = 'review'; $a['reason'] = 'Check the original payment and any dispute in Stripe before returning money.';
                        } else {
                            $refund = $this->findReceiptRefund($receipt, $a);
                            if (!$refund && $a['sent_at'] === null) {
                                // Commit a send reservation only after reads prove a refund can run.
                                $a['sent_at'] = time();
                                $this->writeReceipt($user, $id, $receipt);
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
                                $a['refund_id'] = $refund->id;
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
                                    ExtensionHelper::addPayment($current, 'AeStripe', -$this->fromMinorUnits($a['amount_minor'], $receipt['currency']), null, $a['key']);
                                    unset($a['reason']);
                                } else {
                                    $a['reason'] = $a['state'] === 'review' ? 'Check the dispute in Stripe before returning money.'
                                        : (($refund->status ?? '') === 'requires_action' ? 'Complete the refund instructions in Stripe. Do not issue account credit while this refund is open.' : 'Stripe is processing the refund. We will check it automatically.');
                                }
                            }
                        }
                        $this->writeReceipt($user, $id, $receipt);
                        return false;
                    };
                    if ($this->withPaymentLock($invoice, $process)) $this->withPaymentLock($invoice, $process);
                } catch (\Throwable $e) {
                    report($e);
                    $this->withPaymentLock($invoice, function ($user) use ($id, $key) {
                        $receipt = $this->readReceipt($user, $id);
                        // A lost commit acknowledgement can follow a successful local commit.
                        if (in_array($receipt['allocations'][$key]['state'], ['refunded', 'credited'], true)) return;
                        $receipt['allocations'][$key]['state'] = 'review';
                        $receipt['allocations'][$key]['reason'] = 'Could not verify the return. Check Stripe access and this payment in the invoice Audit Log. Automatic checks will continue; do not issue another refund or credit.';
                        $this->writeReceipt($user, $id, $receipt);
                    });
                }
                $this->notifyReceiptReturn($invoice, $id, $key);
            }
        } catch (\Throwable $e) { report($e); } // Settlement is committed; the scheduler retries.
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
            $notice = $a['state'] . ':' . ($a['reason'] ?? '');
            if (($a['notified'] ?? null) === $notice) return;
            Audit::create(['event' => 'ae_stripe_receipt_return', 'auditable_type' => $current->getMorphClass(), 'auditable_id' => $current->id,
                'old_values' => [], 'new_values' => ['payment_intent_id' => $id, 'allocation' => $key] + $a]);
            Notification::create(['user_id' => $user->id, 'title' => 'Payment return', 'url' => route('invoices.show', $current),
                'body' => match ($a['state']) {
                    'refunded' => 'A payment of ' . $this->fromMinorUnits($a['amount_minor'], $receipt['currency']) . ' ' . strtoupper($receipt['currency']) . ' has been returned to your original payment method.',
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
            $this->writeReceipt($user, $id, $receipt);
        });
    }
}
