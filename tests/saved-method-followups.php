<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, User};
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB, Http, Session};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;
global $count;

$user = User::$row = new User(new Properties('cus_owner'));
Invoice::$row = new Invoice($user);
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
BillingAgreement::$snapshot = null;
DB::$tables['services'] = [];
ExtensionHelper::$payments = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test']);
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card', 'allow_redisplay' => 'limited',
    'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
$row = fn ($id, $pm) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new'), 'pm_alias' => $card('pm_alias')];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
$fail = null;
Http::$handler = function ($verb, $path, $data) use (&$methods, &$fail) {
    if ($fail === [$verb, $path]) throw new RequestException(503);
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
        if ($path !== '/payment_methods/' . $id) continue;
        if (isset($method->missing)) throw new RequestException(404, ['error' => ['code' => 'resource_missing']]);
        if ($verb === 'post') $method->allow_redisplay = $data['allow_redisplay'] ?? $method->allow_redisplay;
        return json_decode(json_encode($method));
    }
    throw new RuntimeException('Unexpected followup HTTP: ' . $path);
};
$save = fn ($id = 'pm_new', $source = 'seti_followup') => invoke($gateway, 'persistAgreement', $user, $methods[$id],
    ['source' => $source, 'user_id' => 7, 'accepted_at' => 1794071700]);
