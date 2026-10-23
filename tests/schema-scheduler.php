<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
if (!function_exists('check')) {
    require __DIR__ . '/fakes.php';
    require __DIR__ . '/../extension/StripeSaved/StripeSaved.php';
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
}

use Illuminate\Console\Application;
use Illuminate\Support\Facades\{DB, Schedule, Schema};
use Paymenter\Extensions\Gateways\StripeSaved\StripeSaved;

$resetFeeSchema = function () {
    if (property_exists(StripeSaved::class, 'paymentFeeSchemaReady')) {
        (new ReflectionProperty(StripeSaved::class, 'paymentFeeSchemaReady'))->setValue(null, false);
    }
};
$resetFeeSchema();
$beforeSchedules = count(Schedule::$callbacks);
$beforeCommands = count(Application::$callbacks);
$schemaFailure = new RuntimeException('DDL unavailable');
Schema::$beforeCheck = function () use (&$registrationAtCheck, $schemaFailure) {
    $registrationAtCheck = [count(Schedule::$callbacks), count(Application::$callbacks)];
    throw $schemaFailure;
};
try {
    (new StripeSaved)->boot();
    check($registrationAtCheck[0] === $beforeSchedules + 1, 'Fee schedule registered before schema checks');
    check($registrationAtCheck[1] === $beforeCommands + 1, 'Adoption command registered before schema checks');
    check(in_array($schemaFailure, $GLOBALS['reportedErrors'] ?? [], true), 'Schema failure reported without aborting boot');
} finally {
    Schema::$beforeCheck = null;
}
$scheduledFee = end(Schedule::$events);
check($scheduledFee->singleServer, 'Fee recovery runs on one server');
check($scheduledFee->overlapMinutes === 55, 'Fee recovery mutex expires before the next hourly run');
$schemaTable = DB::$tables['stripe_saved_payment_fees'] ?? null;
unset(DB::$tables['stripe_saved_payment_fees']);
$migration = require __DIR__ . '/../extension/StripeSaved/database/migrations/2026_10_22_000001_create_stripe_saved_payment_fees.php';
Schema::$beforeCreate = function () { DB::$tables['stripe_saved_payment_fees'] = []; };
try {
    $migration->up();
    check(isset(DB::$tables['stripe_saved_payment_fees']), 'Concurrent schema creator does not abort migration');
} finally {
    Schema::$beforeCreate = null;
    if ($schemaTable !== null) DB::$tables['stripe_saved_payment_fees'] = $schemaTable;
    else unset(DB::$tables['stripe_saved_payment_fees']);
}
unset(DB::$tables['stripe_saved_payment_fees']);
Schema::$beforeCreate = function () use ($schemaFailure) { throw $schemaFailure; };
try {
    $caught = null;
    try { $migration->up(); } catch (Throwable $error) { $caught = $error; }
    check($caught === $schemaFailure, 'Migration preserves failures when no table was created');
} finally {
    Schema::$beforeCreate = null;
    if ($schemaTable !== null) DB::$tables['stripe_saved_payment_fees'] = $schemaTable;
}
$schemaChecks = Schema::$checks;
(new StripeSaved)->boot();
(new StripeSaved)->boot();
check(Schema::$checks === $schemaChecks + 1, 'Successful schema check is reused across gateway instances');
$resetFeeSchema();
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) echo "PASS: $count schema/scheduler assertions\n";
