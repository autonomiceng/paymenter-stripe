<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Two customers' payment requests at once, on real MariaDB sessions (tests/mariadb-app.php). Each case runs request A
// in one process until it reaches a chosen point inside its transaction and holds it there. Request B then runs in a
// second process until it finishes or InnoDB makes it wait on A. A then continues. Any lock cycle between the two is
// a deadlock that InnoDB reports and counts. Each case requires B to wait on A exactly when it says so ('waits'), the
// deadlocks it makes on purpose and no other ('deadlocks'), both requests finishing, and the rows each one owes, once.
// Usage: PAY_MARIADB_PORT=... PAY_MARIADB_PASSWORD=... php tests/mariadb-locks.php
use App\Models\{Invoice, User};
use Illuminate\Support\Facades\DB;

$install = ($argv[1] ?? null) !== 'worker';
require __DIR__ . '/mariadb-app.php';
$count = 0;
function check($value, $label) { global $count; if (!$value) throw new RuntimeException($label); ++$count; }

/** Customer $id, with Stripe customer cus_$id, owning a pending invoice of the total. */
function customerInvoice(int $userId, int $invoiceId, float $total = 20): Invoice
{
    $user = User::find($userId) ?? User::forceCreate(['id' => $userId, 'first_name' => 'Customer', 'last_name' => (string) $userId,
        'email' => "customer$userId@example.test", 'password' => 'synthetic']);
    if (!$user->properties()->where('key', 'stripe_id')->exists()) $user->properties()->create(['key' => 'stripe_id', 'value' => 'cus_' . $userId]);
    $invoice = Invoice::forceCreate(['id' => $invoiceId, 'user_id' => $userId, 'currency_code' => 'USD', 'status' => 'pending', 'due_at' => now()->addWeek()]);
    $invoice->items()->create(['quantity' => 1, 'price' => $total, 'description' => 'Fixture']);
    return $invoice;
}
/** The Stripe PaymentIntent pi_<invoice> of an invoice's customer, as Stripe reports it. */
function intentOf(int $invoiceId, string $status, int $received = 0): object
{
    $invoice = Invoice::findOrFail($invoiceId);
    return (object) ['id' => 'pi_' . $invoiceId, 'object' => 'payment_intent', 'status' => $status, 'amount' => 2000, 'amount_received' => $received,
        'currency' => 'usd', 'customer' => 'cus_' . $invoice->user_id, 'confirmation_method' => 'manual', 'payment_method_types' => ['card'],
        'metadata' => (object) ['invoice_id' => (string) $invoiceId], 'latest_charge' => (object) ['id' => 'ch_' . $invoiceId, 'disputed' => false,
            'payment_method_details' => (object) ['type' => 'card', 'card' => (object) ['brand' => 'visa', 'last4' => '4242']]]];
}
/** Settle an invoice's PaymentIntent as its succeeded webhook does, and any receipt return it queues after commit. */
function settle($gateway, int $invoiceId, int $received = 2000): string
{
    invoke($gateway, 'recordIntentPayment', intentOf($invoiceId, 'succeeded', $received), 'succeeded');
    return 'settled';
}
/** The customer's pending invoice for a subscription service, sub_<invoice>. */
function subscriptionInvoice(int $userId, int $invoiceId): void
{
    $invoice = customerInvoice($userId, $invoiceId);
    $product = App\Models\Product::create(['category_id' => App\Models\Category::create(['slug' => 'locks' . $invoiceId, 'name' => 'Locks'])->id,
        'name' => 'Locks', 'slug' => 'locks' . $invoiceId]);
    $plan = $product->plans()->create(['name' => 'Monthly', 'type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 1]);
    $service = App\Models\Service::create(['user_id' => $userId, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 20,
        'currency_code' => 'USD', 'quantity' => 1, 'status' => 'active', 'subscription_id' => 'sub_' . $invoiceId]);
    $invoice->items()->update(['reference_type' => App\Models\Service::class, 'reference_id' => $service->id]);
}
/** The paid Stripe invoice of that subscription, paid with pi_<invoice>. */
function paidSubscription(int $invoiceId): object
{
    return (object) ['id' => 'in_' . $invoiceId, 'currency' => 'usd', 'amount_paid' => 2000, 'metadata' => (object) ['ae_stripe_invoice_id' => (string) $invoiceId],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_' . $invoiceId]]];
}
// Stripe as the requests see it: Pay creates one intent per invoice, a refund of a receipt return fails, so the
// return becomes account credit, a confirmation of pi_401 is declined, and each charge's fee is 0.50 USD.
$GLOBALS['stripeHandler'] = function ($verb, $path, $data) {
    static $declined = false;
    if ($verb === 'get' && preg_match('#^/customers/(cus_\d+)$#', $path, $id)) return ['id' => $id[1]];
    if ($verb === 'post' && $path === '/customer_sessions') return ['client_secret' => 'cuss_' . $data['customer']];
    if ($verb === 'post' && $path === '/payment_intents') return ['id' => 'pi_' . $data['metadata']['invoice_id'], 'status' => 'requires_payment_method',
        'amount' => $data['amount'], 'amount_received' => 0, 'currency' => strtolower($data['currency']), 'customer' => $data['customer'],
        'confirmation_method' => 'automatic', 'payment_method_types' => $data['payment_method_types'], 'latest_charge' => null,
        'client_secret' => 'pi_secret', 'metadata' => $data['metadata']];
    if (preg_match('#^/payment_intents/pi_(\d+)(/confirm)?$#', $path, $id)) {
        // Invoice 401 is paid with Pay now; every other intent has succeeded, overpaying 6xx invoices.
        if ($id[1] !== '401') return intentOf((int) $id[1], 'succeeded', $id[1][0] === '6' ? 3000 : 2000);
        $intent = intentOf(401, 'requires_payment_method');
        $intent->latest_charge = null;
        if (($id[2] ?? '') === '/confirm') $declined = true;
        if ($declined) $intent->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'];
        if (($id[2] ?? '') === '/confirm') throw new StripeFixtureError(402, ['error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds']]);
        return $intent;
    }
    if ($verb === 'get' && preg_match('#^/balance_transactions/(txn_\d+)$#', $path, $id)) return ['id' => $id[1], 'fee' => 50, 'currency' => 'usd'];
    if ($verb === 'get' && $path === '/refunds') return ['has_more' => false, 'data' => []];
    if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [['status' => 'paid', 'amount_paid' => 2000,
        'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_' . substr($data['invoice'], 3)]]]];
    if ($verb === 'post' && $path === '/refunds') return ['id' => 're_' . $data['payment_intent'], 'payment_intent' => $data['payment_intent'],
        'amount' => (int) $data['amount'], 'currency' => 'usd', 'status' => 'failed', 'metadata' => $data['metadata']];
    throw new RuntimeException('Unexpected Stripe request: ' . $verb . ' ' . $path);
};

