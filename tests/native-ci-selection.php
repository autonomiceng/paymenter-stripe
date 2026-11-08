<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Configuration coverage: a passing offline job alone must not hide missing native CI.
$workflow = file_get_contents(__DIR__ . '/../.github/workflows/ci.yml');
check(str_contains($workflow, 'PAY_PENDING_MATRIX:') && str_contains($workflow, 'PAYMENTER_TEST_APP:') && str_contains($workflow, 'composer install') && str_contains($workflow, "php-version: '8.4.27'"), 'CI provisions and selects the native pending integration matrix');
check(str_contains($workflow, 'php -n tests/run.php') && !str_contains($workflow, 'PAY_NOTICE_THEME:') && !str_contains($workflow, 'secrets.'), 'Public integration keeps stripped offline CI and never provisions private themes or credentials');
$environment = getenv();
$environment['PAYMENTER_TEST_APP'] = __DIR__ . '/missing-native-app';
$process = proc_open([PHP_BINARY, '-n', __DIR__ . '/native-pending-matrix.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
check(proc_close($process) !== 0 && str_contains($output, 'Native CI requires provisioned Paymenter dependencies'), 'Missing native dependencies fail explicitly rather than silently skipping');
