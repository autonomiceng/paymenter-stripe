<?php
// Native migration and storage checks use a pristine release's dependencies and in-memory SQLite.
function base_path($path = '') { return str_replace('extensions/Gateways/AeStripe', dirname(__DIR__) . '/extension/AeStripe', $path); }
$release = getenv('PAYMENTER_TEST_APP');
if (!$release || !is_file($release . '/vendor/autoload.php') || file_exists($release . '/.env')) {
    throw new RuntimeException('A pristine PAYMENTER_TEST_APP with vendor and no .env is required');
}
require $release . '/vendor/autoload.php';
require __DIR__ . '/../extension/AeStripe/AeStripe.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\{DatabaseMigrationRepository, Migrator};
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

$container = new Container;
Container::setInstance($container);
$capsule = new Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$schema = $capsule->getConnection()->getSchemaBuilder();
$schema->create('invoice_transactions', fn ($table) => $table->id());
DB::table('invoice_transactions')->insert([['id' => 1], ['id' => 2]]);
$repository = new DatabaseMigrationRepository($capsule->getDatabaseManager(), 'migrations');
$repository->createRepository();
$container->instance(Migrator::class, new Migrator($repository, $capsule->getDatabaseManager(), new Filesystem));
$gateway = new AeStripe([]);
$count = 0;
function verifyNative(bool $condition): void {
    global $count;
    if (!$condition) throw new RuntimeException('Native method check failed: ' . ($count + 1));
    $count++;
}
$gateway->installed();
verifyNative(Schema::hasTable('ae_stripe_transaction_methods'));
$gateway->upgraded('0.1.0');
verifyNative(DB::table('migrations')->count() === 2);
$record = new ReflectionMethod($gateway, 'recordTransactionMethod');
$intent = json_decode('{"latest_charge":{"payment_method_details":{"type":"card","card":{"brand":"visa","last4":"4242","fingerprint":"never-store"}}}}');
DB::transaction(fn () => $record->invoke($gateway, (object) ['id' => 1, 'invoice_id' => 12], $intent));
verifyNative(DB::table('ae_stripe_transaction_methods')->value('label') === 'Visa **** 4242');
$intent->latest_charge->payment_method_details->card->last4 = '5555';
DB::transaction(fn () => $record->invoke($gateway, (object) ['id' => 1, 'invoice_id' => 12], $intent));
verifyNative(DB::table('ae_stripe_transaction_methods')->count() === 1);
verifyNative(DB::table('ae_stripe_transaction_methods')->value('label') === 'Visa **** 4242');
verifyNative(Schema::getColumnListing('ae_stripe_transaction_methods') === ['invoice_transaction_id', 'label']);
try {
    DB::transaction(function () use ($record, $gateway, $intent) {
        $record->invoke($gateway, (object) ['id' => 2, 'invoice_id' => 12], $intent);
        throw new RuntimeException('rollback');
    });
} catch (RuntimeException $e) { if ($e->getMessage() !== 'rollback') throw $e; }
verifyNative(DB::table('ae_stripe_transaction_methods')->count() === 1);
try {
    DB::table('ae_stripe_transaction_methods')->insert(['invoice_transaction_id' => 999, 'label' => 'Invalid']);
    throw new RuntimeException('Foreign key accepted missing transaction');
} catch (Illuminate\Database\QueryException $e) { verifyNative(true); }
// Only disposable fixture records are removed to verify the migration's foreign key.
DB::table('invoice_transactions')->where('id', 1)->delete();
verifyNative(DB::table('ae_stripe_transaction_methods')->count() === 0);
$container->make(Migrator::class)->rollback([__DIR__ . '/../extension/AeStripe/database/migrations']);
verifyNative(!Schema::hasTable('ae_stripe_transaction_methods'));
Schema::create('ae_stripe_transaction_methods', fn ($table) => $table->id());
try {
    $gateway->upgraded('0.1.0');
    throw new RuntimeException('Incomplete existing schema was accepted');
} catch (Illuminate\Database\QueryException $e) { verifyNative(true); }
verifyNative(!DB::table('migrations')->where('migration', '2026_10_26_000001_create_ae_stripe_transaction_methods')->exists());
Schema::drop('ae_stripe_transaction_methods');
$gateway->upgraded('0.1.0');
verifyNative(Schema::hasTable('ae_stripe_transaction_methods'));
echo "PASS: $count native migration and storage assertions\n";
