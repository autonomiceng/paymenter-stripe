<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Models\{BillingAgreement, Gateway, User};
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\{DB, Log};
use Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException;

trait CardDuplicates
{
    private function cardIdentity($method): ?array
    {
        $card = $method->card ?? null;
        if (($method->type ?? null) !== 'card' || empty($card->fingerprint)
            || empty($card->exp_month) || empty($card->exp_year)) return null;
        return [$card->fingerprint, (int) $card->exp_month, (int) $card->exp_year];
    }

    // Only local current reads belong here. The cache lock spans the unlocked HTTP phases.
    private function cleanupSnapshot($user, $gateway): array
    {
        User::where('id', $user->id)->lockForUpdate()->firstOrFail();
        $customer = $user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value;
        $rows = [...BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get()];
        $state = [];
        foreach ($rows as $row) $state[(string) $row->id] = [$row->external_reference, $row->user_id, $row->gateway_id,
            $row->name, $row->type, (string) $row->expiry, (string) $row->created_at, (string) $row->updated_at];
        ksort($state);
        $properties = [];
        foreach ($user->properties()->where('key', 'like', 'ae_stripe_%')->lockForUpdate()->get() as $property) $properties[$property->key] = $property->value;
        ksort($properties);
        return compact('customer', 'rows', 'state', 'properties');
    }

    private function cleanupUnchanged($user, $gateway, array $snapshot, bool $bindings = false): void
    {
        $current = $this->cleanupSnapshot($user, $gateway);
        if ($bindings) foreach ([self::AUTO_PAY_PROPERTY, self::AUTO_PAY_CARD] as $key) {
            unset($current['properties'][$key], $snapshot['properties'][$key]);
        }
        foreach (['customer', 'state', 'properties'] as $key) {
            if ($current[$key] !== $snapshot[$key]) throw new SavedMethodRetryException('Saved payment methods changed. Try again.');
        }
    }

    private function duplicateIsBound($user, $incoming, ?callable $report): bool
    {
        if ($incoming->services()->lockForUpdate()->first()) {
            Log::info('AeStripe duplicate card kept: agreement is bound to a service', ['agreement_id' => $incoming->id]);
            if ($report) $report('Saved card #' . $incoming->id . ' kept: the duplicate is linked to a service.');
            return true;
        }
        if ($this->autoPayCard($user, lock: true) != $incoming->id) return false;
        Log::info('AeStripe duplicate card kept: agreement is the account Auto Pay card', ['agreement_id' => $incoming->id]);
        if ($report) $report('Saved card #' . $incoming->id . ' kept: the duplicate is the account Auto Pay card.');
        return true;
    }

    // No transaction may surround this provider verification, including detached receipt retries.
    private function cleanupCandidate($user, $gateway, $incoming, array $snapshot): ?array
    {
        $method = $this->request('get', '/payment_methods/' . $incoming->external_reference);
        if (($method->id ?? null) !== $incoming->external_reference || !($identity = $this->cardIdentity($method))) {
            if (isset($snapshot['properties']['ae_stripe_duplicate_' . $incoming->external_reference]) || ($method->customer ?? null) === null) {
                throw new DisplayException('Reserved saved card could not be reconciled. Review it manually.');
            }
            return null;
        }
        if (($method->customer ?? null) !== null && $method->customer !== $snapshot['customer']) {
            if (isset($snapshot['properties']['ae_stripe_duplicate_' . $method->id])) throw new DisplayException('Reserved saved card could not be reconciled. Review it manually.');
            return null;
        }
        $receipt = json_decode($snapshot['properties']['ae_stripe_duplicate_' . $method->id] ?? 'null');
        if (($receipt->customer ?? null) === $snapshot['customer'] && ($receipt->gateway ?? null) == $gateway->id) {
            $candidates = array_filter($snapshot['rows'], fn ($row) => $row->external_reference === ($receipt->kept ?? null));
            if (!$candidates) throw new DisplayException('This payment method was removed. Add a new payment method.');
        } else {
            if (($method->customer ?? null) !== $snapshot['customer']) throw new DisplayException('Detached saved card could not be reconciled. Review it manually.');
            $candidates = $snapshot['rows'];
        }
        usort($candidates, fn ($a, $b) => $a->id <=> $b->id);
        foreach ($candidates as $kept) {
            if ($kept->id >= $incoming->id) continue;
            try {
                $remote = $this->request('get', '/payment_methods/' . $kept->external_reference);
            } catch (RequestException $e) {
                if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') throw $e;
                Log::warning('ae_stripe_duplicate_candidate_missing', ['error' => get_class($e)]);
                continue;
            }
            if (($remote->id ?? null) === $kept->external_reference && ($remote->customer ?? null) === $snapshot['customer']
                && $this->cardIdentity($remote) === $identity) return [$method, $kept, $remote];
        }
        if (($method->customer ?? null) === null || $receipt) throw new DisplayException('Reserved saved card could not be reconciled. Review it manually.');
        return null;
    }

