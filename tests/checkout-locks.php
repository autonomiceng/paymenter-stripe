<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
// Pay row locks across customers. SQLite has no row locks, so this models the MariaDB REPEATABLE READ rule on the
// properties unique index (key, model_id, model_type): a locking read that finds no row locks the gap before the next
// index entry, and an insert into a gap waits for every other transaction that locked it. Two transactions that each
// lock one gap and then insert into it wait on each other, which MariaDB reports as deadlock SQLSTATE 40001.
use App\Models\{Invoice, User};
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\{DB, Event};

$transaction = 0;
Event::listen(TransactionBeginning::class, function () use (&$transaction) { if (DB::transactionLevel() === 1) ++$transaction; });
$GLOBALS['gapTrace'] = null;
$GLOBALS['gapIndex'] = [];
/** Position of a (key, model_id, model_type) entry among the index entries both transactions started from. */
function gapOf(array $entry): int
{
    $order = fn ($a, $b) => [$a[0], (int) $a[1], $a[2]] <=> [$b[0], (int) $b[1], $b[2]];
    return count(array_filter($GLOBALS['gapIndex'], fn ($row) => $order($row, $entry) < 0));
}
DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$transaction) {
    if (!is_array($GLOBALS['gapTrace']) || DB::transactionLevel() === 0) return;
    if (preg_match('/^select .* from "properties" where (.*) \/\* for update \*\/$/', $sql, $where)
        && preg_match_all('/"([a-z_]+)" (=|like) \?/', $where[1], $columns, PREG_SET_ORDER)) {
        $values = [];
        foreach ($columns as $index => $column) $values[$column[1]] = [$column[2], $bindings[$index]];
        if (($values['key'][0] ?? null) !== '=' || !isset($values['model_id'], $values['model_type'])) return;
        $entry = [$values['key'][1], $values['model_id'][1], $values['model_type'][1]];
        $found = DB::connection()->getPdo()->prepare('select count(*) from properties where key = ? and model_id = ? and model_type = ?');
        $found->execute($entry);
        if ((int) $found->fetchColumn() === 0) $GLOBALS['gapTrace'][] = [$transaction, 'lock', gapOf($entry), $entry[0]];
        return;
    }
    if (($write = nativeWrite($sql, $bindings)) && $write[0] === 'properties' && str_starts_with($sql, 'insert')) {
        $GLOBALS['gapTrace'][] = [$transaction, 'insert', gapOf([$write[1]['key'], $write[1]['model_id'], $write[1]['model_type']]), $write[1]['key']];
    }
});
/** Gaps a request's transaction locked while missing a row and then inserted into, as gap => key. */
function lockedThenInserted(array $trace): array
{
    $held = $gaps = [];
    foreach ($trace as [$transaction, $kind, $gap, $key]) {
        if ($kind === 'lock') $held[$transaction][$gap] = true;
        elseif (isset($held[$transaction][$gap])) $gaps[$gap] = $key;
    }
    return $gaps;
}
/** Open Pay as the invoice's customer, tracing the properties gaps each transaction locks and inserts into. */
$open = function ($gateway, Invoice $invoice): array {
    auth()->login(User::findOrFail($invoice->user_id));
    $GLOBALS['gapTrace'] = [];
    try { $view = $gateway->pay($invoice, 20); } finally { $trace = $GLOBALS['gapTrace']; $GLOBALS['gapTrace'] = null; }
    return [$view, $trace];
};
$customer = function (int $id, ?string $stripeId): User {
    $user = User::forceCreate(['id' => $id, 'first_name' => 'Customer', 'last_name' => (string) $id, 'email' => "customer$id@example.test", 'password' => 'synthetic']);
    if ($stripeId) $user->properties()->create(['key' => 'stripe_id', 'value' => $stripeId]);
    return $user;
};
$invoiceFor = function (int $id, int $userId): Invoice {
    $invoice = nativeInvoice($id);
    $invoice->forceFill(['user_id' => $userId])->save();
    return $invoice->fresh();
};
// Stripe: PaymentIntents are keyed by idempotency key, as Stripe replays a repeated creation.
$intents = [];
$customers = 0;
$onCreate = null;
$GLOBALS['stripeHandler'] = function ($verb, $path, $data, $request) use (&$intents, &$customers, &$onCreate) {
    if ($verb === 'get' && preg_match('#^/customers/(cus_[a-z0-9_]+)$#', $path, $id)) return ['id' => $id[1]];
    if ($verb === 'post' && $path === '/customers') return ['id' => 'cus_new_' . (++$customers) . '_' . $data['metadata']['user_id']];
    if ($verb === 'post' && $path === '/customer_sessions') return ['client_secret' => 'cuss_' . $data['customer']];
    if ($verb === 'post' && $path === '/payment_intents') {
        check(DB::transactionLevel() === 0, 'PaymentIntent creation runs outside the payment lock');
        if ($hook = $onCreate) { $onCreate = null; $hook(); }
        return $intents[idempotencyKey($request)] ??= (object) ['id' => 'pi_lock_' . (count($intents) + 1), 'status' => 'requires_payment_method',
            'amount' => $data['amount'], 'amount_received' => 0, 'currency' => strtolower($data['currency']), 'customer' => $data['customer'],
            'confirmation_method' => 'automatic', 'payment_method_types' => $data['payment_method_types'], 'latest_charge' => null,
            'client_secret' => 'pi_lock_secret', 'metadata' => (object) $data['metadata']];
    }
    throw new RuntimeException('Unexpected checkout lock request: ' . $verb . ' ' . $path);
};

