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

use App\Models\User;
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
Http::preventStrayRequests();
Schema::create('users', fn ($t) => $t->id());
Schema::create('extensions', function ($t) {
    $t->id(); $t->string('extension'); $t->string('type'); $t->boolean('enabled'); $t->softDeletes();
});
Schema::create('properties', function ($t) {
    $t->id(); $t->morphs('model'); $t->string('key'); $t->text('value'); $t->timestamps();
});
DB::table('users')->insert(['id' => 7]);
DB::table('extensions')->insert(['extension' => 'AeStripe', 'type' => 'gateway', 'enabled' => true]);
$gateway = new AeStripe([]);
$key = 'ae_stripe_auto_pay_default';
$record = json_encode(['version' => AeStripe::AUTO_PAY_CONSENT, 'user_id' => 7,
    'accepted_at' => time(), 'agreement_id' => 91], JSON_THROW_ON_ERROR);
$count = 0;
foreach ([true, false] as $enabled) {
    DB::table('extensions')->update(['enabled' => $enabled]);
    $user = User::findOrFail(7);
    $user->properties()->updateOrCreate(['key' => $key], ['value' => $record]);
    $request = Request::create('/extensions/ae-stripe/auto-pay-default', 'POST',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        content: '{"enabled":false}');
    $response = $gateway->saveAutoPayDefault($request);
    if ($response->getStatusCode() !== 200 || $response->getData(true) !== ['ok' => true, 'enabled' => false]
        || $user->properties()->where('key', $key)->exists()) {
        throw new RuntimeException('Minimal Off must clear the default with gateway ' . ($enabled ? 'enabled' : 'paused'));
    }
    ++$count;
}
echo "PASS: $count native Auto Pay revocation assertions (enabled and paused)\n";
