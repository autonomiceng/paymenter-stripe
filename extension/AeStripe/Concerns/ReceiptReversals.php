<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{Audit, Credit, Invoice, InvoiceTransaction, Notification, User};

trait ReceiptReversals
{
    /** Display-only history for an already authorized invoice. No provider calls, migrations or locks. */
    public function invoiceReversalHistory(Invoice $invoice): array
    {
        if (!$invoice->exists || !\Illuminate\Support\Facades\Schema::hasTable('ae_stripe_receipt_returns')) return [];
        $result = [];
        foreach ($this->receiptRows()->where('invoice_id', $invoice->id)->where('user_id', $invoice->user_id)
            ->where('currency', strtolower($invoice->currency_code))->orderBy('updated_at')->get() as $row) {
            $observation = preg_match('/^stripe:(refund|dispute):(.+)$/D', $row->allocation_key, $parts);
            if ($observation) {
                [, $kind, $id] = $parts;
                $status = $row->state;
            } elseif (!$this->isReceiptObservation($row->allocation_key) && $row->refund_id) {
                $kind = 'refund'; $id = $row->refund_id;
                $status = $row->state === 'credited' ? 'failed' : $row->state;
            } else continue;
            $key = $kind . ':' . $id;
            // The canonical observation wins if an allocation and webhook describe the same refund.
            if (!$observation && isset($result[$key])) {
                $result[$key]['provider_created_at'] ??= $row->provider_created_at ?? null;
                continue;
            }
            $created = $row->provider_created_at ?? $result[$key]['provider_created_at'] ?? null;
            $result[$key] = ['kind' => $kind, 'id' => $id, 'status' => $status,
                'payment_intent_id' => $row->payment_intent_id, 'received_minor' => (int) $row->received_minor,
                'amount_minor' => (int) $row->amount_minor, 'currency' => $row->currency,
                'amount' => $this->fromMinorUnits((int) $row->amount_minor, $row->currency),
                'provider_created_at' => $created === null ? null : (int) $created,
                // Kept separately for old rows with no provider creation date yet.
                'recorded_at' => (int) $row->updated_at];
        }
        uasort($result, fn ($a, $b) => [$a['provider_created_at'] ?? $a['recorded_at'], $a['id']]
            <=> [$b['provider_created_at'] ?? $b['recorded_at'], $b['id']]);
        return array_values($result);
    }

    /** Gross Stripe cash position, before fees. Each payment has its own deduction ceiling. */
    public function invoiceReversalSummary(Invoice $invoice): array
    {
        $rows = $this->invoiceReversalHistory($invoice);
        $payments = [];
        foreach ($invoice->transactions()->with('gateway')->where('status', 'succeeded')->where('amount', '>', 0)
            ->where('is_credit_transaction', false)->get() as $payment) {
            if ($payment->gateway?->extension !== 'AeStripe' || !str_starts_with($payment->transaction_id ?? '', 'pi_')) continue;
            $payments[$payment->transaction_id] = ['received_minor' => $this->toMinorUnits($payment->amount, $invoice->currency_code)];
        }
        foreach ($rows as $row) {
            $payment = &$payments[$row['payment_intent_id']];
            $payment['received_minor'] = $row['received_minor']; // Canonical gross receipt, including any fee recovery.
            $bucket = $row['kind'] === 'refund' ? ($row['status'] === 'refunded' ? 'refunded' : null)
                : match ($row['status']) { 'open' => 'open_disputes', 'lost' => 'lost_disputes', default => null };
            if ($bucket) $payment[$bucket] = ($payment[$bucket] ?? 0) + $row['amount_minor'];
            if ($row['kind'] === 'refund') $payment['has_refund'] = true;
            elseif (!in_array($row['status'], ['won', 'cleared'], true)) $payment['has_dispute'] = true;
        }
        unset($payment);
        $totals = ['refunded' => 0, 'open_disputes' => 0, 'lost_disputes' => 0, 'net_received' => 0];
        $overlap = false;
        foreach ($payments as $payment) {
            $remaining = $payment['received_minor'];
            // Completed refunds are known cash returned; disputes can only take the unreturned remainder.
            foreach (['refunded', 'open_disputes', 'lost_disputes'] as $bucket) {
                $deduction = min($remaining, $payment[$bucket] ?? 0);
                $totals[$bucket] += $deduction;
                $remaining -= $deduction;
            }
            $totals['net_received'] += $remaining;
            $overlap = $overlap || !empty($payment['has_refund']) && !empty($payment['has_dispute']);
        }
        return ['rows' => $rows, 'totals' => array_map(fn ($amount) => $this->fromMinorUnits($amount, $invoice->currency_code), $totals),
            'overlap' => $overlap];
    }

