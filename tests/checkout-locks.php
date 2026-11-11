<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
// Pay row locks across customers. SQLite has no row locks, so this models the MariaDB REPEATABLE READ rule on the
// properties unique index (key, model_id, model_type): a locking read that finds no row locks the gap before the next
// index entry, and an insert into a gap waits for every other transaction that locked it. Two transactions that each
// lock one gap and then insert into it wait on each other, which MariaDB reports as deadlock SQLSTATE 40001.
// A locking `like` read of a key prefix scans and locks every customer's keys in that range, with the gaps between them
// and the gap after the last. The same rule is modelled on two more indexes: audits (auditable_type, auditable_id), where
// a locking read always locks the gap after the invoice's records and a new record goes into it, and the receipt
// returns unique index (payment_intent_id, allocation_key). Their gaps are named "audits:N" and "receipts:N".
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
$GLOBALS['gapAudits'] = $GLOBALS['gapReceipts'] = [];
/** Number of entries of an index snapshot that sort before the entry, or with it when $inclusive. */
function gapCount(array $index, array $entry, bool $inclusive = false): int
{
    return count(array_filter($index, fn ($row) => ($row <=> $entry) < ($inclusive ? 1 : 0)));
}
DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$transaction) {
    if (!is_array($GLOBALS['gapTrace']) || DB::transactionLevel() === 0) return;
    $trace = function ($kind, $gap, $label) use (&$transaction) { $GLOBALS['gapTrace'][] = [$transaction, $kind, $gap, $label]; };
    if (preg_match('/^select .* from "(properties|audits|ae_stripe_receipt_returns)" where (.*) \/\* for update \*\/$/', $sql, $where)
        && preg_match_all('/"([a-z_]+)" (=|like) \?/', $where[2], $columns, PREG_SET_ORDER)) {
        $values = [];
        foreach ($columns as $index => $column) $values[$column[1]] = [$column[2], $bindings[$index]];
        if ($where[1] === 'audits') {
            if (!isset($values['auditable_type'], $values['auditable_id'])) return;
            $entry = [$values['auditable_type'][1], (int) $values['auditable_id'][1]];
            $trace('lock', 'audits:' . gapCount($GLOBALS['gapAudits'], $entry, true), 'audit');
            return;
        }
        if ($where[1] === 'ae_stripe_receipt_returns') {
            if (!isset($values['payment_intent_id'])) return;
            $intent = $values['payment_intent_id'][1];
            if (isset($values['allocation_key'])) {
                $entry = [$intent, $values['allocation_key'][1]];
                if (!DB::table('ae_stripe_receipt_returns')->where('payment_intent_id', $entry[0])->where('allocation_key', $entry[1])->exists()) {
                    $trace('lock', 'receipts:' . gapCount($GLOBALS['gapReceipts'], $entry), 'receipt');
                }
                return;
            }
            // A read of all rows of a payment locks each of its rows, the gaps before them and the gap after the last.
            $from = gapCount($GLOBALS['gapReceipts'], [$intent, '']);
            $to = count(array_filter($GLOBALS['gapReceipts'], fn ($row) => strcmp($row[0], $intent) <= 0));
            for ($gap = $from; $gap <= $to; ++$gap) $trace('lock', 'receipts:' . $gap, 'receipt');
            return;
        }
        if (!isset($values['key'], $values['model_id'], $values['model_type'])) return;
        if ($values['key'][0] === 'like') {
            $prefix = rtrim($values['key'][1], '%');
            $from = count(array_filter($GLOBALS['gapIndex'], fn ($row) => strcmp($row[0], $prefix) < 0));
            $to = count(array_filter($GLOBALS['gapIndex'], fn ($row) => strcmp($row[0], $prefix) < 0 || str_starts_with($row[0], $prefix)));
            for ($gap = $from; $gap <= $to; ++$gap) $trace('lock', $gap, $prefix . '%');
            return;
        }
        $entry = [$values['key'][1], $values['model_id'][1], $values['model_type'][1]];
        $found = DB::connection()->getPdo()->prepare('select count(*) from properties where key = ? and model_id = ? and model_type = ?');
        $found->execute($entry);
        if ((int) $found->fetchColumn() === 0) $trace('lock', gapOf($entry), $entry[0]);
        return;
    }
    if (!($write = nativeWrite($sql, $bindings)) || !str_starts_with($sql, 'insert')) return;
    [$table, $row] = $write;
    if ($table === 'properties') $trace('insert', gapOf([$row['key'], $row['model_id'], $row['model_type']]), $row['key']);
    if ($table === 'audits') $trace('insert', 'audits:' . gapCount($GLOBALS['gapAudits'], [$row['auditable_type'], (int) $row['auditable_id']], true), 'audit');
    if ($table === 'ae_stripe_receipt_returns') {
        $trace('insert', 'receipts:' . gapCount($GLOBALS['gapReceipts'], [$row['payment_intent_id'], $row['allocation_key']]), 'receipt');
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
$indexNow = function () {
    $GLOBALS['gapIndex'] = DB::table('properties')->get(['key', 'model_id', 'model_type'])->map(fn ($row) => [$row->key, $row->model_id, $row->model_type])->all();
    $GLOBALS['gapAudits'] = DB::table('audits')->get(['auditable_type', 'auditable_id'])->map(fn ($row) => [$row->auditable_type, (int) $row->auditable_id])->all();
    $GLOBALS['gapReceipts'] = DB::table('ae_stripe_receipt_returns')->get(['payment_intent_id', 'allocation_key'])->map(fn ($row) => [$row->payment_intent_id, $row->allocation_key])->all();
};
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

// Two customers turn Auto Pay on at once, each with a renewal retry waiting on an open invoice. Stopping the retries
// must not lock the range of every customer's payment attempts, which the Auto Pay records are inserted beside.
foreach ([11 => 341, 12 => 342] as $userId => $invoiceId) {
    nativeAgreement(700 + $userId, 'pm_auto_' . $userId, ['user_id' => $userId]);
    $invoiceFor($invoiceId, $userId);
    User::findOrFail($userId)->properties()->create(['key' => 'ae_stripe_checkout_' . $invoiceId, 'value' => json_encode(['version' => 2,
        'key' => 'renewal_' . $invoiceId, 'created' => time(), 'retry' => false, 'renewal' => ['state' => 'waiting']])]);
}
$enable = fn (int $userId) => traced(fn () => DB::transaction(function () use ($gateway, $userId) {
    invoke($gateway, 'enableAutoPay', User::where('id', $userId)->lockForUpdate()->firstOrFail(), 700 + $userId);
}));
$indexNow();
$firstTrace = $enable(11);
$secondTrace = $enable(12);
check($insertedKey($firstTrace, 'ae_stripe_auto_pay_') && $insertedKey($secondTrace, 'ae_stripe_auto_pay_'), 'Each customer stores its Auto Pay choice');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two customers turning Auto Pay on at once never deadlock on their waiting renewals');
foreach ([11 => 341, 12 => 342] as $userId => $invoiceId) {
    check((json_decode(User::find($userId)->properties()->where('key', 'ae_stripe_checkout_' . $invoiceId)->value('value'), true)['renewal']['state'] ?? null) === 'stopped',
        'Turning Auto Pay on stops the customer\'s waiting renewal retry');
}

// Two first-time customers save a card at once: each numbers its first saved card.
$customer(13, 'cus_13');
$customer(14, 'cus_14');
foreach ([13, 14] as $userId) {
    $methods['pm_save_' . $userId] = (object) ['id' => 'pm_save_' . $userId, 'customer' => 'cus_' . $userId, 'type' => 'card', 'allow_redisplay' => 'unspecified',
        'card' => (object) ['fingerprint' => 'fp_save_' . $userId, 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 5, 'exp_year' => 2030]];
}
$GLOBALS['stripeHandler'] = nativeMethodHandler($methods);
$save = fn (int $userId) => traced(function () use ($gateway, $methods, $userId) {
    auth()->login(User::findOrFail($userId));
    invoke($gateway, 'persistSavedMethod', User::findOrFail($userId), $methods['pm_save_' . $userId], []);
});
$indexNow();
$firstTrace = $save(13);
$secondTrace = $save(14);
check($insertedKey($firstTrace, 'ae_stripe_save_sequence') && $insertedKey($secondTrace, 'ae_stripe_save_sequence')
    && App\Models\BillingAgreement::where('external_reference', 'pm_save_13')->where('user_id', 13)->exists()
    && App\Models\BillingAgreement::where('external_reference', 'pm_save_14')->where('user_id', 14)->exists(),
    'Each first-time customer saves its card and numbers it');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two first-time customers saving a card at once never deadlock on the save sequence');

// Two held payments on neighbouring invoices go to staff review at once. Neither invoice has audit rows (core audits
// nothing from the console, as for renewal invoices), so both review records go into one audits index gap.
foreach ([11 => 351, 12 => 352] as $userId => $invoiceId) {
    $invoiceFor($invoiceId, $userId);
    User::findOrFail($userId)->properties()->create(['key' => 'ae_stripe_checkout_' . $invoiceId, 'value' => json_encode(['version' => 2,
        'key' => 'held_' . $invoiceId, 'created' => time(), 'retry' => true, 'state' => 'reconcile', 'reason' => 'Stripe cannot find this payment.'])]);
}
$indexNow();
$firstTrace = traced(fn () => invoke($gateway, 'sendAttemptReview', Invoice::findOrFail(351)));
$secondTrace = traced(fn () => invoke($gateway, 'sendAttemptReview', Invoice::findOrFail(352)));
check(App\Models\Audit::where('event', 'ae_stripe_attempt_review')->whereIn('auditable_id', [351, 352])->count() === 2,
    'Each held payment gets one staff review record');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two held payments going to staff review at once never deadlock on their review records');
invoke($gateway, 'sendAttemptReview', Invoice::findOrFail(351));
check(App\Models\Audit::where('event', 'ae_stripe_attempt_review')->where('auditable_id', 351)->count() === 1, 'A repeated review check records nothing more');

// Two customers overpay neighbouring invoices at once: each settlement reserves its excess as a receipt return. Both
// payments sort into one receipt returns index gap.
$nativeGateway->update(['enabled' => false]); // Returns are sent after commit; this case covers only the reservation.
$overpaid = fn (int $invoiceId, int $userId) => (object) ['id' => 'pi_over_' . $invoiceId, 'status' => 'succeeded', 'amount' => 2500,
    'amount_received' => 2500, 'currency' => 'usd', 'customer' => 'cus_' . $userId, 'latest_charge' => null,
    'metadata' => (object) ['invoice_id' => (string) $invoiceFor($invoiceId, $userId)->id]];
$firstPayment = $overpaid(361, 11);
$secondPayment = $overpaid(362, 12);
$indexNow();
$firstTrace = traced(fn () => invoke($gateway, 'recordIntentPayment', $firstPayment, 'succeeded', false));
$secondTrace = traced(fn () => invoke($gateway, 'recordIntentPayment', $secondPayment, 'succeeded', false));
$nativeGateway->update(['enabled' => true]);
$returns = tableRows('ae_stripe_receipt_returns', ['payment_intent_id', 'allocation_key']);
check(($returns['pi_over_361:excess']['amount_minor'] ?? null) == 500 && ($returns['pi_over_362:excess']['amount_minor'] ?? null) == 500
    && (moneyRows()['payments']['361:pi_over_361'] ?? 0) === 1 && (moneyRows()['payments']['362:pi_over_362'] ?? 0) === 1,
    'Each overpayment is recorded once and its excess reserved for return');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two overpayments settling at once never deadlock on their receipt returns');

// Stripe reports a dispute on two customers' neighbouring payments at once. Each event checks for a staff resolution,
// then records the dispute beside the receipt and a staff record on the invoice. Both payments sort into one receipt
// returns gap and both invoices into one audits gap.
$disputed = [];
foreach ([11 => 391, 12 => 392] as $userId => $invoiceId) {
    $disputed[$invoiceId] = (object) ['id' => 'pi_rev' . $invoiceId, 'status' => 'succeeded', 'amount' => 2000, 'amount_received' => 2000,
        'currency' => 'usd', 'customer' => 'cus_' . $userId, 'latest_charge' => null, 'metadata' => (object) ['invoice_id' => (string) $invoiceFor($invoiceId, $userId)->id]];
    invoke($gateway, 'recordIntentPayment', $disputed[$invoiceId], 'succeeded', false);
}
$GLOBALS['stripeHandler'] = function ($verb, $path) use ($disputed) {
    if ($verb === 'get' && preg_match('#^/payment_intents/pi_rev([0-9]+)$#', $path, $id)) return $disputed[$id[1]];
    if ($verb === 'get' && preg_match('#^/disputes/dp_rev_([0-9]+)$#', $path, $id)) return ['id' => 'dp_rev_' . $id[1], 'payment_intent' => 'pi_rev' . $id[1],
        'currency' => 'usd', 'amount' => 2000, 'status' => 'needs_response', 'created' => time()];
    throw new RuntimeException('Unexpected dispute request: ' . $verb . ' ' . $path);
};
$indexNow();
$dispute = fn (int $invoiceId) => traced(fn () => invoke($gateway, 'recordReceiptReversalEvent', (object) ['id' => 'dp_rev_' . $invoiceId, 'payment_intent' => 'pi_rev' . $invoiceId], 'dispute'));
$firstTrace = $dispute(391);
$secondTrace = $dispute(392);
$returns = tableRows('ae_stripe_receipt_returns', ['payment_intent_id', 'allocation_key']);
check(($returns['pi_rev391:stripe:dispute:dp_rev_391']['state'] ?? null) === 'open' && ($returns['pi_rev392:stripe:dispute:dp_rev_392']['state'] ?? null) === 'open'
    && App\Models\Audit::where('event', 'ae_stripe_receipt_reversal')->whereIn('auditable_id', [391, 392])->count() === 2,
    'Each dispute is recorded once beside its receipt, with one staff record');
check(!gapDeadlock($firstTrace, $secondTrace) && !gapWaits($firstTrace) && !gapWaits($secondTrace),
    'Two disputes reported at once never deadlock on their receipt rows or staff records');

// Pay chosen as a deadlock victim runs again from the locked balance check, so the customer gets a working page.
$victims = 0;
beforeWrite('properties', function ($values, $sql) use (&$victims) {
    if ($victims <= 0 || !str_starts_with($sql, 'insert') || ($values['key'] ?? null) !== 'ae_stripe_checkout_371') return;
    --$victims;
    throw new Illuminate\Database\QueryException('mysql', $sql, [],
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
});
$created = 0;
$GLOBALS['stripeHandler'] = function ($verb, $path, $data, $request) use (&$created) {
    if ($verb === 'get' && $path === '/customers/cus_11') return ['id' => 'cus_11'];
    if ($verb === 'post' && $path === '/customer_sessions') return ['client_secret' => 'cuss_11'];
    if ($verb === 'post' && $path === '/payment_intents') {
        ++$created;
        return ['id' => 'pi_victim_371', 'status' => 'requires_payment_method', 'amount' => $data['amount'], 'amount_received' => 0,
            'currency' => strtolower($data['currency']), 'customer' => $data['customer'], 'confirmation_method' => 'automatic',
            'payment_method_types' => $data['payment_method_types'], 'latest_charge' => null, 'client_secret' => 'pi_victim_secret',
            'metadata' => (object) $data['metadata']];
    }
    throw new RuntimeException('Unexpected Pay request: ' . $verb . ' ' . $path);
};
$victims = 1;
try {
    try { [$view] = $open($gateway, $invoiceFor(371, 11)); } catch (Throwable $e) { $view = get_class($e) . ': ' . $e->getMessage(); }
} finally {
    beforeWrite('properties', null);
}
$attempt = json_decode(User::find(11)->properties()->where('key', 'ae_stripe_checkout_371')->value('value') ?? 'null', true);
check(is_object($view) && $victims === 0 && $view->getData()['intent']->id === 'pi_victim_371' && $created === 1
    && ($attempt['intent'] ?? null) === 'pi_victim_371',
    'A Pay chosen as a deadlock victim runs again and gives one working page with one intent: ' . (is_string($view) ? $view : ''));
