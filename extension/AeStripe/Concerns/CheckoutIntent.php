<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Models\{Audit, Invoice, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;

trait CheckoutIntent
{
    private function checkoutIntent($invoice, array $data)
    {
        $replace = null;
        // Replacement is allowed only after Stripe proves the old intent canceled.
        for ($round = 0; $round < 3; ++$round) {
            $attempt = $this->reservePaymentAttempt($invoice, $data, $replace);
            if (($attempt['state'] ?? null) === 'reconcile' && !isset($attempt['intent'])) $this->paymentReconcileMessage();
            if (!isset($attempt['intent']) && time() - $attempt['created'] >= 23 * 3600) {
                $this->storePaymentAttempt($invoice, $attempt, ['state' => 'reconcile']);
                $this->paymentReconcileMessage();
            }
            try {
                $intent = isset($attempt['intent'])
                    ? $this->request('get', '/payment_intents/' . $attempt['intent'])
                    : $this->createAttemptIntent($attempt);
            } catch (RequestException | ConnectionException $e) {
                throw new DisplayException('Payment status is unknown. Open Stripe checkout to check it, or contact support.');
            }
            if (empty($intent->id) || isset($attempt['intent']) && $intent->id !== $attempt['intent']) $this->paymentReconcileMessage();
            $this->storePaymentAttempt($invoice, $attempt, ['intent' => $intent->id]);
            $changed = in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
                && (isset($intent->amount) && $intent->amount !== $attempt['due_amount']
                    || isset($intent->currency) && strtolower($intent->currency) !== strtolower($attempt['due_currency']));
            // A canceled or partially funded intent is not proof that no money was received.
            if (($changed || $intent->status === 'canceled') && ($intent->amount_received ?? 0) > 0) {
                $this->paymentReconcileMessage('Stripe received a payment, so it cannot be replaced automatically.');
            }
            if ($changed) {
                // An old browser can still confirm an unfinished intent. Invalidate it before replacing.
                try {
                    $intent = $this->request('post', '/payment_intents/' . $intent->id . '/cancel', [], 'cancel_' . $attempt['key']);
                } catch (RequestException | ConnectionException $e) {
                    throw new DisplayException('Payment changed. Open Stripe checkout to check it before paying.');
                }
            }
            if (($intent->status ?? null) !== 'canceled') return $intent;
            $replace = $attempt['key'];
        }
        throw new DisplayException('Payment changed while loading. Open Stripe checkout again.');
    }

    private function createAttemptIntent(array $attempt)
    {
        try {
            return $this->request('post', '/payment_intents', $attempt['params'], $attempt['key']);
        } catch (RequestException $e) {
            // Off-session authentication commonly returns HTTP 402 with the same intent.
            $intent = $e->response->json('error.payment_intent');
            if ($e->response->status() !== 402 || !$intent) throw $e;
            return is_string($intent) ? $this->request('get', '/payment_intents/' . $intent)
                : $this->readStripeObject(json_decode(json_encode($intent, JSON_THROW_ON_ERROR)));
        }
    }

    private function reservePaymentAttempt($invoice, array $data, ?string $replace): array
    {
        // Keep the original property name: upgrades must share in-flight checkout attempts.
        return DB::transaction(function () use ($invoice, $data, $replace) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            $current = Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $user->id, 403);
            // Locking current reads avoid a stale caller balance or an older transaction snapshot.
            $paid = $current->transactions()->where('status', 'succeeded')->lockForUpdate()->get()->sum('amount');
            $amount = $this->toMinorUnits($current->total - $paid, $current->currency_code);
            if ($current->status !== 'pending' || $amount <= 0) {
                throw new DisplayException('This invoice has no payment due. Refresh the invoice.');
            }
            $data['amount'] = $amount;
            $data['currency'] = $current->currency_code;
            $property = 'ae_stripe_checkout_' . $invoice->id;
            $stored = $user->properties()->where('key', $property)->lockForUpdate()->first()?->value;
            $attempt = $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : null;
            if (!$attempt || ($replace !== null && $attempt['key'] === $replace)) {
                $attempt = ['key' => 'checkout_' . bin2hex(random_bytes(16)),
                    'digest' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
                    'created' => time(), 'params' => $data];
            } elseif (!isset($attempt['intent']) && time() - $attempt['created'] >= 23 * 3600) {
                $attempt['state'] = 'reconcile';
            } elseif (!isset($attempt['params']) && !isset($attempt['intent'])) {
                // Older releases stored a digest only. Both callers first try the original
                // checkout shape; converted sandbox keys must also retain their old metadata.
                $candidates = [$data, $this->checkoutPaymentData($current, $amount, $data['customer'])];
                foreach ($candidates as $params) {
                    $legacy = $params;
                    $legacy['metadata'] = [];
                    foreach ($params['metadata'] ?? [] as $key => $value) {
                        $legacy['metadata'][$key === 'ae_stripe_gateway' ? 'stripe_saved_gateway' : $key] = $value;
                    }
                    foreach ([$params, $legacy] as $candidate) {
                        if (($attempt['digest'] ?? null) === hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR))) {
                            $attempt['params'] = $candidate;
                            break 2;
                        }
                    }
                }
                if (!isset($attempt['params'])) $attempt['state'] = 'reconcile';
            }
            if (($attempt['state'] ?? null) === 'reconcile' && !isset($attempt['intent'])
                && !Audit::where('auditable_type', $current->getMorphClass())->where('auditable_id', $current->id)
                    ->where('event', 'ae_stripe_attempt_review')->where('new_values->attempt_key', $attempt['key'])->lockForUpdate()->first()) {
                Audit::create([
                    'event' => 'ae_stripe_attempt_review', 'auditable_type' => $current->getMorphClass(),
                    'auditable_id' => $current->id, 'old_values' => [], 'new_values' => [
                        'attempt_key' => $attempt['key'], 'created' => $attempt['created'], 'state' => 'reconcile',
                        'reason' => 'Stripe could not confirm whether this payment charged.',
                        'resolution' => 'Check Stripe before clearing this attempt; a search alone cannot prove it did not charge.',
                    ],
                ]);
            }
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
            return $attempt + ['due_amount' => $amount, 'due_currency' => $current->currency_code];
        });
    }

    private function storePaymentAttempt($invoice, array $attempt, array $values): void
    {
        DB::transaction(function () use ($invoice, $attempt, $values) {
            $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            $property = 'ae_stripe_checkout_' . $invoice->id;
            $stored = $user->properties()->where('key', $property)->lockForUpdate()->firstOrFail();
            $current = json_decode($stored->value, true, flags: JSON_THROW_ON_ERROR);
            if ($current['key'] !== $attempt['key']) return;
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode(array_replace($current, $values), JSON_THROW_ON_ERROR)]);
        });
    }

    private function paymentReconcileMessage(string $reason = 'Stripe could not confirm whether this payment charged.'): never
    {
        throw new DisplayException('Payment needs review. Contact support: ' . $reason);
    }
}
