<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Boots the cached Paymenter release on in-memory SQLite with AeStripe installed. Real models,
// listeners, query builder and HTTP client; only Stripe responses are fixtures.
use Illuminate\Database\Events\{QueryExecuted, TransactionCommitted, TransactionRolledBack};
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$release = getenv('PAY_NATIVE_PINNED_APP') ?: getenv('PAYMENTER_TEST_APP');
if (!$release || !is_file($release . '/vendor/autoload.php')) throw new RuntimeException('A cached Paymenter test release is required (PAYMENTER_TEST_APP).');
require_once __DIR__ . '/temporary-root.php';
$root = TemporaryRoot::create('ae-stripe-native-');
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'extensions/Gateways'] as $path) mkdir($root . '/' . $path, 0700, true);
foreach (['app', 'config', 'database', 'routes', 'vendor', 'resources', 'public', 'themes', 'lang'] as $path) symlink($release . '/' . $path, $root . '/' . $path);
foreach (['bootstrap/app.php', 'bootstrap/providers.php', 'composer.json'] as $path) copy($release . '/' . $path, $root . '/' . $path);
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:' . base64_encode(str_repeat('t', 32)), 'APP_URL' => 'https://paymenter.example.com',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'APP_TELEMETRY_ENABLED' => 'false'] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $_SERVER[$key] = $value;
}
require $release . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once $release . '/app/Classes/helpers.php';
config(['hashing.bcrypt.rounds' => 4, 'audit.enabled' => false, 'settings.mail_disabled' => true, 'settings.default_currency' => 'USD',
    'settings.tax_enabled' => false, 'app.version' => 'development']);
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') throw new RuntimeException('In-memory fixture required.');
Http::preventStrayRequests();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
foreach (glob(__DIR__ . '/../extension/AeStripe/database/migrations/*.php') as $migration) (require $migration)->up();
foreach (['default_currency' => 'USD', 'tax_enabled' => '0', 'mail_disabled' => '1'] as $key => $value) {
    App\Models\Setting::withoutEvents(fn () => App\Models\Setting::create(['key' => $key, 'value' => $value]));
}
foreach (['USD' => '$', 'EUR' => '€', 'JPY' => '¥', 'ISK' => 'kr', 'UGX' => 'USh'] as $code => $prefix) {
    App\Models\Currency::create(['code' => $code, 'name' => $code, 'format' => '1,000.00', 'prefix' => $prefix]);
}
App\Models\Invoice::creating(function ($invoice) { $invoice->send_create_email = false; });

