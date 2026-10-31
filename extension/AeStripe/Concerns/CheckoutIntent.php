<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Models\{BillingAgreement, Invoice, InvoiceTransaction, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\AeStripe\PaymentPendingException;

trait CheckoutIntent
{
    private bool $paymentStripeUnavailable = false;
    private bool $paymentRetryReserved = false;
    private function checkoutIntent($invoice, array $data, bool $elements = false)
    {
        for ($round = 0; $round < 4; ++$round) {
            $attempt = $this->reservePaymentAttempt($invoice, $data, $elements);
            foreach (['renewal', 'automatic_renewal', 'customer_payment'] as $key) {
                if (array_key_exists($key, $attempt)) $data[$key] = $attempt[$key];
            }
            if (!empty($attempt['agreement_id'])) $data['agreement_id'] ??= $attempt['agreement_id'];
            $creating = false;
            try {
                if (isset($attempt['intent'])) $intent = $this->request('get', '/payment_intents/' . $attempt['intent']);
                elseif (($attempt['version'] ?? 1) === 2 || $this->legacyUnconfirmedAttempt($invoice, $attempt, array_replace($data, ['amount' => $attempt['due_amount']]))) {
                    // Creation never confirms. After key expiry an orphan cannot have charged.
                    if (time() - $attempt['created'] >= 23 * 3600) {
                        if (!empty($attempt['automatic_renewal'])) {
                            $this->withPaymentLock($invoice, function ($user, $current) use ($attempt) {
                                $stored = $this->readPaymentAttempt($user, $current);
                                if (($stored['key'] ?? null) !== $attempt['key'] || isset($stored['intent'])) return;
                                // Rotate the proven-unconfirmed creation atomically; a crash cannot discard its authority/count.
                                $next = $this->newPaymentAttempt($current, $stored['params'], $this->paymentBalance($current), !empty($stored['elements']));
                                $this->writePaymentAttempt($user, $current, array_replace($stored, $next));
                            });
                        } else $this->replacePaymentAttempt($invoice, $attempt);
                        continue;
                    }
                    $creating = true;
                    $intent = $this->request('post', '/payment_intents', $attempt['params'], $attempt['key']);
                } else {
                    $intent = $this->findLegacyPayment($invoice, $attempt);
                    if (!$intent) $this->holdPaymentAttempt($invoice, $attempt, 'Stripe has no payment ID for this older attempt. Check its request log before releasing it.');
                }
            } catch (RequestException | ConnectionException $e) {
                if ($e instanceof RequestException && $this->definitePaymentRefusal($e) && $creating && !in_array($e->response->status(), [401, 403], true)) {
                    $this->storePaymentAttempt($invoice, $attempt, [
                        'notice_only' => true, 'retry' => true, 'failure_reason' => $this->paymentRefusalReason($e, $invoice),
                        'agreement_id' => null, 'confirmation' => null,
                    ]);
                    throw new DisplayException($this->paymentRefusalReason($e, $invoice));
                }
                if ($e instanceof RequestException && $this->definitePaymentRefusal($e) && $e->response->status() !== 404) {
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                    $this->paymentStripeUnavailable = true;
                    if (($attempt['checks'] ?? 0) >= 2) $this->notifyPaymentAccessRefusal();
                    $this->paymentPending($this->paymentRefusalReason($e, $invoice));
                }
                $this->paymentStripeUnavailable = true;
                if ($e instanceof RequestException && $e->response->status() === 404 && isset($attempt['intent'])) {
                    $this->paymentStripeUnavailable = false;
                    $this->holdPaymentAttempt($invoice, $attempt, $this->missingPaymentReason($attempt));
                }
                $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                $this->paymentPending('Stripe is unavailable. We will check this payment automatically.');
            }
            if (empty($intent->id) || isset($attempt['intent']) && $intent->id !== $attempt['intent']) {
                $this->holdPaymentAttempt($invoice, $attempt, 'Stripe returned a different payment ID. Link the verified payment before continuing.');
            }
            // A concurrent page load may have retired this unconfirmed native intent for Elements.
            if (!$this->rememberPaymentIntent($invoice, $attempt, $intent)) continue;
            if ($intent->status === 'succeeded') {
                $recorded = InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists();
                if (!$recorded) return $intent;
                // A completed charge is not a new payment of a later balance.
                $this->replacePaymentAttempt($invoice, $attempt);
                continue;
            }
            if (in_array($intent->status, ['processing', 'requires_capture'], true)) {
                $this->storePaymentAttempt($invoice, $attempt, ['retry' => true, 'agreement_id' => null, 'confirmation' => null]);
                return $intent;
            }
            if (($intent->amount_received ?? 0) > 0) {
                $this->holdPaymentAttempt($invoice, $attempt, 'Stripe received part of this payment. Check the funds before replacing it.');
            }
            if (in_array($intent->status, ['requires_payment_method', 'canceled'], true)) {
                foreach ($invoice->transactions()->where('status', 'processing')->get() as $transaction) {
                    if ($transaction->transaction_id === $intent->id) { $this->recordIntentPayment($intent, 'failed'); break; }
                }
            }
            if ($intent->status === 'canceled') { $this->replacePaymentAttempt($invoice, $attempt); continue; }
            if ($this->paymentAttemptFailed($attempt, $intent)) {
                if (!$elements) return $intent; // Recovery must report the refusal, never replace its debit instruction.
                unset($data['agreement_id']);
            }
            $usesElements = $this->isElementsAttempt($attempt, $intent);
            $legacyAutomatic = ($intent->confirmation_method ?? 'automatic') !== 'manual' && !$usesElements;
            $changeFlow = $usesElements && !$elements && isset($data['agreement_id'])
                || $elements && !$usesElements && $intent->status === 'requires_payment_method';
            $failedElements = $usesElements && $intent->status === 'requires_payment_method' && !empty($intent->last_payment_error);
            if ($legacyAutomatic || $changeFlow || $failedElements
                || strtolower($intent->currency) !== strtolower($invoice->currency_code)
                || $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) {
                // Retire legacy tabs, failed Elements authentication and changes between native Pay and Elements.
                try {
                    $this->withPaymentLock($invoice, function ($user, $current) use ($intent) {
                        $fresh = $this->request('get', '/payment_intents/' . $intent->id);
                        if (($fresh->amount_received ?? 0) === 0 && in_array($fresh->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                            $this->request('post', '/payment_intents/' . $fresh->id . '/cancel');
                        }
                    });
                }
                catch (RequestException | ConnectionException $e) {
                    $this->paymentStripeUnavailable = true;
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                    $this->paymentPending('We are checking the earlier payment before replacing it.');
                }
                // Read Stripe again: only a proven cancellation permits replacement.
                continue;
            }
            try {
                if (isset($intent->amount) && $intent->amount !== $attempt['due_amount']) {
                    $intent = $this->withPaymentLock($invoice, function ($user, $current) use ($intent) {
                        $fresh = $this->request('get', '/payment_intents/' . $intent->id);
                        if (in_array($fresh->status, ['requires_payment_method', 'requires_confirmation'], true) && ($fresh->amount_received ?? 0) === 0) {
                            return $this->request('post', '/payment_intents/' . $fresh->id, ['amount' => $this->paymentBalance($current)]);
                        }
                        if ($fresh->status === 'requires_action' && ($fresh->amount_received ?? 0) === 0) {
                            return $this->request('post', '/payment_intents/' . $fresh->id . '/cancel');
                        }
                        return $fresh;
                    });
                    if ($intent->status === 'canceled') continue;
                }
            } catch (RequestException | ConnectionException $e) {
                if ($e instanceof RequestException && $this->definitePaymentRefusal($e)) {
                    $this->storePaymentAttempt($invoice, $attempt, ['retry' => false, 'agreement_id' => null, 'confirmation' => null]);
                    throw new DisplayException($this->paymentRefusalReason($e, $invoice));
                }
                $this->storePaymentAttempt($invoice, $attempt, ['retry' => true]);
                $this->paymentStripeUnavailable = true;
                $this->paymentPending('Stripe is unavailable. We will check this payment automatically.');
            }
            return $intent;
        }
        $this->paymentPending('Payment changed while loading. Refresh the invoice.');
    }

    private function reservePaymentAttempt($invoice, array $data, bool $elements = false): array
    {
        $this->paymentRetryReserved = false;
        $attempt = $this->withPaymentLock($invoice, function ($user, $current) use ($data, $elements) {
            $amount = $this->paymentBalance($current);
            if ($current->status !== 'pending' || $amount <= 0) throw new DisplayException('This invoice has no payment due. Refresh the invoice.');
            $attempt = $this->readPaymentAttempt($user, $current);
            $customerPay = $elements || array_key_exists('renewal_start', $data) && !$data['renewal_start'];
            $context = array_intersect_key($attempt ?? $data, array_flip(['renewal', 'automatic_renewal', 'customer_payment']));
            if (!empty($attempt['renewal_only'])) {
                if (!$customerPay) throw new DisplayException('This renewal is waiting for its next automatic retry. Open the invoice to pay now.');
                $attempt = null;
            }
            if (!empty($attempt['notice_only'])) $attempt = null; // A new Pay starts fresh; this marker can only notify.
            if (!$attempt) {
                // Earlier bank debits have a transaction ID but no attempt property.
                $processing = $current->transactions()->where('status', 'processing')->lockForUpdate()->get();
                foreach ($processing as $transaction) {
                    if (($transaction->gateway->extension ?? null) === 'AeStripe') {
                        $attempt = ['key' => 'adopt_' . $transaction->transaction_id, 'created' => time(), 'intent' => $transaction->transaction_id];
                        break;
                    }
                }
            }
            // R3 could reserve an invalid pair, but its creation never confirmed.
            if (($attempt['version'] ?? null) === 2 && empty($attempt['intent'])
                && empty($attempt['confirmation']) && empty($attempt['params']['confirm'])
                && isset($attempt['params']['automatic_payment_methods'], $attempt['params']['confirmation_method'])) {
                if (!isset($data['agreement_id']) && !empty($attempt['agreement_id'])) $data['agreement_id'] = $attempt['agreement_id'];
                $attempt = null;
            }
            if (!$attempt) {
                $attempt = $this->newPaymentAttempt($current, array_replace($data, [
                    'customer' => $user->properties()->where('key', 'stripe_id')->firstOrFail()->value,
                ]), $amount, $elements);
                $attempt = array_replace($attempt, $context);
                if (!empty($data['renewal_start']) && empty($attempt['customer_payment'])
                    && !isset($attempt['automatic_renewal']) && $this->nativeRenewalServices($current)) {
                    $attempt['automatic_renewal'] = true;
                    $renewal = $this->nativeRenewal($current, (int) $data['agreement_id']);
                    if ($renewal) $attempt['renewal'] = $renewal;
                }
            }
            if ($customerPay) {
                unset($attempt['renewal'], $attempt['automatic_renewal']);
                $attempt['customer_payment'] = true; // Only an explicit customer takeover removes automatic authority.
            } elseif (!empty($data['renewal_start']) && empty($attempt['customer_payment']) && $this->nativeRenewalServices($current)) {
                $attempt['automatic_renewal'] = true;
            }
            if (isset($data['agreement_id']) && (empty($attempt['automatic_renewal'])
                || ($attempt['renewal']['state'] ?? null) === 'active' && empty($attempt['confirmation']))) {
                $attempt['agreement_id'] = $data['agreement_id'];
                $attempt['agreement_pending'] = true;
                $attempt['retry'] = true;
            }
            $this->writePaymentAttempt($user, $current, $attempt);
            return $attempt + ['due_amount' => $amount];
        });
        $this->paymentRetryReserved = !empty($attempt['retry']);
        return $attempt;
    }

    private function rememberPaymentIntent($invoice, array $attempt, $intent): bool
    {
        $values = [
            'intent' => $intent->id,
            'latest_charge' => $intent->latest_charge ?? null,
            'funds_pending' => ($intent->amount_received ?? 0) > 0 || in_array($intent->status, ['succeeded', 'processing', 'requires_capture'], true),
            'retry' => !empty($attempt['confirmation']) || !empty($attempt['agreement_id']) || in_array($intent->status, ['succeeded', 'processing', 'requires_capture'], true),
        ];
        // An agreement is an interrupted instruction, never permission to repeat a debit.
        if (in_array($intent->status, ['succeeded', 'processing', 'requires_capture', 'requires_action'], true)) $values['agreement_id'] = null;
        if ($this->paymentAttemptFailed($attempt, $intent)) {
            $values += ['agreement_id' => null, 'confirmation' => null];
            $values['retry'] = false;
        }
        return $this->storePaymentAttempt($invoice, $attempt, $values);
    }

    private function paymentAttemptFailed(array $attempt, $intent): bool
    {
        if ($intent->status !== 'requires_payment_method') return false;
        // A new explicit Pay is still unsent; an earlier decline cannot cancel that instruction.
        if (empty($attempt['confirmation']) && !empty($attempt['agreement_id']) && !empty($attempt['agreement_pending'])) return false;
        if (!empty($attempt['confirmation']) && array_key_exists('previous_charge', $attempt['confirmation'])) {
            // A previous failed row/error says nothing about this later unknown confirm.
            if (empty($intent->latest_charge) || $intent->latest_charge === $attempt['confirmation']['previous_charge']) return false;
        }
        // Older confirmations have no baseline: honor a known failure rather than risk repeating a failed debit.
        return !empty($intent->last_payment_error)
            || InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'failed')->exists();
    }

    private function newPaymentAttempt($invoice, array $data, int $amount, bool $elements = false): array
    {
        $params = $this->checkoutPaymentData($invoice, $amount, $data['customer']);
        // Stripe rejects automatic_payment_methods together with confirmation_method.
        // Keep checkoutPaymentData unchanged for legacy digest reconstruction.
        if (!$elements) {
            unset($params['automatic_payment_methods']);
            $params['confirmation_method'] = 'manual';
        }
        $key = 'checkout_' . bin2hex(random_bytes(16));
        $params['metadata']['ae_stripe_attempt'] = $key;
        return ['version' => 2, 'key' => $key, 'created' => time(), 'params' => $params, 'elements' => $elements];
    }

    private function isElementsAttempt(array $attempt, $intent): bool
    {
        return !empty($attempt['elements']) && ($attempt['intent'] ?? $intent->id) === $intent->id
            && ($intent->confirmation_method ?? null) === 'automatic';
    }

    private function retireFailedElementsPayment($invoice, $intent)
    {
        if (($intent->status ?? null) !== 'requires_payment_method' || empty($intent->last_payment_error)) return $intent;
        return $this->withPaymentLock($invoice, function ($user, $current) use ($intent) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (!$this->isElementsAttempt($attempt ?? [], $intent)) return $intent;
            $fresh = $this->request('get', '/payment_intents/' . $intent->id);
            if ($fresh->status === 'requires_payment_method' && !empty($fresh->last_payment_error) && ($fresh->amount_received ?? 0) === 0) {
                return $this->request('post', '/payment_intents/' . $fresh->id . '/cancel');
            }
            return $fresh;
        });
    }

    private function withPaymentLock($invoice, callable $callback)
    {
        return DB::transaction(function () use ($invoice, $callback) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            $current = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $user->id, 403);
            return $callback($user, $current);
        });
    }

    private function paymentBalance($invoice): int
    {
        $paid = $invoice->transactions()->where('status', 'succeeded')->lockForUpdate()->get()->sum('amount');
        return $this->toMinorUnits($invoice->total - $paid, $invoice->currency_code);
    }

    private function readPaymentAttempt($user, $invoice): ?array
    {
        $stored = $user->properties()->where('key', 'ae_stripe_checkout_' . $invoice->id)->lockForUpdate()->first()?->value;
        return $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
    }

    private function writePaymentAttempt($user, $invoice, array $attempt): void
    {
        if (empty($attempt['agreement_id'])) unset($attempt['agreement_pending']);
        $user->properties()->updateOrCreate(['key' => 'ae_stripe_checkout_' . $invoice->id], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
    }

    private function replacePaymentAttempt($invoice, array $attempt, bool $keepWaitingRenewal = false): void
    {
        $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $keepWaitingRenewal) {
            $stored = $this->readPaymentAttempt($user, $current);
            if (($stored['key'] ?? null) === $attempt['key']) {
                if ($keepWaitingRenewal && ($stored['renewal']['state'] ?? null) === 'waiting'
                    && $current->status === 'pending' && $this->paymentBalance($current) > 0) {
                    $schedule = array_intersect_key($stored, array_flip(['version', 'key', 'created', 'renewal',
                        'automatic_renewal', 'failure_reason', 'failure_notified']));
                    $this->writePaymentAttempt($user, $current, $schedule + ['renewal_only' => true, 'retry' => false]);
                    return;
                }
                $user->properties()->where('key', 'ae_stripe_checkout_' . $current->id)->delete();
            }
        });
    }

    private function storePaymentAttempt($invoice, array $attempt, array $values, bool $confirmationOnly = false): bool
    {
        return $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $values, $confirmationOnly) {
            $stored = $this->readPaymentAttempt($user, $current);
            if ($confirmationOnly && ($stored['confirmation']['key'] ?? null) !== ($attempt['confirmation']['key'] ?? null)) return false;
            if (($stored['key'] ?? null) !== $attempt['key']) return false;
            $this->writePaymentAttempt($user, $current, array_replace($stored, $values));
            return true;
        });
    }

    private function legacyUnconfirmedAttempt($invoice, array &$attempt, array $data): bool
    {
        if (isset($attempt['params'])) return empty($attempt['params']['confirm']);
        $params = $this->checkoutPaymentData($invoice, $data['amount'], $data['customer']);
        $legacy = $params;
        $legacy['metadata'] = ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1'];
        foreach ([$params, $legacy] as $candidate) {
            if (($attempt['digest'] ?? null) === hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR))) {
                $attempt['params'] = $candidate;
                $this->storePaymentAttempt($invoice, $attempt, ['params' => $candidate]);
                return true;
            }
        }
        return false;
    }

    private function confirmAttempt($invoice, string $intentId, array $params, ?BillingAgreement $agreement = null, ?int $expectedAmount = null)
    {
        // Persist the confirmation identity before HTTP. The second transaction keeps credits,
        // card removal and other callers out until Stripe responds (or the request times out).
        $attempt = $this->withPaymentLock($invoice, function ($user, $current) use ($intentId, $params, $agreement, $expectedAmount) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if (($attempt['intent'] ?? null) !== $intentId) $this->paymentPending('Payment changed. Refresh the invoice to check its status.');
            if (!empty($attempt['automatic_renewal'])) {
                if (($attempt['renewal']['state'] ?? null) !== 'active') throw new DisplayException('Auto Pay stopped for this renewal. Open the invoice to pay the amount due.');
                if (!empty($attempt['confirmation'])) return $attempt; // Resume the claimed instruction, never replace its identity.
            }
            $attempt['confirmation'] = ['key' => 'confirm_' . bin2hex(random_bytes(16)), 'params' => $params, 'agreement' => $agreement?->id, 'amount' => $expectedAmount, 'previous_charge' => $attempt['latest_charge'] ?? null];
            unset($attempt['agreement_pending']); // The confirmation now owns this instruction.
            $attempt['retry'] = true;
            $attempt['failure_reason'] = null;
            $attempt['failure_notified'] = false;
            if ($expectedAmount !== null) $attempt['checkout_amount'] = $expectedAmount;
            if (isset($params['confirmation_token'])) $attempt['agreement_id'] = null;
            $this->writePaymentAttempt($user, $current, $attempt);
            return $attempt;
        });
        if (!empty($attempt['automatic_renewal'])) {
            $params = $attempt['confirmation']['params'];
            $expectedAmount = $attempt['confirmation']['amount'];
        }
        $writeStarted = false;
        $result = $this->withPaymentLock($invoice, function ($user, $current) use ($attempt, $intentId, $params, $agreement, $expectedAmount, &$writeStarted) {
            $stored = $this->readPaymentAttempt($user, $current);
            if (($stored['confirmation']['key'] ?? null) !== $attempt['confirmation']['key']) return null;
            try {
                $intent = $this->request('get', '/payment_intents/' . $intentId);
                if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) return $intent;
                if (!empty($stored['automatic_renewal']) && (empty($stored['renewal'])
                    || $stored['renewal']['state'] !== 'active' || !$this->renewalStillAuthorized($current, $stored['renewal']))) {
                    return new DisplayException('Auto Pay stopped for this renewal. Open the invoice to pay the amount due.');
                }
                $amount = $this->paymentBalance($current);
                if ($current->status !== 'pending' || $amount <= 0) return new DisplayException('This invoice has no payment due. Refresh the invoice.');
                if (strtolower($intent->currency) !== strtolower($current->currency_code) || $expectedAmount !== null && $amount !== $expectedAmount) {
                    return new DisplayException('The amount due changed. Refresh the invoice before paying.');
                }
                if ($agreement) {
                    if (($stored['agreement_id'] ?? $agreement->id) != $agreement->id) return new DisplayException('The payment method changed. Choose your payment method again.');
                    $active = BillingAgreement::where('id', $agreement->id)->lockForUpdate()->first();
                    if (!$active || $active->user_id != $user->id || ($active->gateway->extension ?? null) !== 'AeStripe'
                        || $active->external_reference !== $params['payment_method']) {
                        return new DisplayException('This payment method was removed. Choose another payment method.');
                    }
                    $method = $this->request('get', '/payment_methods/' . $active->external_reference);
                    if (($method->customer ?? null) !== $intent->customer) return new DisplayException('This payment method is no longer saved. Choose another payment method.');
                }
                if ($intent->customer !== $user->properties()->where('key', 'stripe_id')->first()?->value) return new DisplayException('The payment account changed. Refresh the invoice before paying.');
                if (($intent->confirmation_method ?? null) !== 'manual' && !$this->isElementsAttempt($stored, $intent)) return new DisplayException('The payment form changed. Refresh the invoice before paying.');
                if (isset($params['confirmation_token']) && !$this->isElementsAttempt($stored, $intent)) return new DisplayException('The payment form changed. Refresh the invoice before paying.');
                $updates = ['amount' => $amount];
                foreach (['metadata', 'setup_future_usage'] as $field) {
                    if (array_key_exists($field, $params)) { $updates[$field] = $params[$field]; unset($params[$field]); }
                }
                $writeStarted = true;
                $this->request('post', '/payment_intents/' . $intentId, $updates);
                $result = $this->request('post', '/payment_intents/' . $intentId . '/confirm', $params, $attempt['confirmation']['key']);
                if (!empty($stored['automatic_renewal']) && $result->status === 'requires_payment_method'
                    && ($result->amount_received ?? 0) === 0 && !empty($result->last_payment_error)) $this->recordIntentPayment($result, 'failed');
                return $result;
            } catch (RequestException | ConnectionException $e) {
                if ($e instanceof RequestException && $writeStarted && $this->definitePaymentRefusal($e)) {
                    // Even a 400 may follow a debit. Prove refusal and commit its transition before releasing these locks.
                    try {
                        $fresh = $this->request('get', '/payment_intents/' . $intentId);
                        if (($fresh->amount_received ?? 0) === 0 && in_array($fresh->status, ['requires_payment_method', 'requires_confirmation', 'canceled'], true)) {
                            $this->writePaymentAttempt($user, $current, array_replace($stored, ['retry' => false, 'confirmation' => null, 'agreement_id' => null]));
                            if ($fresh->status === 'requires_payment_method' && ($e->response->status() === 402 || !empty($fresh->last_payment_error))) $this->recordIntentPayment($fresh, 'failed');
                            return new DisplayException($this->paymentRefusalReason($e, $current, $fresh->last_payment_error->code ?? null));
                        }
                        return $fresh;
                    } catch (RequestException | ConnectionException $readError) { return $readError; }
                }
                return $e;
            }
        });
        if ($result === null || $result instanceof RequestException || $result instanceof ConnectionException) {
            $this->paymentStripeUnavailable = true;
            $this->paymentPending('We are checking this payment automatically. Refresh the invoice for its status.');
        }
        if (!($result instanceof DisplayException) && ($result->amount_received ?? 0) > 0
            && !in_array($result->status, ['succeeded', 'processing', 'requires_capture'], true)) {
            $this->storePaymentAttempt($invoice, $attempt, ['funds_pending' => true, 'agreement_id' => null, 'confirmation' => null], confirmationOnly: true);
            $this->holdPaymentAttempt($invoice, $attempt, 'Stripe received part of this payment. Check the funds before replacing it.');
        }
        $resume = null;
        if (!($result instanceof DisplayException) && $result->status === 'requires_action') {
            // Bank verification may finish days later, without a browser return. The consumed
            // token must not be replayed; Stripe already holds the authenticated payment method.
            $resumeParams = ['return_url' => route('extensions.gateways.ae-stripe.payment-return', ['invoice' => $invoice->id]), 'use_stripe_sdk' => 'true'];
            if ($agreement) $resumeParams['payment_method'] = $agreement->external_reference;
            $resume = ['key' => $attempt['confirmation']['key'], 'params' => $resumeParams, 'agreement' => $agreement?->id, 'amount' => $expectedAmount ?? $result->amount];
        }
        $this->storePaymentAttempt($invoice, $attempt, ['retry' => !($result instanceof DisplayException)
            && in_array($result->status, ['succeeded', 'processing', 'requires_capture', 'requires_action'], true), 'confirmation' => $resume, 'agreement_id' => null, 'funds_pending' => !($result instanceof DisplayException)
                && (($result->amount_received ?? 0) > 0 || in_array($result->status, ['succeeded', 'processing', 'requires_capture'], true))], confirmationOnly: true);
        if ($result instanceof DisplayException) throw $result;
        if ($result->status === 'requires_payment_method' && !empty($result->last_payment_error)) {
            $this->recordIntentPayment($result, 'failed');
            throw new DisplayException($this->paymentFailureReason($result->last_payment_error->code ?? null));
        }
        return $result;
    }

    private function definitePaymentRefusal(RequestException $e): bool
    {
        return $e->response->status() >= 400 && $e->response->status() < 500 && !in_array($e->response->status(), [409, 429], true);
    }

    private function paymentRefusalReason(RequestException $e, $invoice, ?string $fallbackCode = null): string
    {
        if (str_contains($e->response->json('error.message') ?? '', 'The currency provided')) return "This card can't be charged in " . $invoice->currency_code . '. Use another payment method.';
        if (in_array($e->response->status(), [401, 403], true)) return 'Payments are paused. Please check back later or contact support.';
        return $this->paymentFailureReason($e->response->json('error.code') ?? $fallbackCode);
    }

    private function paymentFailureReason(?string $code): string
    {
        return match ($code) {
            'amount_too_small' => "This balance is below Stripe's minimum. Use account credit or contact support to pay it.",
            'card_declined' => 'This card was declined. Choose another payment method.',
            'expired_card' => 'This card has expired. Choose another payment method.',
            'resource_missing' => 'The saved payment details are no longer available. Choose another payment method.',
            default => 'Stripe refused this payment. Choose another payment method or contact support.',
        };
    }

    private function paymentPending(string $message = 'Payment pending. Refresh the invoice to check its status.'): never
    {
        throw new PaymentPendingException($message);
    }

    private function paymentReconcileMessage(): never
    {
        $this->paymentPending('Payment needs review. Contact support to check this payment.');
    }
}
