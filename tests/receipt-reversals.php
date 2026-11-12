<?php
// Reuse the return fixture: these events must never create a second return or debit.
use App\Models\{Credit, Gateway, InvoiceTransaction, Role, User};
use Illuminate\Support\Facades\DB;

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
check(count($reversals) === 1 && $reversals[0]['id'] === $foreign->id && !$reversals[0]['needs_review']
    && $reversals[0]['amount_minor'] === 100 && count(audits()) === 1 && count(notices()) === 1,
    'Captured USD 1 refund created/updated replays persist one work item, audit and staff alert within the existing schema');
$notice = notices()[0]; $audit = audits()[0];
check($notice['body'] === '$1.00 refunded on invoice INV-12.'
    && $notice['user_id'] === 99
    && str_contains($audit['new_values']['next_step'], 'cus_owner'),
    'Refund bell uses the invoice number while the invoice audit retains reconciliation details');
$storedKey = 'pi_return:stripe:refund:'.$foreign->id;
check($returns()[$storedKey]['provider_created_at'] === 1793400000,
    'External refund stores the canonical Stripe creation date');
// A fixed old recorded time: a rewrite with time() in the same second would otherwise match.
$setReturn($storedKey, ['provider_created_at' => null, 'updated_at' => 1700000000]);
check($returns()[$storedKey]['updated_at'] === 1700000000, 'Replay fixture starts from a fixed recorded time');
$gateway->webhook($updated);
check($returns()[$storedKey]['provider_created_at'] === 1793400000
    && $returns()[$storedKey]['updated_at'] === 1700000000
    && count(audits()) === 1 && count(notices()) === 1,
    'Same-state replay fills an old row date without moving recorded time or repeating audit/notice');
$return = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:foreign', 500);
check($return['state'] === 'review' && !$s->posts && !credits(),
    'A foreign refund observation cannot become an allocation or permit another return');
$s->refunds['foreign']->status = 'failed';
$s->refunds['foreign']->created = 1793489520;
$gateway->webhook(eventRequest($foreign, type: 'refund.failed'));
$auditCount = count(audits()); $gateway->webhook($request); // Old succeeded delivery after canonical failure.
check(!$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'] && !credits() && count(audits()) === $auditCount,
    'Canonical failed foreign refunds clear finance work and a stale success event cannot reopen it');
check($returns()[$storedKey]['provider_created_at'] === 1793400000,
    'A later canonical status change never moves the original provider date');

// An older HTTP response arrives after a second delivery commits the later refund state.
foreach ([['pending', 'succeeded', 'refunded'], ['pending', 'failed', 'failed'], ['succeeded', 'failed', 'failed']] as [$older, $newer, $expected]) {
    [$i, $s] = $setup(2000, 20); $deliver($s);
    $s->refunds['foreign'] = (object) ['id' => 're_delayed', 'payment_intent' => 'pi_return',
        'currency' => 'usd', 'amount' => 500, 'created' => 1793400000, 'status' => $older, 'metadata' => (object) []];
    $normal = $GLOBALS['stripeHandler']; $committed = null; $interleaved = false;
    $GLOBALS['stripeHandler'] = function ($verb, $path, $data, $request) use ($normal, $gateway, $s, $newer, $expected, $returns, &$committed, &$interleaved) {
        $answer = $normal($verb, $path, $data, $request);
        if (!$interleaved && $path === '/refunds/re_delayed') {
            check(DB::transactionLevel() === 0, 'Canonical refund HTTP runs before the owner transaction');
            $interleaved = true; $answer = clone $answer;
            $s->refunds['foreign']->status = $newer;
            $response = $gateway->webhook(eventRequest($s->refunds['foreign'], type: 'refund.updated'));
            $committed = $returns()['pi_return:stripe:refund:re_delayed'];
            check($response->getStatusCode() === 200 && $committed['state'] === $expected && !$committed['retry'],
                'The later canonical refund response commits and is acknowledged first');
        }
        return $answer;
    };
    $settled = [payments(), moneyRows(), credits()];
    $response = $gateway->webhook(eventRequest($s->refunds['foreign'], type: 'refund.created'));
    $GLOBALS['stripeHandler'] = $normal;
    $summary = $gateway->invoiceReversalSummary($i);
    check($interleaved && $response->getStatusCode() === 200
        && $returns()['pi_return:stripe:refund:re_delayed'] === $committed
        && $summary['totals'] === ['refunded' => $expected === 'refunded' ? 5.0 : 0.0,
            'open_disputes' => 0.0, 'lost_disputes' => 0.0, 'net_received' => $expected === 'refunded' ? 15.0 : 20.0]
        && count(audits()) === 1 && count(notices()) === 1 && !$s->posts
        && [payments(), moneyRows(), credits()] === $settled,
        'Delayed ' . $older . ' refund response preserves the committed ' . $newer . ' observation, cash totals and single notice');
}

