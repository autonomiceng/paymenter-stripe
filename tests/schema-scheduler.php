<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
if (!function_exists('check')) {
    require __DIR__ . '/fakes.php';
    require __DIR__ . '/../extension/AeStripe/AeStripe.php';
    $count = 0;
    function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }
}

use Illuminate\Console\Application;
use Illuminate\Support\Facades\{DB, Schedule, Schema};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

$resetFeeSchema = function () {
    if (property_exists(AeStripe::class, 'paymentFeeSchemaReady')) {
        (new ReflectionProperty(AeStripe::class, 'paymentFeeSchemaReady'))->setValue(null, false);
    }
};
$scenario = getenv('FEE_SCHEMA_SCENARIO') ?: 'all';
$resetFeeSchema();
$schemaFailure = new RuntimeException('DDL unavailable');
if (in_array($scenario, ['all', 'boot'], true)) {
    $beforeSchedules = count(Schedule::$callbacks);
    $beforeCommands = count(Application::$callbacks);
    $schemaChecks = Schema::$checks;
    $reports = count($GLOBALS['reportedErrors'] ?? []);
    Schema::$beforeCheck = function () use ($schemaFailure) { throw $schemaFailure; };
    try {
        (new AeStripe)->boot();
        (new AeStripe)->boot();
        check(Schema::$checks === $schemaChecks, 'Boot never checks or migrates the fee schema');
        check(count($GLOBALS['reportedErrors'] ?? []) === $reports, 'Boot never reports fee schema failures');
        check(count(Schedule::$callbacks) === $beforeSchedules + 2, 'Boot registers fee recovery without schema access');
        check(count(Application::$callbacks) === $beforeCommands + 2, 'Boot registers adoption command without schema access');
    } finally {
        Schema::$beforeCheck = null;
    }
    $scheduledFee = end(Schedule::$events);
    check($scheduledFee->singleServer, 'Fee recovery runs on one server');
    check($scheduledFee->overlapMinutes === 55, 'Fee recovery mutex expires before the next hourly run');
}
if (in_array($scenario, ['all', 'fee'], true)) {
    $feeTables = DB::$tables;
    $feePayments = \App\Helpers\ExtensionHelper::$payments;
    $feeHandler = \Illuminate\Support\Facades\Http::$handler;
    $feeEnabled = \App\Models\Gateway::$enabled;
    $feeUser = \App\Models\User::$row;
    $feeInvoice = \App\Models\Invoice::$row;
    try {
        \App\Models\Gateway::$enabled = true;
        \App\Models\User::$row = new \App\Models\User(new \App\Models\Properties('cus_owner'));
        \App\Models\Invoice::$row = new \App\Models\Invoice(\App\Models\User::$row);
        \App\Helpers\ExtensionHelper::$payments['12:pi_schema'] = ['gateway' => 'AeStripe', 'amount' => 20.0, 'status' => 'succeeded'];
        \Illuminate\Support\Facades\Http::$handler = function ($verb, $path) {
            check(!DB::$locked, 'First fee path resolves Stripe data before its transaction');
            if ($path === '/balance_transactions/bt_schema') return ['fee' => 42, 'currency' => 'usd'];
            throw new RuntimeException('Unexpected schema fixture request: ' . $path);
        };
        foreach (['webhook', 'scheduler'] as $entry) {
            unset(DB::$tables['ae_stripe_payment_fees']);
            \Illuminate\Database\Migrations\Migrator::$ran = [];
            $resetFeeSchema();
            Schema::$beforeCheck = function () { check(!DB::$locked, 'Fee schema check runs before DB transaction'); };
            Schema::$beforeCreate = function () { check(!DB::$locked, 'Fee table DDL runs before DB transaction'); };
            $firstGateway = new AeStripe(['stripe_webhook_secret' => 'whsec_test', 'stripe_secret_key' => 'fake-secret']);
            if ($entry === 'webhook') {
                $body = json_encode(['type' => 'charge.succeeded', 'data' => ['object' => [
                    'id' => 'ch_schema', 'payment_intent' => 'pi_schema', 'balance_transaction' => 'bt_schema',
                    'paid' => true, 'status' => 'succeeded',
                ]]]);
                $time = time();
                $firstGateway->webhook(new \Illuminate\Http\Request(body: $body,
                    signature: 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, 'whsec_test')));
                check(count(\Illuminate\Database\Migrations\Migrator::$ran) === 1
                    && (DB::$tables['ae_stripe_payment_fees']['pi_schema']['state'] ?? null) === 'completed',
                    'First charge fee path creates the table and records the fee');
            } else {
                $firstGateway->retryPaymentFees();
                check(isset(DB::$tables['ae_stripe_payment_fees']), 'First scheduled fee path creates the table');
            }
            $schemaChecks = Schema::$checks;
            (new AeStripe)->retryPaymentFees();
            check(Schema::$checks === $schemaChecks, 'Successful fee schema check is reused across gateway instances: ' . $entry);
        }
    } finally {
        Schema::$beforeCheck = Schema::$beforeCreate = null;
        DB::$tables = $feeTables;
        \App\Helpers\ExtensionHelper::$payments = $feePayments;
        \Illuminate\Support\Facades\Http::$handler = $feeHandler;
        \App\Models\Gateway::$enabled = $feeEnabled;
        \App\Models\User::$row = $feeUser;
        \App\Models\Invoice::$row = $feeInvoice;
        $resetFeeSchema();
    }
}
$schemaTable = DB::$tables['ae_stripe_payment_fees'] ?? null;
unset(DB::$tables['ae_stripe_payment_fees']);
$migration = require __DIR__ . '/../extension/AeStripe/database/migrations/2026_10_22_000001_create_ae_stripe_payment_fees.php';
Schema::$beforeCreate = function () { DB::$tables['ae_stripe_payment_fees'] = []; };
try {
    $migration->up();
    check(isset(DB::$tables['ae_stripe_payment_fees']), 'Concurrent schema creator does not abort migration');
} finally {
    Schema::$beforeCreate = null;
    if ($schemaTable !== null) DB::$tables['ae_stripe_payment_fees'] = $schemaTable;
    else unset(DB::$tables['ae_stripe_payment_fees']);
}
unset(DB::$tables['ae_stripe_payment_fees']);
Schema::$beforeCreate = function () use ($schemaFailure) { throw $schemaFailure; };
try {
    $caught = null;
    try { $migration->up(); } catch (Throwable $error) { $caught = $error; }
    check($caught === $schemaFailure, 'Migration preserves failures when no table was created');
} finally {
    Schema::$beforeCreate = null;
    if ($schemaTable !== null) DB::$tables['ae_stripe_payment_fees'] = $schemaTable;
}
$resetFeeSchema();
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) echo "PASS: $count schema/scheduler assertions\n";
