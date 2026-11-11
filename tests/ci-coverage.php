<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// CI coverage: every test file must be loaded by a process in some CI job. tests/entered.php and tests/entered.mjs
// record the loaded files per job; a file named in source but never loaded fails. Suites hold checks. Support files
// load, launch or record suites; they must be loaded too, and loading one never counts for the suites it names.
// Usage: php -n tests/ci-coverage.php DIRECTORY (holding offline.txt, native.txt, browser.txt and mariadb.txt).
$ciCoverage = static function (string $directory): array {
    $support = ['run.php', 'native-pending-matrix.php', 'native-suite.php', 'native-suites.php', 'native-app.php', 'mariadb-app.php', 'temporary-root.php', 'fakes.php', 'entered.php', 'entered.mjs', 'ci-coverage.php'];
    $tests = array_map('basename', array_merge(glob(__DIR__ . '/*.php'), glob(__DIR__ . '/*.mjs')));
    $problems = [];
    foreach (array_diff($support, $tests) as $file) $problems[] = 'Listed support file is missing: ' . $file;
    $loaded = [];
    foreach (['offline', 'native', 'browser', 'mariadb'] as $job) {
        $lines = is_file($directory . '/' . $job . '.txt') ? file($directory . '/' . $job . '.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        if (!$lines) $problems[] = 'The ' . $job . ' job recorded no loaded test files';
        $loaded += array_fill_keys($lines, true);
    }
    $suites = array_diff($tests, $support);
    foreach (['suites' => $suites, 'support files' => array_intersect($tests, $support)] as $kind => $files) {
        $missing = array_diff($files, array_keys($loaded));
        if ($missing) $problems[] = 'No CI job loaded these ' . $kind . ': ' . implode(', ', $missing);
    }
    return [$problems, count($suites), count($tests) - count($suites)];
};
if (get_included_files()[0] !== __FILE__) return $ciCoverage;
[$problems, $suites, $support] = $ciCoverage($argv[1] ?? '');
if ($problems) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(1);
}
echo "PASS: CI loaded all $suites test suites and $support support files\n";
