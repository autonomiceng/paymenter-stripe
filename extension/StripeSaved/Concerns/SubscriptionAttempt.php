<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

use App\Models\User;
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
                // A resolved attempt belongs to the previous subscription lifecycle.
                abort_unless(isset($attempt['subscription']), 409);
            }
            $attempt = ['key' => $key, 'data' => $data, 'created' => time()];
            $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($attempt, JSON_THROW_ON_ERROR)]);
            return $attempt;
        });
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
