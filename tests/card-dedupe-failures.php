<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Gateway, Properties, User};
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\{DB, Http, Log};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;
use Paymenter\Extensions\Gateways\AeStripe\DeduplicateCardsCommand;

if (!function_exists('check')) {
    require __DIR__ . '/fakes.php';
    require __DIR__ . '/../extension/AeStripe/AeStripe.php';
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
}
$user = User::$row = new User(new Properties('cus_owner'));
Gateway::$rows = null;
Gateway::$enabled = Gateway::$present = true;
Gateway::$deleted = false;
BillingAgreement::$snapshot = null;
DB::$tables['services'] = [];
$gateway = new AeStripe(['stripe_secret_key' => 'fake-secret']);
$row = fn ($id, $pm) => ['id' => $id, 'external_reference' => $pm, 'user_id' => 7, 'gateway_id' => 2,
    'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-05-31', 'deleted_at' => null];
ExtensionHelper::$agreements = ['pm_cancel' => $row(19, 'pm_cancel')];
foreach ([404, 503] as $status) {
    Http::$handler = function ($verb, $path) use ($status) {
        if ($verb === 'get' && $path === '/payment_methods/pm_cancel') throw new RequestException($status);
        throw new RuntimeException('Unexpected cancellation request');
    };
    $start = count(Http::$calls);
    $warnings = count(Log::$warnings);
    check($gateway->cancelBillingAgreement(new BillingAgreement($row(19, 'pm_cancel'))) === true,
        "Cancellation proceeds locally after a Stripe $status read failure");
    check(count(Http::$calls) === $start + 1 && Http::$calls[$start][0] === 'get',
        "Cancellation never detaches after a Stripe $status read failure");
    check(count(Log::$warnings) === $warnings + 1 && end(Log::$warnings) === [
        'ae_stripe_cancel_method_read_failed', ['error' => RequestException::class],
    ], 'Cancellation read warnings contain only a fixed code and exception class');
}
Http::$handler = function ($verb, $path) {
    if ($verb === 'get' && $path === '/payment_methods/pm_cancel') return ['customer' => 'cus_other'];
    throw new RuntimeException('Unexpected cancellation request');
};
$start = count(Http::$calls);
check($gateway->cancelBillingAgreement(new BillingAgreement($row(19, 'pm_cancel'))) === false,
    'Cancellation refuses a method belonging to another customer');
check(count(Http::$calls) === $start + 1 && Http::$calls[$start][0] === 'get',
    'Cancellation never detaches a method belonging to another customer');
echo "PASS: cancellation read failures and customer binding\n";

require_once __DIR__ . '/../extension/AeStripe/DeduplicateCardsCommand.php';
Gateway::$rows = ['AeStripe' => [new \App\Models\Extension(['id' => 2, 'settings' => ['stripe_secret_key' => 'fake-secret']])]];
DB::$tables['extensions'] = [['id' => 2, 'deleted_at' => null]];
$card = fn ($id) => (object) ['id' => $id, 'customer' => 'cus_owner', 'type' => 'card',
    'allow_redisplay' => 'unspecified', 'card' => (object) ['fingerprint' => 'fp_same', 'brand' => 'visa',
        'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
foreach (['read 404', 'read 503', 'retained read', 'finalize read', 'removed receipt', 'detach'] as $case) {
    foreach ([false, true] as $apply) {
        if (!$apply && in_array($case, ['finalize read', 'detach'])) continue;
        $user->props->values = [];
        ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_before' => $row(20, 'pm_before'),
            'pm_failed' => $row(21, 'pm_failed'), 'pm_after' => $row(22, 'pm_after')];
        $methods = array_map($card, array_combine(array_keys(ExtensionHelper::$agreements), array_keys(ExtensionHelper::$agreements)));
        if ($case === 'removed receipt') {
            $methods['pm_failed']->customer = null;
            $user->props->values['ae_stripe_duplicate_pm_failed'] = json_encode([
                'customer' => 'cus_owner', 'gateway' => 2, 'kept' => 'pm_removed',
            ]);
        }
        $failedBefore = serialize(ExtensionHelper::$agreements['pm_failed']);
        $current = null;
        $failedReads = 0;
        Http::$handler = function ($verb, $path, $data) use (&$methods, &$current, &$failedReads, $case) {
            foreach ($methods as $id => $method) {
                if ($path === '/payment_methods/' . $id . '/detach') {
                    if ($id === 'pm_failed' && $case === 'detach') throw new RequestException(503);
                    $method->customer = null;
                    return $method;
                }
                if ($path !== '/payment_methods/' . $id) continue;
                if ($verb === 'get') {
                    if ($id === 'pm_failed' && str_starts_with($case, 'read ')) throw new RequestException((int) substr($case, 5));
                    if ($id === 'pm_failed' && $case === 'finalize read' && ++$failedReads === 2) throw new RequestException(503);
                    if ($id === 'pm_old' && $current === 'pm_failed' && $case === 'retained read') throw new RequestException(503);
                    $current = $id;
                } else {
                    $method->allow_redisplay = $data['allow_redisplay'];
                }
                return $method;
            }
            throw new RuntimeException('Unexpected cleanup request');
        };
        $warnings = count(Log::$warnings);
        $command = new DeduplicateCardsCommand;
        $command->options = ['apply' => $apply];
        check($command->handle() === 1, "Cleanup reports failure after isolating $case");
        $output = implode(' ', $command->output);
        check(str_contains($output, 'Agreement #21 skipped. Review it manually.')
            && str_contains($output, '1 agreement(s) skipped.'), 'Cleanup identifies the skipped row and total');
        check(str_contains($output, '#20 duplicates #19') && str_contains($output, '#22 duplicates #19'),
            'Cleanup processes healthy rows before and after the failing row');
        check(serialize(ExtensionHelper::$agreements['pm_failed']) === $failedBefore,
            'Cleanup preserves the failed agreement');
        check($apply ? ExtensionHelper::$agreements['pm_after']['deleted_at'] !== null
            : ExtensionHelper::$agreements['pm_after']['deleted_at'] === null, 'Cleanup honors apply after a failed row');
        check(count(Log::$warnings) === $warnings + 1 && end(Log::$warnings) === [
            'ae_stripe_deduplicate_agreement_skipped', ['agreement_id' => 21,
                'error' => $case === 'removed receipt' ? \App\Exceptions\DisplayException::class : RequestException::class],
        ], 'Cleanup warnings contain only a fixed code, agreement ID and exception class');
    }
}
Gateway::$rows = null;
echo "PASS: cleanup isolates failed agreements and reports a nonzero command result\n";

$user->props->values = [];
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_failed' => $row(21, 'pm_failed'),
    'pm_after' => $row(22, 'pm_after')];
