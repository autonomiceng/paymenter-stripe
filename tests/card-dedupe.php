<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Invoice, Properties, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

$user = User::$row = new User(new Properties('cus_owner'));
Invoice::$row = new Invoice($user);
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
BillingAgreement::$snapshot = [];
DB::$tables['services'] = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$card = fn ($id, $fingerprint = 'fp_same', $month = 5) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
    'allow_redisplay' => 'unspecified', 'card' => (object) ['fingerprint' => $fingerprint, 'brand' => 'visa',
        'last4' => '4242', 'exp_month' => $month, 'exp_year' => 2030]];
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$setup = (object) ['id' => 'seti_dedupe', 'status' => 'succeeded', 'customer' => 'cus_owner', 'payment_method' => 'pm_new',
    'latest_attempt' => null, 'metadata' => (object) ['user_id' => '7', 'ae_stripe_gateway' => '1', 'is_billing_agreement' => '1',
        'ae_stripe_setup_consent' => 'off_session_v1', 'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1700000000']];
Http::$handler = function ($verb, $path, $data) use (&$methods, $setup) {
    if ($path === '/setup_intents/seti_dedupe') return $setup;
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
        if ($path !== '/payment_methods/' . $id) continue;
        if ($verb === 'post') $method->allow_redisplay = $data['allow_redisplay'];
        return $method;
    }
    throw new RuntimeException('Unexpected dedupe request: ' . $path);
};
$row = fn ($id, $pm, $extra = []) => $extra + ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
$result = $gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
check($result['message'] === 'This card is already saved.' && count(ExtensionHelper::$agreements) === 1,
    'A confirmed duplicate setup retains one saved card and shows one notice');
check($methods['pm_new']->customer === null && $methods['pm_old']->allow_redisplay === 'always',
    'Duplicate setup detaches the new method and broadens redisplay on the retained method');
$result = $gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
check($result['message'] === 'This card is already saved.' && count(ExtensionHelper::$agreements) === 1,
    'Webhook or redirect retries recognize the detached duplicate without another agreement');
BillingAgreement::$snapshot = null;

// Exercise the public command using configured gateway settings, like Paymenter's console lifecycle.
Gateway::$rows = ['AeStripe' => [new \App\Models\Extension(['id' => 2, 'settings' => ['stripe_secret_key' => 'fake-secret']])]];
DB::$tables['extensions'] = [['id' => 2, 'deleted_at' => null]];
$methods['pm_new'] = $card('pm_new');
$methods['pm_old']->allow_redisplay = 'limited';
$methods['pm_new']->allow_redisplay = 'always';
$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
$before = serialize([ExtensionHelper::$agreements, $user->props->values, $methods]);
$start = count(Http::$calls);
$command = new \Paymenter\Extensions\Gateways\AeStripe\DeduplicateCardsCommand;
check($command->handle() === 0 && str_contains(implode(' ', $command->output), '#20 duplicates #19'),
    'Cleanup dry run lists duplicate agreement IDs');
check(serialize([ExtensionHelper::$agreements, $user->props->values, $methods]) === $before
    && !array_filter(array_slice(Http::$calls, $start), fn ($call) => $call[0] !== 'get'), 'Cleanup defaults to read-only dry run');
check(Http::$calls[$start][3]['Authorization'] === 'Bearer fake-secret', 'Cleanup uses the installed gateway credentials');
$command->options = ['apply' => true];
check($command->handle() === 0 && ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null
    && $methods['pm_new']->customer === null && $methods['pm_old']->allow_redisplay === 'always',
    'Cleanup apply removes only the unbound duplicate and preserves broader redisplay');
check($command->handle() === 0, 'Cleanup apply can be repeated');
Gateway::$rows = null;

// Imported duplicates with service bindings must be visible in the dry run and retained on apply.
$methods['pm_new'] = $card('pm_new');
$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 20, 'auto_pay' => false]];
$serviceBefore = DB::$tables['services'];
$lines = [];
$gateway->deduplicateCards(false, function ($line) use (&$lines) { $lines[] = $line; });
check(str_contains(implode(' ', $lines), 'bound to a service'), 'Cleanup lists service-bound duplicates with the reason they stay');
$gateway->deduplicateCards(true, fn ($line) => null);
check(ExtensionHelper::$agreements['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_owner'
    && DB::$tables['services'] === $serviceBefore, 'Cleanup preserves bound duplicates and every service preference');

DB::$tables['services'] = [];
foreach (['fingerprint', 'month', 'year', 'missing fingerprint', 'other user', 'other gateway', 'removed', 'other customer'] as $case) {
    $methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
    $user->props->values = [];
    $old = $row(19, 'pm_old');
    switch ($case) {
        case 'fingerprint': $methods['pm_new']->card->fingerprint = 'fp_other'; break;
        case 'month': $methods['pm_new']->card->exp_month = 6; break;
        case 'year': $methods['pm_new']->card->exp_year = 2031; break;
        case 'missing fingerprint': unset($methods['pm_new']->card->fingerprint); break;
        case 'other user': $old['user_id'] = 99; break;
        case 'other gateway': $old['gateway_id'] = 1; break;
        case 'removed': $old['deleted_at'] = '2026-10-22'; break;
        case 'other customer': $methods['pm_old']->customer = 'cus_other'; break;
    }
    ExtensionHelper::$agreements = ['pm_old' => $old];
    $gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
    check(count(ExtensionHelper::$agreements) === 2 && $methods['pm_new']->customer === 'cus_owner',
        "Setup keeps separate methods for $case");
}
// A service using the retained card keeps its agreement ID and Auto Pay state.
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 19, 'auto_pay' => true]];
$serviceBefore = DB::$tables['services'];
$handler = Http::$handler;
$lostDetach = true;
Http::$handler = function ($verb, $path, $data) use ($handler, &$lostDetach) {
    $result = $handler($verb, $path, $data);
    if (str_ends_with($path, '/detach') && $lostDetach) {
        $lostDetach = false;
        throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost detach response');
    }
    return $result;
};
try {
    $gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
    throw new RuntimeException('Lost detach response must remain retryable');
} catch (\Illuminate\Http\Client\ConnectionException $e) {
    check(count(ExtensionHelper::$agreements) === 1, 'Uncertain detach never creates another saved agreement');
}
$result = $gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
check($result['message'] === 'This card is already saved.' && DB::$tables['services'] === $serviceBefore,
    'Retry after a lost detach response preserves the retained agreement and its service settings');
