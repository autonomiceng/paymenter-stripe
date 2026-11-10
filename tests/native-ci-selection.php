<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Configuration coverage: a passing offline job alone must not hide missing native CI.
$workflow = file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
check(str_contains($workflow, 'PAY_PENDING_MATRIX:') && str_contains($workflow, 'PAYMENTER_TEST_APP:') && str_contains($workflow, 'composer install') && str_contains($workflow, "php-version: '8.4.27'"), 'CI provisions and selects the native pending integration matrix');
check(str_contains($workflow, 'php -n tests/run.php') && !str_contains($workflow, 'PAY_NOTICE_THEME:') && !str_contains($workflow, 'secrets.'), 'Public integration keeps stripped offline CI and never provisions private themes or credentials');
check(str_contains($workflow, 'PAY_PENDING_MATRIX: browser') && str_contains($workflow, 'PLAYWRIGHT_MODULE:') && str_contains($workflow, 'playwright@1.58.2') && str_contains($workflow, 'install --with-deps chromium'), 'CI provisions Playwright and selects the browser matrix');
// A matrix child that reaches the offline suite would start these checks again; stop it at one level.
if (getenv('PAY_CI_SELECTION_PARENT')) throw new RuntimeException('A matrix child fell through to the offline suite');
// Every test file runs in CI: each job records the files its processes load, and the coverage job fails on any
// file no job loaded (tests/ci-coverage.php). Naming a file in source, a comment or an unused branch does not count.
check(substr_count($workflow, 'PAY_TEST_ENTERED: ${{ runner.temp }}/entered/') === 3 && str_contains($workflow, 'needs: [php, native-pending, browser]')
    && str_contains($workflow, 'php -n tests/ci-coverage.php "$RUNNER_TEMP/entered"'), 'Each CI job records its loaded test files and the coverage job fails on a file no job loaded');
// Fixture children never add to this job's evidence.
$inherited = array_diff_key(getenv(), ['PAY_TEST_ENTERED' => true, 'PAY_PENDING_MATRIX' => true]);
$launch = function (array $command, array $environment) {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($process), $output];
};
require_once __DIR__ . '/temporary-root.php';
$fixture = TemporaryRoot::create('ae-stripe-matrix-');
mkdir($fixture . '/app/vendor', 0700, true); mkdir($fixture . '/other/vendor', 0700, true);
file_put_contents($fixture . '/app/vendor/autoload.php', '<?php'); file_put_contents($fixture . '/other/vendor/autoload.php', '<?php');
$evidence = $fixture . '/evidence/offline.txt';
for ($run = 1; $run <= 2; ++$run) {
    [$status] = $launch([PHP_BINARY, '-n', '-d', 'auto_prepend_file=' . __DIR__ . '/entered.php', __DIR__ . '/native-suites.php'], array_replace($inherited, ['PAY_TEST_ENTERED' => $evidence]));
    $recorded = is_file($evidence) ? file($evidence, FILE_IGNORE_NEW_LINES) : [];
    sort($recorded);
    check($status === 0 && $recorded === [...array_fill(0, $run, 'entered.php'), ...array_fill(0, $run, 'native-suites.php')],
        'Each process appends the test files it loaded, not the native-app.php its comment names');
}
$ciCoverage = require __DIR__ . '/ci-coverage.php';
$tests = array_map('basename', array_merge(glob(__DIR__ . '/*.php'), glob(__DIR__ . '/*.mjs')));
$jobs = ['offline' => array_values(array_diff($tests, ['receipt-returns.php', 'native-suite.php', 'setup-modal.mjs'])),
    'native' => ['run.php', 'native-pending-matrix.php', 'native-suites.php', 'native-suite.php', 'receipt-returns.php'], 'browser' => ['setup-modal.mjs', 'entered.mjs']];