// Cases: seed (driver), A and B (worker processes), where A pauses, and the rows both must leave behind.
$attempt = fn (int $user, int $invoice) => json_decode(User::find($user)->properties()->where('key', 'ae_stripe_checkout_' . $invoice)->value('value') ?? 'null', true);
$rows = fn (int $invoice) => DB::table('invoice_transactions')->where('invoice_id', $invoice)->orderBy('id')->get(['amount', 'status', 'is_credit_transaction'])
    ->map(fn ($row) => [(float) $row->amount, $row->status, (bool) $row->is_credit_transaction])->all();
$cases = [
    // Two customers open Pay on invoices with no stored attempt; each reserves its attempt row in the properties index.
    'pay-pay' => ['seed' => function () { customerInvoice(7, 301); customerInvoice(8, 302); },
        'pause' => '/^insert into `properties`/',
        'a' => function ($gateway) { auth()->login(User::find(7)); return $gateway->pay(Invoice::find(301), 20)->getData()['intent']->id; },
        'b' => function ($gateway) { auth()->login(User::find(8)); return $gateway->pay(Invoice::find(302), 20)->getData()['intent']->id; },
        'check' => fn () => $attempt(7, 301)['intent'] === 'pi_301' && $attempt(8, 302)['intent'] === 'pi_302'],
    // Pay now on a card the bank declines, recording the failed payment, while a neighbouring invoice settles.
    'pay-settlement' => ['seed' => function () {
            customerInvoice(7, 401)->user->properties()->create(['key' => 'ae_stripe_checkout_401',
                'value' => json_encode(['version' => 2, 'key' => 'checkout_401', 'created' => time(), 'intent' => 'pi_401'])]);
            customerInvoice(8, 402);
        },
        'pause' => '#^POST /payment_intents/pi_401/confirm$#',
        'a' => function ($gateway) {
            try { invoke($gateway, 'confirmAttempt', Invoice::find(401), 'pi_401', ['payment_method' => 'pm_401'], null, 2000, 'usd'); }
            catch (Paymenter\Extensions\Gateways\AeStripe\PaymentDeclinedException $e) { return $e->getMessage(); }
            return 'not declined';
        },
        'b' => fn ($gateway) => settle($gateway, 402),
        'check' => fn ($results) => $results['a']['ok'] === 'Insufficient funds. Try a different card, or contact your bank.'
            && $rows(401) === [[20.0, 'failed', false]] && $rows(402) === [[20.0, 'succeeded', false]]
            && Invoice::find(401)->status === 'pending' && Invoice::find(402)->status === 'paid'],
    // Webhooks settle two neighbouring invoices of different customers at once.
    'settlement-settlement' => ['seed' => function () { customerInvoice(7, 501); customerInvoice(8, 502); },
        'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => settle($gateway, 501),
        'b' => fn ($gateway) => settle($gateway, 502),
        'check' => fn () => $rows(501) === [[20.0, 'succeeded', false]] && $rows(502) === [[20.0, 'succeeded', false]]
            && Invoice::find(501)->status === 'paid' && Invoice::find(502)->status === 'paid'],
    // The same, paused after A has replaced any card label of its new payment, just before it saves the new label.
    'settlement-card-label' => ['seed' => function () { customerInvoice(7, 511); customerInvoice(8, 512); },
        'pause' => '/^insert ignore into `ae_stripe_transaction_methods`/',
        'a' => fn ($gateway) => settle($gateway, 511),
        'b' => fn ($gateway) => settle($gateway, 512),
        'check' => fn () => $rows(511) === [[20.0, 'succeeded', false]] && $rows(512) === [[20.0, 'succeeded', false]]
            && DB::table('ae_stripe_transaction_methods')->pluck('label')->all() === ['Visa **** 4242', 'Visa **** 4242']],
    // Stripe settles two customers' subscription invoices at once. Each finds its own service, never the other's.
    'subscription-subscription' => ['seed' => function () { subscriptionInvoice(8, 582); subscriptionInvoice(7, 581); },
        'pause' => '/^insert into `invoice_transactions`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordSubscriptionPayment', paidSubscription(581)) ?? 'settled',
        'b' => fn ($gateway) => invoke($gateway, 'recordSubscriptionPayment', paidSubscription(582)) ?? 'settled',
        'check' => fn () => $rows(581) === [[20.0, 'succeeded', false]] && $rows(582) === [[20.0, 'succeeded', false]]],
    // Two customers' charge fees are recorded at once, each on a settled payment with no fee row yet.
    'fee-fee' => ['seed' => function () use ($gateway) { customerInvoice(7, 701); customerInvoice(8, 702); settle($gateway, 701); settle($gateway, 702); },
        'pause' => '/^insert into `ae_stripe_payment_fees`/',
        'a' => fn ($gateway) => invoke($gateway, 'recordChargeFee', (object) ['payment_intent' => 'pi_701', 'paid' => true, 'status' => 'succeeded',
            'id' => 'ch_701', 'balance_transaction' => 'txn_701']),
        'b' => fn ($gateway) => invoke($gateway, 'recordChargeFee', (object) ['payment_intent' => 'pi_702', 'paid' => true, 'status' => 'succeeded',
            'id' => 'ch_702', 'balance_transaction' => 'txn_702']),
        'check' => fn () => DB::table('ae_stripe_payment_fees')->orderBy('payment_intent_id')->get(['payment_intent_id', 'state'])
            ->map(fn ($row) => [$row->payment_intent_id, $row->state])->all() === [['pi_701', 'completed'], ['pi_702', 'completed']]
            && DB::table('invoice_transactions')->where('fee', 0.5)->count() === 2],
    // Two customers overpay neighbouring invoices; Stripe refuses each refund, so each excess becomes account credit.
    // Another customer's finished return sorts between their payments in the receipt index, as in a used ledger.
    'receipt-returns' => ['seed' => function () {
            customerInvoice(7, 601); customerInvoice(8, 602);
            DB::table('ae_stripe_receipt_returns')->insert(['payment_intent_id' => 'pi_6015', 'allocation_key' => 'excess', 'user_id' => 9,
                'invoice_id' => 6015, 'currency' => 'usd', 'received_minor' => 3000, 'amount_minor' => 1000, 'return_key' => 'ae-return-6015',
                'state' => 'refunded', 'retry' => false, 'updated_at' => time()]);
        },
        'pause' => '/^insert into `credits`/',
        'a' => fn ($gateway) => settle($gateway, 601, 3000),
        'b' => fn ($gateway) => settle($gateway, 602, 3000),
        'check' => fn () => $rows(601) === [[30.0, 'succeeded', false], [-10.0, 'succeeded', true]]
            && $rows(602) === [[30.0, 'succeeded', false], [-10.0, 'succeeded', true]]
            && DB::table('credits')->orderBy('user_id')->get(['user_id', 'amount'])->map(fn ($c) => [$c->user_id, (float) $c->amount])->all() === [[7, 10.0], [8, 10.0]]
            && DB::table('ae_stripe_receipt_returns')->whereIn('invoice_id', [601, 602])->orderBy('invoice_id')->pluck('state')->all() === ['credited', 'credited']
            && DB::table('audits')->where('event', 'ae_stripe_receipt_return')->count() === 2
            && DB::table('notifications')->where('title', 'Payment return')->count() === 2],
];

