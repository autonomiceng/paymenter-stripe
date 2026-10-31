<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{BillingAgreement, Service};
use Illuminate\Support\Facades\DB;

trait RenewalRetry
{
    // Three retries 48 hours apart let temporary card problems clear within a seven-day renewal window.
    private const RENEWAL_RETRIES = 3;
    private const RENEWAL_RETRY_SECONDS = 48 * 3600;

    /** Only native scheduled renewals. Account defaults never authorize an existing invoice. */
    private function nativeRenewal($invoice, int $agreementId): ?array
    {
        $cutoff = $invoice->due_at?->format('Y-m-d');
        if (!$cutoff || $cutoff <= now()->format('Y-m-d')) return null;
        $ids = $this->nativeRenewalServices($invoice);
        if (!$ids) return null;
        $renewal = ['services' => $ids, 'agreement_id' => $agreementId,
            'cutoff' => $cutoff, 'retries' => 0, 'state' => 'active'];
        return $this->renewalStillAuthorized($invoice, $renewal) ? $renewal : null;
    }

    private function nativeRenewalServices($invoice): ?array
    {
        $ids = [];
        foreach ($invoice->items()->lockForUpdate()->get() as $item) {
            if ($item->reference_type !== (new Service)->getMorphClass()) return null;
            $ids[] = (int) $item->reference_id;
        }
        sort($ids);
        return $ids ? array_values(array_unique($ids)) : null;
    }

    /** Current per-service consent, calendar and balance, under the payment locks, including at confirm. */
    private function renewalStillAuthorized($invoice, array $renewal): bool
    {
        if ($invoice->status !== 'pending' || $this->paymentBalance($invoice) <= 0
            || $renewal['cutoff'] <= now()->format('Y-m-d')
            || $invoice->due_at?->format('Y-m-d') !== $renewal['cutoff']) return false;
        if ($this->nativeRenewalServices($invoice) !== $renewal['services']) return false;
        foreach ($renewal['services'] as $id) {
            $service = Service::where('id', $id)->lockForUpdate()->first();
            if (!$service || $service->user_id != $invoice->user_id || $service->status !== 'active'
                || ($service->plan?->type ?? null) !== 'recurring' || $service->subscription_id
                || $service->cancellation || $service->billing_agreement_id != $renewal['agreement_id']
                || $service->expires_at?->format('Y-m-d') !== $renewal['cutoff']
                || $service->product?->server?->extension === 'Domains') return false;
        }
        $agreement = BillingAgreement::where('id', $renewal['agreement_id'])->lockForUpdate()->first();
        return $agreement && $agreement->user_id == $invoice->user_id && ($agreement->gateway->extension ?? null) === 'AeStripe';
    }

    /** A fresh, definite zero-money failure alone arms a new debit; repeated webhooks cannot move its date. */
    private function renewalRefused($invoice, array $attempt, $intent): array
    {
        if (($attempt['renewal']['state'] ?? null) !== 'active' || $intent->status !== 'requires_payment_method'
            || ($intent->amount_received ?? 0) !== 0) return $attempt;
        $error = $intent->last_payment_error ?? null;
        $retryable = in_array($error->code ?? null, ['card_declined', 'processing_error'], true)
            && in_array($error->decline_code ?? null, [null, 'generic_decline', 'insufficient_funds', 'issuer_not_available', 'processing_error', 'try_again_later'], true)
            && !in_array($error->advice_code ?? null, ['do_not_try_again', 'confirm_card_data'], true);
        $attempt['renewal']['next_at'] = time() + self::RENEWAL_RETRY_SECONDS;
        $willRetry = $retryable && $attempt['renewal']['retries'] < self::RENEWAL_RETRIES
            && date('Y-m-d', $attempt['renewal']['next_at']) < $attempt['renewal']['cutoff'];
        $attempt['renewal']['state'] = $willRetry ? 'waiting' : 'stopped';
        $attempt['failure_reason'] = $this->paymentFailureReason($intent->last_payment_error->code ?? null)
            . ' Open the invoice to pay now or update Auto Pay on your service.'
            . ($willRetry
                ? ' We may retry in two days, before the renewal due date.' : ' Automatic retries have stopped.');
        $attempt['failure_notified'] = false;
        DB::afterCommit(fn () => $this->notifyRecoveryRefusal($invoice, $attempt['failure_reason']));
        return $attempt;
    }

    /** Reserve the next shared attempt atomically. HTTP confirmation belongs to ordinary recovery. */
    private function advanceRenewalRetry($invoice): ?array
    {
        return $this->withPaymentLock($invoice, function ($user, $current) {
            $attempt = $this->readPaymentAttempt($user, $current);
            $renewal = $attempt['renewal'] ?? null;
            if (!$renewal || $renewal['state'] === 'active') return $attempt;
            if (!empty($attempt['funds_pending']) || ($attempt['state'] ?? null) === 'reconcile') return $attempt;
            if ($renewal['state'] === 'stopped') return null;
            if ($renewal['retries'] >= self::RENEWAL_RETRIES || !$this->renewalStillAuthorized($current, $renewal)) {
                $attempt['renewal']['state'] = 'stopped';
                $this->writePaymentAttempt($user, $current, $attempt);
                return null;
            }
            if ($renewal['next_at'] > time()) return null;
            // Never replace uncertainty, money in flight, or a newer customer's payment form.
            if (!empty($attempt['confirmation']) || !empty($attempt['agreement_id'])) return $attempt;
            if (!$this->cancelInvoicePayments($current)) return null;
            $this->replacePaymentAttempt($current, $attempt); // Consume any retained schedule in this same reservation transaction.
            $data = $this->checkoutPaymentData($current, $this->paymentBalance($current),
                $user->properties()->where('key', 'stripe_id')->firstOrFail()->value)
                + ['agreement_id' => $renewal['agreement_id']];
            $next = $this->reservePaymentAttempt($current, $data);
            $next['automatic_renewal'] = true;
            $next['renewal'] = array_replace($renewal, ['state' => 'active', 'retries' => $renewal['retries'] + 1]);
            $this->writePaymentAttempt($user, $current, $next);
            return $next;
        });
    }
}