$methods = ['pm_old' => $card('pm_old'), 'pm_failed' => $card('pm_failed'), 'pm_after' => $card('pm_after')];
Http::$handler = function ($verb, $path) use (&$methods) {
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id) return $method;
        if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
    }
    throw new RuntimeException('Unexpected rollback request');
};
$failedBefore = ExtensionHelper::$agreements['pm_failed'];
$lines = [];
$skipped = $gateway->deduplicateCards(true, function ($line) use (&$lines) {
    $lines[] = $line;
    if ($line === 'Removed duplicate agreement #21.') throw new RuntimeException('Synthetic report failure');
});
check($skipped === 1 && ExtensionHelper::$agreements['pm_failed'] === $failedBefore,
    'An exception after local removal rolls back the entire agreement transaction');
check(ExtensionHelper::$agreements['pm_after']['deleted_at'] !== null
    && in_array('Agreement #21 skipped. Review it manually.', $lines), 'Cleanup continues after a transaction rollback');
check(isset($user->props->values['ae_stripe_duplicate_pm_failed']) && $gateway->deduplicateCards(true, fn ($line) => null) === 0
    && ExtensionHelper::$agreements['pm_failed']['deleted_at'] !== null,
    'The committed receipt lets a skipped detached card finish safely on retry');

ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old'), 'pm_failed' => $row(21, 'pm_failed')];
Http::$handler = fn () => throw new RequestException(503);
Gateway::$rows = ['AeStripe' => [new \App\Models\Extension(['id' => 2, 'settings' => ['stripe_secret_key' => 'fake-secret']])]];
$command = new DeduplicateCardsCommand;
check($command->handle() === 1 && in_array('2 agreement(s) skipped. Review them manually.', $command->output),
    'Cleanup reports the total when more than one agreement is skipped');
Gateway::$rows = null;

// A deleted comparison card must not prevent saving a new, customer-verified card.
ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
$user->props->values = [];
$new = $card('pm_new');
$setup = (object) ['id' => 'seti_missing_candidate', 'status' => 'succeeded', 'customer' => 'cus_owner',
    'payment_method' => 'pm_new', 'latest_attempt' => null, 'metadata' => (object) [
        'user_id' => '7', 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v1',
        'ae_stripe_setup_consent_user' => '7', 'ae_stripe_setup_consent_at' => '1700000000',
    ]];
Http::$handler = function ($verb, $path, $data) use ($setup, $new) {
    if ($path === '/setup_intents/seti_missing_candidate') return $setup;
    if ($verb === 'get' && $path === '/payment_methods/pm_old') throw new RequestException(404, ['error' => ['code' => 'resource_missing']]);
    if ($path === '/payment_methods/pm_new') return $new;
    throw new RuntimeException('Unexpected setup request');
};
$start = count(Http::$calls);
$result = $gateway->setupAgreement(new \Illuminate\Http\Request(['setup_intent' => $setup->id]));
check($result['message'] === 'Payment method added successfully.' && isset(ExtensionHelper::$agreements['pm_new']),
    'A deleted comparison card does not prevent saving a valid new card');
check(!array_filter(array_slice(Http::$calls, $start), fn ($call) => str_ends_with($call[1], '/detach')),
    'A missing comparison card never authorizes a detach');

foreach ([404, 503] as $status) {
    ExtensionHelper::$agreements = ['pm_old' => $row(19, 'pm_old')];
    Http::$handler = function ($verb, $path) use ($setup, $new, $status) {
        if ($path === '/setup_intents/seti_missing_candidate') return $setup;
        if ($verb === 'get' && $path === '/payment_methods/pm_old') throw new RequestException($status);
        if ($path === '/payment_methods/pm_new') return $new;
        throw new RuntimeException('Unexpected setup request');
    };
    try {
        $gateway->setupAgreement(new \Illuminate\Http\Request(['setup_intent' => $setup->id]));
        throw new RuntimeException('Unconfirmed comparison failures must remain retryable');
    } catch (RequestException $e) {
        check(!isset(ExtensionHelper::$agreements['pm_new']), 'Unconfirmed comparison failures preserve the existing agreements');
    }
}
echo "PASS: cleanup rollback, skipped totals and missing comparison cards\n";
