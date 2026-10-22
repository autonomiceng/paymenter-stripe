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
    foreach ($untrackedProbes as $probe) {
        check(file_put_contents($probe, "untracked\n") !== false, 'Created release omission probe: ' . basename($probe));
    }
    // 27c4cc0 relocated the root traits; install its parent as the prior release.
    $priorRelease = '1d98099a94884137fc5fff7d63373146a5ecc65e';
    [$status, $output] = $uploadProcess(['git', '-C', dirname(__DIR__), 'archive', '--format=tar',
        '--output=' . $uploadDirectory . '/prior.tar', $priorRelease]);
    check($status === 0, 'Prior release archives offline: ' . $output);
    [$status, $output] = $uploadProcess(['python3', '-c',
        'import sys, tarfile; tarfile.open(sys.argv[1]).extractall(sys.argv[2], filter="data")',
        $uploadDirectory . '/prior.tar', $uploadDirectory . '/prior-source']);
    check($status === 0, 'Prior release source extracts offline: ' . $output);
    [$status, $output] = $uploadProcess(['python3', $uploadDirectory . '/prior-source/tools/build_release.py',
        '--output', $uploadDirectory . '/prior.zip']);
    check($status === 0, 'Prior release ZIP builds with its own builder: ' . $output);
    [$status, $output] = $uploadProcess(['python3', '-c',
        'import sys, zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])',
        $uploadDirectory . '/prior.zip', $uploadDirectory . '/installed']);
    check($status === 0 && is_file($uploadDirectory . '/installed/StripeSaved/Checkout.php')
        && !is_dir($uploadDirectory . '/installed/StripeSaved/Concerns'), 'Installed fixture uses prior root-trait layout: ' . $output);
    [$status, $output] = $uploadProcess(['python3', __DIR__ . '/build-release.py']);
    check($status === 0, 'Release entry safety regression tests: ' . $output);
    // The uploaded candidate always comes from the current checkout.
    [$status, $output] = $uploadProcess(['python3', __DIR__ . '/../tools/build_release.py',
        '--output', $uploadDirectory . '/StripeSaved.zip']);
    check($status === 0, 'Native ZIP builds offline: ' . $output);
    [$status, $output] = $uploadProcess(['python3', '-c',
        'import sys, zipfile; archive = zipfile.ZipFile(sys.argv[1]); [archive.extractall(path) for path in sys.argv[2:]]',
        $uploadDirectory . '/StripeSaved.zip', $uploadDirectory . '/extracted']);
    check($status === 0, 'Current native ZIP extracts as the uploaded candidate: ' . $output);
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
