<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, User};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{Cache, DB, Log};

trait SavedMethods
{
    private function savedMethodIdentity($method): ?string
    {
        $linkEmail = strtolower(trim((string) ($method->link->email ?? '')));
        $identity = match ($method->type ?? null) {
            'card' => empty($method->card->fingerprint) ? null : $method->card->fingerprint,
            // Only Stripe's Link account email identifies the account. Billing contact email does not.
            'link' => filter_var($linkEmail, FILTER_VALIDATE_EMAIL) ? $linkEmail : null,
            default => null,
        };
        return $identity === null ? null : hash('sha256', $method->type . ':' . $identity);
    }

    /** Read-only identities for retained agreements, scoped to this customer and gateway. */
    public function savedMethodIdentities($user, iterable $agreements): array
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        $identities = [];
        if (!$customer) return $identities;
        foreach ($agreements as $agreement) {
            if ($agreement->user_id != $user->id || $agreement->gateway_id != $gateway->id || $agreement->trashed()) continue;
            try {
                $method = $this->request('get', '/payment_methods/' . $agreement->external_reference);
                if (($method->id ?? null) === $agreement->external_reference && ($method->customer ?? null) === $customer && ($identity = $this->savedMethodIdentity($method))) {
                    $identities[$agreement->id] = $identity;
                }
            } catch (ConnectionException | RequestException $e) {
                // A provider outage must not hide methods or prevent turning Auto Pay off.
                Log::warning('ae_stripe_method_identity_read_failed', ['agreement_id' => $agreement->id, 'error' => get_class($e)]);
            }
        }
        return $identities;
    }

    private function persistAgreement($user, $method, array $consent = [])
    {
        if (empty($method->id)) return false;
        // Serialize provider updates without holding a database owner lock across HTTP. Contention is retryable.
        $lock = Cache::lock('ae-stripe-save-method-' . $user->id, 600);
        if (!$lock->get()) throw new \RuntimeException('Another saved payment method update is in progress. Try again.');
        try {
            return $this->persistSavedMethod($user, $method, $consent);
        } finally {
            $lock->release();
        }
    }

    private function persistSavedMethod($user, $method, array $consent)
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $incomingId = $method->id;
        $source = $consent['source'] ?? $incomingId;
        $key = 'ae_stripe_saved_' . hash('sha256', $source . ':' . $incomingId);
        // Current reads include saves committed after an earlier REPEATABLE READ snapshot.
        [$customer, $rows, $receipt] = DB::transaction(function () use ($user, $gateway, $key) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            return [$user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value,
                BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get(),
                json_decode($user->properties()->where('key', $key)->lockForUpdate()->first()?->value ?? 'null', true)];
        });
        if (!$customer) return false;
        $consent = DB::transaction(function () use ($user, $key, $consent, $incomingId, &$receipt) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            if (!$receipt) {
                $sequenceKey = 'ae_stripe_save_sequence';
                $sequence = 1 + (int) ($user->properties()->where('key', $sequenceKey)->lockForUpdate()->first()?->value ?? 0);
                $user->properties()->updateOrCreate(['key' => $sequenceKey], ['value' => (string) $sequence]);
                $receipt = ['incoming' => $incomingId, 'consent' => $consent + ['accepted_at' => time(), 'user_id' => $user->id, 'sequence' => $sequence]];
                $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
            }
            if (($receipt['incoming'] ?? null) !== $incomingId) throw new DisplayException('This saved payment method changed. Add it again.');
            return $receipt['consent'];
        });
        $method = $this->request('get', '/payment_methods/' . $incomingId);
        if (($method->customer ?? null) === null) {
            // An unfinished return from the previous release may refer to a duplicate it already detached.
            $legacy = json_decode($user->properties()->where('key', 'ae_stripe_duplicate_' . $incomingId)->first()?->value ?? 'null');
            if (($legacy->customer ?? null) === $customer && ($legacy->gateway ?? null) == $gateway->id) {
                $retained = null;
                foreach ($rows as $row) if ($row->external_reference === ($legacy->kept ?? null)) $retained = $row;
                if (!$retained) throw new DisplayException('This payment method was removed. Add a new payment method.');
                $remote = $this->request('get', '/payment_methods/' . $retained->external_reference);
                if (($remote->customer ?? null) === $customer && ($identity = $this->savedMethodIdentity($method)) !== null
                    && $this->savedMethodIdentity($remote) === $identity) {
                    return ['message' => 'This card is already saved. We updated its expiry to '
                        . substr($retained->expiry, 5, 2) . '/' . substr($retained->expiry, 0, 4) . '.'];
                }
            }
        }
        if (!$this->methodCustomerMatches($user, $method)) return false;
        $identity = $this->savedMethodIdentity($method);
        $remotes = [$incomingId => $method];
        $matches = [];
        foreach ($rows as $row) {
            if (($method->type ?? null) === 'link' && $row->type !== 'link') continue;
            if (($method->type ?? null) === 'card' && !in_array($row->type, ['card', 'visa', 'mastercard', 'amex', 'diners', 'discover', 'jcb', 'unionpay', 'unknown'], true)) continue;
            $known = json_decode($user->properties()->where('key', 'ae_stripe_method_' . $row->external_reference)->first()?->value ?? 'null', true);
            if ($identity !== null && ($known['customer'] ?? null) === $customer && ($known['gateway_id'] ?? null) == $gateway->id
                && ($known['identity'] ?? null) !== null && $known['identity'] !== $identity) continue;
            if ($row->external_reference !== $incomingId && $identity !== null) {
                try {
                    $remotes[$row->external_reference] = $this->request('get', '/payment_methods/' . $row->external_reference);
                } catch (RequestException $e) {
                    if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') throw $e;
                    Log::warning('ae_stripe_duplicate_candidate_missing', ['error' => get_class($e)]);
                    continue;
                }
            }
            $remote = $remotes[$row->external_reference] ?? null;
            if ($remote && ($remote->id ?? null) === $row->external_reference && ($remote->customer ?? null) === $customer
                && ($row->external_reference === $incomingId || ($identity !== null && $this->savedMethodIdentity($remote) === $identity))) $matches[] = $row;
        }
        usort($matches, fn ($a, $b) => strcmp((string) $b->created_at, (string) $a->created_at) ?: $b->id <=> $a->id);
        $kept = $matches[0] ?? null;
        // Validate globally unique references before any provider writes, including removed or foreign rows.
        $validate = function () use ($user, $gateway, $incomingId) {
            $row = BillingAgreement::withTrashed()->where('external_reference', $incomingId)->lockForUpdate()->first();
            if ($row && ($row->user_id != $user->id || $row->gateway_id != $gateway->id)) {
                throw new DisplayException('This payment method can\'t be used on this account. Add a new payment method.');
            }
            if ($row?->trashed()) throw new DisplayException('This payment method was removed. Add a new payment method.');
        };
        DB::transaction(function () use ($user, $customer, $validate) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value === $customer, 409);
            $validate();
        });
        if (isset($receipt['reference'])) {
            // Retries never roll a later expiry or acceptance back to this source's older values.
            $active = DB::transaction(function () use ($user, $gateway, $receipt) {
                User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                return BillingAgreement::where('external_reference', $receipt['reference'])->where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->first();
            });
            if (!$active) throw new DisplayException('This payment method was removed. Add a new payment method.');
            return $receipt['message'] ? ['message' => $receipt['message']] : true;
        }
        $data = $this->getPaymentDetails($method);
        $duplicate = $kept !== null;
        $reference = $kept?->external_reference ?? $incomingId;
        $latest = json_decode($user->properties()->where('key', 'ae_stripe_method_' . $reference)->first()?->value ?? 'null', true);
        $older = ($latest['customer'] ?? null) === $customer && ($latest['gateway_id'] ?? null) == $gateway->id
            && [($latest['consent']['accepted_at'] ?? 0), ($latest['consent']['sequence'] ?? 0)] > [$consent['accepted_at'], $consent['sequence']];
        if ($older && $kept) $data = ['name' => $kept->name, 'type' => $kept->type, 'expiry' => $kept->expiry];
        $update = ['allow_redisplay' => 'always'];
        if (!$older && $reference !== $incomingId && ($method->type ?? null) === 'card') {
            $update['card'] = ['exp_month' => $method->card->exp_month, 'exp_year' => $method->card->exp_year];
        }
        // The incoming method remains attached: Stripe intents and subscriptions may still refer to it.
        $this->request('post', '/payment_methods/' . $reference, $update);
        $message = $duplicate ? (($method->type ?? null) === 'card'
            ? 'This card is already saved. We updated its expiry to ' . substr($data['expiry'], 5, 2) . '/' . substr($data['expiry'], 0, 4) . '.'
            : 'This Link account is already saved. We updated it.') : null;
        return DB::transaction(function () use ($user, $gateway, $customer, $incomingId, $reference, $kept, $rows, $data, $identity, $consent, $key, $message, $validate, $older, $receipt) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value === $customer, 409);
            $validate();
            $current = BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get();
            $references = static function ($items) {
                $result = [];
                foreach ($items as $row) $result[$row->id] = $row->external_reference;
                ksort($result);
                return $result;
            };
            if ($references($current) !== $references($rows)) throw new \RuntimeException('Saved payment methods changed. Try again.');
            $agreement = $kept;
            if (!$agreement) {
                try {
                    $agreement = ExtensionHelper::makeBillingAgreement($user, 'AeStripe', $data['name'], $incomingId, $data['type'], $data['expiry']);
                } catch (UniqueConstraintViolationException $e) {
                    $agreement = BillingAgreement::withTrashed()->where('external_reference', $incomingId)->lockForUpdate()->first();
                    if (!$agreement) throw $e;
                    $validate();
                }
            }
            if (!$older) $agreement->update($data);
            if (!$older) $user->properties()->updateOrCreate(['key' => 'ae_stripe_method_' . $reference], ['value' => json_encode([
                'customer' => $customer, 'gateway_id' => $gateway->id, 'identity' => $identity,
                'consent' => $consent + ['accepted_at' => time(), 'user_id' => $user->id],
            ], JSON_THROW_ON_ERROR)]);
            $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($receipt + ['reference' => $reference, 'message' => $message], JSON_THROW_ON_ERROR)]);
            return $message ? ['message' => $message] : true;
        });
    }
}
