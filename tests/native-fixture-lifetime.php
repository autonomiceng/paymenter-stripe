<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native fixture lifetime: each native process removes the application tree it created and nothing else, and
// the standalone native launcher keeps a shared file log intact.
require_once __DIR__ . '/temporary-root.php';
$lifetime = TemporaryRoot::create('ae-stripe-lifetime-');
// Fixture children never add to this job's evidence or inherit a native selection.
$lifetimeEnvironment = array_diff_key(getenv(), array_fill_keys(['PAY_TEST_ENTERED', 'PAY_PENDING_MATRIX', 'PAYMENTER_TEST_APP', 'PAY_NOTICE_THEME',
    'PAY_CHECKOUT_NATIVE', 'PAY_CLEANUP_CASE', 'PAY_BINDING_CASE', 'PAY_NATIVE_SUITE'], true));
$lifetimeRun = function (array $command, array $environment, array $output) {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => ['redirect', 1]], $pipes, null, $environment);
    fclose($pipes[0]);
    $printed = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    if (isset($pipes[1])) fclose($pipes[1]);
    return [proc_close($process), $printed];
};
/** Every path under a directory with its link target or file contents, without following links. */
$lifetimeTree = function (string $directory) use (&$lifetimeTree): array {
    $tree = [];
    foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
        $path = $directory . '/' . $entry;
        if (is_link($path)) $tree[$entry] = 'link:' . readlink($path);
        elseif (is_dir($path)) foreach ($lifetimeTree($path) as $child => $value) $tree[$entry . '/' . $child] = $value;
        else $tree[$entry] = file_get_contents($path);
    }
    return $tree;
};

// A stand-in for the cached release. Its bootstrap ends the run as each case asks, after recording the tree it
// booted from and linking that tree to a sibling one.
$release = $lifetime . '/release';
foreach (['app', 'config', 'database', 'routes', 'vendor', 'resources', 'public', 'themes/default', 'lang', 'bootstrap'] as $path) {
    mkdir($release . '/' . $path, 0700, true);
    file_put_contents($release . '/' . $path . '/kept.txt', $path);
}
file_put_contents($release . '/vendor/autoload.php', '<?php');
file_put_contents($release . '/bootstrap/providers.php', '<?php return [];');
file_put_contents($release . '/composer.json', '{}');
file_put_contents($release . '/bootstrap/app.php', <<<'PHP'
<?php
$root = dirname(__DIR__);
file_put_contents(getenv('PAY_LIFETIME_OUTPUT'), $root . "\n" . (is_link($root . '/vendor') && is_dir($root . '/storage/logs') ? 'built' : 'incomplete'));
symlink(getenv('PAY_LIFETIME_SIBLING'), $root . '/storage/sibling');
file_put_contents($root . '/storage/logs/run.log', 'written');
match (getenv('PAY_LIFETIME_END')) {
    'success' => exit(0),
    'failure' => exit(3),
    'exception' => throw new RuntimeException('Initialization failed'),
};
PHP);
$temporary = $lifetime . '/temp';
$sibling = $temporary . '/ae-stripe-native-sibling';
mkdir($sibling . '/storage', 0700, true);
file_put_contents($sibling . '/storage/kept.txt', 'sibling');
$releaseTree = $lifetimeTree($release);
$siblingTree = $lifetimeTree($sibling);
$suites = require __DIR__ . '/native-suites.php';
// The suite entry propagates the status; the notice fixture turns any unfinished run into exit 1 from its own exit handler.
foreach (['native-suite.php' => ['success' => 0, 'failure' => 3, 'exception' => 255], 'native-saved-notices.php' => ['success' => 1, 'failure' => 1, 'exception' => 1]] as $entry => $statuses) {
    foreach ($statuses as $end => $expected) {
        $recorded = $lifetime . '/booted';
        if (is_file($recorded)) unlink($recorded);
        [$status, $printed] = $lifetimeRun([PHP_BINARY, '-n', __DIR__ . '/' . $entry], array_replace($lifetimeEnvironment, ['TMPDIR' => $temporary,
            'PAY_NATIVE_PINNED_APP' => $release, 'PAY_NATIVE_SUITE' => $suites[0], 'PAY_LIFETIME_OUTPUT' => $recorded, 'PAY_LIFETIME_SIBLING' => $sibling,
            'PAY_LIFETIME_END' => $end]), ['pipe', 'w']);
        [$root, $built] = array_pad(explode("\n", is_file($recorded) ? file_get_contents($recorded) : ''), 2, '');
        $label = $entry . ' ' . $end;
        check($status === $expected && $built === 'built' && str_starts_with($root, $temporary . '/ae-stripe-'), 'The ' . $label . ' run booted from its own tree and exited ' . $expected . ': ' . $status . ' ' . $printed);
        check(!file_exists($root) && !is_link($root) && array_values(array_diff(scandir($temporary), ['.', '..'])) === ['ae-stripe-native-sibling'], 'The ' . $label . ' run removes its own tree and leaves nothing else behind');
        check($lifetimeTree($release) === $releaseTree && $lifetimeTree($sibling) === $siblingTree, 'The ' . $label . ' run keeps the cached release and the sibling tree it linked to');
    }
}

// The standalone launcher hands its own descriptors to the suite: a file log keeps output written before the
// child started, every child line and the child's exit status.
file_put_contents($lifetime . '/prelaunch.php', '<?php echo "prelaunch\n";');
file_put_contents($lifetime . '/php', '#!/bin/sh' . "\n" . 'printf \'child %s\n\' "$*"' . "\n" . 'echo child done' . "\n" . 'exit "$PAY_LIFETIME_STATUS"' . "\n");
chmod($lifetime . '/php', 0700);
foreach ([0, 3] as $expected) {
    $log = $lifetime . '/launcher-' . $expected . '.log';
    [$status] = $lifetimeRun([PHP_BINARY, '-n', '-d', 'auto_prepend_file=' . $lifetime . '/prelaunch.php', __DIR__ . '/run.php'],
        array_replace($lifetimeEnvironment, ['PAY_NATIVE_SUITE' => $suites[0], 'PAYMENTER_TEST_PHP' => $lifetime . '/php', 'PAY_LIFETIME_STATUS' => (string) $expected]),
        ['file', $log, 'w']);
    check($status === $expected && file($log, FILE_IGNORE_NEW_LINES) === ['prelaunch',
        'child -d auto_prepend_file=' . __DIR__ . '/entered.php -d memory_limit=512M ' . __DIR__ . '/native-suite.php', 'child done'],
        'A standalone native suite with exit ' . $expected . ' keeps the earlier log output, its own command and lines, and its status');
}
