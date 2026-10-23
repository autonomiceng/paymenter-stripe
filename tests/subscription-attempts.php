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
    $oldStatus = $service->status;
    $oldServiceProperties = $service->properties->values;
    Gateway::$enabled = true;
    $property = 'stripe_saved_subscription_' . $service->id;
    $olderSetup = clone $setup;
    $olderSetup->id = 'seti_older';
    $olderSetup->created = 1700000099;
    $data = ['customer' => 'cus_owner', 'start_date' => 'now', 'phases' => [['price' => 1999]]];
    $resolved = json_encode(['key' => 'sched_seti_newer_' . $service->id, 'data' => $data,
        'created' => time() - 86400, 'subscription' => 'sub_newer']);
    $status = 'active';
    $schedules = 0;
    $subscriptionCreated = 1700000100;
    $handler = function ($verb, $path) use (&$status, &$schedules, &$subscriptionCreated) {
        check(!DB::$locked, 'Subscription HTTP requests run outside the user transaction');
        if ($path === '/subscriptions/sub_newer') {
            check($verb === 'get', 'Resolved subscription status is retrieved');
            return ['id' => 'sub_newer', 'status' => $status, 'created' => $subscriptionCreated];
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
            foreach ([1700000099, 1700000100, null, '1700000101', 1700000101.5] as $created) {
                $olderSetup->created = $created;
                $user->props->values[$property] = $resolved;
                $service->subscription_id = null;
                $beforeSchedules = $schedules;
                for ($retry = 0; $retry < 2; ++$retry) {
                    rejects(fn () => $gateway->webhook(eventRequest($olderSetup, type: 'setup_intent.succeeded')), 'HTTP 409');
                    check($user->props->values[$property] === $resolved && $schedules === $beforeSchedules
                        && $service->subscription_id === null,
                        'Stale or noninteger SetupIntent cannot replace an ended subscription: ' . $status);
                }
            }
            $newerSetup = clone $olderSetup;
            $newerSetup->id = 'seti_newest';
            $newerSetup->created = 1700000101;
            foreach ([null, '1700000100', 1700000100.5] as $subscriptionCreated) {
                rejects(fn () => $gateway->webhook(eventRequest($newerSetup, type: 'setup_intent.succeeded')), 'HTTP 409');
                check($user->props->values[$property] === $resolved && $schedules === $beforeSchedules
                    && $service->subscription_id === null, 'Missing or noninteger subscription creation time prevents replacement');
            }
            $subscriptionCreated = 1700000100;
            for ($retry = 0; $retry < 2; ++$retry) {
                $gateway->webhook(eventRequest($newerSetup, type: 'setup_intent.succeeded'));
            }
            $attempt = json_decode($user->props->values[$property], true);
            check($service->subscription_id === 'sub_older' && $schedules === $beforeSchedules + 1
                && $attempt['key'] === 'sched_seti_newest_' . $service->id && $attempt['subscription'] === 'sub_older',
                'A newer integer SetupIntent allows exactly one replacement schedule: ' . $status);
            $before = count(Http::$calls);
            check(invoke($gateway, 'subscriptionAttempt', $user, $newerSetup, $service, $data) === 'sub_older'
                && count(Http::$calls) === $before, 'The same resolved key returns without any Stripe lookup');
        }

        $service->status = \App\Models\Service::STATUS_CANCELLED;
        foreach ([null, 'sub_existing'] as $subscriptionId) {
            $service->subscription_id = $subscriptionId;
            $service->properties->values = [];
            $user->props->values[$property] = $resolved;
            $before = count(Http::$calls);
            invoke($gateway, 'setupSubscription', $newerSetup);
            check($service->subscription_id === $subscriptionId && $service->properties->values === []
                && $user->props->values[$property] === $resolved
                && count(array_filter(array_slice(Http::$calls, $before), fn ($call) =>
                    in_array($call[1], ['/products/search', '/subscription_schedules', '/subscriptions/sub_newer'], true))) === 0,
                'Cancelled service skips schedule creation and listener marker repair');
        }
        $service->status = $oldStatus;

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
        $service->status = $oldStatus;
        $service->properties->values = $oldServiceProperties;
    }
})();
