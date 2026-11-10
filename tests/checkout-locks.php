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
// Positive control for the trace: the native grammar renders the lock as a trailing comment, and a locking read of a
// missing property is recorded as a lock on its gap, while a locking read of an existing row is not. Two Stripe
// customer IDs are indexed; a checkout key sorts before both (gap 0) and a third customer's stripe_id after both (gap 2).
check(count($GLOBALS['gapIndex']) === 2 && !array_diff(array_column($GLOBALS['gapIndex'], 0), ['stripe_id']), 'The control index holds only two Stripe customer IDs');
$GLOBALS['gapTrace'] = [];
$unsaved = new User;
$unsaved->id = 30;
DB::transaction(function () use ($unsaved) {
    User::findOrFail(8)->properties()->where('key', 'ae_stripe_checkout_301')->lockForUpdate()->first();
    User::findOrFail(8)->properties()->where('key', 'stripe_id')->lockForUpdate()->first();
    $unsaved->properties()->where('key', 'stripe_id')->lockForUpdate()->first();
});
check(array_map(fn ($event) => array_slice($event, 1), $GLOBALS['gapTrace']) === [['lock', 0, 'ae_stripe_checkout_301'], ['lock', 2, 'stripe_id']],
    'The trace records a locking read of a missing property as a lock on its index gap, and none for an existing row');
$GLOBALS['gapTrace'] = null;
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

/** [locked gap, inserted gap] pairs: a transaction locked a missing property's gap and later inserted a property. */
function gapWaits(array $trace): array
{
    $held = $pairs = [];
    foreach ($trace as [$transaction, $kind, $gap]) {
        if ($kind === 'lock') { $held[$transaction][$gap] = true; continue; }
        foreach (array_keys($held[$transaction] ?? []) as $locked) $pairs[$locked . ':' . $gap] = [$locked, $gap];
    }
    return $pairs;
}
/** Two requests at once deadlock when each inserts into a gap the other locked before its own insert. A request
 * with no gapWaits() pairs cannot be either side of such a cycle, whatever the other request does. */
function gapDeadlock(array $first, array $second): bool
{
    $other = gapWaits($second);
    foreach (gapWaits($first) as [$locked, $inserted]) if (isset($other[$inserted . ':' . $locked])) return true;
    return false;
}
/** Run a request with the properties gap trace on. */
function traced(callable $request): array
{
    $GLOBALS['gapTrace'] = [];
    try { $request(); } finally { $trace = $GLOBALS['gapTrace']; $GLOBALS['gapTrace'] = null; }
    return $trace;
}
$indexNow = function () { $GLOBALS['gapIndex'] = DB::table('properties')->get(['key', 'model_id', 'model_type'])->map(fn ($row) => [$row->key, $row->model_id, $row->model_type])->all(); };
$insertedKey = fn ($trace, $prefix) => (bool) array_filter($trace, fn ($event) => $event[1] === 'insert' && str_starts_with($event[3], $prefix));
$customer(11, 'cus_11');
$customer(12, 'cus_12');

// Two payments from the wrong Stripe customer settle at once: each records a staff review alert for its invoice.
$GLOBALS['stripeHandler'] = fn ($verb, $path) => throw new RuntimeException('Unexpected review request: ' . $verb . ' ' . $path);
$review = fn (int $invoiceId, int $userId) => (object) ['id' => 'pi_review_' . $invoiceId, 'status' => 'succeeded', 'amount' => 2000,
    'amount_received' => 2000, 'currency' => 'usd', 'customer' => 'cus_wrong', 'metadata' => (object) ['invoice_id' => (string) $invoiceFor($invoiceId, $userId)->id]];
$firstReview = $review(311, 11);
$secondReview = $review(312, 12);
$indexNow();
$firstTrace = traced(fn () => invoke($gateway, 'recordIntentPayment', $firstReview, 'succeeded', false));
$secondTrace = traced(fn () => invoke($gateway, 'recordIntentPayment', $secondReview, 'succeeded', false));
check($insertedKey($firstTrace, 'ae_stripe_payment_alert_311_') && $insertedKey($secondTrace, 'ae_stripe_payment_alert_312_'),
    'Each mismatched payment inserts its review alert');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two mismatched payments settling at once never deadlock on their review alerts');