$remove = fn ($id = 'pm_old') => $gateway->cancelBillingAgreement(new BillingAgreement(ExtensionHelper::$agreements[$id]));
$case = $followupCase ?? getenv('PAY_FOLLOWUP_CASE');
if (in_array($case, ['pending', 'removed-replay'], true)) {
    $fail = ['post', '/payment_methods/pm_new'];
    try { $save(); throw new RuntimeException('Expected pending save'); } catch (RequestException $e) {}
    $fail = null;
    check($remove(), 'Removing an agreement with an unfinished incoming save succeeds');
    if ($case === 'pending') check($methods['pm_new']->customer === null, 'Removal detaches matching pending-receipt incoming methods');
    (new BillingAgreement(ExtensionHelper::$agreements['pm_old']))->delete();
    // The same saved source must remain removed even if a later operation reattaches the provider method.
    $methods['pm_new']->customer = 'cus_owner';
    $before = ExtensionHelper::$agreements;
    try { $save(); throw new RuntimeException('A removed save receipt must refuse replay'); }
    catch (\App\Exceptions\DisplayException $e) { check(str_contains($e->getMessage(), 'removed'), 'Removed pending sources refuse replay'); }
    check(ExtensionHelper::$agreements === $before, 'A removed receipt cannot recreate an agreement');
    try { $save('pm_new', 'seti_other'); throw new RuntimeException('A new source must not restore a removed reference'); }
    catch (\App\Exceptions\DisplayException $e) { check(str_contains($e->getMessage(), 'removed'), 'New sources cannot restore removed provider references'); }
    $setup = (object) ['id' => 'seti_followup', 'status' => 'succeeded', 'customer' => 'cus_owner', 'payment_method' => 'pm_new', 'latest_attempt' => null,
        'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_gateway' => '1', 'ae_stripe_setup_consent' => 'off_session_v2',
            'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1794071700']];
    $handler = Http::$handler;
    Http::$handler = fn ($verb, $path, $data) => $path === '/setup_intents/seti_followup' ? $setup : $handler($verb, $path, $data);
    check($gateway->setupAgreement(new Request(['setup_intent' => $setup->id]))['type'] === 'danger', 'A delayed Add card return reports the removed source');
    $warnings = count(\Illuminate\Support\Facades\Log::$warnings);
    check($gateway->webhook(eventRequest($setup, type: 'setup_intent.succeeded'))['status'] === 200, 'A delayed setup webhook acknowledges the removed source');
    check((bool) array_filter(array_slice(\Illuminate\Support\Facades\Log::$warnings, $warnings), fn ($warning) => str_contains($warning[1]['reason'] ?? '', 'removed')), 'The setup webhook reaches the removed-source refusal');
    $paid = (object) ['id' => 'pi_removed', 'customer' => 'cus_owner', 'payment_method' => 'pm_new', 'status' => 'succeeded',
        'amount' => 2000, 'amount_received' => 2000, 'currency' => 'usd', 'payment_method_options' => (object) ['card' => (object) ['setup_future_usage' => 'off_session']],
        'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']];
    check($gateway->webhook(eventRequest($paid))['status'] === 200 && ExtensionHelper::$agreements === $before, 'A delayed payment webhook cannot restore the removed method');
    check(count(ExtensionHelper::$payments) === 1 && (ExtensionHelper::$payments['12:pi_removed']['status'] ?? null) === 'succeeded', 'Refusing to restore the saved method still records the completed payment exactly once');
} elseif (in_array($case, ['cleanup-replay', 'cleanup-removal'], true)) {
    ExtensionHelper::$agreements['pm_new'] = $row(20, 'pm_new');
    $save('pm_alias'); // This completed receipt points at the newer local agreement, pm_new.
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0, 'Cleanup completes after a duplicate was reused');
    check(ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null, 'Cleanup retires the unbound newer copy');
    if ($case === 'cleanup-replay') {
        $save('pm_alias');
        check(count(BillingAgreement::where('user_id', 7)->get()) === 1, 'A completed save still replays against the surviving agreement');
    } else {
        check($remove(), 'The surviving agreement is removable after cleanup');
        check($methods['pm_alias']->customer === null, 'Removal still detaches the receipt-linked incoming copy after cleanup');
    }
} elseif ($case === 'missing') {
    $save();
    $methods['pm_old']->missing = true;
    check($remove(), 'A clicked method confirmed missing still removes locally');
    check($methods['pm_new']->customer === null, 'A stored verified identity allows removal of matching siblings after clicked 404');
} elseif ($case === 'warning') {
    $methods['pm_old']->missing = true;
    check($remove(), 'Missing clicked method without a verified identity remains locally removable');
    check(str_contains(Session::$flashes['notification']['message'] ?? '', 'other copies'), 'Incomplete copy removal warns the customer');
} elseif ($case === 'index') {
    $save();
    for ($i = 0; $i < 12; $i++) {
        $id = 'pm_unrelated_' . $i;
        $methods[$id] = $card($id);
        $methods[$id]->card->fingerprint = 'fp_' . $i;
        $user->props->values['ae_stripe_saved_' . $i] = json_encode(['incoming' => $id, 'reference' => 'pm_old', 'consent' => ['user_id' => 7]]);
        $user->props->values['ae_stripe_method_' . $id] = json_encode(['customer' => 'cus_owner', 'gateway_id' => 2,
            'identity' => hash('sha256', 'card:fp_' . $i)]);
    }
    $before = count(Http::$calls);
    $save('pm_alias', 'seti_fresh');
    check(!array_filter(array_slice(Http::$calls, $before), fn ($c) => str_contains($c[1], 'pm_unrelated_')),
        'Verified unrelated receipt-only methods add no Stripe reads or writes');
    $unknown = 'pm_unknown';
    $methods[$unknown] = $card($unknown);
    $user->props->values['ae_stripe_saved_unknown'] = json_encode(['incoming' => $unknown, 'consent' => ['user_id' => 7]]);
    $before = count(Http::$calls);
    $save('pm_alias', 'seti_unknown');
    check(count(array_filter(array_slice(Http::$calls, $before), fn ($c) => $c[1] === '/payment_methods/' . $unknown)) === 2,
        'Unknown receipt-only identity is remotely verified and matching copies are refreshed');
} elseif ($case === 'cleanup-lock') {
    ExtensionHelper::$agreements['pm_new'] = $row(20, 'pm_new');
    $handler = Http::$handler;
    $entered = false;
    Http::$handler = function ($verb, $path, $data) use ($handler, &$entered, $save) {
        if (!$entered && $verb === 'post') {
            $entered = true;
            $before = count(Http::$calls);
            try { $save('pm_alias'); throw new RuntimeException('Cleanup must exclude concurrent saves'); }
            catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(count(Http::$calls) === $before, 'Cleanup excludes a concurrent save before provider work'); }
        }
        return $handler($verb, $path, $data);
    };
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $entered, 'Cleanup lock regression exercises provider writes');
} elseif (in_array($case, ['cleanup-setup', 'cleanup-checkout'], true)) {
    ExtensionHelper::$agreements['pm_new'] = $row(20, 'pm_new');
    $methods['pm_old']->allow_redisplay = 'unspecified';
    $setup = (object) ['id' => 'seti_cleanup', 'status' => 'succeeded', 'customer' => 'cus_owner', 'payment_method' => 'pm_alias', 'latest_attempt' => null,
        'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v2',
            'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1794071700']];
    $paid = (object) ['id' => 'pi_cleanup', 'customer' => 'cus_owner', 'payment_method' => 'pm_alias',
        'payment_method_options' => (object) ['card' => (object) ['setup_future_usage' => 'off_session']],
        'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']];
    $saveEntry = $case === 'cleanup-setup' ? fn () => $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]))
        : fn () => invoke($gateway, 'savePaidMethod', $paid);
    $handler = Http::$handler;
    $entered = false;
    Http::$handler = function ($verb, $path, $data) use ($handler, $setup, $saveEntry, &$entered, $case) {
        if ($path === '/setup_intents/seti_cleanup') return $setup;
        if (!$entered && $verb === 'post' && $path === '/payment_methods/pm_old') {
            $entered = true;
            $before = count(Http::$calls);
            if ($case === 'cleanup-setup') check(($saveEntry()['type'] ?? null) === 'danger', 'Cleanup makes an interleaved Add card return retryable');
            else {
                try { $saveEntry(); throw new RuntimeException('Cleanup must refuse interleaved checkout saving'); }
                catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(true, 'Cleanup makes checkout saving retryable'); }
            }
            check(!array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[0] === 'post'), 'An interleaved save sends no writes before cleanup finishes');
        }
        return $handler($verb, $path, $data);
    };
    check($gateway->deduplicateCards(true, fn ($line) => null) === 0 && $entered, 'The cleanup/save ordering scenario reaches the stale redisplay write');
    $saveEntry();
    check($methods['pm_old']->allow_redisplay === 'always', 'After cleanup, retrying the save retains always redisplay');
} elseif ($case === 'cache') {
    $GLOBALS['fakeConfig']['cache.default'] = 'array';
    $GLOBALS['fakeConfig']['cache.stores.array.driver'] = 'array';
    $before = count(Http::$calls);
    try { $save(); throw new RuntimeException('An isolated cache must refuse saving'); }
    catch (\Paymenter\Extensions\Gateways\AeStripe\SavedMethodRetryException $e) { check(str_contains($e->getMessage(), 'shared'), 'Unsupported caches explain the shared store requirement'); }
    check(!$remove() && $gateway->deduplicateCards(true, fn ($line) => null) > 0, 'Removal and cleanup refuse the same isolated cache');
    check(count(Http::$calls) === $before, 'Isolated-cache refusal precedes all provider reads and writes');
    $GLOBALS['fakeConfig']['cache.default'] = 'database';
    unset($GLOBALS['fakeConfig']['cache.stores.array.driver']);
} elseif ($case === 'link') {
    foreach ($methods as $method) { $method->type = 'link'; $method->link = (object) ['email' => 'Same@Example.com']; unset($method->card); }
    foreach (['pm_other' => 'other@example.com', 'pm_unknown' => null] as $id => $email) {
        $methods[$id] = (object) ['id' => $id, 'type' => 'link', 'customer' => 'cus_owner', 'link' => (object) ['email' => $email]];
    }
    foreach (['pm_other' => 21, 'pm_unknown' => 22] as $id => $number) ExtensionHelper::$agreements[$id] = array_replace($row($number, $id), ['type' => 'link']);
    ExtensionHelper::$agreements['pm_old']['type'] = 'link';
    ExtensionHelper::$agreements['pm_new'] = array_replace($row(20, 'pm_new'), ['type' => 'link']);
    $save('pm_alias');
    $fail = ['post', '/payment_methods/pm_alias/detach'];
    check(!$remove('pm_new') && ExtensionHelper::$agreements['pm_old']['deleted_at'] === null, 'Failed Link removal retains local agreements for retry');
    $fail = null;
    check($remove('pm_new'), 'Link copy removal succeeds on retry');
    check($methods['pm_old']->customer === null && $methods['pm_new']->customer === null && $methods['pm_alias']->customer === null,
        'Link removal detaches both local and receipt-only matching account copies');
    check($methods['pm_other']->customer === 'cus_owner' && $methods['pm_unknown']->customer === 'cus_owner', 'Different or unknown Link accounts are untouched');
} elseif ($case === 'version') {
    $meta = (new ReflectionClass(AeStripe::class))->getAttributes(\App\Attributes\ExtensionMeta::class)[0]->getArguments();
    check(version_compare($meta['version'], '0.1.1', '>'), 'A schema-changing update advertises a newer extension version');
} elseif ($case === 'terms') {
    $view = file_get_contents(__DIR__ . '/../extension/AeStripe/resources/views/billing-agreement.blade.php');
    check(str_contains($view, '>Terms of Service</a>'), 'The Add card agreement names Terms of Service');
} elseif ($case === 'notices') {
    $intent = (object) ['id' => 'pi_followup', 'customer' => 'cus_owner', 'payment_method' => 'pm_new', 'status' => 'succeeded',
        'payment_method_options' => (object) ['card' => (object) ['setup_future_usage' => 'off_session']],
        'metadata' => (object) ['invoice_id' => 12, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']];
    invoke($gateway, 'saveConsentedMethod', $intent);
    check((Session::$flashes['notification']['type'] ?? null) === 'success'
        && str_contains(Session::$flashes['notification']['message'] ?? '', 'already saved'), 'Checkout reuse notice uses the native notification handler');
    $expected = Session::$flashes['notification'];
    $setup = (object) ['id' => 'seti_followup', 'status' => 'succeeded', 'customer' => 'cus_owner', 'payment_method' => 'pm_new', 'latest_attempt' => null,
        'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v2',
            'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1794071700']];
    $handler = Http::$handler;
    Http::$handler = fn ($verb, $path, $data) => $path === '/setup_intents/seti_followup' ? $setup : $handler($verb, $path, $data);
    check($gateway->setupAgreement(new Request(['setup_intent' => 'seti_followup'])) === $expected, 'Add card uses the same native notification shape as checkout');
}
echo "PASS: $case, $count followup assertions\n";