$coverage = function (array $jobs) use ($fixture, $ciCoverage) {
    $directory = $fixture . '/coverage-' . bin2hex(random_bytes(4));
    mkdir($directory);
    foreach ($jobs as $job => $files) file_put_contents($directory . '/' . $job . '.txt', implode("\n", $files) . "\n");
    return $ciCoverage($directory);
};
[$problems, $suites, $support] = $coverage($jobs);
check($problems === [] && $support === 10 && $suites + $support === count($tests), 'Coverage passes when CI jobs together loaded every test file');
$without = fn ($job, $file) => array_replace($jobs, [$job => array_values(array_diff($jobs[$job], [$file]))]);
check($coverage($without('native', 'receipt-returns.php'))[0] === ['No CI job loaded these suites: receipt-returns.php'], 'A suite the native registry names but no job loaded fails coverage');
check($coverage($without('browser', 'setup-modal.mjs'))[0] === ['No CI job loaded these suites: setup-modal.mjs'], 'A browser check the matrix names but no job loaded fails coverage');
check($coverage($without('native', 'native-suite.php'))[0] === ['No CI job loaded these support files: native-suite.php'], 'A support file no job loaded fails coverage');
check($coverage(array_replace($jobs, ['browser' => []]))[0] === ['The browser job recorded no loaded test files', 'No CI job loaded these suites: setup-modal.mjs'],
    'A job without evidence fails coverage');
// Successful children record their command and selections, in both modes, with stale selectors inherited.
$output = $fixture . '/selected';
foreach (['php', 'node'] as $stub) {
    file_put_contents($fixture . '/' . $stub, '#!/bin/sh' . "\n" . 'printf \'%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n\' "${0##*/}" "${PAY_CHECKOUT_NATIVE-}" "${PAY_CLEANUP_CASE-}" "${PAY_BINDING_CASE-}" "${PAY_NATIVE_SUITE-}" '
        . '"${PAY_PENDING_MATRIX-}" "${PAY_NATIVE_PINNED_APP-}" "${LIVEWIRE_SCRIPT-}" "$*" >> "$PAY_NATIVE_SELECTION_OUTPUT"' . "\n");
    chmod($fixture . '/' . $stub, 0700);
}
$stubbed = array_replace(array_diff_key($inherited, ['LIVEWIRE_SCRIPT' => true]), ['PAYMENTER_TEST_APP' => $fixture . '/app', 'PAY_NATIVE_PINNED_APP' => $fixture . '/other',
    'PAYMENTER_TEST_PHP' => $fixture . '/php', 'PAYMENTER_TEST_NODE' => $fixture . '/node', 'PAY_NATIVE_SELECTION_OUTPUT' => $output,
    'PAY_CHECKOUT_NATIVE' => 'stale', 'PAY_CLEANUP_CASE' => 'stale', 'PAY_BINDING_CASE' => 'stale', 'PAY_NATIVE_SUITE' => 'stale', 'PAY_CI_SELECTION_PARENT' => '1']);
$prepend = '-d auto_prepend_file=' . __DIR__ . '/entered.php';
$row = fn ($stub, $selections, $arguments, $livewire = '') => implode("\t", [$stub, $selections[0] ?? '', $selections[1] ?? '', $selections[2] ?? '', $selections[3] ?? '', '', $fixture . '/app', $livewire, $arguments]);
$suiteRuns = array_map(fn ($suite) => $row('php', [3 => $suite], $prepend . ' -d memory_limit=512M ' . __DIR__ . '/native-suite.php'), require __DIR__ . '/native-suites.php');
$late = [$row('php', [1 => 'notice-native'], $prepend . ' -d memory_limit=256M ' . __DIR__ . '/run.php'), $row('php', [2 => 'native'], $prepend . ' -d memory_limit=256M ' . __DIR__ . '/run.php'),
    ...array_map(fn ($file) => $row('php', [], $prepend . ' -d memory_limit=256M ' . __DIR__ . '/' . $file), ['native-transaction-method.php', 'native-receipt-returns.php', 'native-auto-pay-consent.php']),
    $row('php', [], '-n ' . $prepend . ' ' . __DIR__ . '/conversion-bootstrap.php ' . $fixture . '/app'), ...$suiteRuns];
