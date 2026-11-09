<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Configuration coverage: a passing offline job alone must not hide missing native CI.
$workflow = file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
check(str_contains($workflow, 'PAY_PENDING_MATRIX:') && str_contains($workflow, 'PAYMENTER_TEST_APP:') && str_contains($workflow, 'composer install') && str_contains($workflow, "php-version: '8.4.27'"), 'CI provisions and selects the native pending integration matrix');
check(str_contains($workflow, 'php -n tests/run.php') && !str_contains($workflow, 'PAY_NOTICE_THEME:') && !str_contains($workflow, 'secrets.'), 'Public integration keeps stripped offline CI and never provisions private themes or credentials');
check(str_contains($workflow, 'PAY_PENDING_MATRIX: browser') && str_contains($workflow, 'PLAYWRIGHT_MODULE:') && str_contains($workflow, 'playwright@1.58.2') && str_contains($workflow, 'install --with-deps chromium'), 'CI provisions Playwright and selects the browser matrix');
// Every test file runs in CI: a workflow command names it, or a file those commands run loads it by a quoted name.
$tests = array_map('basename', array_merge(glob(__DIR__ . '/*.php'), glob(__DIR__ . '/*.mjs')));
$named = fn ($pattern, $source) => preg_match_all($pattern, $source, $found) ? array_values(array_intersect(array_unique($found[1]), $tests)) : [];
$reached = []; $queue = $named('#tests/([\w-]+\.(?:php|mjs))\b#', $workflow);
while ($queue) {
    $file = array_shift($queue);
    if (isset($reached[$file])) continue;
    $reached[$file] = true;
    array_push($queue, ...$named('#[\'"]/?([\w-]+\.(?:php|mjs))[\'"]#', file_get_contents(__DIR__ . '/' . $file)));
    // native-suites.php lists suites by name; native-suite.php loads each as '<name>.php'.
    if ($file === 'native-suites.php') array_push($queue, ...array_intersect(array_map(fn ($suite) => $suite . '.php', require __DIR__ . '/native-suites.php'), $tests));
}
$unreached = array_diff($tests, array_keys($reached));
check(!$unreached, 'Every test file runs in CI; unreached: ' . implode(', ', $unreached));
// Environment-selected paths are reached only when the matrix selects them.
$matrix = file_get_contents(__DIR__ . '/native-pending-matrix.php');
foreach (["'PAY_CLEANUP_CASE' => 'notice-native'", "'PAY_BINDING_CASE' => 'native'", "'PAY_CHECKOUT_NATIVE' => 'final-browser'"] as $selection) {
    check(str_contains($matrix, $selection), 'The native matrix selects ' . $selection);
}
$environment = getenv();
$environment['PAYMENTER_TEST_APP'] = __DIR__ . '/missing-native-app';
$process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($process) !== 0 && str_contains($output, 'Native CI requires provisioned Paymenter dependencies'), 'Missing native dependencies fail explicitly rather than silently skipping');
$fixture = sys_get_temp_dir() . '/ae-stripe-matrix-' . bin2hex(random_bytes(6));
mkdir($fixture . '/app/vendor', 0700, true); mkdir($fixture . '/other/vendor', 0700, true);
file_put_contents($fixture . '/app/vendor/autoload.php', '<?php'); file_put_contents($fixture . '/other/vendor/autoload.php', '<?php');
file_put_contents($fixture . '/child', '#!/bin/sh' . "\n" . 'printf \'%s\' "$PAY_NATIVE_PINNED_APP" > "$PAY_NATIVE_SELECTION_OUTPUT"' . "\nexit 1\n");
chmod($fixture . '/child', 0700);
foreach (['other', 'missing'] as $conflict) {
    $environment = array_replace(getenv(), ['PAYMENTER_TEST_APP' => $fixture . '/app', 'PAY_NATIVE_PINNED_APP' => $fixture . '/' . $conflict,
        'PAYMENTER_TEST_PHP' => $fixture . '/child', 'PAY_NATIVE_SELECTION_OUTPUT' => $fixture . '/selected']);
    $process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) !== 0 && str_contains($output, 'Native integration failed: transaction') && is_file($fixture . '/selected')
        && file_get_contents($fixture . '/selected') === $fixture . '/app', 'Every native matrix child uses the validated application despite conflicting ' . $conflict . ' inheritance');
}
// A file log keeps every child's output; CI and local runners read the last line.
file_put_contents($fixture . '/quiet', '#!/bin/sh' . "\n" . "echo child\n");
chmod($fixture . '/quiet', 0700);
$environment = array_replace(getenv(), ['PAYMENTER_TEST_APP' => $fixture . '/app', 'PAYMENTER_TEST_PHP' => $fixture . '/quiet', 'PAY_PENDING_MATRIX' => '1']);
$process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/log', 'w'], 2 => ['redirect', 1]], $pipes, null, $environment);
fclose($pipes[0]); $status = proc_close($process); $lines = file($fixture . '/log', FILE_IGNORE_NEW_LINES);
check($status === 0 && preg_match('/^PASS: ([0-9]+) isolated native runs$/', (string) end($lines), $runs) && $runs[1] > 1 && $lines === [...array_fill(0, (int) $runs[1], 'child'), $runs[0]],
    'A file log keeps every native matrix child line and ends with the summary');
$offline = file_get_contents(__DIR__ . '/run.php');
foreach (require __DIR__ . '/native-suites.php' as $suite) {
    check(is_file(__DIR__ . '/' . $suite . '.php') && !str_contains($offline, "require __DIR__ . '/" . $suite . ".php'"), 'Native suite ' . $suite . ' runs only on the native harness');
}
check(str_contains(file_get_contents(__DIR__ . '/native-pending-matrix.php'), "require __DIR__ . '/native-suites.php'"), 'Native CI runs every native suite');
