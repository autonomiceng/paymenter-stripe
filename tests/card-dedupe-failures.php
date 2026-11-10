<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
use App\Models\BillingAgreement;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;

nativeSharedCache();
$calls = fn (int $start) => array_slice($GLOBALS['stripeCalls'], $start);
$clicked = fn () => BillingAgreement::find(19);
$lastWarning = fn () => array_slice(warnings(), -1)[0] ?? null;
resetSavedMethods();
nativeAgreement(19, 'pm_cancel');
foreach ([404, 503] as $status) {
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($status) {
        if ($verb === 'get' && $path === '/payment_methods/pm_cancel') throw new StripeFixtureError($status, ['error' => ['code' => 'resource_missing']]);
        throw new RuntimeException('Unexpected cancellation request');
    };
    $start = count($GLOBALS['stripeCalls']);
    $warnings = count(warnings());
    $proceeds = $status === 404;
    check($gateway->cancelBillingAgreement($clicked()) === $proceeds,
        $proceeds ? 'Cancellation proceeds locally when the clicked method is missing at Stripe'
            : "Cancellation stops before local removal after a Stripe $status read failure");
    check(count($calls($start)) === 1 && $calls($start)[0][0] === 'get', "Cancellation never detaches after a Stripe $status read failure");
    check($proceeds ? count(warnings()) === $warnings : count(warnings()) === $warnings + 1 && $lastWarning() === [
        'ae_stripe_cancel_method_read_failed', ['error' => RequestException::class],
    ], 'Only uncertain cancellation read failures warn, with a fixed code and exception class');
}
$GLOBALS['stripeHandler'] = function ($verb, $path) {
    if ($verb === 'get' && $path === '/payment_methods/pm_cancel') return ['id' => 'pm_cancel', 'customer' => 'cus_other'];
    throw new RuntimeException('Unexpected cancellation request');
};
$start = count($GLOBALS['stripeCalls']);
check($gateway->cancelBillingAgreement($clicked()) === false, 'Cancellation refuses a method belonging to another customer');
check(count($calls($start)) === 1 && $calls($start)[0][0] === 'get', 'Cancellation never detaches a method belonging to another customer');

resetSavedMethods();
nativeAgreement(19, 'pm_cancel');
nativeAgreement(20, 'pm_live');
$otherBefore = agreements()['pm_live'];
$owner->properties()->create(['key' => 'ae_stripe_auto_pay_default', 'value' => json_encode(['agreement_id' => 19])]);
$GLOBALS['stripeHandler'] = function ($verb, $path) {
    check(!holdsLock('users'), 'A missing clicked method is read outside the owner lock');
    if ($verb === 'get' && $path === '/payment_methods/pm_cancel') throw new StripeFixtureError(404, ['error' => ['code' => 'resource_missing']]);
    throw new RuntimeException('A missing clicked method cannot authorize reading or detaching other methods');
};
check($gateway->cancelBillingAgreement($clicked()) && !isset(ownerProperties()['ae_stripe_auto_pay_default']),
    'A missing clicked method proceeds locally and clears its Auto Pay choice');
check(agreements()['pm_live'] === $otherBefore, 'A missing clicked method leaves other agreements alone');