// SQLite has no row locks. Render them as SQL comments so tests can prove which rows a
// transaction locked, exactly as MariaDB would receive them.
$connection = DB::connection();
$connection->setQueryGrammar(new class($connection) extends SQLiteGrammar {
    protected function compileLock(Builder $query, $value) { return is_string($value) ? '/* ' . $value . ' */' : ($value ? '/* for update */' : '/* lock in share mode */'); }
});
$GLOBALS['nativeLocks'] = [];
$GLOBALS['nativeBeforeWrite'] = [];
$GLOBALS['nativeAfterCommit'] = null;
DB::listen(function (QueryExecuted $query) {
    if (DB::transactionLevel() > 0 && str_contains($query->sql, '/* for update */') && preg_match('/ from "([a-z_]+)"/', $query->sql, $table)) {
        $GLOBALS['nativeLocks'][$table[1]] = true;
    }
});
Illuminate\Support\Facades\Event::listen(TransactionCommitted::class, function () {
    if (DB::transactionLevel() === 0) $GLOBALS['nativeLocks'] = [];
    if ($hook = $GLOBALS['nativeAfterCommit']) { $GLOBALS['nativeAfterCommit'] = null; $hook(); }
});
Illuminate\Support\Facades\Event::listen(TransactionRolledBack::class, function () { if (DB::transactionLevel() === 0) $GLOBALS['nativeLocks'] = []; });
/** Column values of one INSERT or UPDATE statement, or null for other statements. */
function nativeWrite(string $sql, array $bindings): ?array
{
    if (preg_match('/^insert(?: or ignore)? into "([a-z_]+)" \(([^)]*)\) values/', $sql, $match)) {
        $columns = array_map(fn ($c) => trim($c, ' "'), explode(',', $match[2]));
        return [$match[1], array_combine($columns, array_slice($bindings, 0, count($columns)))];
    }
    if (preg_match('/^update "([a-z_]+)" set (.+?) where /', $sql, $match)) {
        preg_match_all('/"([a-z_]+)" = \?/', $match[2], $columns);
        return [$match[1], array_combine($columns[1], array_slice($bindings, 0, count($columns[1])))];
    }
    return null;
}
$connection->beforeExecuting(function ($sql, $bindings) {
    $level = DB::transactionLevel();
    $locked = str_contains($sql, '/* for update */');
    // Guards that MariaDB and the payment design require but SQLite cannot enforce.
    if (str_starts_with($sql, 'insert into "audits"') && $level === 0) throw new RuntimeException('Review record lock missing');
    if (preg_match('/^select .* from "audits"/', $sql) && $level > 0 && !$locked) throw new RuntimeException('Review guard must use a current read');
    if (preg_match('/^select .* from "invoice_transactions" where "transaction_id" = \? and "status" = \?/', $sql) && $level > 0 && !$locked) {
        throw new RuntimeException('Settlement guard must use a current read');
    }
    if (preg_match('/^select .* from "ae_stripe_receipt_returns"/', $sql) && $locked && $level === 0) throw new RuntimeException('Return read needs transaction');
    if (str_starts_with($sql, 'update "invoice_transactions" set "is_credit_transaction" = ? where "id" = ?') && !holdsLock('users')) {
        throw new RuntimeException('Classification requires payment locks');
    }
    if (!$write = nativeWrite($sql, $bindings)) return;
    [$table, $values] = $write;
    if ($table === 'ae_stripe_receipt_returns') {
        // Allocation and observation writes carry their owner; queue maintenance updates do not.
        if (array_key_exists('user_id', $values) && !holdsLock('users')) throw new RuntimeException('Return write needs owner lock');
        if (strlen((string) ($values['return_key'] ?? '')) > 74) throw new Illuminate\Database\QueryException('sqlite', $sql, $bindings, new PDOException('Data too long for column return_key'));
    }
    foreach ($GLOBALS['nativeBeforeWrite'][$table] ?? [] as $hook) $hook($values, $sql);
});

/** Run a hook before each write to a table; the hook may throw to interrupt the statement. */
function beforeWrite(string $table, ?callable $hook): void
{
    if ($hook) $GLOBALS['nativeBeforeWrite'][$table][] = $hook; else unset($GLOBALS['nativeBeforeWrite'][$table]);
}
/** Whether the open transaction holds a row lock on this table. */
function holdsLock(string $table): bool { return DB::transactionLevel() > 0 && isset($GLOBALS['nativeLocks'][$table]); }
/** Run once after the next committed transaction, as if its acknowledgement were lost. */
function afterNextCommit(callable $hook): void { $GLOBALS['nativeAfterCommit'] = $hook; }

// Row lock order and interleavings. Each locking read is logged by table before it runs. A hook set with
// beforeLock() runs once, just before the next locking read of its table, as another process that commits first.
$GLOBALS['nativeLockLog'] = [];
$GLOBALS['nativeBeforeLock'] = [];
$connection->beforeExecuting(function ($sql) {
    if (!str_contains($sql, '/* for update */') || !preg_match('/^select .*? from "([a-z_]+)"/', $sql, $table)) return;
    if ($hook = $GLOBALS['nativeBeforeLock'][$table[1]] ?? null) { unset($GLOBALS['nativeBeforeLock'][$table[1]]); $hook(); }
    $GLOBALS['nativeLockLog'][] = $table[1];
});
/** Tables locked for update, in statement order, since the process started. */
function lockLog(): array { return $GLOBALS['nativeLockLog']; }
/** Run a hook once, just before the next row lock on this table. */
function beforeLock(string $table, callable $hook): void { $GLOBALS['nativeBeforeLock'][$table] = $hook; }
// Log entries as [level, message, context]; the null log channel still dispatches each entry.
$GLOBALS['nativeLog'] = [];
Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class, function ($entry) { $GLOBALS['nativeLog'][] = [$entry->level, $entry->message, $entry->context]; });
/** Warnings logged since the process started, as [message, context]. */
function warnings(): array
{
    return array_values(array_map(fn ($entry) => [$entry[1], $entry[2]], array_filter($GLOBALS['nativeLog'], fn ($entry) => $entry[0] === 'warning')));
}

