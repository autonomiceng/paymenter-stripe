<?php
// Reuse the return fixture: these events must never create a second return or debit.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit, Notification};
use Illuminate\Support\Facades\{DB, Http};

// batch3-click/stripe-replay-open.json: invoice 47 received 1099, external refund 100 USD cents.
[$i, $s] = $setup(1099, 10.99); $deliver($s);
$foreign = (object) ['id' => 're_123456789012345678901234', 'object' => 'refund', 'livemode' => false, 'payment_intent' => 'pi_return', 'charge' => 'ch_return',
    'currency' => 'usd', 'amount' => 100, 'created' => 1793400000, 'status' => 'succeeded', 'metadata' => (object) []];
$s->refunds['foreign'] = $foreign;
$request = eventRequest($foreign, type: 'refund.created');
$gateway->webhook($request); $gateway->webhook($request);
$updated = eventRequest($foreign, type: 'refund.updated');
$gateway->webhook($updated); $gateway->webhook($updated);
$reversals = $gateway->receiptReversals($i, 'pi_return');
check(count($reversals) === 1 && $reversals[0]['id'] === $foreign->id && $reversals[0]['needs_review']
    && $reversals[0]['amount_minor'] === 100 && count(Audit::$rows) === 1 && count(Notification::$rows) === 1,
    'Captured USD 1 refund created/updated replays persist one work item, audit and staff alert within the existing schema');
check(end(Notification::$rows)['body'] === '$1.00 refunded on invoice INV-12.'
    && end(Notification::$rows)['user_id'] === 99
    && str_contains(end(Audit::$rows)['new_values']['next_step'], 'cus_owner'),
    'Refund bell uses the invoice number while the invoice audit retains reconciliation details');
$storedKey = 'pi_return:stripe:refund:'.$foreign->id;
check(DB::$tables['ae_stripe_receipt_returns'][$storedKey]['provider_created_at'] === 1793400000,
    'External refund stores the canonical Stripe creation date');
DB::$tables['ae_stripe_receipt_returns'][$storedKey]['provider_created_at'] = null;
$recorded = DB::$tables['ae_stripe_receipt_returns'][$storedKey]['updated_at'];
$gateway->webhook($updated);
check(DB::$tables['ae_stripe_receipt_returns'][$storedKey]['provider_created_at'] === 1793400000
    && DB::$tables['ae_stripe_receipt_returns'][$storedKey]['updated_at'] === $recorded
    && count(Audit::$rows) === 1 && count(Notification::$rows) === 1,
    'Same-state replay fills an old row date without moving recorded time or repeating audit/notice');
$return = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:foreign', 500);
check($return['state'] === 'review' && !$s->posts && !Credit::$rows,
    'A foreign refund observation cannot become an allocation or permit another return');
$s->refunds['foreign']->status = 'failed';
$s->refunds['foreign']->created = 1793489520;
$gateway->webhook(eventRequest($foreign, type: 'refund.failed'));
$auditCount = count(Audit::$rows); $gateway->webhook($request); // Old succeeded delivery after canonical failure.
check(!$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'] && !Credit::$rows && count(Audit::$rows) === $auditCount,
    'Canonical failed foreign refunds clear finance work and a stale success event cannot reopen it');
check(DB::$tables['ae_stripe_receipt_returns'][$storedKey]['provider_created_at'] === 1793400000,
    'A later canonical status change never moves the original provider date');

// Captured invoice 53: a du_ dispute for 1200 USD cents, closed lost. Reuse spent-credit coverage.
[$i, $s] = $setup(1200, 2); $s->status = 'failed'; $deliver($s);
$credit = Credit::$rows['7:usd']; $credit->amount = 0; // The customer has already spent all fallback credit.
$s->dispute = (object) ['id' => 'du_123456789012345678901234', 'object' => 'dispute', 'livemode' => false,
    'charge' => 'ch_return', 'payment_intent' => 'pi_return', 'currency' => 'usd', 'amount' => 1200,
    'status' => 'needs_response', 'created' => 1793400001, 'evidence_details' => (object) ['due_by' => 1794182399], 'metadata' => (object) []];
unset($s->dispute->payment_intent); // Stripe can identify a dispute through its charge alone.
$event = eventRequest($s->dispute, type: 'charge.dispute.created');
$before = [count(Audit::$rows), count(Notification::$rows), count($s->posts), ExtensionHelper::$payments, $i->status];
$callCount = count(Http::$calls);
$gateway->webhook($event); $gateway->webhook($event);
check(!array_filter(array_slice(Http::$calls, $callCount), fn ($call) => $call[0] !== 'get'),
    'Dispute observation makes no provider money writes');
