<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{Gateway, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway, $user, $service, $setup) {
    $oldHandler = Http::$handler;
    $oldEnabled = Gateway::$enabled;
    $oldProperties = $user->props->values;
    $oldSubscription = $service->subscription_id;
    $oldServiceProperties = $service->properties->values;
    Gateway::$enabled = true;
    $property = 'stripe_saved_subscription_' . $service->id;
    $olderSetup = clone $setup;
    $olderSetup->id = 'seti_older';
    $data = ['customer' => 'cus_owner', 'start_date' => 'now', 'phases' => [['price' => 1999]]];
    $resolved = json_encode(['key' => 'sched_seti_newer_' . $service->id, 'data' => $data,
        'created' => time() - 86400, 'subscription' => 'sub_newer']);
    $status = 'active';
    $schedules = 0;
    $handler = function ($verb, $path) use (&$status, &$schedules) {
        check(!DB::$locked, 'Subscription HTTP requests run outside the user transaction');
        if ($path === '/subscriptions/sub_newer') {
            check($verb === 'get', 'Resolved subscription status is retrieved');
            return ['id' => 'sub_newer', 'status' => $status];
        }
        if ($path === '/subscription_schedules') {
            ++$schedules;
            return ['subscription' => 'sub_older'];
        }
        return match ($path) {
            '/customers/cus_owner' => ['id' => 'cus_owner'],
            '/payment_methods/pm_test' => ['id' => 'pm_test', 'customer' => 'cus_owner'],
            '/products/search' => ['data' => [['id' => 'prod_test']]],
            default => throw new RuntimeException('Unexpected replay request: ' . $path),
        };
    };
    try {
        Http::$handler = $handler;
        foreach (['incomplete', 'trialing', 'active', 'past_due', 'unpaid', 'paused', 'future_status'] as $status) {
            $user->props->values[$property] = $resolved;
            $before = count(Http::$calls);
            for ($retry = 0; $retry < 2; ++$retry) {
                check(invoke($gateway, 'subscriptionAttempt', $user, $olderSetup, $service, $data) === 'sub_newer'
                    && $user->props->values[$property] === $resolved && $schedules === 0,
                    'An older SetupIntent retains the live resolved subscription: ' . $status);
            }
            check(count(Http::$calls) === $before + 2, 'Different setup retries each retrieve the stored subscription');
        }
        $status = 'active';
        $service->subscription_id = null; // The schedule was stored before the service write failed.
        $before = count(Http::$calls);
        for ($retry = 0; $retry < 2; ++$retry) {
            check($gateway->webhook(eventRequest($olderSetup, type: 'setup_intent.succeeded'))['status'] === 200,
                'Older setup webhook succeeds when recovering the live subscription');
        }
        check($service->subscription_id === 'sub_newer' && $schedules === 0
            && $service->properties->values['has_stripe_subscription'] === true
            && $user->props->values[$property] === $resolved,
            'Older setup webhook binds the service to the newer subscription without another schedule');
        check(count(array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[1] === '/subscriptions/sub_newer')) === 1,
            'A bound service skips the resolved attempt lookup on webhook replay');

        foreach (['canceled', 'incomplete_expired'] as $status) {
            $user->props->values[$property] = $resolved;
            $service->subscription_id = null;
            $beforeSchedules = $schedules;
            for ($retry = 0; $retry < 2; ++$retry) {
                $gateway->webhook(eventRequest($olderSetup, type: 'setup_intent.succeeded'));
            }
            $attempt = json_decode($user->props->values[$property], true);
            check($service->subscription_id === 'sub_older' && $schedules === $beforeSchedules + 1
                && $attempt['key'] === 'sched_seti_older_' . $service->id && $attempt['subscription'] === 'sub_older',
                'An ended subscription allows exactly one replacement schedule: ' . $status);
            $before = count(Http::$calls);
            check(invoke($gateway, 'subscriptionAttempt', $user, $olderSetup, $service, $data) === 'sub_older'
                && count(Http::$calls) === $before, 'The same resolved key returns without any Stripe lookup');
        }

        $failures = [new ConnectionException('Connection refused'), new ConnectionException('Request timed out'),
            new RequestException(404), new RequestException(503), new JsonException('Invalid JSON'),
            null, [], ['id' => 'sub_other', 'status' => 'canceled'], ['id' => 'sub_newer'],
            ['id' => 'sub_newer', 'status' => ''], ['id' => 'sub_newer', 'status' => 123]];
        foreach ($failures as $failure) {
            $user->props->values[$property] = $resolved;
            $service->subscription_id = null;
            $beforeSchedules = $schedules;
            Http::$handler = function ($verb, $path) use ($failure, $handler) {
                if ($path !== '/subscriptions/sub_newer') return $handler($verb, $path);
                check(!DB::$locked, 'Failed subscription lookup runs outside the user transaction');
                if ($failure instanceof Throwable) throw $failure;
                return $failure;
            };
            for ($retry = 0; $retry < 2; ++$retry) {
                rejects(fn () => $gateway->webhook(eventRequest($olderSetup, type: 'setup_intent.succeeded')), 'HTTP 409');
                check($user->props->values[$property] === $resolved && $schedules === $beforeSchedules
                    && $service->subscription_id === null, 'Failed lookup preserves the resolved attempt and creates no schedule');
            }
        }

        foreach (['active', 'canceled'] as $status) {
            for ($retry = 0; $retry < 2; ++$retry) {
                $user->props->values[$property] = $resolved;
                $concurrent = json_encode(['key' => 'sched_seti_concurrent_' . $service->id, 'data' => $data,
                    'created' => time(), 'subscription' => 'sub_concurrent']);
                Http::$handler = function ($verb, $path) use ($user, $property, $concurrent, $status) {
                    check(!DB::$locked && $verb === 'get' && $path === '/subscriptions/sub_newer',
                        'Concurrent attempt changes during the unlocked subscription lookup');
                    $user->props->values[$property] = $concurrent;
                    return ['id' => 'sub_newer', 'status' => $status];
                };
                $before = count(DB::$modelLocks);
                $beforeCalls = count(Http::$calls);
                rejects(fn () => invoke($gateway, 'subscriptionAttempt', $user, $olderSetup, $service, $data), 'HTTP 409');
                check($user->props->values[$property] === $concurrent && count(Http::$calls) === $beforeCalls + 1
                    && array_slice(DB::$modelLocks, $before) === [User::class, User::class],
                    'A changed attempt is checked under the user lock and cannot be replaced or returned');
            }
        }
    } finally {
        Http::$handler = $oldHandler;
        Gateway::$enabled = $oldEnabled;
        $user->props->values = $oldProperties;
        $service->subscription_id = $oldSubscription;
        $service->properties->values = $oldServiceProperties;
    }
})();
