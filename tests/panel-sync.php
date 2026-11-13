<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// The panel pieces under extension/AeStripe stay exactly what tools/panel_sync.py writes. Its lock records that
// output; tools/panel_sync.py DOMAINS_CHECKOUT --check rebuilds it from Domains.
(function () {
    $root = dirname(__DIR__);
    $lock = json_decode(file_get_contents($root . '/tools/panel_sync.lock.json'), true);
    foreach ($lock['files'] as $name => $digest) {
        check(is_file("$root/$name") && hash_file('sha256', "$root/$name") === $digest, "Panel copy matches tools/panel_sync.py output: $name");
    }
    check(hash_file('sha256', "$root/tools/panel_sync.py") === $lock['script'], 'Panel lock belongs to tools/panel_sync.py');
    check(str_contains(file_get_contents("$root/tools/panel_sync.py"), "REV = '{$lock['rev']}'"), 'Panel lock names the script revision');
    $owned = ['extension/AeStripe/Panel/Presentation/State.php'];
    $found = [];
    foreach (['extension/AeStripe/Panel', 'extension/AeStripe/resources/views/panel'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$folder", FilesystemIterator::SKIP_DOTS)) as $file) {
            $found[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    check(array_values(array_diff($found, array_keys($lock['files']), $owned)) === [], 'Nothing but the copy and State lives in the panel folders');
    check(array_diff($owned, $found) === [], 'AE Stripe owns its State');
})();