// A dead row must not stop removal of a live card, even if its saved details look identical.
foreach (['row', 'receipt'] as $source) foreach (['missing', 'server', 'connection', 'unconfirmed 404'] as $failure) {
    resetSavedMethods();
    nativeAgreement(19, 'pm_cancel');
    if ($source === 'row') nativeAgreement(20, 'pm_dead');
    else $owner->properties()->create(['key' => 'ae_stripe_saved_fixture', 'value' => json_encode(['incoming' => 'pm_dead', 'reference' => 'pm_cancel', 'consent' => ['user_id' => 7]])]);
    $deadBefore = agreements()['pm_dead'] ?? null;
    $detached = [];
    $ownerLocks = [];
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($failure, &$detached, &$ownerLocks) {
        $ownerLocks[] = holdsLock('users');
        if ($verb === 'get' && $path === '/payment_methods/pm_dead') {
            if ($failure === 'connection') throw new ConnectionException('Synthetic outage');
            throw new StripeFixtureError($failure === 'server' ? 503 : 404, ['error' => ['code' => $failure === 'missing' ? 'resource_missing' : 'unknown']]);
        }
        if ($verb === 'get' && $path === '/payment_methods/pm_cancel') return nativeCard('pm_cancel', 'fp_live');
        if ($verb === 'post' && $path === '/payment_methods/pm_cancel/detach') { $detached[] = 'pm_cancel'; return ['id' => 'pm_cancel', 'customer' => null]; }
        throw new RuntimeException('Unexpected missing-copy removal request');
    };
    $proceeds = $failure === 'missing';
    check($gateway->cancelBillingAgreement($clicked()) === $proceeds,
        $proceeds ? 'Another missing copy does not block removing the live card' : 'An uncertain candidate read still stops removal: ' . $failure);
    check($detached === ($proceeds ? ['pm_cancel'] : []), 'Only the verified live card is detached after a confirmed missing candidate');
    check((agreements()['pm_dead'] ?? null) === $deadBefore, 'Skipping a missing candidate never changes its local row');
    check($ownerLocks && !in_array(true, $ownerLocks, true), 'Missing-method handling keeps all Stripe HTTP outside the owner lock');
}

$nativeGateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'fake-secret']);
foreach (['read 404', 'read 503', 'retained read', 'finalize read', 'removed receipt', 'detach'] as $case) {
    foreach ([false, true] as $apply) {
        if (!$apply && in_array($case, ['finalize read', 'detach'])) continue;
        resetSavedMethods();
        foreach (['pm_old' => 19, 'pm_before' => 20, 'pm_failed' => 21, 'pm_after' => 22] as $reference => $id) nativeAgreement($id, $reference);
        $methods = [];
        foreach (array_keys(agreements()) as $reference) $methods[$reference] = nativeCard($reference);
        if ($case === 'removed receipt') {
            $methods['pm_failed']->customer = null;
            $owner->properties()->create(['key' => 'ae_stripe_duplicate_pm_failed', 'value' => json_encode([
                'customer' => 'cus_owner', 'gateway' => $nativeGateway->id, 'kept' => 'pm_removed',
            ])]);
        }
        $failedBefore = agreements()['pm_failed'];
        $current = null;
        $failedReads = 0;
        $GLOBALS['stripeHandler'] = function ($verb, $path, $data) use (&$methods, &$current, &$failedReads, $case) {
            foreach ($methods as $id => $method) {
                if ($path === '/payment_methods/' . $id . '/detach') {
                    if ($id === 'pm_failed' && $case === 'detach') throw new StripeFixtureError(503);
                    $method->customer = null;
                    return $method;
                }
                if ($path !== '/payment_methods/' . $id) continue;
                if ($verb === 'get') {
                    if ($id === 'pm_failed' && str_starts_with($case, 'read ')) throw new StripeFixtureError((int) substr($case, 5));
                    if ($id === 'pm_failed' && $case === 'finalize read' && ++$failedReads === 2) throw new StripeFixtureError(503);
                    if ($id === 'pm_old' && $current === 'pm_failed' && $case === 'retained read') throw new StripeFixtureError(503);
                    $current = $id;
                } else {
                    $method->allow_redisplay = $data['allow_redisplay'];
                }
                return $method;
            }
            throw new RuntimeException('Unexpected cleanup request');
        };
        $warnings = count(warnings());
        [$code, $lines] = dedupeCommand($apply);
        check($code === 1, "Cleanup reports failure after isolating $case");
        $output = implode(' ', $lines);
        check(str_contains($output, 'Saved card #21 skipped. Review it manually.')
            && str_contains($output, '1 saved card(s) skipped.'), 'Cleanup identifies the skipped row and total');
        check(str_contains($output, '#20 duplicates #19') && str_contains($output, '#22 duplicates #19'),
            'Cleanup processes healthy rows before and after the failing row');
        check(agreements()['pm_failed'] === $failedBefore, 'Cleanup preserves the failed agreement');
        check($apply ? agreements()['pm_after']['deleted_at'] !== null : agreements()['pm_after']['deleted_at'] === null,
            'Cleanup honors apply after a failed row');
        check(count(warnings()) === $warnings + 1 && $lastWarning() === [
            'ae_stripe_deduplicate_agreement_skipped', ['agreement_id' => 21,
                'error' => $case === 'removed receipt' ? App\Exceptions\DisplayException::class : RequestException::class],
        ], 'Cleanup warnings contain only a fixed code, agreement ID and exception class');
    }
}

