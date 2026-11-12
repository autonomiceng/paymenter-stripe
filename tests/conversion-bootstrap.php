<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Optional check against Paymenter's installed vendor directory, without booting its app.
require $argv[1] . '/vendor/autoload.php';
require __DIR__ . '/../extension/AeStripe/ConvertFromStripeSavedCommand.php';

$container = new Illuminate\Foundation\Application($argv[1]);
Illuminate\Container\Container::setInstance($container);
$container->instance('url', new Illuminate\Routing\UrlGenerator(
    new Illuminate\Routing\RouteCollection, Illuminate\Http\Request::create('https://paymenter.example.com')));
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
// No app boot or gateway creation. The database boundary starts with the old identity.
$database = new class {
    public string $extension = 'StripeSaved';
    public array $locks = [];
    public bool $fail = false;
    public function connection() { return $this; }
    public function getDatabaseName() { return 'paymenter'; }
    public function getDriverName() { return 'sqlite'; }
    public function transactionLevel() { return 0; }
    public function transaction($callback) { return $callback(); }
    public function table($name) {
        if ($name === 'ae_stripe_locks') return new class($this) {
            private string $scope;
            public function __construct(private $database) {}
            public function insertOrIgnore($row) {
                if (isset($this->database->locks[$row['scope']])) return 0;
                $this->database->locks[$row['scope']] = $row;
                return 1;
            }
            public function where($key, $value) {
                if ($key !== 'scope') throw new RuntimeException('Unexpected lock key');
                $this->scope = $value; return $this;
            }
            public function lockForUpdate() { return $this; }
            public function firstOrFail() { return (object) $this->database->locks[$this->scope]; }
        };
        if ($name !== 'extensions') throw new RuntimeException('Unexpected table');
        if ($this->fail) throw new RuntimeException('Synthetic private conversion detail');
        return new class($this) {
            public function __construct(private $database) {}
            public function orderBy($key) { return $this; }
            public function lockForUpdate() { return $this; }
            public function where($key, $value) { return $this; }
            public function get() { return [(object) ['id' => 42, 'extension' => $this->database->extension]]; }
            public function update($changes) { $this->database->extension = $changes['extension']; return 1; }
        };
    }
};
Illuminate\Support\Facades\DB::swap($database);
$logger = new class {
    public array $errors = [];
    public function error($message, array $context = []) { $this->errors[] = [$message, $context]; }
};
Illuminate\Support\Facades\Log::swap($logger);
Illuminate\Support\Facades\Schema::swap(new class {
    public function create($table, $callback) {
        if ($table !== 'ae_stripe_locks') throw new RuntimeException('Only the lock schema may be bootstrapped');
    }
    public function getTableListing($schema = null, $schemaQualified = true) {
        if ($schema !== 'paymenter' || $schemaQualified) throw new RuntimeException('Expected current schema and bare names');
        return ['extensions'];
    }
    public function getColumnListing($table) { return ['id', 'extension']; }
    public function hasTable($table) { return false; }
});
$artisan = new Illuminate\Console\Application($container, new Illuminate\Events\Dispatcher($container), 'bootstrap-test');
$artisan->add(new Paymenter\Extensions\Gateways\AeStripe\ConvertFromStripeSavedCommand);
foreach ([[[], 'Dry run'], [['--apply' => true], 'Converted gateway #42'], [[], 'Already converted']] as [$parameters, $expected]) {
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $status = $artisan->call('ae-stripe:convert-from-stripe-saved', $parameters, $output);
    if ($status !== 0 || !str_contains($output->fetch(), $expected)) {
        throw new RuntimeException('Explicit registration did not call the conversion command');
    }
}
$database->fail = true;
$output = new Symfony\Component\Console\Output\BufferedOutput;
$status = $artisan->call('ae-stripe:convert-from-stripe-saved', [], $output);
$message = $output->fetch();
if ($status !== 1 || !str_contains($message, 'Conversion failed. Keep writers and webhook delivery paused.')
    || str_contains($message, 'Synthetic private conversion detail') || $container->bound('config')
    || $logger->errors !== [['AE Stripe conversion failed.', ['exception' => RuntimeException::class, 'code' => 'conversion_failed']]]) {
    throw new RuntimeException('Conversion failure must return its status and sanitized record without booting the app');
}
echo "PASS: explicit Artisan registration, dry/apply dispatch and sanitized failure without app configuration\n";

// A legacy database has neither the target mutex nor its other new extension tables.
$capsule = new Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Illuminate\Support\Facades\DB::swap($capsule->getDatabaseManager());
$schema = $capsule->getConnection()->getSchemaBuilder();
Illuminate\Support\Facades\Schema::swap($schema);
// SQLite names its in-memory schema main; conversion asks for the current database's bare table names.
$capsule->getConnection()->setDatabaseName('main');
$schema->create('extensions', function ($table) {
    $table->integer('id')->primary(); $table->string('extension'); $table->string('name');
});
$schema->create('stripe_saved_payment_fees', function ($table) {
    $table->string('payment_intent_id')->primary(); $table->string('state');
});
Illuminate\Support\Facades\DB::table('extensions')->insert(['id' => 42, 'extension' => 'StripeSaved', 'name' => 'Stripe Saved Methods']);
Illuminate\Support\Facades\DB::table('stripe_saved_payment_fees')->insert(['payment_intent_id' => 'fixture', 'state' => 'pending']);
foreach ([[[], 'Dry run'], [['--apply' => true], 'Converted gateway #42'], [[], 'Already converted']] as [$parameters, $expected]) {
    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $status = $artisan->call('ae-stripe:convert-from-stripe-saved', $parameters, $output);
    if ($status !== 0 || !str_contains($output->fetch(), $expected)) throw new RuntimeException('Real legacy conversion must support dry run, apply and retry');
    if (!$schema->hasTable('ae_stripe_locks') || $schema->hasTable('ae_stripe_outbox') || $schema->hasTable('ae_stripe_settlements')) {
        throw new RuntimeException('Conversion must create only its mutex, without running target migrations');
    }
    if (!$parameters && $expected === 'Dry run' && (!$schema->hasTable('stripe_saved_payment_fees')
        || Illuminate\Support\Facades\DB::table('extensions')->value('extension') !== 'StripeSaved')) throw new RuntimeException('Dry run preserves legacy data');
}
if (Illuminate\Support\Facades\DB::table('ae_stripe_payment_fees')->value('state') !== 'pending'
    || Illuminate\Support\Facades\DB::table('extensions')->value('id') !== 42) throw new RuntimeException('Legacy data and gateway identity must survive');
echo "PASS: real legacy schema conversion without target migrations\n";
