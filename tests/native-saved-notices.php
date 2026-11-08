<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Optional native integration against a cached Paymenter release; only an in-memory fixture is booted.
$successful = false;
register_shutdown_function(static function () use (&$successful) { if (!$successful) exit(1); });
$release = getenv('PAYMENTER_TEST_APP');
if (!$release || !is_file($release . '/vendor/autoload.php')) throw new RuntimeException('A cached Paymenter test release is required.');
$root = sys_get_temp_dir() . '/ae-stripe-notices-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'extensions/Gateways', 'themes'] as $path) mkdir($root . '/' . $path, 0700, true);
foreach (['app', 'config', 'database', 'routes', 'vendor', 'resources', 'public'] as $path) symlink($release . '/' . $path, $root . '/' . $path);
foreach (['bootstrap/app.php', 'bootstrap/providers.php', 'composer.json'] as $path) copy($release . '/' . $path, $root . '/' . $path);
symlink($release . '/themes/default', $root . '/themes/default');
$themeRoot = getenv('PAY_NOTICE_THEME');
$theme = $themeRoot ? 'beco' : 'default';
if ($themeRoot) symlink($themeRoot, $root . '/themes/beco');
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:' . base64_encode(str_repeat('t', 32)), 'APP_URL' => 'http://stripe-tests.invalid',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'APP_TELEMETRY_ENABLED' => 'false'] as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$loader = require $release . '/vendor/autoload.php';
if ($themeRoot) $loader->addPsr4('Paymenter\\Extensions\\Others\\', dirname($themeRoot) . '/extensions/');
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once $release . '/app/Classes/helpers.php';
config(['hashing.bcrypt.rounds' => 4, 'audit.enabled' => false, 'settings.mail_disabled' => true, 'settings.default_currency' => 'USD',
    'settings.tax_enabled' => false, 'settings.theme' => $theme, 'app.version' => 'development']);
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') throw new RuntimeException('In-memory fixture required.');
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
config(['cache.default' => 'database']);
foreach (['cache' => false, 'cache_locks' => true] as $table => $locks) Illuminate\Support\Facades\Schema::create($table, function ($schema) use ($locks) {
    $schema->string('key')->primary();
    if ($locks) $schema->string('owner'); else $schema->text('value');
    $schema->integer('expiration');
});
config(['theme.base_path' => $root . '/themes']);
Qirolab\Theme\Theme::set($theme, $themeRoot ? 'default' : null);
$app['view']->addLocation($release . '/themes/default/views');
// The upstream breadcrumb declares a global function on each render. Isolate that unrelated fixture issue.
$fixtureViews = $root . '/fixture-views';
mkdir($fixtureViews . '/components/navigation', 0700, true);
$breadcrumb = file_get_contents($release . '/themes/default/views/components/navigation/breadcrumb.blade.php');
$breadcrumb = str_replace('    function findBreadcrumb(', "    if (!function_exists('findBreadcrumb')) {\n    function findBreadcrumb(", $breadcrumb);
$breadcrumb = str_replace('    $breadcrumbs = [];', "    }\n    \$breadcrumbs = [];", $breadcrumb);
file_put_contents($fixtureViews . '/components/navigation/breadcrumb.blade.php', $breadcrumb);
$app['view']->prependLocation($fixtureViews);
Illuminate\Support\Facades\Blade::anonymousComponentPath($fixtureViews . '/components');

if ($themeRoot) {
    $app['view']->prependLocation($themeRoot . '/views');
    Illuminate\Support\Facades\Blade::anonymousComponentPath($themeRoot . '/views/components');
}
app('translator')->getLoader()->addPath($release . '/lang');
foreach (['theme' => $theme, 'pagination' => 10, 'default_currency' => 'USD', 'tax_enabled' => '0', 'mail_disabled' => '1'] as $key => $value) {
    App\Models\Setting::withoutEvents(fn () => App\Models\Setting::create(['key' => $key, 'value' => $value]));
}
App\Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$']);
App\Models\Invoice::creating(function ($invoice) { $invoice->send_create_email = false; });
$user = App\Models\User::create(['email' => 'fixture@example.com', 'password' => 'synthetic', 'first_name' => 'Test', 'last_name' => 'Owner']);
auth()->login($user);
$invoice = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'due_at' => now()->addWeek(), 'status' => 'pending']);
$other = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => 'USD', 'due_at' => now()->addWeek(), 'status' => 'pending']);
foreach ([$invoice, $other] as $bill) $bill->items()->create(['quantity' => 1, 'price' => 20, 'description' => 'Fixture']);
$native = App\Models\Gateway::create(['name' => 'AE Stripe', 'extension' => 'AeStripe', 'type' => 'gateway', 'enabled' => true]);
$user->properties()->create(['key' => 'stripe_id', 'value' => 'cus_fixture']);
require __DIR__ . '/../extension/AeStripe/AeStripe.php';
$gateway = new Paymenter\Extensions\Gateways\AeStripe\AeStripe(['stripe_secret_key' => 'rk_test_fixture']);
$gateway->boot();
$checks = 0;
$assert = function ($condition, $label) use (&$checks) { if (!$condition) throw new RuntimeException($label); ++$checks; };
$method = fn ($id) => ['id' => $id, 'type' => 'card', 'customer' => 'cus_fixture', 'allow_redisplay' => 'always',
    'card' => ['fingerprint' => 'fp_fixture', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 6, 'exp_year' => 2030]];
