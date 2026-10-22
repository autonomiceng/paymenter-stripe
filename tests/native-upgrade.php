<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
$uploadProcess = function (array $command): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start native upload fixture');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
};
$uploadDirectory = sys_get_temp_dir() . '/stripe-saved-upload-' . bin2hex(random_bytes(8));
mkdir($uploadDirectory, 0700);
// Untracked files in the release sources (e.g. a stray .env) must never be packaged.
$untrackedProbes = [__DIR__ . '/../extension/StripeSaved/.env-release-probe-' . bin2hex(random_bytes(4)),
    __DIR__ . '/../docs/untracked-release-probe-' . bin2hex(random_bytes(4)) . '.md'];
try {
    foreach ($untrackedProbes as $probe) file_put_contents($probe, "untracked\n");
    // Exercise the release builder too: both copies come from the actual native ZIP.
    [$status, $output] = $uploadProcess(['python3', __DIR__ . '/../tools/build_release.py',
        '--output', $uploadDirectory . '/StripeSaved.zip']);
    check($status === 0, 'Native ZIP builds offline: ' . $output);
    [$status, $output] = $uploadProcess(['python3', '-c',
        'import sys, zipfile; archive = zipfile.ZipFile(sys.argv[1]); [archive.extractall(path) for path in sys.argv[2:]]',
        $uploadDirectory . '/StripeSaved.zip', $uploadDirectory . '/installed', $uploadDirectory . '/extracted']);
    check($status === 0, 'Native ZIP extracts into independent installed and uploaded copies: ' . $output);
    foreach ($untrackedProbes as $probe) {
        $entry = $uploadDirectory . '/extracted/StripeSaved/' . (str_contains($probe, '/docs/') ? 'docs/' : '') . basename($probe);
        check(!file_exists($entry), 'Native ZIP omits untracked ' . basename($probe));
    }
    foreach (['installed/StripeSaved', 'absent'] as $installedCopy) {
        [$status, $output] = $uploadProcess([PHP_BINARY, '-n', __DIR__ . '/native-upload-probe.php',
            $uploadDirectory . '/' . $installedCopy, $uploadDirectory . '/extracted/StripeSaved']);
        check($status === 0 && trim($output) === 'PASS: native upload detected gateway StripeSaved',
            'Native upload detects gateway with ' . $installedCopy . ': ' . $output);
    }
} finally {
    foreach ($untrackedProbes as $probe) if (is_file($probe)) unlink($probe);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadDirectory,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($uploadDirectory);
}

// php_strip_whitespace works under php -n without the optional tokenizer extension.
// Ignore comments and quoted strings when checking for non-class declarations.
foreach (glob(__DIR__ . '/../extension/StripeSaved/*.php') as $file) {
    $source = preg_replace('/\x27(?:[^\x27\\\\]|\\\\.)*\x27|"(?:[^"\\\\]|\\\\.)*"/s', '', php_strip_whitespace($file));
    check(preg_match('/\b(?:trait|interface|enum)\s+[A-Za-z_]/', $source) === 0,
        basename($file) . ': native uploader root may declare only classes');
}
