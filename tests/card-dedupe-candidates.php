<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
use Illuminate\Http\Client\RequestException;

nativeSharedCache();
$setup = nativeSetupIntent('candidates');
foreach (['setup', 'cleanup', 'server failure', 'receipt'] as $case) {
    resetSavedMethods();
    nativeAgreement(19, 'pm_missing');
    nativeAgreement(20, 'pm_kept');
    $methods = ['pm_kept' => nativeCard('pm_kept'), 'pm_new' => nativeCard('pm_new')];
    if ($case === 'cleanup') nativeAgreement(21, 'pm_new');
    if ($case === 'receipt') {
        $owner->properties()->create(['key' => 'ae_stripe_duplicate_pm_new', 'value' => json_encode([
            'customer' => 'cus_owner', 'gateway' => $nativeGateway->id, 'kept' => 'pm_missing',
        ])]);
    }
    $methodHandler = nativeMethodHandler($methods, $setup);
    $GLOBALS['stripeHandler'] = function ($verb, $path, $data) use ($case, $methodHandler) {
        if ($verb === 'get' && $path === '/payment_methods/pm_missing') {
            throw new StripeFixtureError($case === 'server failure' ? 503 : 404, ['error' => ['code' => 'resource_missing']]);
        }
        return $methodHandler($verb, $path, $data);
    };
    $start = count($GLOBALS['stripeCalls']);
    $warnings = count(warnings());
    if ($case === 'cleanup') {
        $lines = [];
        $skipped = $gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; });
        check(in_array('Saved card #21 duplicates #20 (customer #7).', $lines)
            && agreements()['pm_new']['deleted_at'] !== null && $methods['pm_new']->customer === null,
            'Cleanup finds a later duplicate after a missing older candidate');
        check($skipped === 1 && agreements()['pm_kept']['deleted_at'] === null,
            'Cleanup skips only the missing agreement and retains the matching card');
    } elseif ($case === 'server failure') {
        $before = agreements();
        try {
            setupReturn($gateway, $setup);
            throw new RuntimeException('A candidate server failure must propagate');
        } catch (RequestException $e) {
            check($e->response->status() === 503 && agreements() === $before,
                'A candidate server failure propagates without changing agreements');
        }
        check(!array_filter(array_slice($GLOBALS['stripeCalls'], $start), fn ($call) => $call[0] !== 'get'),
            'A candidate server failure authorizes no Stripe writes');
        nativeAgreement(21, 'pm_new');
        $lines = [];
        check($gateway->deduplicateCards(true, function ($line) use (&$lines) { $lines[] = $line; }) === 3,
            'Cleanup reports each row affected by a candidate server failure');
        check(!array_filter($lines, fn ($line) => str_contains($line, 'duplicates'))
            && agreements()['pm_new']['deleted_at'] === null,
            'Cleanup never treats an incomplete candidate scan as a duplicate match');
        continue;
    } else {
        $result = setupReturn($gateway, $setup);
        if ($case === 'receipt') {
            check(str_contains($result['message'], 'already saved') && !isset(agreements()['pm_new']),
                'A missing legacy receipt does not override a verified matching agreement');
            check(!array_filter(array_slice($GLOBALS['stripeCalls'], $start), fn ($call) => str_ends_with($call[1], '/detach')),
                'A missing retained receipt method never authorizes a detach');
        } else {
            check(str_contains($result['message'], 'already saved') && !isset(agreements()['pm_new'])
                && $methods['pm_new']->customer === 'cus_owner', 'Setup finds a later duplicate after a missing older candidate');
            check(agreements()['pm_kept']['deleted_at'] === null && $methods['pm_kept']->customer === 'cus_owner',
                'Setup retains the later matching agreement');
        }
    }
    $candidateWarnings = array_filter(array_slice(warnings(), $warnings), fn ($warning) => $warning[0] === 'ae_stripe_duplicate_candidate_missing');
    check($candidateWarnings && !array_filter($candidateWarnings, fn ($warning) => $warning[1] !== ['error' => RequestException::class]),
        'Missing candidate warnings contain only a fixed code and exception class');
}
