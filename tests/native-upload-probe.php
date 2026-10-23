<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Run in a fresh process: an installed trait redeclaration is an uncatchable fatal error.
require __DIR__ . '/fakes.php';

[$script, $installed, $extracted] = $argv;
$prefix = 'Paymenter\\Extensions\\Gateways\\AeStripe\\';
spl_autoload_register(function ($class) use ($prefix, $installed) {
    if (!str_starts_with($class, $prefix)) return;
    $file = $installed . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

// Paymenter 1.5.9 UploadExtensionService::getExtensionType(), including its
// class_exists() check (which autoloads traits but still returns false).
$type = ['class' => null, 'type' => null];
foreach (glob($extracted . '/*.php') as $file) {
    $content = file_get_contents($file);
    if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
        if (!class_exists($matches[1] . '\\' . pathinfo(basename($file), PATHINFO_FILENAME))) {
            require_once $file;
        }
        $namespace = $matches[1];
        if (preg_match('/^\s*class\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:extends|implements|\{)/m', $content, $classMatches)) {
            $type['class'] = $classMatches[1];
            $fullClassName = $namespace . '\\' . $type['class'];
            if (is_subclass_of($fullClassName, App\Classes\Extension\Server::class)) {
                $type['type'] = 'server';
            } elseif (is_subclass_of($fullClassName, App\Classes\Extension\Gateway::class)) {
                $type['type'] = 'gateway';
            } elseif (is_subclass_of($fullClassName, App\Classes\Extension\Extension::class)) {
                $type['type'] = 'other';
            }
            if ($type['class'] && $type['type']) break;
        }
    }
}
if ($type !== ['class' => 'AeStripe', 'type' => 'gateway']) {
    throw new RuntimeException('Native upload did not detect AeStripe as a gateway');
}
$expected = is_file($installed . '/AeStripe.php') ? $installed : $extracted;
$reflection = new ReflectionClass($prefix . 'AeStripe');
if ($reflection->getFileName() !== $expected . '/AeStripe.php') {
    throw new RuntimeException('Native upload did not use the expected gateway copy');
}
if ($expected === $extracted) {
    // The uploader deletes extraction before calling the already loaded class.
    $GLOBALS['paymenterBase'] = dirname($extracted) . '/fee-app';
    $destination = base_path('extensions/Gateways/AeStripe');
    mkdir(dirname($destination), 0700, true);
    rename($extracted, $destination);
    $gateway = new ($prefix . 'AeStripe');
    $gateway->installed();
    if (!isset(\Illuminate\Support\Facades\DB::$tables['ae_stripe_payment_fees'])) {
        throw new RuntimeException('Fresh upload did not migrate from the installed directory');
    }
}
echo "PASS: native upload detected gateway AeStripe\n";
