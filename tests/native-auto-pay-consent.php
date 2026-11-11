<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// The offline Request fake skips validation. Use Laravel's actual request macro and
// validator here: an implicit accepted rule can reject Off before its delete branch.
// Only dependencies/native models are loaded; all data lives in disposable SQLite.
$release = getenv('PAYMENTER_TEST_APP');
if (!$release || !is_file($release . '/vendor/autoload.php') || file_exists($release . '/.env')) {
    throw new RuntimeException('Use a pristine PAYMENTER_TEST_APP with vendor and no .env');
}
require $release . '/vendor/autoload.php';
require __DIR__ . '/../extension/AeStripe/AeStripe.php';

use App\Models\{Service, User};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Facade, Http, Schema};
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\Factory;
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

$container = new Container; Container::setInstance($container);
$container->instance('app', $container);
$container->instance('config', new Repository(['audit' => ['enabled' => false]]));
$container->instance('events', new Dispatcher($container));
$container->instance('auth', new class { public function id() { return 7; } });
// JSON responses do not need the factory's view/redirect services.
$container->instance(Illuminate\Contracts\Routing\ResponseFactory::class,
    (new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$container->instance(Illuminate\Contracts\Validation\Factory::class,
    new Factory(new Translator(new ArrayLoader, 'en'), $container));
(new FoundationServiceProvider($container))->registerRequestValidation();
$capsule = new Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setEventDispatcher($container['events']); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
(require __DIR__ . '/../extension/AeStripe/database/migrations/2026_11_11_000001_create_ae_stripe_locks.php')->up();
Http::preventStrayRequests();
Schema::create('users', fn ($t) => $t->id());
Schema::create('extensions', function ($t) {
    $t->id(); $t->string('extension'); $t->string('type'); $t->boolean('enabled'); $t->softDeletes();
});
Schema::create('properties', function ($t) {
    $t->id(); $t->morphs('model'); $t->string('key'); $t->text('value'); $t->timestamps();
});
// Off also releases the saved card and every linked service, so their native tables exist.
Schema::create('billing_agreements', function ($t) {
    $t->id(); $t->foreignId('user_id'); $t->foreignId('gateway_id'); $t->timestamps(); $t->softDeletes();
});
Schema::create('services', function ($t) {
    $t->id(); $t->foreignId('user_id'); $t->string('subscription_id')->nullable(); $t->foreignId('billing_agreement_id')->nullable(); $t->timestamps();
});
DB::table('users')->insert([['id' => 7], ['id' => 8]]);
DB::table('extensions')->insert(['extension' => 'AeStripe', 'type' => 'gateway', 'enabled' => true]);
DB::table('billing_agreements')->insert([['id' => 91, 'user_id' => 7, 'gateway_id' => 1], ['id' => 92, 'user_id' => 8, 'gateway_id' => 1]]);
$gateway = new AeStripe([]);
$key = 'ae_stripe_auto_pay_default';
$pendingKey = 'ae_stripe_auto_pay_pending';
$record = json_encode(['version' => AeStripe::AUTO_PAY_CONSENT, 'user_id' => 7,
    'accepted_at' => time(), 'agreement_id' => 91], JSON_THROW_ON_ERROR);
$count = 0;
foreach ([true, false] as $enabled) {
    DB::table('extensions')->update(['enabled' => $enabled]);
    DB::table('services')->delete(); DB::table('properties')->delete();
    $user = User::findOrFail(7);
    $user->properties()->updateOrCreate(['key' => $key], ['value' => $record]);
    // Service 7 shares its id with user 7, so the user's same-key row proves the polymorphic type filter.
    $user->properties()->create(['key' => $pendingKey, 'value' => $record]);
    // Linked and waiting services of the owner, then a Stripe subscription and a foreign owner that Off must keep.
    foreach ([[7, 7, null, 91], [9, 7, null, null], [10, 7, 'sub_fixture', 91], [8, 8, null, 92]] as [$id, $owner, $subscription, $agreement]) {
        Service::forceCreate(['id' => $id, 'user_id' => $owner, 'subscription_id' => $subscription, 'billing_agreement_id' => $agreement])
            ->properties()->create(['key' => $pendingKey, 'value' => $record]);
    }
    $request = Request::create('/extensions/ae-stripe/auto-pay-default', 'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        content: '{"enabled":false}');
    $response = $gateway->saveAutoPayDefault($request);
    if ($response->getStatusCode() !== 200 || $response->getData(true) !== ['ok' => true, 'enabled' => false]
        || $user->properties()->where('key', $key)->exists()) {
        throw new RuntimeException('Minimal Off must clear the default with gateway ' . ($enabled ? 'enabled' : 'paused'));
    }
    ++$count;
    $state = fn ($id) => [Service::findOrFail($id)->billing_agreement_id,
        DB::table('properties')->where('model_type', Service::class)->where('model_id', $id)->where('key', $pendingKey)->count()];
    $released = $state(7) === [null, 0] && $state(9) === [null, 0];
    $kept = $state(10) === [91, 1] && $state(8) === [92, 1]
        && $user->properties()->where('key', $pendingKey)->count() === 1;
    if (!$released || !$kept) {
        throw new RuntimeException('Off must release only the owner\'s non-subscription services with gateway ' . ($enabled ? 'enabled' : 'paused'));
    }
    ++$count;
}
echo "PASS: $count native Auto Pay revocation assertions (enabled and paused)\n";