$methods = ['pm_old' => $method('pm_old'), 'pm_new' => $method('pm_new')];
$setupId = 'seti_' . str_repeat('s', 24);
$setup = ['id' => $setupId, 'status' => 'succeeded', 'customer' => 'cus_fixture', 'payment_method' => 'pm_new', 'latest_attempt' => null,
    'metadata' => ['user_id' => (string) $user->id, 'is_billing_agreement' => '1', 'ae_stripe_setup_consent' => 'off_session_v2',
        'ae_stripe_setup_consent_user' => (string) $user->id, 'ae_stripe_setup_consent_at' => '1794071700']];
Illuminate\Support\Facades\Http::fake(function ($request) use (&$methods, $setup, $setupId, $assert) {
    $assert(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'Provider I/O stays outside transactions.');
    $path = parse_url($request->url(), PHP_URL_PATH);
    if ($path === '/v1/setup_intents/' . $setupId) return Illuminate\Support\Facades\Http::response($setup);
    foreach ($methods as $id => &$remote) {
        if ($path !== '/v1/payment_methods/' . $id) continue;
        if ($request->method() === 'POST') $remote['allow_redisplay'] = $request['allow_redisplay'];
        return Illuminate\Support\Facades\Http::response($remote);
    }
    throw new RuntimeException('Unexpected native fixture request.');
});
$delivered = fn ($page) => array_values(array_filter($page->effects['dispatches'] ?? [], fn ($event) => $event['name'] === 'notify'));
foreach (['first', 'duplicate'] as $case) {
    if (getenv('PAY_NOTICE_CASE') && getenv('PAY_NOTICE_CASE') !== $case) continue;
    App\Models\BillingAgreement::query()->forceDelete();
    $user->properties()->where('key', 'like', 'ae_stripe_%')->delete();
    session()->flush();
    if ($case === 'duplicate') App\Models\BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_old']);
    $gateway->setupAgreement(Illuminate\Http\Request::create('/extensions/ae-stripe/setup-agreement', 'GET', ['setup_intent' => $setupId]));
    $expected = session()->get('notification');
    $account = Livewire\Livewire::test(App\Livewire\Client\PaymentMethods::class);
    $assert(($delivered($account)[0]['params'][0] ?? null) == $expected, 'Add card delivers its native account notice.');
    // Start checkout from the same independent agreement/receipt/session state as Add card.
    App\Models\BillingAgreement::query()->forceDelete();
    $user->properties()->where('key', 'like', 'ae_stripe_%')->delete();
    session()->flush();
    if ($case === 'duplicate') App\Models\BillingAgreement::create(['user_id' => $user->id, 'gateway_id' => $native->id, 'name' => 'Visa **** 4242', 'type' => 'visa', 'expiry' => '2030-06-30', 'external_reference' => 'pm_old']);
    $intent = json_decode(json_encode(['id' => 'pi_notice_' . $case, 'status' => 'succeeded', 'customer' => 'cus_fixture', 'payment_method' => 'pm_new',
        'payment_method_options' => ['card' => ['setup_future_usage' => 'off_session']],
        'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1', 'ae_stripe_consent_at' => '1794071700']]));
    (new ReflectionMethod($gateway, 'saveConsentedMethod'))->invoke($gateway, $intent);
    // Finish the JSON response and one unrelated session request before loading the destination.
    session()->save(); session()->start(); session()->save(); session()->start();
    $unrelated = Livewire\Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $other->fresh()]);
    $assert(!$delivered($unrelated), 'Another invoice cannot consume the pending save notice.');
    $page = Livewire\Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $assert(($delivered($page)[0]['params'][0] ?? null) == $expected, 'The destination invoice delivers the saved-card notice after intervening requests.');
    $assert(str_contains($page->html(), 'invoice'), 'The actual invoice view renders with notice delivery.');
    $page = Livewire\Livewire::test(App\Livewire\Invoices\Show::class, ['invoice' => $invoice->fresh()]);
    $assert(!$delivered($page), 'Refreshing the invoice does not deliver the notice twice.');
}
$successful = true;
echo "PASS: $checks native saved notice checks ($theme)\n";
