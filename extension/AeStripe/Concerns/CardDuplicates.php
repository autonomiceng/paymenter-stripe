<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Exceptions\DisplayException;
use App\Models\{BillingAgreement, Gateway, User};
use Illuminate\Support\Facades\{DB, Log};

trait CardDuplicates
{
    private function cardIdentity($method): ?array
    {
        $card = $method->card ?? null;
        if (($method->type ?? null) !== 'card' || empty($card->fingerprint)
            || empty($card->exp_month) || empty($card->exp_year)) return null;
        return [$card->fingerprint, (int) $card->exp_month, (int) $card->exp_year];
    }

    private function duplicateKey($method): string
    {
        return 'ae_stripe_duplicate_' . $method->id;
    }

    private function duplicateReceipt($user, $method): ?object
    {
        $value = $user->properties()->where('key', $this->duplicateKey($method))->lockForUpdate()->first()?->value;
        return $value ? json_decode($value) : null;
    }

    // The caller holds the user lock. All agreement and service checks are current reads.
    private function duplicateCard($user, $method, $gateway, $incoming = null, ?callable $report = null): ?array
    {
        $identity = $this->cardIdentity($method);
        if (!$identity) return null;
        $customer = $user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()?->value;
        if (!$customer || (($method->customer ?? null) !== null && $method->customer !== $customer)) return null;
        $receipt = $this->duplicateReceipt($user, $method);
        if ($receipt && ($receipt->customer ?? null) === $customer && ($receipt->gateway ?? null) == $gateway->id) {
            $kept = BillingAgreement::withTrashed()->where('external_reference', $receipt->kept)->lockForUpdate()->first();
            if (!$kept || $kept->trashed() || $kept->user_id != $user->id || $kept->gateway_id != $gateway->id) {
                if (($method->customer ?? null) === $customer) return null;
                throw new DisplayException('This payment method was removed. Add a new payment method.');
            }
            $remote = $this->request('get', '/payment_methods/' . $kept->external_reference);
            if (($remote->customer ?? null) !== $customer || $this->cardIdentity($remote) !== $identity) return null;
            return $this->duplicateIsBound($incoming, $report) ? null : [$kept, $remote];
        }
        if (($method->customer ?? null) !== $customer) return null;
        $agreements = BillingAgreement::where('user_id', $user->id)->where('gateway_id', $gateway->id)->lockForUpdate()->get();
        // Keep the oldest existing agreement. Cleanup never replaces an older row with a newer row.
        $agreements = [...$agreements];
        usort($agreements, fn ($a, $b) => $a->id <=> $b->id);
        foreach ($agreements as $kept) {
            if ($kept->external_reference === $method->id || ($incoming && $kept->id >= $incoming->id)) continue;
            $remote = $this->request('get', '/payment_methods/' . $kept->external_reference);
            if (($remote->customer ?? null) !== $customer || $this->cardIdentity($remote) !== $identity) continue;
            return $this->duplicateIsBound($incoming, $report) ? null : [$kept, $remote];
        }
        return null;
    }

    private function duplicateIsBound($incoming, ?callable $report): bool
    {
        if (!$incoming || !$incoming->services()->lockForUpdate()->first()) return false;
        Log::info('AeStripe duplicate card kept: agreement is bound to a service', ['agreement_id' => $incoming->id]);
        if ($report) $report('Agreement #' . $incoming->id . ' retained: duplicate is bound to a service.');
        return true;
    }

    private function rememberDuplicate($user, $method, $kept): void
    {
        // Commit the receipt before detaching. Redirects and uncertain HTTP retries can resume safely.
        $user->properties()->updateOrCreate(['key' => $this->duplicateKey($method)], ['value' => json_encode([
            'customer' => $user->properties()->where('key', 'stripe_id')->lockForUpdate()->first()->value,
            'gateway' => $kept->gateway_id, 'kept' => $kept->external_reference,
        ], JSON_THROW_ON_ERROR)]);
    }

    private function finalizeDuplicate($user, $method, bool $consented, ?callable $report = null, ?int $incomingId = null): bool
    {
        return DB::transaction(function () use ($user, $method, $consented, $report, $incomingId) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
            $incoming = BillingAgreement::withTrashed()->where('external_reference', $method->id)->lockForUpdate()->first();
            if ($incomingId !== null && $incoming?->id != $incomingId) return false;
            if ($incoming && ($incoming->user_id != $user->id || $incoming->gateway_id != $gateway->id)) return false;
            $fresh = $this->request('get', '/payment_methods/' . $method->id);
            if ($incoming?->trashed() && ($fresh->customer ?? null) !== null) return false;
            $duplicate = $this->duplicateCard($user, $fresh, $gateway, $incoming, report: $report);
            if (!$duplicate) return false;
            [$kept, $remote] = $duplicate;
            $this->finishDuplicate($fresh, $kept, $remote, $consented);
            if ($incoming && !$incoming->trashed()) {
                $incoming->delete();
                if ($report) $report('Removed duplicate agreement #' . $incoming->id . '.');
            }
            return true;
        });
    }

    // The caller holds the user, agreement and service locks through both Stripe writes.
    private function finishDuplicate($method, $kept, $remote, bool $consented): void
    {
        $rank = ['unspecified' => 0, 'limited' => 1, 'always' => 2];
        $redisplay = $consented ? 'always' : ($method->allow_redisplay ?? 'unspecified');
        if (($rank[$redisplay] ?? 0) > ($rank[$remote->allow_redisplay ?? 'unspecified'] ?? 0)) {
            $this->request('post', '/payment_methods/' . $kept->external_reference, ['allow_redisplay' => $redisplay]);
        }
        if (($method->customer ?? null) !== null) $this->request('post', '/payment_methods/' . $method->id . '/detach');
    }

    public function deduplicateCards(bool $apply, callable $report): int
    {
        $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
        $rows = BillingAgreement::where('gateway_id', $gateway->id)->get();
        $skipped = 0;
        foreach ($rows as $row) {
            try {
                $action = DB::transaction(function () use ($gateway, $row, $apply, $report) {
                    $user = User::where('id', $row->user_id)->lockForUpdate()->firstOrFail();
                    $incoming = BillingAgreement::where('external_reference', $row->external_reference)->lockForUpdate()->first();
                    if (!$incoming || $incoming->gateway_id != $gateway->id || $incoming->user_id != $user->id) return null;
                    $method = $this->request('get', '/payment_methods/' . $incoming->external_reference);
                    $duplicate = $this->duplicateCard($user, $method, $gateway, $incoming, report: $report);
                    if (!$duplicate) return null;
                    [$kept, $remote] = $duplicate;
                    $report('Agreement #' . $incoming->id . ' duplicates #' . $kept->id . ' (customer #' . $user->id . ').');
                    if (!$apply) return null;
                    $this->rememberDuplicate($user, $method, $kept);
                    return [$user, $method];
                });
                if (!$action) continue;
                [$user, $method] = $action;
                $this->finalizeDuplicate($user, $method, consented: false, report: $report, incomingId: $row->id);
            } catch (\Throwable $e) {
                ++$skipped;
                Log::warning('ae_stripe_deduplicate_agreement_skipped', ['agreement_id' => $row->id, 'error' => get_class($e)]);
                $report('Agreement #' . $row->id . ' skipped. Review it manually.');
            }
        }
        return $skipped;
    }
}
