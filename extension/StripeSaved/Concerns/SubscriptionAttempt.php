<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

use App\Models\User;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;

trait SubscriptionAttempt
{
    private function subscriptionAttempt($user, $setupIntent, $service, array $data): string
    {
        $property = 'stripe_saved_subscription_' . $service->id;
        $key = 'sched_' . $setupIntent->id . '_' . $service->id;
        // This transaction commits before any remote schedule creation. Its immutable
        // payload survives later service-write failures and partial multi-service setup.
        $attempt = DB::transaction(function () use ($user, $property, $key, $data) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $stored = $user->properties()->where('key', $property)->first()?->value;
            if ($stored) {
                $attempt = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
                if ($attempt['key'] === $key) return $attempt;
                abort_unless(isset($attempt['subscription']), 409);
                return $attempt;
            }
            $attempt = ['key' => $key, 'data' => $data, 'created' => time()];
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
            return $attempt;
        });
        if ($attempt['key'] !== $key) {
            // A failed service write can leave a live subscription on another setup key.
            try {
                $subscription = $this->request('get', '/subscriptions/' . $attempt['subscription']);
            } catch (ConnectionException | RequestException | \JsonException $e) {
                abort(409);
            }
            abort_unless(($subscription->id ?? null) === $attempt['subscription']
                && is_string($subscription->status ?? null) && $subscription->status !== '', 409);
            $ended = in_array($subscription->status, ['canceled', 'incomplete_expired'], true);
            $attempt = DB::transaction(function () use ($user, $property, $key, $data, $attempt, $ended, $setupIntent, $subscription) {
                $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                $stored = $user->properties()->where('key', $property)->first()?->value;
                // The Stripe read ran without a lock. Refuse if another request changed the attempt.
                abort_unless($stored && json_decode($stored, true, flags: JSON_THROW_ON_ERROR) === $attempt, 409);
                if (!$ended) return $attempt;
                abort_unless(is_int($setupIntent->created ?? null) && is_int($subscription->created ?? null)
                    && $setupIntent->created > $subscription->created, 409);
                $replacement = ['key' => $key, 'data' => $data, 'created' => time()];
                $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($replacement, JSON_THROW_ON_ERROR)]);
                return $replacement;
            });
        }
        if (isset($attempt['subscription'])) return $attempt['subscription'];
        abort_unless(time() - $attempt['created'] < 23 * 3600, 409);
        $schedule = $this->request('post', '/subscription_schedules', $attempt['data'], $attempt['key']);
        $attempt['subscription'] = $schedule->subscription;
        DB::transaction(function () use ($user, $property, $attempt) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
        });
        return $attempt['subscription'];
    }
}