$count = 0;
function check($value, $label) {
    global $count;
    if (!$value) throw new RuntimeException($label);
    ++$count;
}
/** Expect an exception message; native aborts compare as "HTTP <status>". */
function rejects($callback, $message) {
    try { $callback(); } catch (Throwable $e) {
        $actual = $e instanceof HttpExceptionInterface ? 'HTTP ' . $e->getStatusCode() : $e->getMessage();
        if ($actual !== $message) throw new RuntimeException('Expected: ' . $message . '; got: ' . $actual, 0, $e);
        ++$GLOBALS['count']; return;
    }
    throw new RuntimeException('Expected: ' . $message);
}
function invoke($gateway, $method, ...$args) { return (new ReflectionMethod($gateway, $method))->invoke($gateway, ...$args); }
function eventRequest($object, $time = null, $type = 'payment_intent.succeeded') {
    if (str_starts_with($type, 'charge.') && !str_starts_with($type, 'charge.dispute.')) $object = (object) array_merge(['paid' => true, 'status' => 'succeeded'], (array) $object);
    $body = json_encode(['type' => $type, 'data' => ['object' => $object]]);
    $time ??= time();
    return Illuminate\Http\Request::create('/extensions/ae-stripe/webhook', 'POST', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, 'whsec_test')], $body);
}

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
    $data = $request->data();
    if (!$data && ($query = parse_url($request->url(), PHP_URL_QUERY))) parse_str($query, $data);
    // Stripe parses form integers; metadata values stay strings.
    $typed = function ($value, $key = null) use (&$typed) {
        if ($key === 'metadata') return $value;
        if (is_array($value)) { foreach ($value as $k => $v) $value[$k] = $typed($v, $k); return $value; }
        return is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/D', $value) ? (int) $value : $value;
    };
    $data = $typed($data);
    $headers = array_map(fn ($values) => $values[0] ?? null, $request->headers());
    $GLOBALS['stripeCalls'][] = [$verb, $path, $data, $headers];
    try { $value = ($GLOBALS['stripeHandler'])($verb, $path, $data, $request); }
    catch (StripeFixtureError $e) { return Http::response($e->body, $e->status); }
    catch (ConnectionException $e) { return Http::failedConnection($e->getMessage())($request); }
    // Encode the body directly so empty Stripe objects stay objects.
    return Http::response(json_encode($value, JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
});
/** Idempotency key of a fixture request. */
function idempotencyKey($request): ?string { return $request->header('Idempotency-Key')[0] ?? null; }

