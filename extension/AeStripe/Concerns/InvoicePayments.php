<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\InvoiceTransaction;

trait InvoicePayments
{
    /** Call under the caller's user/invoice transaction before applying credit. False means funds are in flight. */
    public function cancelInvoicePayments($invoice): bool
    {
        return $this->withPaymentLock($invoice, function ($user, $current) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (!$attempt) {
                // Older asynchronous debits may predate durable attempt properties.
                foreach ($current->transactions()->where('status', 'processing')->lockForUpdate()->get() as $transaction) {
                    if (($transaction->gateway->extension ?? null) === 'AeStripe') return false;
                }
                return true;
            }
            // Persist before HTTP so an outage is retried even for a checkout-only form.
            $attempt['retire'] = true;
            $attempt['retry'] = true;
            $this->writePaymentAttempt($user, $current, $attempt);
            if (empty($attempt['intent'])) {
                if (($attempt['version'] ?? 1) !== 2 && !isset($attempt['params'])) {
                    return $this->deferInvoiceRetirement($current, $attempt, 'This invoice changed, but the older payment has no ID. Verify its Stripe request before applying credit.');
                }
                if (!empty($attempt['params']['confirm'])) {
                    return $this->deferInvoiceRetirement($current, $attempt, 'An older debit may have been sent. Verify its Stripe request before applying credit.');
                }
                if (time() - $attempt['created'] >= 23 * 3600) {
                    // No client secret can have left checkout before its ID was stored.
                    $this->replacePaymentAttempt($current, $attempt);
                    return true;
                }
                $intent = $this->request('post', '/payment_intents', $attempt['params'], $attempt['key']);
                $attempt['intent'] = $intent->id;
                $this->writePaymentAttempt($user, $current, $attempt);
            }
            try { $intent = $this->request('get', '/payment_intents/' . $attempt['intent']); }
            catch (\Illuminate\Http\Client\RequestException $e) {
                if ($e->response->status() !== 404) throw $e;
                return $this->deferInvoiceRetirement($current, $attempt, $this->missingPaymentReason($attempt));
            }
            if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
                && ($intent->amount_received ?? 0) === 0) {
                try { $intent = $this->request('post', '/payment_intents/' . $intent->id . '/cancel'); }
                catch (\Illuminate\Http\Client\RequestException $e) {
                    if ($e->response->status() !== 400) throw $e;
                    // Confirmation may have won at Stripe. Re-read; never cancel processing money.
                    $intent = $this->request('get', '/payment_intents/' . $intent->id);
                    if (!in_array($intent->status, ['processing', 'requires_capture', 'succeeded', 'canceled'], true)) throw $e;
                }
            }
            if ($intent->status === 'succeeded') $this->recordIntentPayment($intent, 'succeeded');
            if ($intent->status === 'canceled' && ($intent->amount_received ?? 0) === 0
                || $intent->status === 'succeeded' && InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) {
                $this->replacePaymentAttempt($current, $attempt);
                // A just-settled payment changes the balance. Credit callers must re-read before applying.
                return $intent->status === 'canceled';
            }
            if (($intent->amount_received ?? 0) > 0 && !in_array($intent->status, ['processing', 'requires_capture', 'succeeded'], true)) {
                return $this->deferInvoiceRetirement($current, $attempt, 'Stripe received part of this payment. Check the funds before applying credit.');
            }
            return false;
        });
    }

    private function deferInvoiceRetirement($invoice, array $attempt, string $reason): bool
    {
        $this->storePaymentAttempt($invoice, $attempt, ['state' => 'reconcile', 'reason' => $reason, 'retry' => true]);
        $this->sendAttemptReview($invoice);
        return false;
    }
}
