<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\{Audit, BillingAgreement, Invoice, Notification, Property, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\AeStripe\PaymentPendingException;

trait ChargeRecovery
{
    private function findLegacyPayment($invoice, array $attempt)
    {
        // A positive, unambiguous match supplies an identity. An empty search proves nothing.
        $result = $this->request('get', '/payment_intents/search', [
            'query' => "metadata['invoice_id']:'" . $invoice->id . "'", 'limit' => 100,
        ]);
        $matches = array_values(array_filter($result->data ?? [], fn ($intent) =>
            ($intent->metadata->ae_stripe_attempt ?? null) === $attempt['key']
            && ($intent->customer ?? null) === $invoice->user->properties()->where('key', 'stripe_id')->first()?->value));
        return !($result->has_more ?? false) && count($matches) === 1 ? $matches[0] : null;
    }

    private function missingPaymentReason(array $attempt): string
    {
        return !empty($attempt['confirmation'])
            ? 'Stripe cannot find this payment. A debit was sent. Restore access to its Stripe account or check the confirm key in that account’s request log.'
            : 'Stripe cannot find this payment. Restore access to its Stripe account or verify that no charge was made before releasing it.';
    }

    private function holdPaymentAttempt($invoice, array $attempt, string $reason): never
    {
        $this->storePaymentAttempt($invoice, $attempt, ['state' => 'reconcile', 'reason' => $reason, 'retry' => true]);
        try { $this->sendAttemptReview($invoice); } catch (\Throwable $e) { report($e); }
        $this->paymentReconcileMessage();
    }

    private function sendAttemptReview($invoice): void
    {
        $this->withPaymentLock($invoice, function ($user, $current) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (($attempt['state'] ?? null) !== 'reconcile' || ($attempt['alert_sent'] ?? false)) return;
            if (!Audit::where('auditable_type', $current->getMorphClass())->where('auditable_id', $current->id)
                ->where('event', 'ae_stripe_attempt_review')->where('new_values->attempt_key', $attempt['key'])->lockForUpdate()->first()) {
                Audit::create(['event' => 'ae_stripe_attempt_review', 'auditable_type' => $current->getMorphClass(),
                    'auditable_id' => $current->id, 'old_values' => [], 'new_values' => [
                        'attempt_key' => $attempt['key'], 'reason' => $attempt['reason'],
                        'confirm_key' => $attempt['confirmation']['key'] ?? null,
                        'resolution' => 'Use ae-stripe:resolve-charge to link the verified payment or record proof that it did not charge.',
                    ]]);
            }
            DB::afterCommit(function () use ($current) {
                try {
                    $this->withPaymentLock($current, function ($user, $invoice) {
                        $attempt = $this->readPaymentAttempt($user, $invoice);
                        if (($attempt['state'] ?? null) !== 'reconcile' || ($attempt['alert_sent'] ?? false)) return;
                        foreach (User::whereNotNull('role_id')->get() as $staff) Notification::create([
                            'user_id' => $staff->id, 'title' => 'Stripe payment needs review',
                            'body' => 'Invoice ' . $invoice->id . ': ' . $attempt['reason'] . ' Use ae-stripe:resolve-charge.',
                            'url' => url('/admin/invoices/' . $invoice->id . '/edit'),
                        ]);
                        $attempt['alert_sent'] = true;
                        $this->writePaymentAttempt($user, $invoice, $attempt);
                    });
                } catch (\Throwable $e) { report($e); } // The scheduler retries delivery; never fail a settled cron payment.
            });
        });
    }

    public function retryChargeAttempts(): void
    {
        if (!$this->gatewayEnabled()) return;
        $this->retryPaymentReviewAlerts();
        $this->paymentStripeUnavailable = false;
        foreach (Property::where('key', 'like', 'ae_stripe_checkout_%')->where(fn ($query) =>
            $query->where('value', 'like', '%"retry":true%')->orWhere('value', 'not like', '%"version":2%')
                ->orWhere(fn ($saved) => $saved->where('value', 'like', '%"agreement_id":%')->where('value', 'not like', '%"agreement_id":null%')))
            ->orderBy('updated_at')->limit(100)->get() as $property) {
            if (!preg_match('/^ae_stripe_checkout_([0-9]+)$/', $property->key, $match)) continue;
            try {
                $invoice = Invoice::findOrFail($match[1]);
                if ($property->model_id != $invoice->user_id || $property->model_type !== $invoice->user->getMorphClass()) continue;
                $attempt = $this->withPaymentLock($invoice, function ($user, $current) {
                    $attempt = $this->readPaymentAttempt($user, $current);
                    if (!$attempt) return null;
                    $attempt['checks'] = ($attempt['checks'] ?? 0) + 1;
                    $this->writePaymentAttempt($user, $current, $attempt);
                    return $attempt;
                });
                if (!$attempt) continue;
                if ($invoice->status !== 'pending') {
                    if (!isset($attempt['intent'])) {
                        if (($attempt['version'] ?? 1) === 2 || isset($attempt['params']) && empty($attempt['params']['confirm'])) $this->replacePaymentAttempt($invoice, $attempt);
                        else $this->holdPaymentAttempt($invoice, $attempt, 'This invoice is closed, but Stripe has no payment ID for the older attempt. Check its request log.');
                        continue;
                    }
                    try { $intent = $this->request('get', '/payment_intents/' . $attempt['intent']); }
                    catch (\Illuminate\Http\Client\RequestException $e) {
                        if ($e->response->status() !== 404) throw $e;
                        $this->holdPaymentAttempt($invoice, $attempt, $this->missingPaymentReason($attempt));
                    }
                    if ($intent->status === 'succeeded') $this->recordIntentPayment($intent, 'succeeded');
                    elseif (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true) && ($intent->amount_received ?? 0) === 0) {
                        $intent = $this->request('post', '/payment_intents/' . $intent->id . '/cancel');
                    }
                    if ($intent->status === 'canceled' && ($intent->amount_received ?? 0) === 0
                        || $intent->status === 'succeeded' && \App\Models\InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) $this->replacePaymentAttempt($invoice, $attempt);
                    continue;
                }
                $this->sendAttemptReview($invoice);
                if (!empty($attempt['failure_reason']) && empty($attempt['failure_notified'])) {
                    $this->notifyRecoveryRefusal($invoice, $attempt['failure_reason']);
                    continue;
                }
                // Recovery never replays a frozen charge. Every confirmation uses today's balance
                // and an active agreement; checkout tokens resume only at the same displayed amount.
                $data = $attempt['params'] ?? $this->checkoutPaymentData($invoice, 0,
                    $invoice->user->properties()->where('key', 'stripe_id')->firstOrFail()->value);
                if (!isset($attempt['intent']) && !empty($attempt['agreement_id'])) $data['agreement_id'] = $attempt['agreement_id'];
                $intent = $this->checkoutIntent($invoice, $data);
                if ($intent->status === 'succeeded') { $this->recordIntentPayment($intent, 'succeeded'); continue; }
                $attempt = $this->withPaymentLock($invoice, fn ($user, $current) => $this->readPaymentAttempt($user, $current));
                if (($attempt['intent'] ?? null) !== $intent->id) continue;
                if ($this->paymentAttemptFailed($attempt, $intent)) {
                    $this->recordIntentPayment($intent, 'failed');
                    $this->notifyRecoveryRefusal($invoice, $this->paymentFailureReason($intent->last_payment_error->code ?? null));
                    continue;
                }
                $confirmation = $attempt['confirmation'] ?? null;
                if ($confirmation && !empty($attempt['agreement_id']) && ($confirmation['agreement'] ?? null) != $attempt['agreement_id']) $confirmation = null;
                if (!$confirmation && !empty($attempt['agreement_id']) && in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) {
                    $agreement = BillingAgreement::where('id', $attempt['agreement_id'])->first();
                    if ($agreement) $this->charge($invoice, 0, $agreement);
                    if ($this->paymentStripeUnavailable) break;
                    continue;
                }
                if ($confirmation && in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) {
                    $agreement = !empty($confirmation['agreement']) ? BillingAgreement::where('id', $confirmation['agreement'])->first() : null;
                    if (!empty($confirmation['agreement']) && !$agreement) continue;
                    $intent = $this->confirmAttempt($invoice, $intent->id, $confirmation['params'], $agreement, $confirmation['amount'] ?? null);
                    if ($intent->status === 'succeeded') $this->recordIntentPayment($intent, 'succeeded');
                }
            } catch (PaymentPendingException $e) {
                if ($this->paymentStripeUnavailable) break; // One outage does not make the worker wait on every customer.
                // The durable retry marker survives an outage. Other invoices still get their turn.
            } catch (\App\Exceptions\DisplayException $e) {
                $this->notifyRecoveryRefusal($invoice, $e->getMessage());
            } catch (\Illuminate\Http\Client\RequestException | \Illuminate\Http\Client\ConnectionException $e) { report($e); break; }
            catch (\Throwable $e) { report($e); }
        }
    }

    private function notifyRecoveryRefusal($invoice, string $reason): void
    {
        $this->withPaymentLock($invoice, function ($user, $current) use ($reason) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (!$attempt || !empty($attempt['failure_notified'])) return;
            $this->writePaymentAttempt($user, $current, array_replace($attempt, [
                'agreement_id' => null, 'confirmation' => null, 'retry' => true, 'failure_reason' => $reason,
            ]));
            DB::afterCommit(function () use ($current, $reason) {
                try {
                    $this->withPaymentLock($current, function ($user, $invoice) use ($reason) {
                        $attempt = $this->readPaymentAttempt($user, $invoice);
                        if (!$attempt || ($attempt['failure_reason'] ?? null) !== $reason || !empty($attempt['failure_notified'])) return;
                        Notification::create(['user_id' => $user->id, 'title' => 'Payment not completed',
                            'body' => $reason, 'url' => route('invoices.show', $invoice)]);
                        $this->writePaymentAttempt($user, $invoice, array_replace($attempt, ['failure_notified' => true, 'retry' => false]));
                    });
                } catch (\Throwable $e) { report($e); }
            });
        });
    }

    private function retryPaymentReviewAlerts(): void
    {
        foreach (Property::where('key', 'like', 'ae_stripe_payment_alert_%')->where('value', 'like', '%"sent":false%')->limit(100)->get() as $property) {
            if (!preg_match('/^ae_stripe_payment_alert_([0-9]+)_(pi_[a-zA-Z0-9]+)$/', $property->key, $match)) continue;
            try {
                $invoice = Invoice::findOrFail($match[1]);
                if ($property->model_id != $invoice->user_id || $property->model_type !== $invoice->user->getMorphClass()) continue;
                $this->withPaymentLock($invoice, fn () => $this->schedulePaymentReviewAlert($invoice, $match[2]));
            } catch (\Throwable $e) { report($e); }
        }
    }

    public function resolveChargeAttempt($invoice, ?string $intentId, string $evidence = '', string $accountedEvidence = '', string $checkedConfirmKey = ''): void
    {
        $held = $this->withPaymentLock($invoice, fn ($user, $current) => $this->readPaymentAttempt($user, $current));
        if ($accountedEvidence !== '') {
            $intentId = $held['intent'] ?? null;
            if (!$intentId) throw new \App\Exceptions\DisplayException('Link the verified payment before accounting for its funds.');
        }
        $intent = $intentId ? $this->request('get', '/payment_intents/' . $intentId) : null;
        $missingId = null;
        if (!$intentId && !empty($held['intent']) && $evidence !== '') {
            try { $this->request('get', '/payment_intents/' . $held['intent']); }
            catch (\Illuminate\Http\Client\RequestException $e) {
                if ($e->response->status() !== 404) throw $e;
                $missingId = $held['intent'];
            }
        }
        $this->withPaymentLock($invoice, function ($user, $current) use ($intent, $evidence, $accountedEvidence, $checkedConfirmKey, $held, $missingId) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (($attempt['key'] ?? null) !== ($held['key'] ?? null) || ($attempt['intent'] ?? null) !== ($held['intent'] ?? null)) throw new \App\Exceptions\DisplayException('The held payment changed. Check it again.');
            if (($attempt['state'] ?? null) !== 'reconcile') throw new \App\Exceptions\DisplayException('This payment does not need staff review.');
            if ($accountedEvidence !== '') {
                if (($attempt['intent'] ?? null) !== $intent->id || !in_array($intent->status, ['succeeded', 'canceled'], true)
                    || strlen(trim($accountedEvidence)) < 12) throw new \App\Exceptions\DisplayException('Finish or cancel the Stripe payment and provide proof that its funds were refunded or credited.');
                $user->properties()->where('key', 'ae_stripe_checkout_' . $current->id)->delete();
            } elseif ($intent) {
                if (!empty($attempt['intent']) && $attempt['intent'] !== $intent->id) throw new \App\Exceptions\DisplayException('Account for the held payment before linking a different payment.');
                abort_unless(($intent->metadata->invoice_id ?? null) == $current->id
                    && ($intent->customer ?? null) === $user->properties()->where('key', 'stripe_id')->first()?->value, 409);
                $attempt = array_replace($attempt, ['intent' => $intent->id, 'state' => null, 'retry' => true]);
                $this->writePaymentAttempt($user, $current, $attempt);
            } else {
                if (!empty($attempt['confirmation']) && (empty($attempt['confirmation']['key'])
                    || !hash_equals($attempt['confirmation']['key'], $checkedConfirmKey))) {
                    throw new \App\Exceptions\DisplayException('A debit was sent. Restore access to its Stripe account, or check confirm key ' . ($attempt['confirmation']['key'] ?? '(missing)') . ' in that account’s request log before using --checked-confirm-key.');
                }
                if (!empty($attempt['funds_pending'])
                    || Audit::where('auditable_type', $current->getMorphClass())->where('auditable_id', $current->id)
                        ->where('event', 'ae_stripe_payment_review')->where('new_values->payment_intent_id', $attempt['intent'] ?? '')->lockForUpdate()->first()
                    || \App\Models\InvoiceTransaction::where('transaction_id', $attempt['intent'] ?? '')->where('status', 'processing')->exists()
                    || \App\Models\InvoiceTransaction::where('transaction_id', $attempt['intent'] ?? '')->where('status', 'succeeded')->exists()) {
                    throw new \App\Exceptions\DisplayException('This payment may still hold money. Restore access to its Stripe account and account for the funds first.');
                }
                if (isset($attempt['intent']) && $missingId !== $attempt['intent'] || strlen(trim($evidence)) < 12) throw new \App\Exceptions\DisplayException('Link the payment, or provide proof from the original Stripe account that no charge was made.');
                $user->properties()->where('key', 'ae_stripe_checkout_' . $current->id)->delete();
            }
            Audit::create(['event' => 'ae_stripe_attempt_resolved', 'auditable_type' => $current->getMorphClass(),
                'auditable_id' => $current->id, 'old_values' => [], 'new_values' => [
                    'attempt_key' => $attempt['key'], 'payment_intent_id' => $intent?->id ?? $missingId, 'evidence' => $accountedEvidence ?: $evidence,
                    'funds_accounted' => $accountedEvidence !== '', 'checked_confirm_key' => $checkedConfirmKey ?: null,
                ]]);
        });
    }

    private function paymentManuallyAccounted($invoice, string $intentId): bool
    {
        return (bool) Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->where('event', 'ae_stripe_attempt_resolved')->where('new_values->payment_intent_id', $intentId)
            ->where('new_values->funds_accounted', true)->lockForUpdate()->first();
    }

    private function bindWebhookAttempt($user, $invoice, $intent, ?string $requestKey = null): void
    {
        // Called under the settlement's user and invoice locks.
        $attempt = $this->readPaymentAttempt($user, $invoice);
        if (!$attempt || isset($attempt['intent']) || ($intent->customer ?? null) !== $user->properties()->where('key', 'stripe_id')->first()?->value) return;
        if (($intent->metadata->ae_stripe_attempt ?? null) !== $attempt['key'] && $requestKey !== $attempt['key']) return;
        $this->writePaymentAttempt($user, $invoice, array_replace($attempt, ['intent' => $intent->id, 'state' => null, 'retry' => true]));
    }
}