/** Native ledger as invoice:transaction => gateway, amount, status, credit flag. */
function payments(): array
{
    $rows = [];
    foreach (App\Models\InvoiceTransaction::with('gateway')->orderBy('id')->get() as $row) {
        $rows[$row->invoice_id . ':' . $row->transaction_id] = ['gateway' => $row->gateway?->extension, 'amount' => (float) $row->amount,
            'status' => $row->status->value ?? $row->status, 'is_credit_transaction' => (bool) $row->is_credit_transaction];
    }
    return $rows;
}
/** Account credit balances as user:currency => amount. */
function credits(): array
{
    $rows = [];
    foreach (App\Models\Credit::orderBy('id')->get() as $row) $rows[$row->user_id . ':' . strtolower($row->currency_code)] = (float) $row->amount;
    return $rows;
}
/** In-app notifications in creation order. */
function notices(): array { return App\Models\Notification::orderBy('id')->get(['user_id', 'title', 'body', 'url'])->map->toArray()->all(); }
/** Audit records in creation order, with decoded values. */
function audits(): array
{
    return App\Models\Audit::orderBy('id')->get()->map(fn ($a) => ['event' => $a->event, 'user_id' => $a->user_id,
        'auditable_id' => $a->auditable_id, 'old_values' => $a->old_values, 'new_values' => $a->new_values])->all();
}
/** Rows of an extension table keyed by the given columns. */
function tableRows(string $table, array $key): array
{
    $rows = [];
    foreach (DB::table($table)->orderBy('rowid')->get() as $row) $rows[implode(':', array_map(fn ($c) => $row->$c, $key))] = (array) $row;
    return $rows;
}

// Shared fixture: invoice owner 7 with Stripe customer cus_owner, staff 99 who can open
// invoices, gateways and audits, and the enabled AeStripe gateway.
$owner = App\Models\User::forceCreate(['id' => 7, 'first_name' => 'Test', 'last_name' => 'Owner', 'email' => 'user@example.test', 'password' => 'synthetic']);
$owner->properties()->create(['key' => 'stripe_id', 'value' => 'cus_owner']);
$staffRole = App\Models\Role::create(['name' => 'Staff', 'permissions' => ['admin.invoices.viewAny', 'admin.invoices.view', 'admin.invoices.update',
    'admin.gateways.viewAny', 'admin.gateways.update', 'admin.audit.viewAny']]);
$staff = App\Models\User::forceCreate(['id' => 99, 'first_name' => 'Staff', 'last_name' => 'Member', 'email' => 'staff@example.test', 'password' => 'synthetic', 'role_id' => $staffRole->id]);
$nativeGateway = App\Models\Gateway::create(['name' => 'AE Stripe', 'extension' => 'AeStripe', 'type' => 'gateway', 'enabled' => true]);
// Webhook endpoint continuity is covered by its own suite; mark it current so sweeps make no account calls.
$nativeGateway->settings()->create(['key' => 'ae_stripe_webhook_events', 'value' => 'ready']);
require_once __DIR__ . '/../extension/AeStripe/AeStripe.php';
$gateway = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_webhook_secret' => 'whsec_test', 'stripe_secret_key' => 'fake-secret']);
$gateway->boot();
auth()->login($owner);
/** A pending invoice for the owner with one item of the given total. */
function nativeInvoice(int $id, float $total = 20, string $currency = 'USD', ?string $number = null): App\Models\Invoice
{
    $invoice = App\Models\Invoice::forceCreate(['id' => $id, 'number' => $number, 'user_id' => 7, 'currency_code' => $currency,
        'status' => 'pending', 'due_at' => now()->addWeek()]);
    $invoice->items()->create(['quantity' => 1, 'price' => $total, 'description' => 'Fixture']);
    return $invoice->fresh();
}
/** An active service of the owner for a Stripe subscription, billed by the given invoices. */
function nativeService(string $subscriptionId, array $invoices = []): App\Models\Service
{
    static $plan;
    if (!$plan) {
        $category = App\Models\Category::create(['slug' => 'native-fixture', 'name' => 'Fixture']);
        $product = App\Models\Product::create(['category_id' => $category->id, 'name' => 'Fixture', 'slug' => 'native-fixture']);
        $plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
        $plan->prices()->create(['currency_code' => 'USD', 'price' => 20]);
    }
    $service = App\Models\Service::create(['user_id' => 7, 'product_id' => $plan->priceable_id, 'plan_id' => $plan->id, 'price' => 20,
        'currency_code' => 'USD', 'quantity' => 1, 'status' => 'active', 'subscription_id' => $subscriptionId]);
    foreach ($invoices as $invoice) $invoice->items()->update(['reference_type' => App\Models\Service::class, 'reference_id' => $service->id]);
    return $service;
}
