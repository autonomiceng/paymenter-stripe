<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\Gateway;
use Illuminate\Support\Facades\{Event, Http, Route};

$listeners = Event::$listeners;
$routes = Route::$routes;
$calls = count(Http::$calls);
foreach (['enabled', 'disabled', 'absent', 'deleted'] as $forkState) {
    Gateway::$enabled = $forkState === 'enabled';
    Gateway::$present = $forkState !== 'absent';
    Gateway::$deleted = $forkState === 'deleted';
    foreach (['active', 'disabled', 'absent', 'deleted'] as $bundledState) {
        Gateway::$bundled = $bundledState !== 'absent';
        Gateway::$bundledEnabled = $bundledState !== 'disabled';
        Gateway::$bundledDeleted = $bundledState === 'deleted';
        check($gateway->canUseGateway(20, 'USD', 'invoice', []) === ($forkState === 'enabled' && $bundledState !== 'active'),
            'Checkout ownership: fork ' . $forkState . ', bundled ' . $bundledState);
        check(invoke($gateway, 'ownsWebhookObject', (object) [], 'payment_intent.succeeded') === in_array($bundledState, ['absent', 'deleted']),
            'Unmarked webhook ownership requires bundled retirement: ' . $bundledState);
        Event::$listeners = [];
        $gateway->boot();
        check(count(Event::$listeners) === (in_array($bundledState, ['absent', 'deleted']) ? 2 : 0),
            'Disabled bundled still owns listeners; retired/absent transfers them: ' . $bundledState);
    }
}
check(count(Http::$calls) === $calls, 'Checkout visibility and boot make no Stripe requests');
Event::$listeners = $listeners;
Route::$routes = $routes;
Gateway::$enabled = false;
Gateway::$present = Gateway::$bundled = true;
Gateway::$deleted = Gateway::$bundledDeleted = Gateway::$bundledEnabled = false;