Http::$handler = $handler;

// Receipts cannot authorize detaching a method that has moved to another customer.
DB::$tables['services'] = [];
ExtensionHelper::$agreements['pm_new'] = $row(20, 'pm_new');
$methods['pm_new']->customer = 'cus_other';
$gateway->deduplicateCards(true, fn ($line) => null);
check(ExtensionHelper::$agreements['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_other',
    'Cleanup refuses a receipt when the duplicate now belongs to another customer');

// A setup retry must keep an imported duplicate that already has a service.
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 20, 'auto_pay' => false]];
$beforeLogs = count(\Illuminate\Support\Facades\Log::$lines);
$gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
check(count(ExtensionHelper::$agreements) === 2 && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null
    && $methods['pm_new']->customer === 'cus_owner' && count(\Illuminate\Support\Facades\Log::$lines) === $beforeLogs + 1,
    'Setup retains and logs a duplicate already bound to a service');

// Cleanup resumes after Stripe detaches a card but the response is lost.
DB::$tables['services'] = [];
$methods['pm_old']->allow_redisplay = 'always';
$user->props->values = [];
$lostDetach = true;
Http::$handler = function ($verb, $path, $data) use ($handler, &$lostDetach) {
    $result = $handler($verb, $path, $data);
    if (str_ends_with($path, '/detach') && $lostDetach) {
        $lostDetach = false;
        throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost detach response');
    }
    return $result;
};
check($gateway->deduplicateCards(true, fn ($line) => null) === 1,
    'Cleanup reports an agreement skipped after an uncertain detach');
check(ExtensionHelper::$agreements['pm_new']['deleted_at'] === null, 'Cleanup rolls back local removal on an uncertain detach');
$gateway->deduplicateCards(true, fn ($line) => null);
check(ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null && $methods['pm_old']->allow_redisplay === 'always',
    'Cleanup resumes a detached method without reducing the retained consent');
Http::$handler = $handler;

// Validate agreement ownership again if a service is linked between cleanup phases.
$methods['pm_new'] = $card('pm_new');
$user->props->values = [];
ExtensionHelper::$agreements['pm_new'] = $row(20, 'pm_new');
$gateway->deduplicateCards(true, function ($line) {
    if (!str_contains($line, 'duplicates')) return;
    DB::$beforeUserLock = function () { DB::$tables['services'] = [['id' => 41, 'billing_agreement_id' => 20]]; };
});
check(ExtensionHelper::$agreements['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_owner',
    'Cleanup rechecks a service binding added before apply');
BillingAgreement::$snapshot = null;

$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$user->props->values = [];
DB::$tables['services'] = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_new' => $row(20, 'pm_new')];
$setup->payment_method = 'pm_old';
$gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
check(ExtensionHelper::$agreements['pm_old']['deleted_at'] === null && $methods['pm_old']->customer === 'cus_owner',
    'Retrying the oldest saved method never replaces it with a newer duplicate');
$setup->payment_method = 'pm_new';

// A previous duplicate receipt never revives a removed retained agreement.
$methods = ['pm_old' => $card('pm_old'), 'pm_new' => $card('pm_new')];
$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
$gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
ExtensionHelper::$agreements['pm_old']['deleted_at'] = '2026-10-23 13:00:00';
$result = $gateway->setupAgreement(new Request(['setup_intent' => 'seti_dedupe']));
check($result['type'] === 'danger' && str_contains($result['message'], 'removed')
    && count(ExtensionHelper::$agreements) === 1 && ExtensionHelper::$agreements['pm_old']['deleted_at'] !== null,
    'Duplicate setup receipts preserve removal of the retained agreement');
echo "PASS: saved-card scope, service bindings, cleanup and retry recovery\n";
