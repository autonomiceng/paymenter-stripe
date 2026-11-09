<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Runs the native suite named by PAY_NATIVE_SUITE against the cached Paymenter release.
$suite = getenv('PAY_NATIVE_SUITE');
if (!in_array($suite, require __DIR__ . '/native-suites.php', true)) throw new RuntimeException('Unknown native suite: ' . $suite);
require __DIR__ . '/native-app.php';
require __DIR__ . '/' . $suite . '.php';
echo "PASS: $count native $suite assertions\n";