// Captured invoice 53: a du_ dispute for 1200 USD cents, closed lost. Reuse spent-credit coverage.
[$i, $s] = $setup(1200, 2); $s->status = 'failed'; $deliver($s);
Credit::where('user_id', 7)->update(['amount' => 0]); // The customer has already spent all fallback credit.
$s->dispute = (object) ['id' => 'du_123456789012345678901234', 'object' => 'dispute', 'livemode' => false,
    'charge' => 'ch_return', 'payment_intent' => 'pi_return', 'currency' => 'usd', 'amount' => 1200,
    'status' => 'needs_response', 'created' => 1793400001, 'evidence_details' => (object) ['due_by' => 1794182399], 'metadata' => (object) []];
unset($s->dispute->payment_intent); // Stripe can identify a dispute through its charge alone.
$event = eventRequest($s->dispute, type: 'charge.dispute.created');
$before = [count(audits()), count(notices()), count($s->posts), payments(), $i->refresh()->status, moneyRows()];
$callCount = count($GLOBALS['stripeCalls']);
$gateway->webhook($event); $gateway->webhook($event);
check(!array_filter(array_slice($GLOBALS['stripeCalls'], $callCount), fn ($call) => $call[0] !== 'get'),
    'Dispute observation makes no provider money writes');
$work = $gateway->receiptReversals($i, 'pi_return')[0];
check($work['status'] === 'open' && $work['needs_review'] && $work['credited_minor'] === 1000
    && $work['credit_balance_minor'] === 0 && $work['user_id'] === 7 && $work['customer'] === 'cus_owner'
    && $work['amount_minor'] === 1200 && count(audits()) === $before[0] + 1
    && count(notices()) === $before[1] + 1,
    'A duplicate dispute after spent credit identifies the customer, reversal, credited amount and current balance once');
$audits = audits(); $audit = end($audits)['new_values']; $notices = notices();
check(($audit['stripe_id'] ?? null) === $s->dispute->id && !array_key_exists('id', $audit) && $work['id'] === $s->dispute->id,
    'Invoice reversal audits retain the full Stripe ID without the native integer ID cast, while receipt readers keep id');
check(!str_contains(end($notices)['body'], 'cus_owner')
    && str_contains($audit['next_step'], '10 USD'),
    'Staff reconciliation details stay in the invoice audit rather than the bell');
$s->dispute->status = 'lost';
$s->dispute->created = 1793489520;
foreach (['charge.dispute.updated', 'charge.dispute.closed'] as $type) {
    $closed = eventRequest($s->dispute, type: $type);
    $gateway->webhook($closed); $gateway->webhook($closed);
}
check($gateway->receiptReversals($i, 'pi_return')[0]['status'] === 'lost' && credits()['7:usd'] === 0.0
    && count(audits()) === $before[0] + 2 && count(notices()) === $before[1] + 2
    && count($s->posts) === $before[2] && payments() === $before[3] && $i->refresh()->status === $before[4] && moneyRows() === $before[5],
    'Losing a chargeback retains finance work without debiting spent credit, refunding again or changing invoice status');
check($returns()['pi_return:stripe:dispute:'.$s->dispute->id]['provider_created_at'] === 1793400001,
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
    && !$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'] && count(audits()) === 1,
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
    $normal = $GLOBALS['stripeHandler']; $interleaved = false; $committed = null; $counts = null;
    $GLOBALS['stripeHandler'] = function ($verb, $path, $data, $request) use ($normal, $gateway, $s, $returns, &$interleaved, &$committed, &$counts) {
        $answer = $normal($verb, $path, $data, $request);
        if (!$interleaved && $path === '/disputes/du_inquiry') {
            check(DB::transactionLevel() === 0, 'Dispute HTTP finishes before the owner transaction');
            $interleaved = true; $answer = clone $answer;
            $s->dispute->status = 'needs_response';
            $gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.updated'));
            $committed = $returns()['pi_return:stripe:dispute:du_inquiry'];
            $counts = [count(audits()), count(notices())];
        }
        return $answer;
    };
    $settled = [payments(), moneyRows(), credits()];
    $gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.updated'));
    $GLOBALS['stripeHandler'] = $normal;
    $summary = $gateway->invoiceReversalSummary($i);
    check($interleaved && $returns()['pi_return:stripe:dispute:du_inquiry'] === $committed
        && $summary['totals']['open_disputes'] === 20.0 && $summary['totals']['net_received'] === 0.0
        && [count(audits()), count(notices())] === $counts && [payments(), moneyRows(), credits()] === $settled,
        'A delayed inquiry cannot replace an open dispute, overstate cash or repeat its notice');

}

