<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
namespace Paymenter\Extensions\Gateways\StripeSaved;

use RuntimeException;

final class AdoptionRefusal extends RuntimeException {}

/** Pure planner and coordinator; the store owns locks, persistence and Stripe transport. */
final class Adoption
{
    public function __construct(private object $store) {}

    public static function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function preflight(): array
    {
        $config = $this->store->configuration();
        $modes = $accounts = [];
        foreach ($config as $gateway) {
            if (!preg_match('/^(?:sk|rk)_(test|live)_/', $gateway['secret'], $key)
                || !str_starts_with($gateway['public'], 'pk_' . $key[1] . '_')
                || $gateway['webhook'] === '') {
                throw new AdoptionRefusal('Gateway configuration or key mode invalid');
            }
            $modes[] = $key[1];
            // Required reads: exactly one account request per key; never bypassed.
            $accounts[] = $this->store->stripe($gateway['secret'], 'get', '/account', [])['id'] ?? '';
        }
        if (count($config) !== 2 || $accounts[0] === '' || $accounts[0] !== $accounts[1]) {
            throw new AdoptionRefusal('Stripe account mismatch');
        }
        if ($modes[0] !== $modes[1]) {
            throw new AdoptionRefusal('Stripe mode mismatch');
        }
        return ['account' => $accounts[0], 'mode' => $modes[0], 'configuration' => self::digest($config)];
    }

    public function plan(array $before, array $identity): array
    {
        if ($before['configuration'] !== $identity['configuration']) throw new AdoptionRefusal('Configuration changed during preflight');
        $after = $before;
        $groups = $adopted = $actions = [];
        foreach ($before['agreements'] as $id => $row) {
            if (!preg_match('/^pm_[a-zA-Z0-9]+$/D', $row['external_reference'])) {
                throw new AdoptionRefusal('Unsupported method reference; manual review required');
            }
            $groups[$row['external_reference']][] = $id;
            if ($row['gateway_id'] == $before['bundled']) $adopted[] = $id;
        }
        foreach ($groups as $ids) {
            $users = array_unique(array_map(fn ($id) => $before['agreements'][$id]['user_id'], $ids));
            if (count($users) !== 1) throw new AdoptionRefusal('Method shared across users; manual review required');
            if (!array_intersect($ids, $adopted)) continue;
            // Preserve the oldest referenced id, otherwise the oldest id. Never resurrect deleted methods.
            $referenced = array_filter($ids, fn ($id) => count(array_filter($before['services'], fn ($s) => $s['billing_agreement_id'] == $id)) > 0);
            $active = array_filter($ids, fn ($id) => $before['agreements'][$id]['deleted_at'] === null);
            $candidates = array_intersect($referenced, $active) ?: ($active ?: ($referenced ?: $ids));
            $keep = min($candidates);
            foreach ($ids as $id) {
                $after['agreements'][$id]['gateway_id'] = $before['fork'];
                if ($id != $keep && $after['agreements'][$id]['deleted_at'] === null) {
                    // Fixed sentinel makes the reviewed plan deterministic, without deleting any row.
                    $after['agreements'][$id]['deleted_at'] = '2000-01-01 00:00:00';
                }
                $actions[] = ['agreement' => $id, 'gateway' => $before['fork'], 'canonical' => $keep,
                    'inactive' => $after['agreements'][$id]['deleted_at'] !== null];
            }
            foreach ($after['services'] as &$service) {
                $old = $service['billing_agreement_id'];
                if (in_array($old, $ids) && $before['agreements'][$old]['deleted_at'] === null && $old != $keep) {
                    $service['billing_agreement_id'] = $keep;
                }
            }
            unset($service);
        }
        $plan = compact('before', 'after', 'identity', 'adopted', 'actions');
        return $plan + ['digest' => self::digest($plan)];
    }

    public function run(?string $digest = null): array
    {
        $identity = $this->preflight();
        return $this->store->transaction(function () use ($identity, $digest) {
            $plan = $this->plan($this->store->snapshot(), $identity);
            if ($digest === null) return $plan;
            if (!hash_equals($plan['digest'], $digest)) throw new AdoptionRefusal('Plan changed; run dry run again');
            // Persist recovery data BEFORE writing. A failed commit leaves a harmless, drift-refused manifest.
            $name = $this->store->saveManifest($plan);
            $this->store->replace($plan['before'], $plan['after']);
            return $plan + ['manifest' => $name];
        });
    }

    public function rollback(array $plan): void
    {
        $identity = $this->preflight();
        $this->store->transaction(function () use ($plan, $identity) {
            if ($identity !== $plan['identity'] || $this->store->snapshot() !== $plan['after']) {
                throw new AdoptionRefusal('Rollback drift; restore from reviewed backup instead');
            }
            $this->store->replace($plan['after'], $plan['before']);
        });
    }

    public function redisplay(array $plan, string $name): array
    {
        if ($this->preflight() !== $plan['identity']) throw new AdoptionRefusal('Stripe identity changed');
        $this->store->transaction(function () use ($plan) {
            if ($this->store->snapshot() !== $plan['after']) throw new AdoptionRefusal('Redisplay drift; review ownership first');
        });
        $outcomes = $this->store->outcomes($name);
        $key = $this->store->configuration()[1]['secret'];
        foreach ($plan['adopted'] as $id) {
            $row = $plan['before']['agreements'][$id];
            if ($row['deleted_at'] !== null) continue;
            $method = $row['external_reference'];
            if (($outcomes[$method] ?? '') === 'ok') continue;
            $this->store->throttle();
            try {
                $this->store->stripe($key, 'post', '/payment_methods/' . $method, ['allow_redisplay' => 'always']);
                $outcomes[$method] = 'ok';
            } catch (\Throwable $e) {
                // Transport errors can contain secrets/PII; record a safe retry status only.
                $outcomes[$method] = 'retry';
            }
            $this->store->saveOutcomes($name, $outcomes);
        }
        return $outcomes;
    }
}
