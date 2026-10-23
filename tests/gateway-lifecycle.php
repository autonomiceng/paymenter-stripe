<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{Extension, Gateway};
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $tables = DB::$tables; $enabled = Gateway::$enabled; $bundled = Gateway::$bundledEnabled;
    DB::$tables = ['billing_agreements' => [], 'services' => [], 'properties' => []];
    Gateway::$enabled = Gateway::$bundledEnabled = true;
    $calls = count(Http::$calls);
    $gateway->enabled(new Extension(['enabled' => true]));
    check(!Gateway::$bundledEnabled, 'Enabling StripeSaved turns off empty core Stripe');
    check(count(Http::$calls) === $calls, 'Existing webhook setup needs no Stripe request');
    Gateway::$bundledEnabled = true;
    DB::$tables['billing_agreements'] = [['id' => 10, 'gateway_id' => 1, 'deleted_at' => null]];
    $gateway->enabled(new Extension(['enabled' => true]));
    check(Gateway::$bundledEnabled, 'Enabling with a saved card preserves core Stripe');
    DB::$tables['billing_agreements'][0]['deleted_at'] = '2026-01-01';
    DB::$tables['services'] = [['id' => 20, 'billing_agreement_id' => 10, 'subscription_id' => null]];
    $gateway->enabled(new Extension(['enabled' => true]));
    check(Gateway::$bundledEnabled, 'A service referencing a deleted core agreement prevents automatic disable');
    DB::$tables['services'][0] = ['id' => 20, 'billing_agreement_id' => null, 'subscription_id' => 'sub_legacy'];
    DB::$tables['properties'] = [['model_id' => 20, 'model_type' => \App\Models\Service::class, 'key' => 'has_stripe_subscription', 'value' => '1']];
    $gateway->enabled(new Extension(['enabled' => true]));
    check(Gateway::$bundledEnabled, 'Legacy Stripe subscription without an agreement prevents automatic disable');
    \App\Models\User::$staff = true;
    $gateway->boot();
    $warning = \Filament\Support\Facades\FilamentView::$hooks['panels::page.start'] ?? null;
    check($warning && $warning()['name'] === 'gateways.stripe-saved::gateway-warning', 'Boot installs a persistent admin warning for enabled core Stripe');
    DB::$tables['extensions'] = [
        ['id' => 28, 'deleted_at' => null], ['id' => 62, 'deleted_at' => null],
        ['id' => 63, 'deleted_at' => null], ['id' => 64, 'deleted_at' => '2026-01-01'], ['id' => 2, 'deleted_at' => null],
    ];
    Gateway::$rows = ['Stripe' => array_map(fn ($id) => new Extension(['id' => $id, 'extension' => 'Stripe', 'enabled' => $id !== 28]), [28, 62, 63, 64]),
        'StripeSaved' => [new Extension(['id' => 2, 'extension' => 'StripeSaved', 'enabled' => true])]];
    check(array_column($warning()['data']['gateways'], 'id') === [62, 63], 'Warning covers every enabled nondeleted core row');
    check(!$gateway->canUseGateway(20, 'USD', 'invoice', []), 'A later enabled core row still hides StripeSaved from customers');
    Gateway::$rows = null;
    DB::$tables['billing_agreements'] = DB::$tables['services'] = DB::$tables['properties'] = [];
    Gateway::$bundledEnabled = true;
    $gateway->updated(new Extension(['enabled' => true, 'changed' => true]));
    check(!Gateway::$bundledEnabled, 'Gateway admin updated hook handles an enabled transition');
    \App\Models\User::$gatewayUpdate = true;
    Gateway::$bundledEnabled = true;
    $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1]));
    check(!Gateway::$bundledEnabled, 'Authorized one click action disables empty core Stripe');
    $definition = \Illuminate\Support\Facades\Route::$definitions['/extensions/stripe-saved/disable-core'];
    check($definition['method'] === 'POST' && $definition['middleware'] === ['web', 'auth'], 'Disable action requires the native session and CSRF middleware');
    Gateway::$bundledEnabled = true;
    \App\Models\User::$gatewayUpdate = false;
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 403');
    check(Gateway::$bundledEnabled, 'Staff without gateway update permission cannot disable core');
    \Illuminate\Support\Facades\Auth::$id = null;
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 403');
    check($warning() === '', 'Guests do not see the admin warning');
    \Illuminate\Support\Facades\Auth::$id = 7;
    \App\Models\User::$staff = false;
    check($warning() === '', 'Customers do not see the admin warning');
    \App\Models\User::$staff = \App\Models\User::$gatewayUpdate = true;
    DB::$tables['billing_agreements'] = [['id' => 10, 'gateway_id' => 1, 'deleted_at' => null]];
    check($warning()['data']['gateways'][0]['hasReferences'], 'Saved cards show the adoption command instead of the disable control');
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 409');
    check(Gateway::$bundledEnabled, 'A saved card added after rendering prevents one click disable');
    Gateway::$enabled = false;
    check($warning() === '', 'Disabled StripeSaved does not claim both gateways are on');
    Gateway::$enabled = true; Gateway::$bundledEnabled = false;
    check($warning() === '', 'Warning disappears when core Stripe is disabled');
    Gateway::$bundledEnabled = true;
    check($warning() !== '', 'Reenabled core Stripe restores the warning without enabling StripeSaved again');
    DB::$tables['billing_agreements'] = [];
    $gateway->updated(new Extension(['enabled' => true, 'changed' => false]));
    check(Gateway::$bundledEnabled, 'An ordinary settings save does not silently disable reenabled core');
    check(in_array('Core Stripe was turned off.', \Filament\Notifications\Notification::$sent), 'Staff receive the native success notice');
    check(count(Http::$calls) === $calls, 'All lifecycle guards and admin notices make no Stripe requests');
    \App\Models\User::$gatewayUpdate = \App\Models\User::$staff = false;
    DB::$tables = $tables; Gateway::$enabled = $enabled; Gateway::$bundledEnabled = $bundled;
})();