$livewire = $fixture . '/app/vendor/livewire/livewire/dist/livewire.js';
$browser = [$row('php', ['final-browser'], $prepend . ' -d memory_limit=256M ' . __DIR__ . '/run.php', $livewire),
    $row('node', [], __DIR__ . '/payment-errors.mjs', $livewire), $row('node', [], __DIR__ . '/setup-modal.mjs', $livewire)];
$selected = [];
foreach (['1' => [__DIR__ . '/run.php'], 'browser' => [__DIR__ . '/run.php'], '' => [__DIR__ . '/native-pending-matrix.php'], 'unset' => [__DIR__ . '/native-pending-matrix.php']] as $mode => $entry) {
    if (is_file($output)) unlink($output);
    $environment = $mode === 'unset' ? $stubbed : array_replace($stubbed, ['PAY_PENDING_MATRIX' => (string) $mode]);
    [$status, $printed] = $launch([PHP_BINARY, '-n', ...$entry], $environment);
    $selected[$mode] = is_file($output) ? file($output, FILE_IGNORE_NEW_LINES) : [];
    check($status === 0 && $printed === 'PASS: ' . count($selected[$mode]) . " isolated native runs\n", 'Matrix mode ' . $mode . ' finishes every successful child and reports the count: ' . $printed);
}
check($selected['browser'] === $browser, 'The browser matrix runs final-browser, payment-errors.mjs and setup-modal.mjs with only their own selections and the release Livewire script');
// Written out on purpose: a case dropped from or added to the matrix list must fail here.
$expectedCases = [
    'transaction', 'checkout', 'pending-retry', 'polish-staff', 'polish-staff-delayed', 'polish-staff-select', 'polish-staff-subscription',
    'polish-owner', 'polish-owner-callback', 'polish-exhausted', 'polish-poll', 'polish-poll-many', 'polish-inflight-off', 'polish-inflight-clear',
    'polish-select-service', 'polish-select-invoice', 'polish-select-account', 'polish-deferred', 'polish-deferred-first', 'polish-deferred-before',
    'polish-deferred-first-before', 'polish-deferred-recovery', 'polish-deferred-first-recovery', 'polish-deferred-connection', 'polish-token',
    'polish-legacy', 'polish-prune', 'polish-refused', 'followup-queue-missing', 'followup-queue-progress', 'followup-queue-storage',
    'followup-queue-terminal', 'followup-queue-terminal-log-failure', 'followup-queue-local-budget', 'followup-queue-save-budget',
    'followup-queue-notice-budget', 'followup-queue-save-outage', 'followup-queue-state-race', 'followup-queue-malformed', 'followup-queue-spaces',
    'followup-after-commit-gateway', 'followup-after-commit-bundled', 'followup-after-commit-state', 'followup-after-commit-log',
    'followup-retention', 'followup-contention', 'followup-stopped', 'followup-malformed', 'followup-transfer', 'followup-transfer-rollback',
    'followup-prune-failure', 'followup-poll', 'followup-staff-race', 'followup-webhook-complete', 'followup-webhook-discard',
    'followup-webhook-discard-log', 'followup-webhook-save-failure', 'polish-paused-disabled', 'polish-paused-disabled-off',
    'polish-paused-disabled-removed', 'polish-paused-disabled-subscription', 'polish-paused-disabled-owner', 'polish-paused-disabled-failure',
    'polish-paused-bundled', 'polish-paused-bundled-off', 'polish-paused-bundled-removed', 'polish-paused-bundled-subscription',
    'polish-paused-bundled-owner', 'polish-paused-bundled-failure', 'followup-stopped-off', 'followup-stopped-removed', 'followup-stopped-cancelled',
    'followup-stopped-plan', 'followup-queue-outage-401', 'followup-queue-outage-403', 'followup-queue-outage-429', 'followup-queue-outage-503',
    'followup-queue-outage-connection', 'final-transfer-race', 'final-transfer-order', 'final-method-404', 'final-orphans', 'final-retention',
    'final-rotation', 'final-row-400', 'final-row-402', 'final-row-409', 'final-backoff', 'final-diagnostics', 'final-late-notice',
    'final-delay-notice', 'final-replacement', 'final-prune-isolation', 'final-terminal-status', 'final-diagnostics-account', 'final-method-row-400',
    'final-method-row-402', 'final-method-row-409', 'final-logging', 'final-clean-log', 'final-backoff-fresh', 'final-render-pcre',
    'final-method-401', 'final-method-403', 'final-method-429', 'final-method-503', 'final-method-connection'];
