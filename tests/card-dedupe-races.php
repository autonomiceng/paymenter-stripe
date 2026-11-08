<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

if (!function_exists('check')) {
    require __DIR__ . '/fakes.php';
    require __DIR__ . '/../extension/AeStripe/AeStripe.php';
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
}
$user = User::$row = new User(new Properties('cus_owner'));
Invoice::$row = new Invoice($user);
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
BillingAgreement::$snapshot = null;
DB::$tables['services'] = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
    'allow_redisplay' => 'unspecified', 'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa',
        'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
$row = fn ($id, $pm) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$setup = (object) ['id' => 'seti_races', 'status' => 'succeeded', 'customer' => 'cus_owner', 'payment_method' => 'pm_new',
    'latest_attempt' => null, 'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1',
        'ae_stripe_setup_consent' => 'off_session_v1', 'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1700000000']];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$handler = function ($verb, $path, $data) use (&$methods, $setup) {
    if ($path === '/setup_intents/seti_races') return $setup;
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
        if ($path !== '/payment_methods/' . $id) continue;
        if ($verb === 'post') $method->allow_redisplay = $data['allow_redisplay'];
        return $method;
    }
    throw new RuntimeException('Unexpected race request: ' . $path);
};
Http::$handler = $handler;
// A removed comparison agreement cannot be written through a stale plan.
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
DB::$afterTransaction = function () { ExtensionHelper::$agreements['pm_old']['deleted_at'] = '2026-10-23 13:00:00'; };
$result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
check($result['type'] === 'danger' && $result['message'] === 'Saved payment methods changed. Try again.', 'A changed agreement set shows a retry notice');
$gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
check($methods['pm_new']->customer === 'cus_owner' && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
    'Retry preserves the current agreement and provider reference');

// A second save cannot interleave provider writes while the first holds the save lock.
$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$entered = false;
Http::$handler = function ($verb, $path, $data) use ($handler, $gateway, &$entered) {
    if ($verb === 'post' && !$entered) {
        $entered = true;
        $before = count(Http::$calls);
        try { invoke($gateway, 'persistAgreement', User::$row, (object) ['id' => 'pm_new']); throw new RuntimeException('Expected lock contention'); }
        catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(str_contains($e->getMessage(), 'in progress'), 'Concurrent saving stays retryable'); }
        check(count(Http::$calls) === $before, 'Lock contention never starts provider work');
    }
    return $handler($verb, $path, $data);
};
$gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
check($entered && $methods['pm_old']->allow_redisplay === 'always', 'The serialized save completes its redisplay consent');
Http::$handler = $handler;

foreach (['incoming user', 'incoming gateway', 'incoming removed', 'incoming bound', 'retained user', 'retained gateway', 'method customer'] as $case) {
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $user->props->values = [];
    DB::$tables['services'] = [];
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
    DB::$afterTransaction = function () use ($case, &$methods) {
        switch ($case) {
            case 'incoming user': ExtensionHelper::$agreements['pm_new']['user_id'] = 99; break;
            case 'incoming gateway': ExtensionHelper::$agreements['pm_new']['gateway_id'] = 1; break;
            case 'incoming removed': ExtensionHelper::$agreements['pm_new']['deleted_at'] = '2026-10-23'; break;
            case 'incoming bound': DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 20]]; break;
            case 'retained user': ExtensionHelper::$agreements['pm_old']['user_id'] = 99; break;
            case 'retained gateway': ExtensionHelper::$agreements['pm_old']['gateway_id'] = 1; break;
            case 'method customer': $methods['pm_new']->customer = 'cus_other'; break;
        }
    };
    $start = count(Http::$calls);
    try { $gateway->setupAgreement(new Request(['setup_intent' => $setup->id])); }
    catch (RuntimeException $e) { check(in_array($e->getMessage(), ['HTTP 409', 'Saved payment methods changed. Try again.'], true), 'Only an expected concurrent change is retryable'); }
    check(!array_filter(array_slice(Http::$calls, $start), fn ($call) => str_ends_with($call[1], '/detach')),
        "Setup preserves provider references after $case");
    if ($case === 'incoming bound') check(DB::$tables['services'][0]['billing_agreement_id'] === 20, 'A concurrent service choice is retained');
}

$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$stale = new BillingAgreement($row(19, 'pm_old'));
ExtensionHelper::$agreements = ['pm_old' => array_replace($row(19, 'pm_old'), ['user_id' => 99])];
$gateway->cancelBillingAgreement($stale);
check($methods['pm_old']->customer === 'cus_owner', 'Cancellation revalidates agreement ownership before detaching');
DB::$tables['services'] = [];
echo "PASS: saved method ownership, concurrent changes and serialized provider writes\n";
