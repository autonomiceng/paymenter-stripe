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
use App\Models\{Credit, Invoice, InvoiceTransaction};
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
$gateway = new AeStripe([]);
$historyInvoice = new Invoice;
$historyInvoice->forceFill(['id' => 54, 'user_id' => 1, 'currency_code' => 'USD']); $historyInvoice->exists = true;
if ($gateway->invoiceReversalHistory($historyInvoice) !== [] || Schema::hasTable('ae_stripe_receipt_returns')) {
    throw new RuntimeException('An invoice read must not install the missing ledger');
}
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
// Domains-shaped native guard: general writers cannot change financial classification.
Schema::create('credits', function ($t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('currency_code'); $t->decimal('amount', 12, 2); $t->timestamps(); });
DB::table('invoices')->insert(['id' => 4, 'user_id' => 1, 'currency_code' => 'USD', 'status' => 'paid']);
DB::table('invoice_items')->insert(['invoice_id' => 4, 'price' => 10, 'quantity' => 1]);
$guardRefusals = 0; $initialPosts = 0;
InvoiceTransaction::updating(function (InvoiceTransaction $row) use (&$guardRefusals) {
    if ($row->invoice_id === 4 && (int) $row->is_credit_transaction !== (int) $row->getRawOriginal('is_credit_transaction')) {
        ++$guardRefusals;
        throw new DomainException('Domain financial evidence is retained; append an audited adjustment.');
    }
});
InvoiceTransaction::created(function (InvoiceTransaction $row) use (&$initialPosts) {
    if ($row->invoice_id === 4 && $row->amount < 0) ++$initialPosts;
});
$receipt['invoice_id'] = 4; $receipt['closed_invoice'] = false; $receipt['payment_intent_id'] = 'pi_guarded';
$allocation = ['amount_minor' => 1000, 'key' => 'ae-return-guarded', 'refund_id' => 're_guarded', 'state' => 'refunded'];
ExtensionHelper::addPayment(Invoice::findOrFail(4), 'AeStripe', 20, null, $receipt['payment_intent_id']);
DB::transaction(fn () => $post->invoke($gateway, Invoice::findOrFail(4), $receipt, $allocation));
$before = (array) DB::table('invoice_transactions')->where('transaction_id', $allocation['key'])->first();
$cash = fn () => (float) DB::table('invoice_transactions')->where('invoice_id', 4)->where('status', 'succeeded')->where('is_credit_transaction', false)->sum('amount');
verifyReturn($initialPosts === 1 && $cash() === 10.0 && Invoice::findOrFail(4)->remaining === 0.0, 'Ordinary initial return keeps native events, cash revenue and invoice totals');
try {
    ExtensionHelper::addPayment(Invoice::findOrFail(4), 'AeStripe', -10, null, $allocation['key'], isCreditTransaction: true);
    throw new RuntimeException('Native classification guard did not run');
} catch (DomainException) {
    verifyReturn($guardRefusals === 1 && $cash() === 10.0, 'Native guard rejects a general event-driven classification change');
}
$allocation['state'] = 'credited';
DB::transaction(function () use ($post, $gateway, $receipt, $allocation) {
    Credit::create(['user_id' => 1, 'currency_code' => 'USD', 'amount' => 10]);
    $post->invoke($gateway, Invoice::findOrFail(4), $receipt, $allocation);
});
$after = (array) DB::table('invoice_transactions')->where('id', $before['id'])->first();
verifyReturn((bool) $after['is_credit_transaction'] && array_replace($after, ['is_credit_transaction' => $before['is_credit_transaction']]) === $before,
    'Owned reclassification changes only its flag, preserving invoice, key, amount, gateway, status and timestamps');
verifyReturn($guardRefusals === 1 && $initialPosts === 1 && DB::table('credits')->count() === 1 && (float) DB::table('credits')->sum('amount') === 10.0,
    'Later failed-refund credit and owned reclassification commit without triggering the native classification guard');
verifyReturn($cash() === 20.0 && Invoice::findOrFail(4)->remaining === 0.0 && Invoice::findOrFail(4)->status === 'paid',
    'Event-free flip restores cash revenue without changing native invoice funding or paid status');
DB::transaction(fn () => $post->invoke($gateway, Invoice::findOrFail(4), $receipt, $allocation));
verifyReturn(DB::table('invoice_transactions')->where('invoice_id', 4)->count() === 2 && $guardRefusals === 1,
    'Repeated owned reclassification retains one native return and leaves the guard active for other writers');

// Display reads use the real SQL builder: no locks, transactions, migrations, provider calls or writes.
$sample = (array) DB::table('ae_stripe_receipt_returns')->first();
$historyRow = function (string $key, array $changes = []) use ($sample) {
    DB::table('ae_stripe_receipt_returns')->insert(array_replace($sample, [
        'payment_intent_id' => 'pi_visible', 'allocation_key' => $key, 'return_key' => hash('sha256', $key.serialize($changes)),
        'invoice_id' => 54, 'user_id' => 1, 'currency' => 'usd', 'amount_minor' => 100,
        'state' => 'refunded', 'updated_at' => 1793489520, 'refund_id' => null,
    ], $changes));
};
$historyRow('stripe:refund:re_first'); $historyRow('stripe:refund:re_second');
$historyRow('stripe:refund:re_pending', ['state' => 'open']);
$historyRow('stripe:dispute:du_lost', ['state' => 'lost', 'amount_minor' => 1200]);
$historyRow('domains:owned', ['refund_id' => 're_owned']);
$historyRow('stripe:refund:re_owned'); // Same money is displayed and totalled once.
$historyRow('domains:credit', ['state' => 'credited']);
$historyRow('stripe:refund:re_other_invoice', ['invoice_id' => 55]);
$historyRow('stripe:refund:re_other_owner', ['user_id' => 2]);
$historyRow('stripe:refund:re_other_currency', ['currency' => 'eur']);
$unchanged = DB::table('ae_stripe_receipt_returns')->get()->toJson();
$queries = []; DB::listen(function ($event) use (&$queries) { $queries[] = $event->sql; });
$history = $gateway->invoiceReversalHistory($historyInvoice);
verifyReturn(count($history) === 5 && count(array_filter($history, fn ($row) => $row['kind'] === 'refund' && $row['status'] === 'refunded')) === 3,
    'History keeps distinct refunds/disputes, deduplicates allocation observations and excludes credits, other owners, invoices and currencies');
verifyReturn(array_sum(array_column(array_filter($history, fn ($row) => $row['kind'] === 'refund' && $row['status'] === 'refunded'), 'amount')) === 3.0,
    'USD refunds retain their stored minor-unit scale');
verifyReturn(!array_filter($queries, fn ($sql) => !str_starts_with(strtolower($sql), 'select') || str_contains(strtolower($sql), 'for update'))
    && DB::transactionLevel() === 0 && DB::table('ae_stripe_receipt_returns')->get()->toJson() === $unchanged,
    'History emits only unlocked SELECTs and leaves the ledger unchanged');
$historyRow('stripe:refund:re_yen', ['currency' => 'jpy']);
$historyInvoice->currency_code = 'JPY';
verifyReturn($gateway->invoiceReversalHistory($historyInvoice)[0]['amount'] === 100.0, 'History reuses the gateway zero-decimal currency conversion');
echo "PASS: $count native receipt storage/accounting assertions\n";
