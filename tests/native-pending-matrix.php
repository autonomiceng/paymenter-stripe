<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Separate native processes keep observers, authentication and SQL fixtures isolated.
require_once __DIR__ . '/entered.php';
// Modes: unset, empty or 1 runs the native cases; browser runs the browser checks.
$mode = getenv('PAY_PENDING_MATRIX');
if (!in_array($mode, [false, '', '1', 'browser'], true)) throw new RuntimeException('Unknown PAY_PENDING_MATRIX mode "' . $mode . '"; use 1 for native cases or browser.');
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
foreach (['disabled', 'bundled'] as $state) foreach (['', '-noconsent', '-nocard'] as $suffix) $cases[] = 'paused-create-' . $state . $suffix;
foreach (['off', 'removed', 'cancelled', 'plan'] as $state) $cases[] = 'followup-stopped-' . $state;
foreach (['401', '403', '429', '503', 'connection'] as $error) $cases[] = 'followup-queue-outage-' . $error;
foreach (['transfer-race', 'transfer-order', 'method-404', 'orphans', 'retention', 'rotation', 'row-400', 'row-402', 'row-409', 'backoff', 'diagnostics', 'late-notice', 'delay-notice',
    'replacement', 'prune-isolation', 'terminal-status', 'diagnostics-account', 'method-row-400', 'method-row-402', 'method-row-409', 'logging', 'clean-log', 'backoff-fresh', 'render-pcre'] as $suffix) $cases[] = 'final-' . $suffix;
foreach (['401', '403', '429', '503', 'connection'] as $error) $cases[] = 'final-method-' . $error;
// Every PHP child records the test files it loads (tests/entered.php) when CI asks for the evidence.
$php = getenv('PAYMENTER_TEST_PHP') ?: PHP_BINARY;
$record = ['-d', 'auto_prepend_file=' . __DIR__ . '/entered.php'];
$runner = [$php, ...$record, '-d', 'memory_limit=256M', __DIR__ . '/run.php'];
$runs = [];
if ($mode === 'browser') {
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
        $runs[] = [$file, [], [$php, ...$record, '-d', 'memory_limit=256M', __DIR__ . '/' . $file]];
    }
    // Keep configured PDO/SQLite extensions; the fresh process and unbooted application provide isolation.
    $runs[] = ['conversion-bootstrap.php', [], [$php, ...$record, __DIR__ . '/conversion-bootstrap.php', $app]];
    foreach (require __DIR__ . '/native-suites.php' as $suite) {
        $runs[] = [$suite, ['PAY_NATIVE_SUITE' => $suite], [$php, ...$record, '-d', 'memory_limit=512M', __DIR__ . '/native-suite.php']];
    }
}
putenv('PAY_PENDING_MATRIX');
$jobs = (int) (getenv('PAY_TEST_JOBS') ?: 1);
if ($jobs < 1 || $jobs > 6) throw new RuntimeException('Native workers must be between one and six.');
$base = array_diff_key(getenv(), array_fill_keys(['PAY_CHECKOUT_NATIVE', 'PAY_CLEANUP_CASE', 'PAY_BINDING_CASE', 'PAY_NATIVE_SUITE'], true));
$active = [];
$next = 0;
$failed = [];
while ($next < count($runs) || $active) {
    while ($next < count($runs) && count($active) < $jobs) {
        [$label, $environment, $command] = $runs[$next++];
        $receipt = null;
        if (getenv('PAY_TEST_CGROUP')) {
            $receipt = getenv('PAY_TEST_RECEIPTS') . '/' . $mode . '-' . $next . '.json';
            $command = ['python3', getenv('PAY_TEST_BUDGET'), '--name', $label, '--test-scope', '--receipt', $receipt, '--', ...$command];
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, array_replace($base, $environment));
        if (!is_resource($process)) { $failed[] = $label; continue; }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $active[] = ['process' => $process, 'stream' => $pipes[1], 'label' => $label, 'output' => '', 'started' => microtime(true), 'receipt' => $receipt];
    }
    $read = array_column($active, 'stream');
    $write = $except = null;
    if ($read) stream_select($read, $write, $except, 1);
    foreach ($active as $index => &$child) {
        $child['output'] .= stream_get_contents($child['stream']);
        if (!feof($child['stream'])) continue;
        fclose($child['stream']);
        $code = proc_close($child['process']);
        echo $child['output'];
        $resources = $child['receipt'] && is_file($child['receipt']) ? json_decode(file_get_contents($child['receipt']), true, 16, JSON_THROW_ON_ERROR) : null;
        if ($child['receipt'] && !$resources) $code = $code ?: 1;
        if ($child['receipt']) echo json_encode(['label' => $child['label'], 'argv' => ['native-case', $child['label']], 'seconds' => microtime(true) - $child['started'], 'exit' => $code, 'resources' => $resources]) . "\n";
        if ($code !== 0) $failed[] = $child['label'];
        unset($active[$index]);
    }
    unset($child);
}
if ($failed) throw new RuntimeException('Native integration failed: ' . implode(', ', $failed));
echo 'PASS: ' . count($runs) . " isolated native runs\n";