if (($argv[1] ?? null) === 'worker') {
    $case = $cases[$argv[2]];
    if ($argv[3] === 'a') pauseBefore($case['pause']);
    fwrite(STDOUT, 'ready ' . DB::selectOne('select connection_id() as id')->id . "\n"); fflush(STDOUT);
    try { $result = ['ok' => $case[$argv[3]]($gateway)]; } catch (Throwable $e) { $result = ['error' => get_class($e) . ': ' . $e->getMessage()]; }
    fwrite(STDOUT, 'done ' . json_encode($result) . "\n");
    exit(0);
}

/** The next line a worker writes, or null when $stop() becomes true first. Fails after 30 seconds. */
function workerLine(array $worker, ?callable $stop = null): ?string
{
    for ($deadline = microtime(true) + 30; microtime(true) < $deadline;) {
        $read = [$worker[2]]; $none = null;
        if (stream_select($read, $none, $none, 0, 50000)) return rtrim((string) fgets($worker[2]), "\n");
        if ($stop && $stop()) return null;
    }
    throw new RuntimeException('A worker stopped answering.');
}
$deadlocks = fn () => (int) DB::selectOne("show global status like 'Innodb_deadlocks'")->Value;
$php = getenv('PAYMENTER_TEST_PHP') ?: PHP_BINARY;
foreach ($cases as $name => $case) {
    DB::statement('set foreign_key_checks = 0');
    foreach (['invoice_transactions', 'ae_stripe_transaction_methods', 'ae_stripe_receipt_returns', 'ae_stripe_payment_fees', 'credits', 'audits', 'notifications',
        'properties', 'invoice_items', 'invoices', 'services', 'plans', 'products', 'categories', 'users'] as $table) DB::table($table)->truncate();
    DB::statement('set foreign_key_checks = 1');
    $case['seed']();
    $before = $deadlocks();
    $workers = [];
    foreach (['a', 'b'] as $role) {
        $process = proc_open([$php, '-d', 'auto_prepend_file=' . __DIR__ . '/entered.php', '-d', 'memory_limit=256M', __FILE__, 'worker', $name, $role],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes); // Errors go to the inherited stderr; passing it would rewind a log file.
        $workers[$role] = [$process, $pipes[0], $pipes[1], (int) substr(workerLine([$process, $pipes[0], $pipes[1]]), 6)];
        if ($role === 'a') check(($line = workerLine($workers['a'])) === 'paused', $name . ': request A reaches its pause point: ' . $line);
    }
    // B runs until it finishes or waits on a lock that A holds. The InnoDB transaction list can leave out a session waiting to
    // insert, so a statement still running after 1.5 seconds on this small database also counts as a lock wait.
    $waiting = fn () => (bool) DB::selectOne("select count(*) as n from information_schema.innodb_trx where trx_mysql_thread_id = ? and trx_state = 'LOCK WAIT'", [$workers['b'][3]])->n
        || (bool) DB::selectOne("select count(*) as n from information_schema.processlist where id = ? and command in ('Query', 'Execute') and time_ms > 1500", [$workers['b'][3]])->n;
    $early = workerLine($workers['b'], $waiting);
    fwrite($workers['a'][1], "go\n");
    $results = ['a' => json_decode(substr(workerLine($workers['a']), 5), true),
        'b' => json_decode(substr($early ?? workerLine($workers['b']), 5), true)];
    foreach ($workers as $worker) { fclose($worker[1]); fclose($worker[2]); proc_close($worker[0]); }
    echo $name . ': B ' . ($early === null ? 'waited on A' : 'finished while A was paused') . "\n";
    check(($early === null) === !empty($case['waits']), $name . ': B ' . (empty($case['waits']) ? 'never waits on A' : 'waits on A'));
    check(isset($results['a']['ok'], $results['b']['ok']), $name . ': both requests finish: ' . json_encode($results));
    check($deadlocks() - $before === ($case['deadlocks'] ?? 0), $name . ': InnoDB reports ' . ($case['deadlocks'] ?? 0) . ' deadlocks between the two');
    check($case['check']($results), $name . ': each request leaves its own rows, once: ' . json_encode($results));
}
echo "PASS: $count MariaDB lock assertions\n";
