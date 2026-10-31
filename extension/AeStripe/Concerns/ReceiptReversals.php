<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{Audit, Credit, Invoice, InvoiceTransaction, Notification, User};

trait ReceiptReversals
{
    // Reserved ledger keys distinguish observations from money-return allocations.
    private function isReceiptObservation(string $key): bool
    {
        return str_starts_with($key, 'stripe:');
    }

    private function receiptHasReversalHold(string $id): bool
    {
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
            'needs_review' => !in_array($row->state, ['won', 'cleared', 'failed'], true),
            'invoice_id' => $invoice->id, 'user_id' => $user->id, 'customer' => $receipt['customer'],
            'payment_intent_id' => $receipt['payment_intent_id'], 'currency' => $receipt['currency'],
            'amount_minor' => (int) $row->amount_minor,
            'credited_minor' => array_sum(array_map(fn ($a) => $a['state'] === 'credited' ? $a['amount_minor'] : 0, $receipt['allocations'])),
            'credit_balance_minor' => $credit ? $this->toMinorUnits($credit->amount, $receipt['currency']) : 0];
    }

    private function recordReceiptReversalEvent($object, string $kind): void
    {
        $this->ensureReceiptReturnSchema();
        $id = $object->id ?? '';
        abort_unless(is_string($id) && preg_match($kind === 'refund' ? '/^re_[a-zA-Z0-9]+$/D' : '/^dp_[a-zA-Z0-9]+$/D', $id), 409);
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
                : match ($latest->status ?? '') { 'won' => 'won', 'lost' => 'lost', 'warning_closed' => 'cleared', default => 'open' };
            $key = 'stripe:' . $kind . ':' . $id;
            $previous = $this->receiptRows()->where('payment_intent_id', $paymentId)->where('allocation_key', $key)->lockForUpdate()->first();
            if ($previous) {
                abort_unless((int) $previous->amount_minor === $latest->amount, 409);
                if ($kind === 'dispute' && in_array($previous->state, ['won', 'lost', 'cleared'], true) && $state === 'open') return;
                if ($previous->state === $state) return;
            }
            $values = ['user_id' => $user->id, 'invoice_id' => $current->id, 'customer' => $receipt['customer'],
                'currency' => $receipt['currency'], 'received_minor' => $receipt['received_minor'], 'closed_invoice' => $receipt['closed_invoice'],
                'subscription_invoice_id' => $receipt['subscription_invoice_id'] ?? null,
                'amount_minor' => $latest->amount, 'return_key' => 'ae-observe-' . hash('sha256', $paymentId . ':' . $key),
                'state' => $state, 'retry' => false, 'recheck' => false, 'updated_at' => time(),
                'refund_id' => $kind === 'refund' ? $id : null, 'credit_id' => null, 'sent_at' => null, 'reason' => null, 'notified' => $state];
            $details = $this->receiptReversalDetails($user, $current, $receipt, (object) ($values + ['allocation_key' => $key]));
            $money = fn ($amount) => $this->fromMinorUnits($amount, $receipt['currency']) . ' ' . strtoupper($receipt['currency']);
            // A pooled balance cannot prove which credit was spent or what can be debited.
            $line = 'Invoice ' . $current->id . ', customer ' . $user->id . ' (' . $receipt['customer'] . '), payment ' . $paymentId
                . ': ' . $kind . ' ' . $id . ' ' . $state . ', ' . $money($latest->amount)
                . '; receipt credit ' . $money($details['credited_minor']) . ', current account credit ' . $money($details['credit_balance_minor']) . '. '
                . (!$details['needs_review'] ? 'Finance work cleared; check any earlier manual adjustment against Stripe.'
                    : ($kind === 'dispute' && $state === 'open' ? 'Review the dispute in Stripe and submit evidence before its deadline; '
                        : 'Reconcile this reversal in Stripe with the invoice and customer credit history; ')
                    . 'only staff can confirm spent credit and any recovery amount. Do not issue another refund or debit without that proof.');
            $values['reason'] = $line;
            $this->receiptRows()->updateOrInsert(['payment_intent_id' => $paymentId, 'allocation_key' => $key], $values);
            Audit::create(['event' => 'ae_stripe_receipt_reversal', 'auditable_type' => $current->getMorphClass(), 'auditable_id' => $current->id,
                'old_values' => $previous ? ['status' => $previous->state] : [], 'new_values' => $details + ['next_step' => $line]]);
            foreach (User::whereNotNull('role_id')->get() as $staff) Notification::create(['user_id' => $staff->id,
                'title' => $details['needs_review'] ? 'Stripe reversal needs review' : 'Stripe reversal cleared',
                'url' => url('/admin/invoices/' . $current->id . '/edit'), 'body' => $line]);
        });
    }
}