    private function cleanupCard($row, $gateway, bool $apply, callable $report): void
    {
        $user = User::where('id', $row->user_id)->firstOrFail();
        $snapshot = DB::transaction(fn () => $this->cleanupSnapshot($user, $gateway));
        $incoming = null;
        foreach ($snapshot['rows'] as $current) if ($current->id == $row->id && $current->external_reference === $row->external_reference) $incoming = $current;
        if (!$incoming) return;
        if (!$snapshot['customer']) {
            if (isset($snapshot['properties']['ae_stripe_duplicate_' . $incoming->external_reference])) throw new DisplayException('Reserved saved card could not be reconciled. Review it manually.');
            return;
        }
        $candidate = $this->cleanupCandidate($user, $gateway, $incoming, $snapshot);
        if (!$candidate) return;
        [$method, $kept, $remote] = $candidate;
        $detached = ($method->customer ?? null) === null;
        $bound = DB::transaction(function () use ($user, $gateway, $snapshot, $incoming, $report, $detached) {
            $this->cleanupUnchanged($user, $gateway, $snapshot);
            return !$detached && $this->duplicateIsBound($user, $incoming, $report);
        });
        if ($bound) return;
        $report('Saved card #' . $incoming->id . ' duplicates #' . $kept->id . ' (customer #' . $user->id . ').');
        if (!$apply) {
            if ($detached) throw new SavedMethodRetryException('Detached saved card needs reconciliation. Run cleanup with --apply.');
            return;
        }
        // Verify provider state again before reserving the exact local plan.
        $fresh = $this->request('get', '/payment_methods/' . $method->id);
        $remote = $this->request('get', '/payment_methods/' . $kept->external_reference);
        if (($fresh->id ?? null) !== $method->id || ($fresh->customer ?? null) !== ($method->customer ?? null)
            || $this->cardIdentity($fresh) !== $this->cardIdentity($method)
            || ($remote->id ?? null) !== $kept->external_reference || ($remote->customer ?? null) !== $snapshot['customer']
            || $this->cardIdentity($remote) !== $this->cardIdentity($method)) throw new SavedMethodRetryException('Saved payment methods changed. Try again.');
        $reserved = DB::transaction(function () use ($user, $gateway, $snapshot, $incoming, $method, $kept, $report, $detached) {
            $this->cleanupUnchanged($user, $gateway, $snapshot);
            if (!$detached && $this->duplicateIsBound($user, $incoming, $report)) return null;
            $user->properties()->updateOrCreate(['key' => 'ae_stripe_duplicate_' . $method->id], ['value' => json_encode([
                'customer' => $snapshot['customer'], 'gateway' => $gateway->id, 'kept' => $kept->external_reference,
            ], JSON_THROW_ON_ERROR)]);
            return $this->cleanupSnapshot($user, $gateway);
        });
        if (!$reserved) return;
        $rank = ['unspecified' => 0, 'limited' => 1, 'always' => 2];
        if (($rank[$fresh->allow_redisplay ?? 'unspecified'] ?? 0) > ($rank[$remote->allow_redisplay ?? 'unspecified'] ?? 0)) {
            $this->request('post', '/payment_methods/' . $kept->external_reference, ['allow_redisplay' => $fresh->allow_redisplay]);
        }
        if (($fresh->customer ?? null) !== null) $this->request('post', '/payment_methods/' . $method->id . '/detach');
        $verified = $this->request('get', '/payment_methods/' . $kept->external_reference);
        if (($verified->id ?? null) !== $kept->external_reference || ($verified->customer ?? null) !== $snapshot['customer']
            || $this->cardIdentity($verified) !== $this->cardIdentity($method)) throw new DisplayException('Detached saved card could not be reconciled. Review it manually.');
        DB::transaction(function () use ($user, $gateway, $reserved, $incoming, $kept, $method, $report) {
            $this->cleanupUnchanged($user, $gateway, $reserved, bindings: true);
            $this->reconcileDuplicateBindings($user, $incoming, $kept);
            foreach ($user->properties()->where('key', 'like', 'ae_stripe_saved_%')->lockForUpdate()->get() as $property) {
                $receipt = json_decode($property->value, true);
                if (($receipt['reference'] ?? null) !== $method->id || ($receipt['consent']['user_id'] ?? null) != $user->id) continue;
                $receipt['reference'] = $kept->external_reference;
                $user->properties()->updateOrCreate(['key' => $property->key], ['value' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
            }
            $incoming->delete();
            $report('Removed duplicate saved card #' . $incoming->id . '.');
        });
    }

    private function reconcileDuplicateBindings($user, $incoming, $kept): void
    {
        foreach ([self::AUTO_PAY_PROPERTY, self::AUTO_PAY_CARD] as $key) {
            $record = json_decode($user->properties()->where('key', $key)->lockForUpdate()->first()?->value ?? 'null', true);
            if (($record['agreement_id'] ?? null) != $incoming->id) continue;
            if (($record['user_id'] ?? null) != $user->id) throw new DisplayException('Saved card bindings could not be reconciled. Review them manually.');
            $record['agreement_id'] = (int) $kept->id;
            $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($record, JSON_THROW_ON_ERROR)]);
        }
        foreach ($incoming->services()->lockForUpdate()->get() as $service) {
            if ($service->user_id != $user->id) throw new DisplayException('Saved card bindings could not be reconciled. Review them manually.');
            $service->update(['billing_agreement_id' => $kept->id]);
        }
    }

    public function deduplicateCards(bool $apply, callable $report): int
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $skipped = 0;
        foreach (BillingAgreement::where('gateway_id', $gateway->id)->get() as $row) {
            $lock = null;
            try {
                $lock = $this->savedMethodLock((int) $row->user_id);
                if (!$lock->get()) {
                    $lock = null;
                    throw new SavedMethodRetryException('Retry after the saved payment method update finishes or its lock expires.');
                }
                $this->cleanupCard($row, $gateway, $apply, $report);
            } catch (\Throwable $e) {
                ++$skipped;
                Log::warning('ae_stripe_deduplicate_agreement_skipped', ['agreement_id' => $row->id, 'error' => get_class($e)]);
                $report('Saved card #' . $row->id . ($e instanceof SavedMethodRetryException
                    ? ' skipped. ' . $e->getMessage() : ' skipped. Review it manually.'));
            } finally {
                $lock?->release();
            }
        }
        return $skipped;
    }
}