// Two customers open Pay at once, each on an invoice with no stored attempt (demo invoice 239). Each reservation
// writes ae_stripe_checkout_<invoice>; the two keys sort into the same index gap.
$customer(8, 'cus_second');
$first = $invoiceFor(301, 7);
$second = $invoiceFor(302, 8);
$GLOBALS['gapIndex'] = DB::table('properties')->get(['key', 'model_id', 'model_type'])->map(fn ($row) => [$row->key, $row->model_id, $row->model_type])->all();
[$firstView, $firstTrace] = $open($gateway, $first);
[$secondView, $secondTrace] = $open($gateway, $second);
$inserted = fn ($trace, $key) => (bool) array_filter($trace, fn ($event) => $event[1] === 'insert' && $event[3] === $key);
check($inserted($firstTrace, 'ae_stripe_checkout_301') && $inserted($secondTrace, 'ae_stripe_checkout_302'), 'Each reservation inserts its attempt row');
check(!array_intersect_key(lockedThenInserted($firstTrace), lockedThenInserted($secondTrace)),
    'Two customers opening Pay never both lock the same properties gap and insert into it, so neither deadlocks');
check($firstView->getData()['intent']->id !== $secondView->getData()['intent']->id
    && $firstView->getData()['intent']->customer === 'cus_owner' && $secondView->getData()['intent']->customer === 'cus_second',
    'Each customer gets a working Pay page with their own intent');

// Two first-time customers open Pay at once: each saves its new Stripe customer ID as stripe_id.
$customer(9, null);
$customer(10, null);
$third = $invoiceFor(303, 9);
$fourth = $invoiceFor(304, 10);
$GLOBALS['gapIndex'] = DB::table('properties')->get(['key', 'model_id', 'model_type'])->map(fn ($row) => [$row->key, $row->model_id, $row->model_type])->all();
[$thirdView, $thirdTrace] = $open($gateway, $third);
[$fourthView, $fourthTrace] = $open($gateway, $fourth);
check($inserted($thirdTrace, 'stripe_id') && $inserted($fourthTrace, 'stripe_id'), 'Each first-time customer inserts its stripe_id');
check(!array_intersect_key(lockedThenInserted($thirdTrace), lockedThenInserted($fourthTrace)),
    'Two first-time customers opening Pay never both lock the stripe_id gap and insert into it');
check($customers === 2 && User::find(9)->properties()->where('key', 'stripe_id')->value('value') === $thirdView->getData()['intent']->customer
    && User::find(10)->properties()->where('key', 'stripe_id')->value('value') === $fourthView->getData()['intent']->customer,
    'Each first-time customer gets one Stripe customer and a working Pay page');

// One customer opens Pay twice on one invoice: the second open runs while the first is creating its intent.
$fifth = $invoiceFor(305, 7);
$created = count($intents);
$inner = null;
$onCreate = function () use (&$inner, $open, $gateway, $fifth) { [$inner] = $open($gateway, $fifth); };
[$outer] = $open($gateway, $fifth);
$attempt = json_decode(User::find(7)->properties()->where('key', 'ae_stripe_checkout_305')->value('value'), true);
check($inner && $inner->getData()['intent']->id === $outer->getData()['intent']->id && count($intents) === $created + 1
    && ($attempt['intent'] ?? null) === $outer->getData()['intent']->id,
    'Two opens of Pay on one invoice give both pages the same single intent');
check(!array_filter($GLOBALS['stripeCalls'], fn ($call) => str_contains($call[1], '/confirm') || str_starts_with($call[1], '/charges')),
    'Opening Pay never confirms or charges');
