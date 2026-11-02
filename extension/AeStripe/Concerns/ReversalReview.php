<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{Audit, Invoice};
use Illuminate\Support\Facades\{Auth, Schema};

/** Review decisions only. Canonical reversals and money-return guards are never changed here. */
trait ReversalReview
{
    /** Read-only staff display; callers authorize the invoice. No provider calls or schema changes. */
    public function invoiceReversalReviews(Invoice $invoice): array
    {
        if (!$invoice->exists || !Schema::hasTable('ae_stripe_receipt_returns')) return [];
        $reviews = [];
        foreach ($this->receiptRows()->where('invoice_id', $invoice->id)->where('user_id', $invoice->user_id)
            ->where('currency', strtolower($invoice->currency_code))->get() as $row) {
            if (preg_match('/^stripe:(refund|dispute):(.+)$/D', $row->allocation_key, $parts)) {
                $reviews[$parts[1] . ':' . $parts[2]] = $this->reversalReview($invoice, $row, false);
            }
        }
        return $reviews;
    }

    public function resolveReceiptReversal(Invoice $invoice, string $paymentId, string $id, string $kind, string $reviewKey, string $note): void
    {
        $actor = Auth::user();
        abort_unless($actor?->role_id && $actor->hasPermission('admin.invoices.view') && $actor->hasPermission('admin.invoices.update'), 403);
        $note = trim($note);
        abort_unless($note !== '' && strlen($note) <= 1000, 422);
        abort_unless(in_array($kind, ['refund', 'dispute'], true) && preg_match('/^[a-zA-Z0-9_]+$/D', $id), 409);
        $this->withPaymentLock($invoice, function ($user, $current) use ($actor, $paymentId, $id, $kind, $reviewKey, $note) {
            $receipt = $this->readReceipt($user, $paymentId);
            abort_unless($receipt && $receipt['invoice_id'] === $current->id && $receipt['currency'] === strtolower($current->currency_code), 409);
            $row = $this->receiptRows()->where('payment_intent_id', $paymentId)->where('allocation_key', 'stripe:' . $kind . ':' . $id)->lockForUpdate()->first();
            abort_unless($row, 409);
            $review = $this->reversalReview($current, $row);
            abort_unless(hash_equals($review['review_key'], $reviewKey), 409);
            if (!$review['needs_review']) return; // Includes a replay of the same resolution.
            Audit::create(['event' => 'ae_stripe_reversal_resolved', 'auditable_type' => $current->getMorphClass(), 'auditable_id' => $current->id,
                'user_type' => $actor->getMorphClass(), 'user_id' => $actor->id, 'old_values' => [],
                'new_values' => ['stripe_id' => $id, 'kind' => $kind, 'payment_intent_id' => $paymentId,
                    'review_key' => $reviewKey, 'note' => $note]]);
        });
    }

    private function reversalReview(Invoice $invoice, object $row, bool $locked = true): array
    {
        $query = $this->receiptRows()->where('payment_intent_id', $row->payment_intent_id);
        $rows = [];
        foreach (($locked ? $query->lockForUpdate() : $query)->get() as $sibling) $rows[$sibling->allocation_key] = $sibling;
        $rows[$row->allocation_key] = $row; // Also supports a canonical event before its observation is written.
        ksort($rows);
        $safe = str_starts_with($row->allocation_key, 'stripe:refund:') && $row->state === 'refunded';
        $evidence = []; $refunds = [];
        foreach ($rows as $key => $sibling) {
            $evidence[$key] = array_intersect_key((array) $sibling, array_flip([
                'user_id', 'invoice_id', 'customer', 'currency', 'received_minor', 'amount_minor', 'state', 'refund_id', 'credit_id', 'recheck', 'updated_at',
            ]));
            // Allocation retry/notification touches are not new financial evidence.
            if (!$this->isReceiptObservation($key)) unset($evidence[$key]['updated_at']);
            ksort($evidence[$key]);
            if ((int) $sibling->user_id !== (int) $invoice->user_id || (int) $sibling->invoice_id !== (int) $invoice->id
                || $sibling->currency !== strtolower($invoice->currency_code)) $safe = false;
            if (str_starts_with($key, 'stripe:dispute:')) {
                if (!in_array($sibling->state, ['won', 'cleared'], true)) $safe = false;
            } elseif ($this->isReceiptObservation($key)) {
                if (isset($refunds[$sibling->refund_id]) && ($sibling->state !== 'refunded'
                    || $refunds[$sibling->refund_id] !== (int) $sibling->amount_minor)) $safe = false;
                if ($sibling->state === 'refunded') $refunds[$sibling->refund_id] = (int) $sibling->amount_minor;
                elseif ($sibling->state !== 'failed') $safe = false;
            } else {
                // Even a spent credit with zero pooled balance still needs attribution by staff.
                if ($sibling->credit_id || $sibling->state !== 'refunded' || $sibling->recheck || !$sibling->refund_id) $safe = false;
                elseif (isset($refunds[$sibling->refund_id]) && $refunds[$sibling->refund_id] !== (int) $sibling->amount_minor) $safe = false;
                else $refunds[$sibling->refund_id] = (int) $sibling->amount_minor;
            }
        }
        if (array_sum($refunds) > (int) $row->received_minor) $safe = false;
        $payments = $invoice->transactions();
        $creditEvidence = [];
        foreach (($locked ? $payments->lockForUpdate() : $payments)->get() as $payment) {
            if ($payment->is_credit_transaction ?? false) {
                $safe = false;
                $creditEvidence[$payment->transaction_id ?? $payment->id] = [$payment->amount, $payment->status];
            }
        }
        ksort($creditEvidence);
        $reviewKey = hash('sha256', json_encode([$row->payment_intent_id, $row->allocation_key, $evidence, $creditEvidence], JSON_THROW_ON_ERROR));
        $cleared = in_array($row->state, ['won', 'cleared', 'failed'], true) || $safe;
        $resolution = null;
        if (!$cleared && ($locked || Schema::hasTable('audits'))) {
            $query = Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
                ->where('event', 'ae_stripe_reversal_resolved')->where('new_values->review_key', $reviewKey);
            $resolution = ($locked ? $query->lockForUpdate() : $query)->first();
        }
        return ['needs_review' => !$cleared && !$resolution, 'review_key' => $reviewKey,
            'review_status' => $cleared ? 'cleared' : ($resolution ? 'resolved' : 'needs_review')];
    }
}