check(App\Models\Audit::where('event', 'ae_stripe_payment_review')->whereIn('auditable_id', [311, 312])->count() === 2
    && !App\Models\InvoiceTransaction::whereIn('invoice_id', [311, 312])->exists(), 'Each mismatched payment gets one staff review and no payment');

// Two customers return from paying with "save card" at once: each reserves its saved card notice.
$GLOBALS['stripeHandler'] = function ($verb, $path) {
    if ($verb === 'get' && preg_match('#^/payment_methods/(pm_notice_[0-9]+)$#', $path, $id)) return ['id' => $id[1], 'type' => 'sepa_debit'];
    throw new RuntimeException('Unexpected notice request: ' . $verb . ' ' . $path);
};
$notice = fn (int $invoiceId, int $userId) => (object) ['id' => 'pi_notice_' . $invoiceId, 'status' => 'succeeded', 'customer' => 'cus_' . $userId,
    'payment_method' => 'pm_notice_' . $invoiceId, 'created' => time(), 'metadata' => (object) ['invoice_id' => (string) $invoiceFor($invoiceId, $userId)->id,
        'ae_stripe_gateway' => '1', 'ae_stripe_consent' => '1']];
$firstNotice = $notice(313, 11);
$secondNotice = $notice(314, 12);
$indexNow();
// A SEPA method is not saved, so each notice is discarded after its reservation.
$firstTrace = traced(function () use ($gateway, $firstNotice) { auth()->login(User::findOrFail(11)); invoke($gateway, 'saveConsentedMethod', $firstNotice, null); });
$secondTrace = traced(function () use ($gateway, $secondNotice) { auth()->login(User::findOrFail(12)); invoke($gateway, 'saveConsentedMethod', $secondNotice, null); });
check($insertedKey($firstTrace, 'ae_stripe_notice_') && $insertedKey($secondTrace, 'ae_stripe_notice_'),
    'Each return reserves its saved card notice');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two customers reserving saved card notices at once never deadlock');

// Two customers remove a saved card at once: each records its removal journal before detaching.
$removing = $methods = [];
foreach ([11, 12] as $userId) {
    $removing[$userId] = nativeAgreement(500 + $userId, 'pm_remove_' . $userId, ['user_id' => $userId]);
    $methods['pm_remove_' . $userId] = (object) ['id' => 'pm_remove_' . $userId, 'customer' => 'cus_' . $userId, 'type' => 'card',
        'card' => (object) ['fingerprint' => 'fp_remove_' . $userId, 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
}
$GLOBALS['stripeHandler'] = nativeMethodHandler($methods);
$indexNow();
$firstTrace = traced(fn () => check(invoke($gateway, 'removeSavedMethodCopies', $removing[11]) === true, 'The first removal finishes'));
$secondTrace = traced(fn () => check(invoke($gateway, 'removeSavedMethodCopies', $removing[12]) === true, 'The second removal finishes'));
check($insertedKey($firstTrace, 'ae_stripe_removal_pm_remove_11') && $insertedKey($secondTrace, 'ae_stripe_removal_pm_remove_12'),
    'Each removal inserts its journal');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two customers removing a saved card at once never deadlock on the removal journal');

// Card cleanup reserves a duplicate for a customer with no Auto Pay card. The reservation inserts a property, so it
// must hold no missing-row gap lock, which the Auto Pay card check would otherwise take.
foreach (['pm_keep_11' => 611, 'pm_dup_11' => 612] as $reference => $id) {
    nativeAgreement($id, $reference, ['user_id' => 11]);
    $methods[$reference] = (object) ['id' => $reference, 'customer' => 'cus_11', 'type' => 'card', 'allow_redisplay' => 'unspecified',
        'card' => (object) ['fingerprint' => 'fp_dup_11', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
}
$indexNow();
$cleanupTrace = traced(fn () => invoke($gateway, 'cleanupCard', App\Models\BillingAgreement::findOrFail(612), $nativeGateway, true, fn () => null));
check($insertedKey($cleanupTrace, 'ae_stripe_duplicate_pm_dup_11') && App\Models\BillingAgreement::find(612) === null,
    'Cleanup reserves and removes the duplicate card');
check(!gapWaits($cleanupTrace), 'The duplicate card reservation takes no missing-row gap lock before inserting');