$work = $gateway->receiptReversals($i, 'pi_return')[0];
check($work['status'] === 'open' && $work['needs_review'] && $work['credited_minor'] === 1000
    && $work['credit_balance_minor'] === 0 && $work['user_id'] === 7 && $work['customer'] === 'cus_owner'
    && $work['amount_minor'] === 1200 && count(Audit::$rows) === $before[0] + 1
    && count(Notification::$rows) === $before[1] + 1,
    'A duplicate dispute after spent credit identifies the customer, reversal, credited amount and current balance once');
$audit = end(Audit::$rows)['new_values'];
check(($audit['stripe_id'] ?? null) === $s->dispute->id && !array_key_exists('id', $audit) && $work['id'] === $s->dispute->id,
    'Invoice reversal audits retain the full Stripe ID without the native integer ID cast, while receipt readers keep id');
check(!str_contains(end(Notification::$rows)['body'], 'cus_owner')
    && str_contains(end(Audit::$rows)['new_values']['next_step'], '10 USD'),
    'Staff reconciliation details stay in the invoice audit rather than the bell');
$s->dispute->status = 'lost';
$s->dispute->created = 1793489520;
foreach (['charge.dispute.updated', 'charge.dispute.closed'] as $type) {
    $closed = eventRequest($s->dispute, type: $type);
    $gateway->webhook($closed); $gateway->webhook($closed);
}
check($gateway->receiptReversals($i, 'pi_return')[0]['status'] === 'lost' && $credit->amount === 0
    && count(Audit::$rows) === $before[0] + 2 && count(Notification::$rows) === $before[1] + 2
    && count($s->posts) === $before[2] && ExtensionHelper::$payments === $before[3] && $i->status === $before[4],
    'Losing a chargeback retains finance work without debiting spent credit, refunding again or changing invoice status');
check(DB::$tables['ae_stripe_receipt_returns']['pi_return:stripe:dispute:'.$s->dispute->id]['provider_created_at'] === 1793400001,
    'Dispute provider creation date survives a later loss');

// Captured invoice 49: 1164 USD cents, canonical won before old open deliveries.
[$i, $s] = $setup(1164, 11.64); $deliver($s);
$s->dispute = (object) ['id' => 'du_234567890123456789012345', 'object' => 'dispute', 'livemode' => false,
    'charge' => 'ch_return', 'payment_intent' => 'pi_return', 'currency' => 'usd', 'amount' => 1164,
    'status' => 'won', 'evidence_details' => (object) ['due_by' => 1794182399], 'metadata' => (object) []];
$won = eventRequest($s->dispute, type: 'charge.dispute.closed');
$gateway->webhook($won); $gateway->webhook($won);
$stale = clone $s->dispute; $stale->status = 'needs_response';
$gateway->webhook(eventRequest($stale, type: 'charge.dispute.created'));
check($gateway->receiptReversals($i, 'pi_return')[0]['status'] === 'won'
    && !$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'] && count(Audit::$rows) === 1,
    'A closed won dispute before its open event stays cleared on canonical replay');

