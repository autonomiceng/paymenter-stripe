<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Separate native processes keep observers, authentication and SQL fixtures isolated.
$app = getenv('PAYMENTER_TEST_APP');
if (!$app || !is_file($app . '/vendor/autoload.php')) throw new RuntimeException('Native CI requires provisioned Paymenter dependencies (PAYMENTER_TEST_APP).');
if (getenv('PAY_NOTICE_THEME') && !is_file(getenv('PAY_NOTICE_THEME') . '/views/invoices/show.blade.php')) throw new RuntimeException('The selected private integration theme is unavailable.');
putenv('PAY_NATIVE_PINNED_APP=' . $app);
$cases = ['transaction', 'checkout', 'pending-retry', 'polish-staff', 'polish-staff-delayed', 'polish-staff-select', 'polish-staff-subscription',
    'polish-owner', 'polish-owner-callback', 'polish-exhausted', 'polish-poll', 'polish-poll-many', 'polish-inflight-off', 'polish-inflight-clear',
    'polish-select-service', 'polish-select-invoice', 'polish-select-account', 'polish-deferred', 'polish-deferred-first', 'polish-deferred-before',
    'polish-deferred-first-before', 'polish-deferred-recovery', 'polish-deferred-first-recovery', 'polish-deferred-connection', 'polish-token', 'polish-legacy', 'polish-prune', 'polish-refused',
    'followup-queue-missing', 'followup-queue-progress', 'followup-queue-storage', 'followup-queue-terminal', 'followup-queue-terminal-log-failure', 'followup-queue-local-budget', 'followup-queue-save-budget', 'followup-queue-notice-budget', 'followup-queue-save-outage', 'followup-queue-state-race', 'followup-queue-malformed', 'followup-queue-spaces',
    'followup-after-commit-gateway', 'followup-after-commit-bundled', 'followup-after-commit-state', 'followup-after-commit-log',
    'followup-retention', 'followup-contention', 'followup-stopped', 'followup-malformed', 'followup-transfer', 'followup-transfer-rollback', 'followup-prune-failure',
    'followup-poll', 'followup-staff-race', 'followup-webhook-complete', 'followup-webhook-discard', 'followup-webhook-discard-log', 'followup-webhook-save-failure'];
foreach (['disabled', 'bundled'] as $state) foreach (['', '-off', '-removed', '-subscription', '-owner', '-failure'] as $suffix) $cases[] = 'polish-paused-' . $state . $suffix;
foreach (['off', 'removed', 'cancelled', 'plan'] as $state) $cases[] = 'followup-stopped-' . $state;
foreach (['401', '403', '429', '503', 'connection'] as $error) $cases[] = 'followup-queue-outage-' . $error;
foreach (['transfer-race', 'transfer-order', 'method-404', 'orphans', 'retention', 'rotation', 'row-400', 'row-402', 'row-409', 'backoff', 'diagnostics', 'late-notice', 'delay-notice',
    'replacement', 'prune-isolation', 'terminal-status', 'diagnostics-account', 'method-row-400', 'method-row-402', 'method-row-409', 'logging', 'clean-log', 'backoff-fresh', 'render-pcre'] as $suffix) $cases[] = 'final-' . $suffix;
foreach (['401', '403', '429', '503', 'connection'] as $error) $cases[] = 'final-method-' . $error;
$php = getenv('PAYMENTER_TEST_PHP') ?: PHP_BINARY;
$runner = [$php, '-d', 'memory_limit=256M', __DIR__ . '/run.php'];
$runs = [];
if (getenv('PAY_PENDING_MATRIX') === 'browser') {
    // Playwright drives the production views; Stripe.js, Livewire responses and HTTP are local fixtures.
    $node = getenv('PAYMENTER_TEST_NODE') ?: 'node';
    putenv('LIVEWIRE_SCRIPT=' . (getenv('LIVEWIRE_SCRIPT') ?: $app . '/vendor/livewire/livewire/dist/livewire.js'));
    $runs[] = ['final-browser', ['PAY_CHECKOUT_NATIVE' => 'final-browser'], $runner];
    foreach (['payment-errors.mjs', 'setup-modal.mjs'] as $file) $runs[] = [$file, [], [$node, __DIR__ . '/' . $file]];
} else {
    foreach ($cases as $case) $runs[] = [$case, ['PAY_CHECKOUT_NATIVE' => $case], $runner];
    $runs[] = ['notice-native', ['PAY_CLEANUP_CASE' => 'notice-native'], $runner];
    $runs[] = ['binding-native', ['PAY_BINDING_CASE' => 'native'], $runner];
    foreach (['native-transaction-method.php', 'native-receipt-returns.php', 'native-auto-pay-consent.php'] as $file) {
        $runs[] = [$file, [], [$php, '-d', 'memory_limit=256M', __DIR__ . '/' . $file]];
    }
    $runs[] = ['conversion-bootstrap.php', [], [$php, '-n', __DIR__ . '/conversion-bootstrap.php', $app]];
}
putenv('PAY_PENDING_MATRIX');
foreach ($runs as [$label, $environment, $command]) {
    foreach (['PAY_CHECKOUT_NATIVE', 'PAY_CLEANUP_CASE', 'PAY_BINDING_CASE'] as $name) putenv(isset($environment[$name]) ? $name . '=' . $environment[$name] : $name);
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) throw new RuntimeException('Native integration failed: ' . $label);
}
echo 'PASS: ' . count($runs) . " isolated native runs\n";
