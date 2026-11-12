<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

trait PaymentActions
{
    /** Claim an unconfirmed amount change or cancellation, send once per key, then record locally. */
    private function sendPaymentAction($invoice, $intent, string $kind)
    {
        abort_unless(DB::transactionLevel() === 0, 409);
        if (($intent->amount_received ?? 0) !== 0
            || !in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            $this->withPaymentLock($invoice, function ($user, $current) use ($intent) {
                $attempt = $this->readPaymentAttempt($user, $current);
                if (($attempt['action']['intent'] ?? null) !== $intent->id) return;
                unset($attempt['action']);
                $this->writePaymentAttempt($user, $current, $attempt);
            });
            return $intent;
        }
        if ($kind === 'amount' && $intent->status === 'requires_action') $kind = 'cancel';
        $claim = $this->withPaymentLock($invoice, function ($user, $current) use ($intent, $kind) {
            $attempt = $this->readPaymentAttempt($user, $current);
            if ($attempt && ($attempt['intent'] ?? null) !== $intent->id) $this->paymentPending('Payment changed. Refresh the invoice to check its status.');
            if ($kind !== 'cancel' && in_array($attempt['confirmation']['phase'] ?? null, ['reserved', 'sending'], true)
                && !$this->paymentAttemptFailed($attempt, $intent)) $this->paymentPending('We are checking this payment automatically. Refresh the invoice for its status.');
            if (!empty($attempt['action'])) {
                if ($attempt['action']['kind'] !== $kind) $this->paymentPending('Payment changed. Refresh the invoice to check its status.');
                // A fresh canonical read still proves this cancel/amount operation is safe. A Stripe 500 may be cached
                // under its old key, so reserve a new key for the same non-debit operation after that observation.
                if (!empty($attempt['action']['retry_key'])) {
                    $attempt['action']['key'] = 'action_' . bin2hex(random_bytes(16));
                    unset($attempt['action']['retry_key']);
                    $this->writePaymentAttempt($user, $current, $attempt);
                }
                return $attempt['action'];
            }
            $attempt ??= ['version' => 2, 'key' => 'retire_' . $intent->id, 'created' => time(), 'intent' => $intent->id];
            $action = ['kind' => $kind, 'key' => 'action_' . bin2hex(random_bytes(16)), 'intent' => $intent->id,
                'params' => $kind === 'amount' ? ['amount' => $this->paymentBalance($current)] : []];
            $this->writePaymentAttempt($user, $current, array_replace($attempt, ['action' => $action, 'retry' => true]));
            return $action;
        });
        $finish = function () use ($invoice, $claim) {
            $this->withPaymentLock($invoice, function ($user, $current) use ($claim) {
                $stored = $this->readPaymentAttempt($user, $current);
                if (($stored['action']['key'] ?? null) !== $claim['key']) return;
                unset($stored['action']);
                $this->writePaymentAttempt($user, $current, $stored);
            });
        };
        try {
            $answer = $this->request('post', '/payment_intents/' . $claim['intent'] . ($claim['kind'] === 'cancel' ? '/cancel' : ''), $claim['params'], $claim['key']);
        } catch (RequestException $e) {
            if ($this->definitePaymentRefusal($e)) $finish();
            elseif ($e->response->status() >= 500) $this->withPaymentLock($invoice, function ($user, $current) use ($claim) {
                $stored = $this->readPaymentAttempt($user, $current);
                if (($stored['action']['key'] ?? null) !== $claim['key']) return;
                $stored['action']['retry_key'] = true;
                $this->writePaymentAttempt($user, $current, $stored);
            });
            throw $e;
        }
        $finish();
        return $answer;
    }
}
