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
    public function connection() { return $this; }
    public function getDatabaseName() { return 'paymenter'; }
    public function transaction($callback) { return $callback(); }
    public function table($name) {
        if ($name !== 'extensions') throw new RuntimeException('Unexpected table');
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
Illuminate\Support\Facades\Schema::swap(new class {
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
echo "PASS: explicit Artisan registration and dry/apply dispatch with Paymenter vendor classes\n";
