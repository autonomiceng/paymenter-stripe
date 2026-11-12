<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

nativeSharedCache();
$otherGateway = App\Models\Gateway::create(['name' => 'Other', 'extension' => 'Other', 'type' => 'gateway', 'enabled' => true]);
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
$setup = nativeSetupIntent('dedupe');
$handler = nativeMethodHandler($methods, $setup);
$GLOBALS['stripeHandler'] = $handler;
$serviceRows = fn () => tableRows('services', ['id']);
$bind = fn (int $agreement) => nativeService(null)->forceFill(['billing_agreement_id' => $agreement])->save();
$calls = fn (int $start) => array_slice($GLOBALS['stripeCalls'], $start);

// Database read views and missing-key locks are exercised by mariadb-locks.php. These checks cover the saved result.
resetSavedMethods();
nativeAgreement(19, 'pm_old');
$result = setupReturn($gateway, $setup);
check($result['message'] === 'This card is already saved. We updated its expiry to 05/2030.' && count(agreements()) === 1,
    'A confirmed duplicate setup retains one saved card and shows one notice');
check($methods['pm_new']->customer === 'cus_owner' && $methods['pm_old']->allow_redisplay === 'always',
    'Duplicate setup retains referenced methods and broadens redisplay on the retained method');
$result = setupReturn($gateway, $setup);
check($result['message'] === 'This card is already saved. We updated its expiry to 05/2030.' && count(agreements()) === 1,
    'Webhook or redirect retries recognize the detached duplicate without another agreement');

// Exercise the public command using the installed gateway settings, like Paymenter's console lifecycle.
$nativeGateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'installed-secret']);
$methods['pm_new'] = nativeCard('pm_new');
$methods['pm_old']->allow_redisplay = 'limited';
$methods['pm_new']->allow_redisplay = 'always';
resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(20, 'pm_new');
$before = serialize([agreements(), ownerProperties(), $methods]);
$start = count($GLOBALS['stripeCalls']);
[$code, $output] = dedupeCommand(false);
check($code === 0 && str_contains(implode(' ', $output), '#20 duplicates #19'), 'Cleanup dry run lists duplicate agreement IDs');
check(serialize([agreements(), ownerProperties(), $methods]) === $before
    && !array_filter($calls($start), fn ($call) => $call[0] !== 'get'), 'Cleanup defaults to read-only dry run');
check($GLOBALS['stripeCalls'][$start][3]['Authorization'] === 'Bearer installed-secret', 'Cleanup uses the installed gateway credentials');
check(dedupeCommand(true)[0] === 0 && agreements()['pm_new']['deleted_at'] !== null
    && $methods['pm_new']->customer === null && $methods['pm_old']->allow_redisplay === 'always',
    'Cleanup apply removes only the unbound duplicate and preserves broader redisplay');
check(dedupeCommand(true)[0] === 0, 'Cleanup apply can be repeated');

