<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\InvoiceTransaction;

trait InvoicePayments
{
    /** Retire the provider form before a caller applies credit. Unknown funds keep credit waiting. */
    public function cancelInvoicePayments($invoice, bool $forCredit = false, ?string $expectedAttemptKey = null): bool
    {
        $attempt = $this->withPaymentLock($invoice, function ($user, $current) use ($forCredit, $expectedAttemptKey) {
            if ($forCredit) {
                abort_unless($current->user_id == \Illuminate\Support\Facades\Auth::id(), 403);
                if ($current->status !== 'pending') return false;
            }
            $attempt = $this->readPaymentAttempt($user, $current);
            if ($expectedAttemptKey !== null && ($attempt['key'] ?? null) !== $expectedAttemptKey) return false;
            if (!$attempt) {
                foreach ($this->lockedTransactions($current->transactions(), 'processing') as $transaction) {
                    if (($transaction->gateway->extension ?? null) === 'AeStripe') return false;
                }
                return true;
            }
            if (!empty($attempt['renewal_only'])) {
                $this->replacePaymentAttempt($current, $attempt, keepWaitingRenewal: true);
                return true;
            }
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
                    $this->replacePaymentAttempt($current, $attempt, keepWaitingRenewal: true);
                    return true;
                }
            }
            return $attempt;
        });
        if (is_bool($attempt)) return $attempt;
        // A core transaction cannot be suspended for HTTP. Its committed retirement is completed after commit.
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => $this->cancelInvoicePayments($invoice));
            return false;
        }
        if (empty($attempt['intent'])) {
            $created = $this->request('post', '/payment_intents', $attempt['params'], $attempt['key']);
            if (!$this->rememberPaymentIntent($invoice, $attempt, $created)) return false;
            $attempt['intent'] = $created->id;
        }
        try { $intent = $this->request('get', '/payment_intents/' . $attempt['intent']); }
        catch (\Illuminate\Http\Client\RequestException $e) {
            if ($e->response->status() !== 404) throw $e;
            return $this->deferInvoiceRetirement($invoice, $attempt, $this->missingPaymentReason($attempt));
        }
        if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
            && ($intent->amount_received ?? 0) === 0) {
            try { $intent = $this->sendPaymentAction($invoice, $intent, 'cancel'); }
            catch (\Illuminate\Http\Client\RequestException $e) {
                if ($e->response->status() !== 400) throw $e;
                $intent = $this->request('get', '/payment_intents/' . $intent->id);
                if (!in_array($intent->status, ['processing', 'requires_capture', 'succeeded', 'canceled'], true)) throw $e;
            }
        }
        if ($intent->status === 'succeeded') $this->recordIntentPayment($intent, 'succeeded');
        return $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $intent, $forCredit) {
            if ($forCredit) {
                abort_unless($current->user_id == \Illuminate\Support\Facades\Auth::id(), 403);
                if ($current->status !== 'pending') return false;
            }
            if (($this->readPaymentAttempt($user, $current)['key'] ?? null) !== $attempt['key']) return false;
            if ($intent->status === 'canceled' && ($intent->amount_received ?? 0) === 0
                || $intent->status === 'succeeded' && (!in_array($current->status, ['pending', 'paid'], true)
                    || InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists())) {
                $this->replacePaymentAttempt($current, $attempt, keepWaitingRenewal: true);
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
