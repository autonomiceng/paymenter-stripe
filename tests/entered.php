<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// CI coverage evidence for tests/ci-coverage.php: with PAY_TEST_ENTERED set, append every test file this process loaded.
if (!defined('AE_STRIPE_TESTS_ENTERED')) {
    define('AE_STRIPE_TESTS_ENTERED', true);
    (static function () {
        $log = getenv('PAY_TEST_ENTERED');
        if ($log === false || $log === '') return;
        register_shutdown_function(static function () use ($log) {
            $files = array_map('basename', array_filter(get_included_files(), fn ($file) => dirname($file) === __DIR__));
            if ((!is_dir(dirname($log)) && !mkdir(dirname($log), 0777, true)) || file_put_contents($log, implode("\n", $files) . "\n", FILE_APPEND | LOCK_EX) === false) {
                fwrite(STDERR, "Could not record the loaded test files in PAY_TEST_ENTERED.\n");
                exit(1);
            }
        });
    })();
}