    private function providerCreatedAt(object $object): ?int
    {
        return is_int($object->created ?? null) && $object->created > 0 ? $object->created : null;
    }

    // Reserved ledger keys distinguish observations from money-return allocations.
    private function isReceiptObservation(string $key): bool
    {
        return str_starts_with($key, 'stripe:');
    }

    private function receiptHasReversalHold(string $id): bool
    {
        // Inquiries take no money, but retain the existing hold while the outcome is unresolved.
        foreach ($this->receiptRows()->where('payment_intent_id', $id)->lockForUpdate()->get() as $row) {
            if (str_starts_with($row->allocation_key, 'stripe:refund:')
                || str_starts_with($row->allocation_key, 'stripe:dispute:') && !in_array($row->state, ['won', 'cleared'], true)) return true;
        }
        return false;
    }

    /** Internal read interface for invoice owners such as Domains. No money writes. */
    public function receiptReversals(Invoice $invoice, string $paymentIntentId): array
    {
        $this->ensureReceiptReturnSchema();
        return $this->withPaymentLock($invoice, function ($user, $current) use ($paymentIntentId) {
            $receipt = $this->readReceipt($user, $paymentIntentId);
            abort_unless(!$receipt || $receipt['invoice_id'] === $current->id, 409);
            $result = [];
            foreach ($this->receiptRows()->where('payment_intent_id', $paymentIntentId)->lockForUpdate()->get() as $row) {
                if ($this->isReceiptObservation($row->allocation_key)) $result[] = $this->receiptReversalDetails($user, $current, $receipt, $row) + ['next_step' => $row->reason];
            }
            return $result;
        });
    }

    private function receiptReversalDetails($user, $invoice, array $receipt, $row): array
    {
        [, $kind, $id] = explode(':', $row->allocation_key, 3);
        $credit = Credit::where('user_id', $user->id)->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();
        return ['kind' => $kind, 'id' => $id, 'status' => $row->state,
            'invoice_id' => $invoice->id, 'user_id' => $user->id, 'customer' => $receipt['customer'],
            'payment_intent_id' => $receipt['payment_intent_id'], 'currency' => $receipt['currency'],
            'amount_minor' => (int) $row->amount_minor,
            'credited_minor' => array_sum(array_map(fn ($a) => $a['state'] === 'credited' ? $a['amount_minor'] : 0, $receipt['allocations'])),
            'credit_balance_minor' => $credit ? $this->toMinorUnits($credit->amount, $receipt['currency']) : 0]
            + $this->reversalReview($invoice, $row);
    }