$native = [...array_map(fn ($case) => $row('php', [$case], $prepend . ' -d memory_limit=256M ' . __DIR__ . '/run.php'), $expectedCases), ...$late];
foreach (['1', '', 'unset'] as $mode) {
    check($selected[$mode] === $native, 'Native matrix mode ' . ($mode === '' ? 'empty' : $mode) . ' runs every expected checkout case once in order, then notice-native, binding-native, '
        . 'the standalone native files and every registered native suite, each with only its own selection');
}
// Unknown nonempty modes, including "0", stop before any child starts.
foreach (['0', 'Browser', 'native', ' 1'] as $mode) {
    if (is_file($output)) unlink($output);
    [$status, $printed] = $launch([PHP_BINARY, '-n', __DIR__ . '/run.php'], array_replace($stubbed, ['PAY_PENDING_MATRIX' => $mode]));
    check($status !== 0 && str_contains($printed, 'Unknown PAY_PENDING_MATRIX mode "' . $mode . '"') && !is_file($output), 'Matrix mode "' . $mode . '" is rejected before any child starts');
}
$environment = $inherited;
$environment['PAYMENTER_TEST_APP'] = __DIR__ . '/missing-native-app';
$process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($process) !== 0 && str_contains($output, 'Native CI requires provisioned Paymenter dependencies'), 'Missing native dependencies fail explicitly rather than silently skipping');
file_put_contents($fixture . '/child', '#!/bin/sh' . "\n" . 'printf \'%s\' "$PAY_NATIVE_PINNED_APP" > "$PAY_NATIVE_SELECTION_OUTPUT"' . "\nexit 1\n");
chmod($fixture . '/child', 0700);
foreach (['other', 'missing'] as $conflict) {
    $environment = array_replace($inherited, ['PAYMENTER_TEST_APP' => $fixture . '/app', 'PAY_NATIVE_PINNED_APP' => $fixture . '/' . $conflict,
        'PAYMENTER_TEST_PHP' => $fixture . '/child', 'PAY_NATIVE_SELECTION_OUTPUT' => $fixture . '/selected']);
    $process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) !== 0 && str_contains($output, 'Native integration failed: transaction') && is_file($fixture . '/selected')
        && file_get_contents($fixture . '/selected') === $fixture . '/app', 'Every native matrix child uses the validated application despite conflicting ' . $conflict . ' inheritance');
}
// A file log keeps every child's output; CI and local runners read the last line.
file_put_contents($fixture . '/quiet', '#!/bin/sh' . "\n" . "echo child\n");
chmod($fixture . '/quiet', 0700);
$environment = array_replace($inherited, ['PAYMENTER_TEST_APP' => $fixture . '/app', 'PAYMENTER_TEST_PHP' => $fixture . '/quiet', 'PAY_PENDING_MATRIX' => '1']);
$process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/log', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
fclose($pipes[0]); $status = proc_close($process); $lines = file($fixture . '/log', FILE_IGNORE_NEW_LINES);
check($status === 0 && preg_match('/^PASS: ([0-9]+) isolated native runs$/', (string) end($lines), $runs) && $runs[1] > 1 && $lines === [...array_fill(0, (int) $runs[1], 'child'), $runs[0]],
    'A file log keeps every native matrix child line and ends with the summary');
$offline = file_get_contents(__DIR__ . '/run.php');
foreach (require __DIR__ . '/native-suites.php' as $suite) {
    check(is_file(__DIR__ . '/' . $suite . '.php') && !str_contains($offline, "require __DIR__ . '/" . $suite . ".php'"), 'Native suite ' . $suite . ' runs only on the native harness');
}