// Imported duplicates with service bindings must be visible in the dry run and retained on apply.
$methods['pm_new'] = nativeCard('pm_new');
resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(20, 'pm_new');
$bind(20);
$serviceBefore = $serviceRows();
$lines = [];
$gateway->deduplicateCards(false, function ($line) use (&$lines) { $lines[] = $line; });
check(str_contains(implode(' ', $lines), 'linked to a service'), 'Cleanup lists service-bound duplicates with the reason they stay');
$gateway->deduplicateCards(true, fn ($line) => null);
check(agreements()['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_owner'
    && $serviceRows() === $serviceBefore, 'Cleanup preserves bound duplicates and every service preference');

// The account card can have no services, including duplicates saved before expiry matching changed.
foreach (['on', 'off'] as $state) {
    $accountProperty = $state === 'on' ? 'ae_stripe_auto_pay_default' : 'ae_stripe_auto_pay_card';
    $accountChoice = json_encode(['version' => AeStripe::AUTO_PAY_CONSENT, 'user_id' => 7,
        'accepted_at' => 1700000000, 'agreement_id' => 20], JSON_THROW_ON_ERROR);
    $accountGateway = new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test']);
    foreach (['return', 'webhook', 'selected before detach', 'cleanup'] as $entry) {
        $methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new', month: $entry === 'cleanup' ? 5 : 6)];
        resetSavedMethods();
        nativeAgreement(19, 'pm_old');
        nativeAgreement(20, 'pm_new', ['expiry' => $entry === 'cleanup' ? '2030-05-31' : '2030-06-30']);
        $tablesBefore = [$serviceRows(), tableRows('invoice_transactions', ['id'])];
        if ($entry !== 'selected before detach') $owner->properties()->create(['key' => $accountProperty, 'value' => $accountChoice]);
        $selectedBetweenPhases = false;
        if ($entry === 'selected before detach') {
            afterNextCommit(function () use ($owner, $accountProperty, $accountChoice, &$selectedBetweenPhases) {
                check(!holdsLock('ae_stripe_locks'), 'The account choice commits after the first duplicate transaction');
                $owner->properties()->create(['key' => $accountProperty, 'value' => $accountChoice]);
                $selectedBetweenPhases = true;
            });
        }
        $start = count($GLOBALS['stripeCalls']);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($entry === 'webhook') {
                check($accountGateway->webhook(eventRequest($setup, type: 'setup_intent.succeeded'))->getStatusCode() === 200,
                    'Delayed setup success is acknowledged for the account card');
            } elseif ($entry === 'cleanup') {
                check($accountGateway->deduplicateCards(true, fn ($line) => null) === 0, 'Cleanup preserves the account card without an error');
            } else {
                setupReturn($accountGateway, $setup);
            }
            check(agreements()['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_owner',
                "$entry keeps the newer account Auto Pay agreement attached and active");
            check($accountGateway->autoPayCard($owner) === 20
                && ($accountGateway->autoPayDefault($owner) !== null) === ($state === 'on')
                && ownerProperties()[$accountProperty] === $accountChoice,
                "$entry preserves the exact account choice and Auto Pay still resolves");
        }
        check(count(agreements()) === 2 && [$serviceRows(), tableRows('invoice_transactions', ['id'])] === $tablesBefore,
            "$entry preserves both existing agreements, service settings and charge history");
        check(!array_filter($calls($start), fn ($call) => str_ends_with($call[1], '/detach')),
            "$entry sends no detach for the account card");
        if ($entry === 'selected before detach') check($selectedBetweenPhases, 'The pre-detach recheck scenario ran');
    }
}

foreach (['fingerprint', 'missing fingerprint', 'other user', 'other gateway', 'removed', 'other customer'] as $case) {
    $methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
    resetSavedMethods();
    $old = [];
    switch ($case) {
        case 'fingerprint': $methods['pm_new']->card->fingerprint = 'fp_other'; break;
        case 'missing fingerprint': unset($methods['pm_new']->card->fingerprint); break;
        case 'other user': $old['user_id'] = 99; break;
        case 'other gateway': $old['gateway_id'] = $otherGateway->id; break;
        case 'removed': $old['deleted_at'] = '2026-10-22 00:00:00'; break;
        case 'other customer': $methods['pm_old']->customer = 'cus_other'; break;
    }
    nativeAgreement(19, 'pm_old', $old);
    setupReturn($gateway, $setup);
    check(count(agreements()) === 2 && $methods['pm_new']->customer === 'cus_owner', "Setup keeps separate methods for $case");
}
// A service using the retained card keeps its agreement ID and Auto Pay state.
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
resetSavedMethods();
nativeAgreement(19, 'pm_old');
$bind(19);
$serviceBefore = $serviceRows();
$result = setupReturn($gateway, $setup);
check(str_contains($result['message'], 'already saved') && $serviceRows() === $serviceBefore,
    'A repeated save preserves the retained agreement and its service settings');

// Receipts cannot authorize detaching a method that has moved to another customer.
DB::table('services')->delete();
nativeAgreement(20, 'pm_new');
$methods['pm_new']->customer = 'cus_other';
$gateway->deduplicateCards(true, fn ($line) => null);
check(agreements()['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_other',
    'Cleanup refuses a receipt when the duplicate now belongs to another customer');

// A setup retry must keep an imported duplicate that already has a service.
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(20, 'pm_new');
$bind(20);
setupReturn($gateway, $setup);
check(count(agreements()) === 2 && agreements()['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_owner',
    'Setup retains a duplicate already bound to a service');

// Cleanup resumes after Stripe detaches a card but the response is lost.
DB::table('services')->delete();
$methods['pm_old']->allow_redisplay = 'always';
$lostDetach = true;
$GLOBALS['stripeHandler'] = function ($verb, $path, $data) use ($handler, &$lostDetach) {
    $result = $handler($verb, $path, $data);
    if (str_ends_with($path, '/detach') && $lostDetach) {
        $lostDetach = false;
        throw new ConnectionException('Synthetic lost detach response');
    }
    return $result;
};
check($gateway->deduplicateCards(true, fn ($line) => null) === 1, 'Cleanup reports an agreement skipped after an uncertain detach');
check(agreements()['pm_new']['deleted_at'] === null, 'Cleanup rolls back local removal on an uncertain detach');
$gateway->deduplicateCards(true, fn ($line) => null);
check(agreements()['pm_new']['deleted_at'] !== null && $methods['pm_old']->allow_redisplay === 'always',
    'Cleanup resumes a detached method without reducing the retained consent');
$GLOBALS['stripeHandler'] = $handler;

// Validate agreement ownership again if a service is linked between cleanup phases.
$methods['pm_new'] = nativeCard('pm_new');
resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(20, 'pm_new');
$gateway->deduplicateCards(true, function ($line) use ($bind) {
    if (!str_contains($line, 'duplicates')) return;
    beforeLock('users', fn () => $bind(20));
});
check(agreements()['pm_new']['deleted_at'] === null && $methods['pm_new']->customer === 'cus_owner',
    'Cleanup rechecks a service binding added before apply');

$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(20, 'pm_new');
$setup->payment_method = 'pm_old';
setupReturn($gateway, $setup);
check(agreements()['pm_old']['deleted_at'] === null && $methods['pm_old']->customer === 'cus_owner',
    'Retrying the oldest saved method never replaces it with a newer duplicate');
$setup->payment_method = 'pm_new';

// A previous duplicate receipt never revives a removed retained agreement.
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_new' => nativeCard('pm_new')];
resetSavedMethods();
nativeAgreement(19, 'pm_old');
setupReturn($gateway, $setup);
DB::table('billing_agreements')->where('id', 19)->update(['deleted_at' => '2026-10-23 13:00:00']);
$result = setupReturn($gateway, $setup);
check($result['type'] === 'danger' && str_contains($result['message'], 'removed')
    && count(agreements()) === 1 && agreements()['pm_old']['deleted_at'] !== null,
    'Duplicate setup receipts preserve removal of the retained agreement');