$s->dispute->status = 'needs_response';
// A canonical stale read cannot reopen a terminal outcome already recorded.
$gateway->webhook(eventRequest($stale, type: 'charge.dispute.updated'));
check(!$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'], 'A terminal dispute never regresses to open');
$s->dispute->status = 'warning_under_review';
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.updated'));
check($gateway->receiptReversals($i, 'pi_return')[0]['status'] === 'won', 'A terminal dispute never regresses to inquiry');

foreach (['warning_needs_response', 'warning_under_review'] as $inquiry) {
    [$i, $s] = $setup(2000, 20); $deliver($s);
    $s->dispute = (object) ['id' => 'du_inquiry', 'charge' => 'ch_return', 'payment_intent' => 'pi_return',
        'currency' => 'usd', 'amount' => 2000, 'created' => 1793400000, 'status' => $inquiry];
    $gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.created'));
    $inquiryRow = $gateway->receiptReversals($i, 'pi_return')[0];
    $return = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:inquiry', 500);
    check($inquiryRow['status'] === 'inquiry' && $inquiryRow['needs_review'] && $return['state'] === 'review' && !$s->posts,
        'Inquiry has its own state and still requires review/holds automatic returns without claiming money was taken');
}

[$i, $s] = $setup(2000, 20); $deliver($s);
$s->dispute = (object) ['id' => 'dp_return', 'charge' => 'ch_return', 'payment_intent' => 'pi_return',
    'currency' => 'usd', 'amount' => 2000, 'status' => 'needs_response'];
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.created'));
$return = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:disputed', 500);
check($return['state'] === 'review' && !$s->posts && !Credit::$rows,
    'An observed dispute prevents a return even if the charge snapshot has not caught up');
$s->dispute->status = 'won';
$auditCount = count(Audit::$rows); $noticeCount = count(Notification::$rows);
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.closed'));
check(!$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'] && count(Audit::$rows) === $auditCount + 1
    && count(Notification::$rows) === $noticeCount + 1, 'A won dispute clears an existing work item and tells staff once');

[$i, $s] = $setup(2000, 20); $deliver($s);
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
$settled = ExtensionHelper::$payments; Notification::$fail = true;
rejects(fn () => $gateway->webhook($request), 'Inbox unavailable');
Notification::$fail = false;
check(!$gateway->receiptReversals($i, 'pi_return') && !Audit::$rows && ExtensionHelper::$payments === $settled,
    'Failed staff delivery escapes for Stripe to retry, rolling back the observation without losing the original payment');
$gateway->webhook($request);
check(count(Audit::$rows) === 1 && count(Notification::$rows) === 1, 'Stripe replay completes the rolled-back observation and alert');

$s->refunds['foreign']->currency = 'eur';
rejects(fn () => $gateway->webhook($request), 'HTTP 409');
check($gateway->receiptReversals($i, 'pi_return')[0]['currency'] === 'usd', 'Changed refund currency cannot rebind a receipt');
$s->refunds['foreign']->currency = 'usd';
ExtensionHelper::$payments['810:pi_return']['gateway'] = 'Other';
$auditCount = count(Audit::$rows); $gateway->webhook($request);
check(count(Audit::$rows) === $auditCount, 'A receipt now owned by another gateway receives no finance writes');

[$i, $s] = $setup(2000, 20);
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
rejects(fn () => $gateway->webhook($request), 'HTTP 409');
$deliver($s); $gateway->webhook($request);
check(count($gateway->receiptReversals($i, 'pi_return')) === 1, 'An event delivered before local settlement retries against the eventual native receipt');

[$i, $s] = $setup(); $deliver($s); // Existing receipt retains cus_owner.
$i->user->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_replacement']);
$s->dispute = (object) ['id' => 'dp_return', 'charge' => 'ch_return', 'payment_intent' => 'pi_return',
    'currency' => 'usd', 'amount' => 2000, 'status' => 'needs_response'];
$oldReceiptEvent = eventRequest($s->dispute, type: 'charge.dispute.created');
$gateway->webhook($oldReceiptEvent);
check($gateway->receiptReversals($i, 'pi_return')[0]['customer'] === 'cus_owner',
    'Replacing the account Stripe customer cannot discard a dispute on its older receipt');
$s->intent->customer = 'cus_replacement';
rejects(fn () => $gateway->webhook($oldReceiptEvent), 'HTTP 409');

// Every staff invoice notice must open Paymenter's clustered invoice resource.
[$i, $s] = $setup(1099, 10.99); $deliver($s);
foreach (['refund' => 're_notice', 'dispute' => 'du_notice'] as $kind => $id) {
    $object = (object) ['id' => $id, 'payment_intent' => 'pi_return', 'currency' => 'usd', 'amount' => 100,
        'status' => $kind === 'refund' ? 'succeeded' : 'needs_response', 'metadata' => (object) []];
    if ($kind === 'refund') $s->refunds['foreign'] = $object;
    else $s->dispute = $object;
    $gateway->webhook(eventRequest($object, type: $kind === 'refund' ? 'refund.created' : 'charge.dispute.created'));
}
$links = array_column(Notification::$rows, 'url');
[$i, $s] = $setup();
$s->intent->latest_charge->disputed = true; $deliver($s);
invoke($gateway, 'withPaymentLock', $i, function ($user, $invoice) use ($gateway, $s) {
    invoke($gateway, 'writePaymentAttempt', $user, $invoice, ['key' => 'notice-review', 'state' => 'reconcile', 'reason' => 'Unknown payment']);
    invoke($gateway, 'recordPaymentReview', $invoice, $s->intent, 'currency mismatch');
});
invoke($gateway, 'sendAttemptReview', $i);
$links = array_merge($links, array_column(array_filter(Notification::$rows, fn ($notice) => $notice['user_id'] !== $i->user_id), 'url'));
check($links === array_fill(0, 5, url('/admin/invoice/invoices/' . $i->id . '/edit')),
    'Refund, dispute, return, checkout and charge recovery notices open the native staff invoice page');

// A staff role without access to the destination must not receive invoice details.
[$i, $s] = $setup(1099, 10.99); $deliver($s);
$allowed = new \App\Models\User(new \App\Models\Properties('')); $allowed->id = 99;
$allowed->permissions = ['admin.invoices.view', 'admin.invoices.update'];
$denied = clone $allowed; $denied->id = 98; $denied->permissions = ['admin.invoices.view'];
\App\Models\User::$noticeStaff = [$denied, $allowed];
try {
    $s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
    $gateway->webhook($request);
    check(array_column(Notification::$rows, 'user_id') === [99], 'Only staff who can open the invoice edit destination receive a reversal notice');
} finally { \App\Models\User::$noticeStaff = null; }