[$i, $s] = $setup(2000, 20); $deliver($s);
$s->dispute = (object) ['id' => 'dp_return', 'charge' => 'ch_return', 'payment_intent' => 'pi_return',
    'currency' => 'usd', 'amount' => 2000, 'status' => 'needs_response'];
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.created'));
$return = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:disputed', 500);
check($return['state'] === 'review' && !$s->posts && !credits(),
    'An observed dispute prevents a return even if the charge snapshot has not caught up');
$s->dispute->status = 'won';
$auditCount = count(audits()); $noticeCount = count(notices());
$gateway->webhook(eventRequest($s->dispute, type: 'charge.dispute.closed'));
check(!$gateway->receiptReversals($i, 'pi_return')[0]['needs_review'] && count(audits()) === $auditCount + 1
    && count(notices()) === $noticeCount + 1, 'A won dispute clears an existing work item and tells staff once');

[$i, $s] = $setup(2000, 20); $deliver($s);
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
$settled = [payments(), moneyRows()]; $inboxDown();
$gateway->webhook($request);
$inboxUp();
check(count($gateway->receiptReversals($i, 'pi_return')) === 1 && count(audits()) === 1 && !notices() && DB::table('ae_stripe_outbox')->where('state', 'pending')->count() === 1
    && [payments(), moneyRows()] === $settled, 'Failed delivery preserves the reversal, original money and pending notice');
$gateway->retryChargeAttempts(); $gateway->webhook($request); $gateway->retryChargeAttempts();
check(count(audits()) === 1 && count(notices()) === 1 && DB::table('ae_stripe_outbox')->where('state', 'sent')->count() === 1, 'Recovery and replay deliver the committed reversal notice exactly once');

$s->refunds['foreign']->currency = 'eur';
rejects(fn () => $gateway->webhook($request), 'HTTP 409');
check($gateway->receiptReversals($i, 'pi_return')[0]['currency'] === 'usd', 'Changed refund currency cannot rebind a receipt');
$s->refunds['foreign']->currency = 'usd';
$other = Gateway::create(['name' => 'Other', 'extension' => 'Other', 'type' => 'gateway', 'enabled' => true]);
InvoiceTransaction::where('transaction_id', 'pi_return')->update(['gateway_id' => $other->id]);
$auditCount = count(audits()); $gateway->webhook($request);
check(count(audits()) === $auditCount, 'A receipt now owned by another gateway receives no finance writes');

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
$links = array_column(notices(), 'url');
[$i, $s] = $setup();
$s->intent->latest_charge->disputed = true; $deliver($s);
invoke($gateway, 'withPaymentLock', $i, function ($user, $invoice) use ($gateway, $s) {
    invoke($gateway, 'writePaymentAttempt', $user, $invoice, ['key' => 'notice-review', 'state' => 'reconcile', 'reason' => 'Unknown payment']);
    invoke($gateway, 'recordPaymentReview', $invoice, $s->intent, 'currency mismatch');
});
invoke($gateway, 'sendAttemptReview', $i);
$links = array_merge($links, array_column(array_filter(notices(), fn ($notice) => $notice['user_id'] !== $i->user_id), 'url'));
check($links === array_fill(0, 5, url('/admin/invoice/invoices/' . $i->id . '/edit')),
    'Refund, dispute, return, checkout and charge recovery notices open the native staff invoice page');

// A staff role without access to the destination must not receive invoice details.
[$i, $s] = $setup(1099, 10.99); $deliver($s);
$restricted = [];
foreach ([98 => ['admin.invoices.viewAny', 'admin.invoices.view'], 97 => ['admin.invoices.view', 'admin.invoices.update']] as $id => $permissions) {
    $role = Role::create(['name' => 'Limited ' . $id, 'permissions' => $permissions]);
    $restricted[] = User::forceCreate(['id' => $id, 'first_name' => 'Limited', 'last_name' => (string) $id, 'email' => 'staff' . $id . '@example.test', 'password' => 'synthetic', 'role_id' => $role->id]);
}
$s->refunds['foreign'] = clone $foreign; $s->refunds['foreign']->status = 'succeeded';
$gateway->webhook($request);
check(array_column(notices(), 'user_id') === [99], 'Only staff who can open the invoice edit destination receive a reversal notice');
foreach ($restricted as $limited) { $role = $limited->role; $limited->delete(); $role->delete(); }

require __DIR__ . '/reversal-review.php';
