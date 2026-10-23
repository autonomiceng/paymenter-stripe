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
$scenario = getenv('DEDUPE_RACE') ?: 'all';
if (in_array($scenario, ['all', 'retained-removed'])) {
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
    DB::$afterTransaction = function () { ExtensionHelper::$agreements['pm_old']['deleted_at'] = '2026-10-23 13:00:00'; };
    $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races']));
    check($methods['pm_new']->customer === 'cus_owner' && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
        'Setup preserves the incoming saved card when the retained agreement is removed before finalization');
    echo "PASS: retained-removed\n";
}
if (in_array($scenario, ['all', 'detach-failure'])) {
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $user->props->values = [];
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
    Http::$handler = function ($verb, $path, $data) use ($handler) {
        if (str_ends_with($path, '/detach')) throw new \Illuminate\Http\Client\ConnectionException('Synthetic detach failure');
        return $handler($verb, $path, $data);
    };
    try {
        $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races']));
        throw new RuntimeException('Detach failure must remain retryable');
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        check($methods['pm_new']->customer === 'cus_owner' && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
            'Setup keeps the incoming agreement when detach fails before any remote effect');
        check(isset($user->props->values['ae_stripe_duplicate_pm_new']), 'Detach failure retains the committed retry receipt');
    }
    Http::$handler = $handler;
    echo "PASS: detach-failure\n";
}

// Pause an unlocked consent request while cleanup reads the previous Stripe value.
// Requests holding the user lock finish first; cleanup then runs after commit.
foreach (['setup', 'checkout'] as $entry) {
    if (!in_array($scenario, ['all', 'redisplay-' . $entry])) continue;
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $methods['pm_new']->allow_redisplay = 'limited';
    $user->props->values = [];
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
    $pendingAlways = false;
    $cleanup = fn () => $gateway->deduplicateCards(true, fn ($line) => null);
    Http::$handler = function ($verb, $path, $data) use ($handler, &$methods, &$pendingAlways, $cleanup, $user) {
        if ($verb === 'post' && $path === '/payment_methods/pm_old' && $data['allow_redisplay'] === 'always') {
            if (DB::$userLocked) {
                DB::$afterTransaction = $cleanup;
                return $handler($verb, $path, $data);
            }
            $pendingAlways = true;
            $cleanup();
            $pendingAlways = false;
            return $methods['pm_old'];
        }
        if ($verb === 'get' && $path === '/payment_methods/pm_old' && $pendingAlways
            && isset($user->props->values['ae_stripe_duplicate_pm_new'])) {
            $stale = clone $methods['pm_old'];
            $handler('post', $path, ['allow_redisplay' => 'always']);
            return $stale;
        }
        return $handler($verb, $path, $data);
    };
    if ($entry === 'setup') {
        $setup->payment_method = 'pm_old';
        $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races']));
        $setup->payment_method = 'pm_new';
    } else {
        $paid = (object) ['id' => 'pi_races', 'status' => 'succeeded', 'customer' => 'cus_owner', 'currency' => 'usd',
            'amount' => 2000, 'amount_received' => 2000, 'payment_method' => 'pm_old',
            'payment_method_options' => (object) ['card' => (object) ['setup_future_usage' => 'off_session']],
            'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1']];
        $body = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => $paid]]);
        $time = time();
        $webhook = new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test']);
        $webhook->webhook(new Request(body: $body,
            signature: 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, 'whsec_test')));
    }
    check($methods['pm_old']->allow_redisplay === 'always', "$entry consent survives cleanup's interleaved retained read and update");
    Http::$handler = $handler;
    echo "PASS: redisplay-$entry\n";
}

if (in_array($scenario, ['all', 'cancel-stale'])) {
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $stale = new BillingAgreement($row(19, 'pm_old'));
    ExtensionHelper::$agreements = ['pm_old' => array_replace($row(19, 'pm_old'), ['user_id' => 99])];
    $gateway->cancelBillingAgreement($stale);
    check($methods['pm_old']->customer === 'cus_owner', 'Cancellation revalidates agreement ownership before detaching');
    echo "PASS: cancel-stale\n";
}

if (in_array($scenario, ['all', 'finalization-boundaries'])) {
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
        try { $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races'])); }
        catch (RuntimeException $e) { if ($case !== 'method customer' || $e->getMessage() !== 'HTTP 409') throw $e; }
        check(!array_filter(array_slice(Http::$calls, $start), fn ($call) => str_ends_with($call[1], '/detach')),
            "Setup revalidates $case through finalization");
    }
    DB::$tables['services'] = [];
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $user->props->values = [];
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
    $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races']));
    $result = $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races']));
    check($result['message'] === 'This card is already saved.', 'Setup retries a successfully removed imported duplicate');
    echo "PASS: finalization-boundaries\n";
}

if (in_array($scenario, ['all', 'save-boundaries'])) {
    $methods = ['pm_new' => $card('pm_new')];
    ExtensionHelper::$agreements = [];
    $user->props->values = [];
    DB::$beforeUserLock = function () use (&$methods) { $methods['pm_new']->customer = 'cus_other'; };
    try { $gateway->setupAgreement(new Request(['setup_intent' => 'seti_races'])); }
    catch (RuntimeException $e) { if ($e->getMessage() !== 'HTTP 409') throw $e; }
    DB::$beforeUserLock = null;
    check(!isset(ExtensionHelper::$agreements['pm_new']), 'Saving refreshes method ownership after acquiring the user lock');
    Http::$handler = function ($verb, $path, $data) use ($handler, $setup) {
        if ($path === '/customers/cus_owner') {
            check(DB::$userLocked, 'Customer lookup and replacement use the agreement owner lock');
            return ['id' => 'cus_owner'];
        }
        if ($path === '/setup_intents') return $setup;
        return $handler($verb, $path, $data);
    };
    $gateway->createBillingAgreement($user);
    Http::$handler = $handler;
    echo "PASS: save-boundaries\n";
}