    private function recordReceiptReversalEvent($object, string $kind): void
    {
        $this->ensureReceiptReturnSchema();
        $id = $object->id ?? '';
        // The event type selects the resource; its opaque ID must be a safe path segment.
        abort_unless(is_string($id) && preg_match('/^[a-zA-Z0-9_]+$/D', $id), 409);
        // The signed event locates the receipt; only canonical state is recorded below.
        $paymentId = $object->payment_intent ?? null;
        if (!$paymentId && is_string($object->charge ?? null)) {
            $charge = $this->request('get', '/charges/' . $object->charge);
            abort_unless(($charge->id ?? null) === $object->charge, 409);
            $paymentId = $charge->payment_intent ?? null;
        }
        if (!$paymentId) return; // Not a PaymentIntent receipt.
        abort_unless(is_string($paymentId) && preg_match('/^pi_[a-zA-Z0-9]+$/D', $paymentId), 409);
        $payment = InvoiceTransaction::where('transaction_id', $paymentId)->first();
        if ($payment && ($payment->gateway->extension ?? null) !== 'AeStripe') return;
        $row = $this->receiptRows()->where('payment_intent_id', $paymentId)->first();
        if (!$payment && !$row) {
            // Reuse the gateway's checkout/subscription ownership checks. A reversal can
            // arrive before settlement; retry it rather than discarding owned money.
            if ($this->feeSettlementExpected((object) ['payment_intent' => $paymentId], $paymentId)) abort(409);
            return;
        }
        $invoice = $payment?->invoice ?? Invoice::findOrFail($row->invoice_id);
        $this->withPaymentLock($invoice, function ($user, $current) use ($id, $kind, $paymentId) {
            $receipt = $this->readReceipt($user, $paymentId);
            $payment = InvoiceTransaction::where('transaction_id', $paymentId)->where('status', 'succeeded')->lockForUpdate()->first();
            abort_unless($receipt || $payment, 409);
            if ($payment) abort_unless($payment->invoice->id === $current->id && ($payment->gateway->extension ?? null) === 'AeStripe', 409);
            $intent = $this->request('get', '/payment_intents/' . $paymentId);
            abort_unless(($intent->id ?? null) === $paymentId && ($intent->status ?? null) === 'succeeded'
                && !empty($intent->customer) && ($intent->customer ?? null) === ($receipt ? $receipt['customer'] : $user->properties()->where('key', 'stripe_id')->first()?->value)
                && strtolower($intent->currency ?? '') === strtolower($current->currency_code)
                && is_int($intent->amount_received ?? null) && $intent->amount_received > 0, 409);
            if (!$receipt) $receipt = $this->recordReceipt($current, $intent, 0);
            abort_unless($receipt['invoice_id'] === $current->id && $receipt['customer'] === $intent->customer
                && $receipt['received_minor'] === $intent->amount_received && $receipt['currency'] === strtolower($intent->currency)
                && ($receipt['closed_invoice'] || $payment && $this->toMinorUnits($payment->amount, $receipt['currency']) === $receipt['received_minor']), 409);
            $latest = $this->request('get', ($kind === 'refund' ? '/refunds/' : '/disputes/') . $id);
            $latestPayment = $latest->payment_intent ?? null;
            if (!$latestPayment && is_string($latest->charge ?? null)) {
                $charge = $this->request('get', '/charges/' . $latest->charge);
                abort_unless(($charge->id ?? null) === $latest->charge, 409);
                $latestPayment = $charge->payment_intent ?? null;
            }
            abort_unless(($latest->id ?? null) === $id && $latestPayment === $paymentId
                && strtolower($latest->currency ?? '') === $receipt['currency'] && is_int($latest->amount ?? null)
                && $latest->amount > 0 && $latest->amount <= $receipt['received_minor'], 409);
            $state = $kind === 'refund'
                ? match ($latest->status ?? '') { 'succeeded' => 'refunded', 'failed', 'canceled' => 'failed', default => 'open' }
                : match ($latest->status ?? '') { 'won' => 'won', 'lost' => 'lost', 'warning_closed' => 'cleared',
                    'warning_needs_response', 'warning_under_review' => 'inquiry', default => 'open' };
            $key = 'stripe:' . $kind . ':' . $id;
            $previous = $this->receiptRows()->where('payment_intent_id', $paymentId)->where('allocation_key', $key)->lockForUpdate()->first();
            $created = !empty($previous->provider_created_at) ? (int) $previous->provider_created_at : $this->providerCreatedAt($latest);
            if ($previous) {
                abort_unless((int) $previous->amount_minor === $latest->amount, 409);
                if (empty($previous->provider_created_at) && $created) $this->receiptRows()
                    ->where('payment_intent_id', $paymentId)->where('allocation_key', $key)->update(['provider_created_at' => $created]);
                if ($kind === 'dispute' && in_array($previous->state, ['won', 'lost', 'cleared'], true) && in_array($state, ['open', 'inquiry'], true)) return;
                if ($previous->state === $state) return;
            }
            $values = ['user_id' => $user->id, 'invoice_id' => $current->id, 'customer' => $receipt['customer'],
                'currency' => $receipt['currency'], 'received_minor' => $receipt['received_minor'], 'closed_invoice' => $receipt['closed_invoice'],
                'subscription_invoice_id' => $receipt['subscription_invoice_id'] ?? null,
                'amount_minor' => $latest->amount, 'return_key' => 'ae-obsv-' . hash('sha256', $paymentId . ':' . $key),
                'state' => $state, 'retry' => false, 'recheck' => false, 'updated_at' => time(), 'provider_created_at' => $created,
                'refund_id' => $kind === 'refund' ? $id : null, 'credit_id' => null, 'sent_at' => null, 'reason' => null, 'notified' => $state];
            $details = $this->receiptReversalDetails($user, $current, $receipt, (object) ($values + ['allocation_key' => $key, 'payment_intent_id' => $paymentId]));
            $money = fn ($amount) => $this->fromMinorUnits($amount, $receipt['currency']) . ' ' . strtoupper($receipt['currency']);
            // A pooled balance cannot prove which credit was spent or what can be debited.
            $auditStep = 'Invoice ' . $current->id . ', customer ' . $user->id . ' (' . $receipt['customer'] . '), payment ' . $paymentId
                . ': ' . $kind . ' ' . $id . ' ' . $state . ', ' . $money($latest->amount)
                . '; receipt credit ' . $money($details['credited_minor']) . ', current account credit ' . $money($details['credit_balance_minor']) . '. '
                . (!$details['needs_review'] ? 'Finance work cleared; check any earlier manual adjustment against Stripe.'
                    : ($kind === 'dispute' && in_array($state, ['open', 'inquiry'], true) ? 'Review the dispute in Stripe and submit evidence before its deadline; '
                        : 'Reconcile this reversal in Stripe with the invoice and customer credit history; ')
                    . 'only staff can confirm spent credit and any recovery amount. Do not issue another refund or debit without that proof.');
            $values['reason'] = $auditStep;
            $amount = $current->formattedTotal->format($this->fromMinorUnits($latest->amount, $receipt['currency']));
            $bellText = ($kind === 'refund' ? match ($state) {
                'refunded' => $amount . ' refunded on invoice ', 'failed' => $amount . ' refund failed on invoice ',
                default => $amount . ' refund pending on invoice ',
            } : match ($state) {
                'won' => 'Dispute won on invoice ', 'cleared' => 'Inquiry closed on invoice ',
                'lost' => $amount . ' lost to a dispute on invoice ', 'inquiry' => 'Stripe inquiry on invoice ',
                default => $amount . ' disputed on invoice ',
            }) . $this->invoiceLabel($current) . '.';
            $this->receiptRows()->updateOrInsert(['payment_intent_id' => $paymentId, 'allocation_key' => $key], $values);
            Audit::create(['event' => 'ae_stripe_receipt_reversal', 'auditable_type' => $current->getMorphClass(), 'auditable_id' => $current->id,
                'old_values' => $previous ? ['status' => $previous->state] : [],
                'new_values' => array_diff_key($details, ['id' => true]) + ['stripe_id' => $id, 'next_step' => $auditStep]]);
            foreach ($this->invoiceNoticeStaff($current) as $staff) Notification::create(['user_id' => $staff->id,
                'title' => $details['needs_review'] ? 'Stripe reversal needs review' : 'Stripe reversal cleared',
                'url' => $this->adminInvoiceUrl($current), 'body' => $this->invoiceNoticeBody('reversal', $bellText, $auditStep)]);
        });
    }
}