resetSavedMethods();
foreach (['pm_old' => 19, 'pm_failed' => 21, 'pm_after' => 22] as $reference => $id) nativeAgreement($id, $reference);
$methods = ['pm_old' => nativeCard('pm_old'), 'pm_failed' => nativeCard('pm_failed'), 'pm_after' => nativeCard('pm_after')];
$GLOBALS['stripeHandler'] = function ($verb, $path) use (&$methods) {
    foreach ($methods as $id => $method) {
        if ($path === '/payment_methods/' . $id) return $method;
        if ($path === '/payment_methods/' . $id . '/detach') { $method->customer = null; return $method; }
    }
    throw new RuntimeException('Unexpected rollback request');
};
$failedBefore = agreements()['pm_failed'];
$lines = [];
$skipped = $gateway->deduplicateCards(true, function ($line) use (&$lines) {
    $lines[] = $line;
    if ($line === 'Removed duplicate saved card #21.') throw new RuntimeException('Synthetic report failure');
});
check($skipped === 1 && agreements()['pm_failed'] === $failedBefore, 'An exception after local removal rolls back the entire agreement transaction');
check(agreements()['pm_after']['deleted_at'] !== null && in_array('Saved card #21 skipped. Review it manually.', $lines),
    'Cleanup continues after a transaction rollback');
check(isset(ownerProperties()['ae_stripe_duplicate_pm_failed']) && $gateway->deduplicateCards(true, fn ($line) => null) === 0
    && agreements()['pm_failed']['deleted_at'] !== null, 'The committed receipt lets a skipped detached card finish safely on retry');

resetSavedMethods();
nativeAgreement(19, 'pm_old');
nativeAgreement(21, 'pm_failed');
$GLOBALS['stripeHandler'] = fn () => throw new StripeFixtureError(503);
[$code, $lines] = dedupeCommand(false);
check($code === 1 && in_array('2 saved card(s) skipped. See the per-card reasons above.', $lines), 'Cleanup reports the total when more than one agreement is skipped');

// A deleted comparison card must not prevent saving a new, customer-verified card.
resetSavedMethods();
nativeAgreement(19, 'pm_old');
$new = nativeCard('pm_new');
$setup = nativeSetupIntent('missingcandidate');
$GLOBALS['stripeHandler'] = function ($verb, $path, $data) use ($setup, $new) {
    if ($path === '/setup_intents/' . $setup->id) return $setup;
    if ($verb === 'get' && $path === '/payment_methods/pm_old') throw new StripeFixtureError(404, ['error' => ['code' => 'resource_missing']]);
    if ($path === '/payment_methods/pm_new') return $new;
    throw new RuntimeException('Unexpected setup request');
};
$start = count($GLOBALS['stripeCalls']);
$result = setupReturn($gateway, $setup);
check($result['message'] === 'Payment method added successfully.' && isset(agreements()['pm_new']),
    'A deleted comparison card does not prevent saving a valid new card');
check(!array_filter($calls($start), fn ($call) => str_ends_with($call[1], '/detach')), 'A missing comparison card never authorizes a detach');

foreach ([404, 503] as $status) {
    resetSavedMethods();
    nativeAgreement(19, 'pm_old');
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($setup, $new, $status) {
        if ($path === '/setup_intents/' . $setup->id) return $setup;
        if ($verb === 'get' && $path === '/payment_methods/pm_old') throw new StripeFixtureError($status);
        if ($path === '/payment_methods/pm_new') return $new;
        throw new RuntimeException('Unexpected setup request');
    };
    try {
        setupReturn($gateway, $setup);
        throw new RuntimeException('Unconfirmed comparison failures must remain retryable');
    } catch (RequestException $e) {
        check(!isset(agreements()['pm_new']), 'Unconfirmed comparison failures preserve the existing agreements');
    }
}
