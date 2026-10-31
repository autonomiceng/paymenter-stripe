<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Models\{Extension, Gateway};
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $tables = DB::$tables; $enabled = Gateway::$enabled; $bundled = Gateway::$bundledEnabled;
    DB::$tables = ['billing_agreements' => [], 'services' => [], 'properties' => []];
    Gateway::$enabled = Gateway::$bundledEnabled = true;
    $handler = Http::$handler;
    $gateway = new \Paymenter\Extensions\Gateways\AeStripe\AeStripe([
        'stripe_secret_key' => 'rk_test_fixture', 'stripe_publishable_key' => 'pk_test_fixture', 'stripe_webhook_secret' => 'whsec_test',
    ]);
    Http::$handler = function ($verb, $path) {
        return match ($path) {
            '/account' => (object) ['id' => 'acct_fixture'],
            '/webhook_endpoints' => (object) ['data' => [(object) ['id' => 'we_fixture', 'url' => route('extensions.gateways.ae-stripe.webhook'),
                'api_version' => '2025-07-30.basil', 'enabled_events' => ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.processing',
                    'setup_intent.succeeded', 'subscription_schedule.canceled', 'invoice.created', 'invoice.payment_succeeded', 'charge.updated', 'charge.succeeded', 'refund.failed', 'refund.updated']]]],
            default => throw new RuntimeException('Unexpected lifecycle request'),
        };
    };
    $calls = count(Http::$calls);
    $gateway->enabled(new Extension(['id' => 2, 'enabled' => true]));
    check(!Gateway::$bundledEnabled, 'Enabling AeStripe turns off empty core Stripe after verifying webhook continuity');
    Gateway::$bundledEnabled = true;
    DB::$tables['billing_agreements'] = [['id' => 10, 'gateway_id' => 1, 'deleted_at' => null]];
    \Filament\Notifications\Notification::$sent = [];
    $gateway->enabled(new Extension(['id' => 2, 'enabled' => true]));
    check(Gateway::$bundledEnabled, 'Enabling with a saved card preserves core Stripe');
    DB::$tables['billing_agreements'][0]['deleted_at'] = '2026-01-01';
    DB::$tables['services'] = [['id' => 20, 'billing_agreement_id' => 10, 'subscription_id' => null]];
    \Filament\Notifications\Notification::$sent = [];
    $gateway->enabled(new Extension(['id' => 2, 'enabled' => true]));
    check(Gateway::$bundledEnabled, 'A service referencing a deleted core agreement prevents automatic disable');
    DB::$tables['services'][0] = ['id' => 20, 'billing_agreement_id' => null, 'subscription_id' => 'sub_legacy'];
    DB::$tables['properties'] = [['model_id' => 20, 'model_type' => \App\Models\Service::class, 'key' => 'has_stripe_subscription', 'value' => '1']];
    \Filament\Notifications\Notification::$sent = [];
    $gateway->enabled(new Extension(['id' => 2, 'enabled' => true]));
    check(Gateway::$bundledEnabled, 'Legacy Stripe subscription without an agreement prevents automatic disable');
    \App\Models\User::$staff = true;
    \Filament\Notifications\Notification::$sent = [];
    $gateway->boot();
    $warning = \Filament\Support\Facades\FilamentView::$hooks['panels::content.start'] ?? null;
    check($warning && $warning()['name'] === 'gateways.ae-stripe::gateway-warning', 'Boot installs the warning at the shared panel content hook for resource pages');
    DB::$tables['extensions'] = [
        ['id' => 28, 'deleted_at' => null], ['id' => 62, 'deleted_at' => null],
        ['id' => 63, 'deleted_at' => null], ['id' => 64, 'deleted_at' => '2026-01-01'], ['id' => 2, 'deleted_at' => null],
    ];
    Gateway::$rows = ['Stripe' => array_map(fn ($id) => new Extension(['id' => $id, 'extension' => 'Stripe', 'enabled' => $id !== 28]), [28, 62, 63, 64]),
        'AeStripe' => [new Extension(['id' => 2, 'extension' => 'AeStripe', 'enabled' => true])]];
    check(array_column($warning()['data']['gateways'], 'id') === [62, 63], 'Warning covers every enabled nondeleted core row');
    check(!$gateway->canUseGateway(20, 'USD', 'invoice', []), 'A later enabled core row still hides AeStripe from customers');
    Gateway::$rows = null;
    DB::$tables['billing_agreements'] = DB::$tables['services'] = DB::$tables['properties'] = [];
    Gateway::$bundledEnabled = true;
    \Filament\Notifications\Notification::$sent = [];
    $gateway->updated(new Extension(['id' => 2, 'enabled' => true, 'changed' => true]));
    check(!Gateway::$bundledEnabled, 'Gateway admin updated hook handles an enabled transition');
    \App\Models\User::$gatewayUpdate = true;
    Gateway::$bundledEnabled = true;
    \Filament\Notifications\Notification::$sent = [];
    $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1]));
    check(!Gateway::$bundledEnabled, 'Authorized one click action disables empty core Stripe');
    check(\Filament\Notifications\Notification::$sent === ['Core Stripe was turned off.'], 'Staff receive the native success notice for the disable action');
    Gateway::$bundledEnabled = true;
    \Filament\Notifications\Notification::$sent = [];
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 2])), 'Missing row');
    check(Gateway::$bundledEnabled && Gateway::$enabled && \Filament\Notifications\Notification::$sent === [], 'AeStripe gateway ID cannot pass the core Stripe filter');
    Gateway::$enabled = false;
    \Filament\Notifications\Notification::$sent = [];
    $beforeDisabled = DB::$tables;
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 409');
    check(Gateway::$bundledEnabled && !Gateway::$enabled && DB::$tables === $beforeDisabled && \Filament\Notifications\Notification::$sent === [], 'Disabled AeStripe refuses core disable without state changes or notice');
    Gateway::$enabled = true;
    $definition = \Illuminate\Support\Facades\Route::$definitions['/extensions/ae-stripe/disable-core'];
    check($definition['method'] === 'POST' && $definition['middleware'] === ['web', 'auth'], 'Disable action requires the native session and CSRF middleware');
    Gateway::$bundledEnabled = true;
    \App\Models\User::$gatewayUpdate = false;
    \Filament\Notifications\Notification::$sent = [];
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 403');
    check(Gateway::$bundledEnabled, 'Staff without gateway update permission cannot disable core');
    \Illuminate\Support\Facades\Auth::$id = null;
    \Filament\Notifications\Notification::$sent = [];
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 403');
    check($warning() === '', 'Guests do not see the admin warning');
    \Illuminate\Support\Facades\Auth::$id = 7;
    \App\Models\User::$staff = false;
    check($warning() === '', 'Customers do not see the admin warning');
    \App\Models\User::$staff = \App\Models\User::$gatewayUpdate = true;
    DB::$tables['billing_agreements'] = [['id' => 10, 'gateway_id' => 1, 'deleted_at' => null]];
    check($warning()['data']['gateways'][0]['hasReferences'], 'Saved cards show the adoption command instead of the disable control');
    \Filament\Notifications\Notification::$sent = [];
    rejects(fn () => $gateway->disableCore(new \Illuminate\Http\Request(['gateway_id' => 1])), 'HTTP 409');
    check(Gateway::$bundledEnabled, 'A saved card added after rendering prevents one click disable');
    Gateway::$enabled = false;
    check($warning() === '', 'Disabled AeStripe does not claim both gateways are on');
    Gateway::$enabled = true; Gateway::$bundledEnabled = false;
    check($warning() === '', 'Warning disappears when core Stripe is disabled');
    Gateway::$bundledEnabled = true;
    check($warning() !== '', 'Reenabled core Stripe restores the warning without enabling AeStripe again');
    DB::$tables['billing_agreements'] = [];
    \Filament\Notifications\Notification::$sent = [];
    $gateway->updated(new Extension(['id' => 2, 'enabled' => true, 'changed' => false]));
    check(Gateway::$bundledEnabled, 'An ordinary settings save does not silently disable reenabled core');
    check(\Filament\Notifications\Notification::$sent === [], 'An ordinary settings save sends no core disable notice');
    check(!array_filter(array_slice(Http::$calls, $calls), fn ($call) => $call[0] !== 'get'), 'Lifecycle checks reuse the existing endpoint without Stripe writes');
    Http::$handler = $handler;
    \App\Models\User::$gatewayUpdate = \App\Models\User::$staff = false;
    DB::$tables = $tables; Gateway::$enabled = $enabled; Gateway::$bundledEnabled = $bundled;
})();
