<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Boots the cached Paymenter release on a real MariaDB database with AeStripe installed, so tests can run two
// database sessions at once in separate processes. Real models, listeners, query builder and InnoDB row locks;
// only Stripe responses are fixtures. A missing database fails the run; it is never skipped.
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};

if (!getenv('PAY_MARIADB_PORT')) throw new RuntimeException('The MariaDB lock tests need a database: set PAY_MARIADB_PORT and PAY_MARIADB_PASSWORD.');
$release = getenv('PAY_NATIVE_PINNED_APP') ?: getenv('PAYMENTER_TEST_APP');
if (!$release || !is_file($release . '/vendor/autoload.php')) throw new RuntimeException('A cached Paymenter test release is required (PAYMENTER_TEST_APP).');
require_once __DIR__ . '/temporary-root.php';
$root = TemporaryRoot::create('ae-stripe-mariadb-');
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'extensions/Gateways'] as $path) mkdir($root . '/' . $path, 0700, true);
foreach (['app', 'config', 'database', 'routes', 'vendor', 'resources', 'public', 'themes', 'lang'] as $path) symlink($release . '/' . $path, $root . '/' . $path);
foreach (['bootstrap/app.php', 'bootstrap/providers.php', 'composer.json'] as $path) copy($release . '/' . $path, $root . '/' . $path);
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:' . base64_encode(str_repeat('t', 32)), 'APP_URL' => 'https://paymenter.example.com',
    'DB_CONNECTION' => 'mariadb', 'DB_HOST' => getenv('PAY_MARIADB_HOST') ?: '127.0.0.1', 'DB_PORT' => getenv('PAY_MARIADB_PORT'),
    'DB_DATABASE' => getenv('PAY_MARIADB_DATABASE') ?: 'paymenter_locks', 'DB_USERNAME' => getenv('PAY_MARIADB_USER') ?: 'root',
    'DB_PASSWORD' => (string) getenv('PAY_MARIADB_PASSWORD'), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_TELEMETRY_ENABLED' => 'false'] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $_SERVER[$key] = $value;
}
require $release . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// The console bootstrap renders uncaught errors and exits 0; a failed check must fail the run.
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e . "\n"); exit(1); });
require_once $release . '/app/Classes/helpers.php';
// Event discovery misses the linked app directory; register Paymenter's listeners from the release, as an install does.
foreach (Illuminate\Foundation\Events\DiscoverEvents::within($release . '/app/Listeners', $release) as $event => $listeners) {
    foreach ($listeners as $listener) Illuminate\Support\Facades\Event::listen($event, $listener);
}
config(['hashing.bcrypt.rounds' => 4, 'audit.enabled' => true, 'audit.console' => true, 'settings.mail_disabled' => true, 'settings.default_currency' => 'USD',
    'settings.tax_enabled' => false, 'app.version' => 'development']);
if (DB::connection()->getDriverName() !== 'mariadb' || !str_contains(DB::selectOne('select version() as v')->v, 'MariaDB')) throw new RuntimeException('A MariaDB server is required.');
Http::preventStrayRequests();
// A wait longer than this is a hang, not a lock wait the driver is about to release.
DB::statement('set session innodb_lock_wait_timeout = 20');

// The driver process ($install) recreates the schema with the AeStripe gateway, as a fresh install; workers reuse it.
if (!empty($install)) {
    Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
    foreach (glob(__DIR__ . '/../extension/AeStripe/database/migrations/*.php') as $migration) (require $migration)->up();
    foreach (['default_currency' => 'USD', 'tax_enabled' => '0', 'mail_disabled' => '1'] as $key => $value) {
        App\Models\Setting::withoutEvents(fn () => App\Models\Setting::create(['key' => $key, 'value' => $value]));
    }
    App\Models\Currency::create(['code' => 'USD', 'name' => 'USD', 'format' => '1,000.00', 'prefix' => '$']);
    App\Models\Gateway::create(['name' => 'Other', 'extension' => 'Other', 'type' => 'gateway', 'enabled' => true]);
    $installed = App\Models\Gateway::create(['name' => 'AE Stripe', 'extension' => 'AeStripe', 'type' => 'gateway', 'enabled' => true]);
    $installed->settings()->create(['key' => 'ae_stripe_webhook_events', 'value' => 'ready']);
}
require_once __DIR__ . '/../extension/AeStripe/AeStripe.php';
App\Models\Invoice::creating(function ($invoice) { $invoice->send_create_email = false; });
$gateway = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_webhook_secret' => 'whsec_test', 'stripe_secret_key' => 'fake-secret']);
$gateway->boot();
function invoke($gateway, $method, ...$args) { return (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$args); }

// A process stops before the first statement or Stripe request matching a pause pattern, says "paused" on stdout and
// waits for a line on stdin. The driver uses it to hold one session at a chosen point while the other runs.
$GLOBALS['pauses'] = [];
function pauseBefore(string $pattern): void { $GLOBALS['pauses'][] = $pattern; }
function pausePoint(string $subject): void
{
    foreach ($GLOBALS['pauses'] as $index => $pattern) {
        if (!preg_match($pattern, $subject)) continue;
        unset($GLOBALS['pauses'][$index]);
        fwrite(STDOUT, "paused\n"); fflush(STDOUT);
        if (fgets(STDIN) === false) throw new RuntimeException('The driver stopped while this session was paused.');
    }
}
DB::connection()->beforeExecuting(fn ($sql) => pausePoint($sql));

/** A Stripe API error response; the client raises a real RequestException for it. */
class StripeFixtureError extends RuntimeException
{
    public function __construct(public int $status, public array $body = []) { parent::__construct('Stripe fixture HTTP ' . $status); }
}
// Stripe fixtures: $handler($verb, $path, $data, $request) returns an object/array or throws.
$GLOBALS['stripeHandler'] = fn ($verb, $path) => throw new RuntimeException('Unexpected HTTP request: ' . $path);
$GLOBALS['stripeCalls'] = [];
Http::fake(function ($request) {
    $path = substr(strtok($request->url(), '?'), strlen('https://api.stripe.com/v1'));
    $verb = strtolower($request->method());
    pausePoint(strtoupper($verb) . ' ' . $path);
    $GLOBALS['stripeCalls'][] = [$verb, $path];
    try { $value = ($GLOBALS['stripeHandler'])($verb, $path, $request->data(), $request); }
    catch (StripeFixtureError $e) { return Http::response($e->body, $e->status); }
    catch (ConnectionException $e) { return Http::failedConnection($e->getMessage())($request); }
    return Http::response(json_encode($value, JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
});
