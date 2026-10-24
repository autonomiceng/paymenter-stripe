<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Properties, User};
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Log};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

if (!function_exists('check')) {
    require __DIR__ . '/fakes.php';
    require __DIR__ . '/../extension/AeStripe/AeStripe.php';
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
}
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
BillingAgreement::$snapshot = null;
DB::$tables['services'] = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$row = fn ($id, $method) => ['id' => $id, 'external_reference' => $method, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
    'allow_redisplay' => 'unspecified', 'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa',
        'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
$setup = (object) ['id' => 'seti_candidates', 'status' => 'succeeded', 'customer' => 'cus_owner',
    'payment_method' => 'pm_new', 'latest_attempt' => null, 'metadata' => (object) [
        'user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v1',
        'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1700000000',
    ]];
$scenario = getenv('DEDUPE_CANDIDATE_CASE') ?: 'all';
foreach (['setup', 'cleanup', 'server failure', 'receipt'] as $case) {
    if ($scenario !== 'all' && $scenario !== $case) continue;
    $user = User::$row = new User(new Properties('cus_owner'));
    ExtensionHelper::$agreements = ['pm_missing' => $row(19, 'pm_missing'), 'pm_kept' => $row(20, 'pm_kept')];
    $methods = ['pm_kept' => $card('pm_kept'), 'pm_new' => $card('pm_new')];
    if ($case === 'cleanup') ExtensionHelper::$agreements['pm_new'] = $row(21, 'pm_new');
    if ($case === 'receipt') {
        $user->props->values['ae_stripe_duplicate_pm_new'] = json_encode([
            'customer' => 'cus_owner', 'gateway' => 2, 'kept' => 'pm_missing',
        ]);
    }
    Http::$handler = function ($verb, $path, $data) use ($case, $setup, &$methods) {
        if ($path === '/setup_intents/seti_candidates') return $setup;
        if ($verb === 'get' && $path === '/payment_methods/pm_missing') {
            throw new RequestException($case === 'server failure' ? 503 : 404, ['error' => ['code' => 'resource_missing']]);
        }
        foreach ($methods as $id => $method) {
            if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
            if ($path !== '/payment_methods/' . $id) continue;
            if ($verb === 'post') $method->allow_redisplay = $data['allow_redisplay'];
            return $method;
        }
        throw new RuntimeException('Unexpected candidate request');
    };
    $start = count(Http::$calls);
    $warnings = count(Log::$warnings);
    if ($case === 'cleanup') {
        $lines = [];
        $skipped = $gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; });
        check(in_array('Agreement #21 duplicates #20 (customer #7).', $lines)
            && ExtensionHelper::$agreements['pm_new']['deleted_at'] !== null && $methods['pm_new']->customer === null,
            'Cleanup finds a later duplicate after a missing older candidate');
        check($skipped === 1 && ExtensionHelper::$agreements['pm_kept']['deleted_at'] === null,
            'Cleanup skips only the missing agreement and retains the matching card');
    } elseif ($case === 'server failure') {
        $before = ExtensionHelper::$agreements;
        try {
            $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
            throw new RuntimeException('A candidate server failure must propagate');
        } catch (RequestException $e) {
            check($e->response->status() === 503 && ExtensionHelper::$agreements === $before,
                'A candidate server failure propagates without changing agreements');
        }
        check(!array_filter(array_slice(Http::$calls, $start), fn ($call) => $call[0] !== 'get'),
            'A candidate server failure authorizes no Stripe writes');
        ExtensionHelper::$agreements['pm_new'] = $row(21, 'pm_new');
        $lines = [];
        check($gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; }) === 3,
            'Cleanup reports each row affected by a candidate server failure');
        check(!array_filter($lines, fn ($line) => str_contains($line, 'duplicates'))
            && ExtensionHelper::$agreements['pm_new']['deleted_at'] === null,
            'Cleanup never treats an incomplete candidate scan as a duplicate match');
        continue;
    } else {
        $result = $gateway->setupAgreement(new Request(['setup_intent' => $setup->id]));
        if ($case === 'receipt') {
            check($result['message'] === 'Payment method added successfully.' && isset(ExtensionHelper::$agreements['pm_new']),
                'A missing retained receipt method still uses the setup fallback');
            check(!array_filter(array_slice(Http::$calls, $start), fn ($call) => str_ends_with($call[1], '/detach')),
                'A missing retained receipt method never authorizes a detach');
        } else {
            check($result['message'] === 'This card is already saved.' && !isset(ExtensionHelper::$agreements['pm_new'])
                && $methods['pm_new']->customer === null, 'Setup finds a later duplicate after a missing older candidate');
            check(ExtensionHelper::$agreements['pm_kept']['deleted_at'] === null && $methods['pm_kept']->customer === 'cus_owner',
                'Setup retains the later matching agreement');
        }
    }
    $candidateWarnings = array_filter(array_slice(Log::$warnings, $warnings), fn ($warning) => $warning[0] === 'ae_stripe_duplicate_candidate_missing');
    check($candidateWarnings && !array_filter($candidateWarnings, fn ($warning) => $warning[1] !== ['error' => RequestException::class]),
        'Missing candidate warnings contain only a fixed code and exception class');
}
echo "PASS: missing duplicate candidates, server failures and receipt fallback\n";
