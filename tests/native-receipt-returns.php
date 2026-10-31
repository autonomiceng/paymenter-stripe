<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Real Laravel query builder, migration, native models/helper/observers; disposable SQLite only.
$release = getenv('PAYMENTER_TEST_APP');
if (!$release || !is_file($release . '/vendor/autoload.php') || file_exists($release . '/.env')) throw new RuntimeException('Use a pristine PAYMENTER_TEST_APP with vendor and no .env');
require $release . '/vendor/autoload.php';
require __DIR__ . '/../extension/AeStripe/AeStripe.php';

use App\Events\InvoiceTransaction\{Created, Updated};
use App\Helpers\ExtensionHelper;
use App\Listeners\InvoiceTransactionCreatedListener;
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\{DB, Facade, Schema};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

$container = new Container; Container::setInstance($container);
$container->instance('app', $container);
$container->instance('config', new Repository(['audit' => ['enabled' => false]]));
$events = new Dispatcher($container); $container->instance('events', $events);
$capsule = new Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setEventDispatcher($events); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
$capsule->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
Facade::setFacadeApplication($container);
(require __DIR__ . '/../extension/AeStripe/database/migrations/2026_10_31_000001_create_ae_stripe_receipt_returns.php')->up();
$count = 0;
function verifyReturn(bool $ok, string $message): void { global $count; if (!$ok) throw new RuntimeException($message); ++$count; }
$gateway = new AeStripe([]);
$read = new ReflectionMethod($gateway, 'readReceipt'); $write = new ReflectionMethod($gateway, 'writeReceipt');
$user = (object) ['id' => 1];
$receipt = ['invoice_id' => 1, 'payment_intent_id' => 'pi_storage', 'customer' => 'cus_fixture', 'currency' => 'usd',
    'received_minor' => 20000, 'closed_invoice' => false, 'subscription_invoice_id' => 'in_storage', 'allocations' => []];
for ($n = 0; $n < 167; ++$n) {
    $key = str_pad('domains:' . $n . ':', 180, 'x');
    $receipt['allocations'][$key] = ['amount_minor' => 100, 'key' => 'ae-return-' . hash('sha256', $key), 'sent_at' => time(),
        'state' => 'reserved', 'refund_id' => null];
    DB::transaction(fn () => $write->invoke($gateway, $user, 'pi_storage', $receipt, $key));
}
foreach ($receipt['allocations'] as $key => &$a) {
    $a['state'] = 'refunded'; $a['refund_id'] = 're_' . hash('sha256', $key); $a['notified'] = 'refunded:';
    DB::transaction(fn () => $write->invoke($gateway, $user, 'pi_storage', $receipt, $key));
}
unset($a);
$stored = DB::transaction(fn () => $read->invoke($gateway, $user, 'pi_storage'));
verifyReturn(count($stored['allocations']) === 167 && array_sum(array_column($stored['allocations'], 'amount_minor')) === 16700, 'Independent SQL rows retain every completed allocation past the old aggregate TEXT limit');
verifyReturn($stored['subscription_invoice_id'] === 'in_storage', 'Subscription invoice binding survives native storage for recovery');
verifyReturn(DB::table('ae_stripe_receipt_returns')->where('retry', true)->count() === 0, 'Notified completion leaves the due queue');
$sample = (array) DB::table('ae_stripe_receipt_returns')->first();
try { DB::table('ae_stripe_receipt_returns')->insert($sample); throw new RuntimeException('Duplicate allocation accepted'); }
catch (Illuminate\Database\QueryException) { verifyReturn(true, 'Receipt/allocation unique constraint refuses duplicate storage'); }
verifyReturn((bool) array_filter(Schema::getIndexes('ae_stripe_receipt_returns'), fn ($index) => $index['columns'] === ['state', 'retry', 'updated_at']), 'Due work uses a state/retry/time index');

// Core's actual transaction-created listener changes even a cancelled invoice to paid.
Schema::create('extensions', function ($t) { $t->id(); $t->string('extension'); $t->string('type'); $t->softDeletes(); });
Schema::create('invoices', function ($t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('currency_code'); $t->string('status'); $t->timestamps(); });
Schema::create('invoice_items', function ($t) { $t->id(); $t->unsignedBigInteger('invoice_id'); $t->decimal('price', 12, 2); $t->unsignedInteger('quantity'); });
Schema::create('invoice_transactions', function ($t) { $t->id(); $t->unsignedBigInteger('invoice_id'); $t->unsignedBigInteger('gateway_id')->nullable(); $t->string('transaction_id'); $t->decimal('amount', 12, 2); $t->decimal('fee', 12, 2)->nullable(); $t->string('status'); $t->boolean('is_credit_transaction'); $t->timestamps(); });
DB::table('extensions')->insert(['id' => 1, 'extension' => 'AeStripe', 'type' => 'gateway']);
foreach ([1, 2, 3] as $id) {
    DB::table('invoices')->insert(['id' => $id, 'user_id' => 1, 'currency_code' => 'USD', 'status' => 'cancelled']);
    DB::table('invoice_items')->insert(['invoice_id' => $id, 'price' => 10, 'quantity' => 1]);
}
$activation = new class { public $calls = 0; public function handle(Invoice $invoice): void { ++$this->calls; } };
$container->instance(App\Services\Invoice\ProcessPaidInvoiceService::class, $activation);
$listener = new InvoiceTransactionCreatedListener;
$events->listen(Created::class, fn ($event) => $listener->handle($event));
$events->listen(Updated::class, fn ($event) => $listener->handle($event));
ExtensionHelper::addPayment(Invoice::findOrFail(1), 'AeStripe', 20, null, 'pi_control');
verifyReturn(Invoice::findOrFail(1)->status === 'paid' && $activation->calls === 1, 'Unprotected native helper really reopens a closed invoice and reaches paid processing');
DB::table('invoice_transactions')->where('invoice_id', 1)->delete();
$post = new ReflectionMethod($gateway, 'postReceiptReturn');
$receipt['closed_invoice'] = true; $receipt['received_minor'] = 2000;
foreach (['refunded', 'credited'] as $n => $state) {
    $id = $n + 2; $receipt['payment_intent_id'] = 'pi_closed' . $id;
    $allocation = ['amount_minor' => 2000, 'key' => 'ae-return-' . $id, 'state' => $state];
    DB::transaction(fn () => $post->invoke($gateway, Invoice::findOrFail($id), $receipt, $allocation));
    DB::transaction(fn () => $post->invoke($gateway, Invoice::findOrFail($id), $receipt, $allocation));
    verifyReturn(Invoice::findOrFail($id)->status === 'cancelled' && $activation->calls === 1, 'Closed return suppresses actual native paid/activation observers');
    verifyReturn(DB::table('invoice_transactions')->where('invoice_id', $id)->count() === 2 && (float) DB::table('invoice_transactions')->where('invoice_id', $id)->sum('amount') === 0.0, 'Closed receipt and return commit as one deduplicated zero-net native pair');
    $cash = (float) DB::table('invoice_transactions')->where('invoice_id', $id)->where('status', 'succeeded')->where('is_credit_transaction', false)->sum('amount');
    verifyReturn($cash === ($state === 'credited' ? 20.0 : 0.0), 'Native revenue distinguishes a credit reclassification from outgoing cash');
}
echo "PASS: $count native receipt storage/accounting assertions\n";
