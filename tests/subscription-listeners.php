<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Models\{Gateway, Service};
use Illuminate\Support\Facades\{Event, Http};

$previousHandler = Http::$handler;
Http::$handler = function ($verb, $path) {
    return match ([$verb, $path]) {
        ['delete', '/subscriptions/sub_test'] => [],
        ['get', '/subscriptions/sub_test'] => ['schedule' => null, 'items' => ['data' => [
            ['id' => 'si_test', 'price' => ['product' => 'prod_test']]]]],
        ['post', '/subscription_items/si_test'] => [],
        default => throw new RuntimeException('Unexpected subscription listener request'),
    };
};
$subscriptionService = function () {
    $service = new class extends Service { public $status = Service::STATUS_CANCELLED; };
    $service->properties->values['has_stripe_subscription'] = '1';
    return $service;
};
$dispatchers = [
    'Updated' => fn ($service) => Event::$listeners[Updated::class](new Updated($service)),
    'Created' => fn ($service) => Event::$listeners[Created::class](new Created((object) ['service' => $service])),
];

Gateway::$enabled = false;
foreach ($dispatchers as $name => $dispatch) {
    $service = $subscriptionService();
    $service->dirty = ['price', 'status'];
    $before = count(Http::$calls);
    $dispatch($service);
    check(array_map(fn ($call) => array_slice($call, 0, 2), array_slice(Http::$calls, $before))
        === [['delete', '/subscriptions/sub_test']],
        $name . ' cancels an existing subscription while disabled without syncing price');
    check($service->subscription_id === null && !isset($service->properties->values['has_stripe_subscription']),
        $name . ' clears the subscription id and marker after cancellation');
}

foreach (['price', 'expires_at'] as $field) {
    $service = $subscriptionService();
    $service->dirty = [$field];
    $before = count(Http::$calls);
    $dispatchers['Updated']($service);
    check(count(Http::$calls) === $before, 'Disabled fork skips subscription ' . $field . ' sync');
}

foreach ([false, true] as $enabled) {
    Gateway::$enabled = $enabled;
    foreach ($dispatchers as $name => $dispatch) {
        foreach (['marker', 'subscription_id'] as $missing) {
            $service = $subscriptionService();
            $service->dirty = ['price', 'status'];
            if ($missing === 'marker') unset($service->properties->values['has_stripe_subscription']);
            else $service->subscription_id = null;
            $before = count(Http::$calls);
            $dispatch($service);
            check(count(Http::$calls) === $before, $name . ' skips services without ' . $missing);
        }
    }
}
$service = $subscriptionService();
$dispatchers['Updated']($service);
check(end(Http::$calls)[0] === 'post' && end(Http::$calls)[1] === '/subscription_items/si_test',
    'Enabled fork still syncs subscription price');
Http::$handler = $previousHandler;
Gateway::$enabled = false;
