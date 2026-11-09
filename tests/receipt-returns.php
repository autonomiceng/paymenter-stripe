<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit, Gateway, Invoice, InvoiceTransaction, Notification};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Schema};

$returns = fn () => tableRows('ae_stripe_receipt_returns', ['payment_intent_id', 'allocation_key']);
$setReturn = function (string $key, array $values) {
    [$payment, $allocation] = explode(':', $key, 2);
    DB::table('ae_stripe_receipt_returns')->where('payment_intent_id', $payment)->where('allocation_key', $allocation)->update($values);
};
// Native paid processing: count each invoice that becomes paid.
$activations = 0;
Invoice::updated(function ($invoice) use (&$activations) { if ($invoice->wasChanged('status') && $invoice->status === 'paid') ++$activations; });
$setup = function ($amount = 2000, $total = 10, $currency = 'usd') use ($owner, $nativeGateway) {
    beforeWrite('ae_stripe_receipt_returns', null); beforeWrite('notifications', null);
    foreach (['invoice_transactions', 'ae_stripe_receipt_returns', 'audits', 'notifications', 'credits', 'invoice_items', 'invoices'] as $table) DB::table($table)->delete();
    $owner->properties()->where('key', '!=', 'stripe_id')->delete();
    $owner->properties()->updateOrCreate(['key' => 'stripe_id'], ['value' => 'cus_owner']);
    $owner->forceFill(['role_id' => null])->save();
    $nativeGateway->update(['enabled' => true]);
    $invoice = nativeInvoice(810, $total, strtoupper($currency), 'INV-12');
    $stripe = new class($amount, $currency) {
        public $refunds = [], $posts = [], $lose = false, $drop = false, $failWrite = false, $status = 'succeeded';
        public $intent, $dispute;
        public function __construct($amount, $currency) {
            $this->intent = (object) ['id' => 'pi_return', 'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
                'currency' => $currency, 'customer' => 'cus_owner', 'latest_charge' => (object) ['id' => 'ch_return', 'disputed' => false],
                'metadata' => (object) ['invoice_id' => '810', 'ae_stripe_gateway' => '1']];
        }
        public function request($verb, $path, $data, $request) {
            if ($path === '/payment_intents/pi_return') return $this->intent;
            if ($path === '/charges/ch_return') return (object) ['id' => 'ch_return', 'payment_intent' => 'pi_return', 'customer' => 'cus_owner', 'currency' => $this->intent->currency];
            if ($this->dispute && $path === '/disputes/' . $this->dispute->id) return $this->dispute;
            if (str_starts_with($path, '/refunds/')) {
                foreach ($this->refunds as $refund) if ($path === '/refunds/' . $refund->id) return $refund;
            }
            if ($path === '/refunds' && $verb === 'get') return (object) ['data' => array_values($this->refunds), 'has_more' => false];
            if ($path !== '/refunds' || $verb !== 'post') throw new RuntimeException('Unexpected return fixture request: ' . $path);
            check(holdsLock('users') && holdsLock('invoices'), 'Return sends serialize with invoice payments');
            $key = idempotencyKey($request);
            $stored = tableRows('ae_stripe_receipt_returns', ['payment_intent_id', 'allocation_key']);
            check(!empty($stored[array_key_last($stored)]['sent_at']), 'First-send marker survives outside the external-write transaction');
            $this->posts[] = [$key, $data];
            if ($this->drop) throw new ConnectionException('Not sent');
            $this->refunds[$key] ??= (object) ['id' => 're_' . count($this->refunds), 'payment_intent' => $data['payment_intent'],
                'amount' => $data['amount'], 'currency' => $this->intent->currency, 'metadata' => (object) $data['metadata'],
                'created' => 1793400000, 'status' => $this->status];
            if ($this->lose) { $this->lose = false; throw new ConnectionException('Lost response'); }
            return $this->refunds[$key];
        }
    };
    $GLOBALS['stripeHandler'] = fn ($verb, $path, $data, $request) => $stripe->request($verb, $path, $data, $request);
    return [$invoice, $stripe];
};
$deliver = fn ($s) => $gateway->webhook(eventRequest($s->intent));
$status = fn ($i, $key = 'excess') => $gateway->receiptReturnStatus($i, 'pi_return', $key);
$inboxDown = fn () => beforeWrite('notifications', fn () => throw new RuntimeException('Inbox unavailable'));
$inboxUp = fn () => beforeWrite('notifications', null);

[$i, $s] = $setup(); $deliver($s); $deliver($s); $gateway->retryReceiptReturns();
$a = $status($i);
check($a['provider_created_at'] === 1793400000, 'Owned refund response supplies its provider creation date');
check($a['state'] === 'refunded' && $a['amount_minor'] === 1000 && count($s->refunds) === 1 && count($s->posts) === 1,
    'Duplicate success and scheduled retries return only the excess once');
check(payments()['810:pi_return']['amount'] === 20.0 && payments()['810:' . $a['key']]['amount'] === -10.0
    && !credits() && count(notices()) === 1, 'Native receipt, return adjustment and customer notice remain distinct and deduplicated');
$r = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:1', 1000);
$gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:1', 1000);
check($r['state'] === 'refunded' && count($s->refunds) === 2, 'Domains and excess share one receipt budget and immutable allocations');
rejects(fn () => $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:2', 1), 'HTTP 409');
rejects(fn () => $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:1', 900), 'HTTP 409');

[$i, $s] = $setup(); $s->lose = true; $deliver($s);
check($status($i)['state'] === 'review' && count($s->refunds) === 1 && !credits(), 'A lost refund response cannot create spendable credit');
$setReturn('pi_return:excess', ['sent_at' => time() - 3 * 86400]);
$gateway->retryReceiptReturns(); $deliver($s);
check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count($s->refunds) === 1, 'Refund metadata recovers a lost response even after key expiry without another send');

[$i, $s] = $setup(); $s->drop = true; $deliver($s);
$key = $status($i)['key']; $s->drop = false; $gateway->retryReceiptReturns();
check($status($i)['state'] === 'refunded' && count($s->posts) === 2 && $s->posts[0][0] === $key && $s->posts[1][0] === $key,
    'An unsent refund retries the exact payment, amount and idempotency key');

[$i, $s] = $setup(); $s->drop = true; $deliver($s);
$setReturn('pi_return:excess', ['sent_at' => time() - 86400]);
$s->drop = false; $gateway->retryReceiptReturns();
check($status($i)['state'] === 'review' && count($s->posts) === 1 && !credits(), 'An expired unknown send stops with a resolution path instead of a duplicate refund or credit');

[$i, $s] = $setup(); $s->status = 'pending'; $deliver($s); $gateway->retryReceiptReturns();
check($status($i)['state'] === 'pending' && !credits() && count($s->posts) === 1, 'A pending refund never becomes account credit');
current($s->refunds)->status = 'failed';
current($s->refunds)->created = 1793489520;
$gateway->retryReceiptReturns(); $deliver($s);
check($status($i)['state'] === 'credited' && credits()['7:usd'] === 10.0 && count($s->posts) === 1,
    'A proven failed refund becomes account credit exactly once');
check($status($i)['provider_created_at'] === 1793400000, 'A later failed refund response cannot move the stored creation date');

foreach (['succeeded', 'failed'] as $remoteStatus) {
    [$i, $s] = $setup(); $s->status = $remoteStatus;
    beforeWrite('ae_stripe_receipt_returns', function ($a) {
        if (!in_array($a['state'] ?? '', ['refunded', 'credited'], true)) return;
        beforeWrite('ae_stripe_receipt_returns', null);
        throw new RuntimeException('Commit interrupted after remote refund');
    });
    $deliver($s);
    check($status($i)['state'] === 'review' && !credits() && count(payments()) === 1,
        'Interrupted local settlement rolls back credit and the return adjustment');
    $gateway->retryReceiptReturns(); $deliver($s);
    check(count($s->posts) === 1 && count(payments()) === 2
        && ($remoteStatus === 'failed' ? credits()['7:usd'] === 10.0 : !credits()),
        'Recovery reconciles the remote outcome after local rollback and completes only once');
}

foreach (['succeeded', 'failed'] as $remoteStatus) {
    [$i, $s] = $setup(); $s->status = $remoteStatus;
    beforeWrite('ae_stripe_receipt_returns', function ($a) {
        if (!in_array($a['state'] ?? '', ['refunded', 'credited'], true)) return;
        beforeWrite('ae_stripe_receipt_returns', null);
        afterNextCommit(fn () => throw new PDOException('Lost commit acknowledgement'));
    });
    $deliver($s); $gateway->retryReceiptReturns(); $deliver($s);
    check($status($i)['state'] === ($remoteStatus === 'failed' ? 'credited' : 'refunded')
        && count($s->posts) === 1 && count(payments()) === 2
        && ($remoteStatus === 'failed' ? credits()['7:usd'] === 10.0 : !credits()),
        'A lost commit acknowledgement preserves a completed return instead of crediting it twice');
}

[$i, $s] = $setup(); $s->intent->latest_charge->disputed = true; $deliver($s); $gateway->retryReceiptReturns();
check($status($i)['state'] === 'review' && !$s->posts && !credits() && count(notices()) === 2,
    'A disputed receipt surfaces the invoice and Stripe path without refunding or crediting it');

[$i, $s] = $setup(); $s->refunds['external'] = (object) ['id' => 're_external', 'amount' => 1000]; $deliver($s);
check($status($i)['state'] === 'review' && !$s->posts && !credits(), 'Unallocated external returns cannot cause duplicate reimbursement');

[$i, $s] = $setup(); $s->status = 'pending'; $deliver($s);
$i->update(['currency_code' => 'EUR']); current($s->refunds)->status = 'failed'; $gateway->retryReceiptReturns();
check($status($i)['state'] === 'review' && !credits() && count(payments()) === 1,
    'A changed native currency cannot credit or adjust the wrong currency');

[$i, $s] = $setup();
$normal = $GLOBALS['stripeHandler'];
$GLOBALS['stripeHandler'] = fn ($verb, $path, $data, $request) => $path === '/refunds' ? (object) ['data' => [], 'has_more' => true] : $normal($verb, $path, $data, $request);
$deliver($s);
check($status($i)['state'] === 'review' && $status($i)['sent_at'] === null && !$s->posts && !credits(),
    'Incomplete history cannot start the replay timer, send money or create credit');
$GLOBALS['stripeHandler'] = $normal; $gateway->retryReceiptReturns();
check($status($i)['state'] === 'refunded' && count($s->posts) === 1, 'Restoring readable history automatically returns the reserved excess');

[$i, $s] = $setup(); $inboxDown(); $deliver($s); $inboxUp();
$gateway->retryReceiptReturns();
check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count(notices()) === 1,
    'Notification failure retries the notice without repeating the completed return');

[$i, $s] = $setup(); $s->status = 'failed'; $inboxDown(); $deliver($s); $inboxUp();
// The invoice row is unavailable to the sweep, then restored with the same identity.
$invoiceRow = (array) DB::table('invoices')->where('id', $i->id)->first();
Schema::disableForeignKeyConstraints(); DB::table('invoices')->where('id', $i->id)->delete();
$gateway->retryReceiptReturns();
$held = $returns()['pi_return:excess'];
DB::table('invoices')->insert($invoiceRow); Schema::enableForeignKeyConstraints();
$deliver($s); $gateway->retryReceiptReturns();
check(credits()['7:usd'] === 10.0 && $held['state'] === 'credited' && !$held['retry']
    && $status($i)['state'] === 'credited' && count($s->posts) === 1 && count(notices()) === 1,
    'An unavailable invoice cannot erase completed credit or add it twice when its binding is restored');

foreach (['succeeded', 'failed'] as $later) {
    [$i, $s] = $setup(); $s->status = 'pending'; $deliver($s);
    current($s->refunds)->status = $later; $inboxDown();
    $gateway->retryReceiptReturns(); $inboxUp();
    $gateway->retryReceiptReturns();
    $sent = notices();
    check(count($sent) === 2 && str_contains(end($sent)['body'], $later === 'failed' ? 'account credit' : 'original payment method'),
        'A pending notice cannot hide a completion notice after settlement crashes; sweep alone recovers it');
}

[$i, $s] = $setup(); $deliver($s);
check(!array_filter($owner->properties()->pluck('key')->all(), fn ($key) => str_starts_with($key, 'ae_stripe_receipt_')),
    'Native customer profile properties contain no receipt history or return reasons');
$firstKey = $status($i)['key']; current($s->refunds)->status = 'failed';
$failed = clone current($s->refunds);
$gateway->webhook(eventRequest($failed, type: 'refund.failed'));
$gateway->webhook(eventRequest($failed, type: 'refund.failed'));
$gateway->webhook(eventRequest($failed, type: 'refund.updated'));
$a = $status($i);
check($a['state'] === 'credited' && $a['key'] === $firstKey && credits()['7:usd'] === 10.0
    && count($s->posts) === 1 && count(payments()) === 2 && count(notices()) === 2,
    'An already-notified successful refund can later fail and become credit once, with the same adjustment');
check(payments()['810:' . $firstKey]['is_credit_transaction'], 'Credit fallback reclassifies the existing adjustment as credit');
$owner->properties()->updateOrCreate(['key' => 'ae_stripe_receipt_pi_return'], ['value' => json_encode(['allocations' => ['excess' => ['state' => 'pending']]])]);
$gateway->retryReceiptReturns(); $deliver($s);
check(credits()['7:usd'] === 10.0 && count($s->posts) === 1,
    'A stale stock-admin property write cannot overwrite the authoritative credit-once row');
$owner->properties()->where('key', 'ae_stripe_receipt_pi_return')->delete();
$cash = fn () => array_sum(array_column(array_filter(payments(), fn ($p) => $p['status'] === 'succeeded' && !$p['is_credit_transaction']), 'amount'));
check($cash() === 20.0, 'Credit fallback preserves all cash received in native revenue');
$next = nativeInvoice(811, 10);
DB::transaction(fn () => ExtensionHelper::addPayment($next, 'AeStripe', 10.0, null, 'credit_spend', isCreditTransaction: true));
check($cash() === 20.0, 'Later account-credit spending does not double-count cash revenue');

[$i, $s] = $setup(); $deliver($s);
check(!payments()['810:' . $status($i)['key']]['is_credit_transaction'], 'A cash refund remains a cash-revenue adjustment');
current($s->refunds)->status = 'failed'; current($s->refunds)->failure_reason = 'charge_for_pending_refund_disputed';
$gateway->webhook(eventRequest(current($s->refunds), type: 'refund.failed'));
$gateway->retryReceiptReturns();
check($status($i)['state'] === 'review' && !credits() && count(payments()) === 2,
    'A later disputed refund is held and surfaced without credit or a second adjustment');

[$i, $s] = $setup(); $i->update(['status' => 'cancelled']); $s->status = 'pending';
$before = $activations;
$deliver($s); $i->update(['status' => 'pending']); $deliver($s);
check(!payments() && $i->refresh()->status === 'pending' && $activations === $before,
    'Reopening an invoice cannot spend a closed receipt already reserved for a whole return');
current($s->refunds)->status = 'succeeded'; $gateway->retryReceiptReturns(); $deliver($s);
check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count(payments()) === 2
    && array_sum(array_column(payments(), 'amount')) === 0.0 && $i->refresh()->status === 'pending'
    && $activations === $before, 'A reopened invoice retains one whole return without paid processing');

foreach (['succeeded', 'failed'] as $outcome) {
    [$i, $s] = $setup(); $i->update(['status' => 'cancelled']); $s->status = 'pending';
    $before = $activations;
    $deliver($s); $deliver($s);
    check($status($i)['amount_minor'] === 2000 && $status($i)['state'] === 'pending' && !payments()
        && !credits() && $i->refresh()->status === 'cancelled', 'Closed-invoice money stays outside native settled funds while its full return is pending');
    current($s->refunds)->status = $outcome; $gateway->retryReceiptReturns(); $deliver($s); $gateway->retryChargeAttempts();
    $net = array_sum(array_column(array_filter(payments(), fn ($p) => $p['status'] === 'succeeded'), 'amount'));
    check($status($i)['state'] === ($outcome === 'failed' ? 'credited' : 'refunded') && $net === 0.0
        && count(payments()) === 2 && count($s->posts) === 1 && $i->refresh()->status === 'cancelled'
        && $activations === $before, 'Whole closed-invoice receipt returns once without native paid events, activation or Domains late-money funding');
    check($outcome === 'failed' ? credits()['7:usd'] === 20.0 : !credits(), 'Closed receipt fallback credits only a proved failed refund');
}

foreach ([401, 503] as $outageStatus) {
    [$i, $s] = $setup(); $normal = $GLOBALS['stripeHandler'];
    $customerNotices = fn () => array_filter(notices(), fn ($n) => $n['user_id'] === $i->user_id);
    $GLOBALS['stripeHandler'] = fn ($verb, $path, $data, $request) => $path === '/refunds'
        ? throw new StripeFixtureError($outageStatus) : $normal($verb, $path, $data, $request);
    $deliver($s);
    $a = $status($i);
    check($a['state'] === 'review' && !empty($a['notified']) && !$customerNotices()
        && count(audits()) === 1 && count(notices()) === 1 && notices()[0]['user_id'] === 99,
        'HTTP ' . $outageStatus . ': a Stripe access outage records the return audit and staff alert without a customer attention notice');
    $GLOBALS['stripeHandler'] = $normal; $s->intent->customer = 'cus_other'; // Access returns, but verification still holds the same review reason.
    $gateway->retryReceiptReturns(); $gateway->retryReceiptReturns();
    check($status($i)['state'] === 'review' && $status($i)['reason'] === $a['reason'] && count($customerNotices()) === 1,
        'A staff-only outage notice cannot suppress the customer notice when access returns with the same review state');
    $s->intent->customer = 'cus_owner'; $gateway->retryReceiptReturns(); $gateway->retryReceiptReturns();
    check($status($i)['state'] === 'refunded' && count($customerNotices()) === 2,
        'A later return state change sends its customer completion notice exactly once');
}

foreach ([new ConnectionException('Offline'), new StripeFixtureError(401), new StripeFixtureError(403)] as $outage) {
    [$i, $s] = $setup(); $s->status = 'pending'; $deliver($s);
    $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:outage', 1000);
    $setReturn('pi_return:excess', ['updated_at' => 1]);
    $setReturn('pi_return:domains:outage', ['updated_at' => 2]);
    $calls = 0; $GLOBALS['stripeHandler'] = function () use (&$calls, $outage) { ++$calls; throw $outage; };
    $gateway->retryReceiptReturns();
    check($calls === 1 && $returns()['pi_return:domains:outage']['updated_at'] === 2,
        'Receipt sweep stops its indexed due batch after the first connection or authentication failure');
}

foreach ([['jpy', 20, 10, 10], ['ugx', 2000, 10, 1000]] as [$currency, $received, $total, $excess]) {
    [$i, $s] = $setup($received, $total, $currency); $deliver($s);
    check($status($i)['state'] === 'refunded' && $s->posts[0][1]['amount'] === $excess, 'Return amount uses Stripe minor units for ' . $currency);
}

foreach ([['usd', 1050, 10, '0.50 USD'], ['usd', 2000, 10, '10.00 USD'], ['jpy', 20, 10, '10 JPY'],
    ['isk', 2000, 10, '10 ISK'], ['ugx', 2000, 10, '10 UGX']] as [$currency, $received, $total, $display]) {
    [$i, $s] = $setup($received, $total, $currency); $deliver($s);
    check(str_contains(notices()[0]['body'], 'A payment of ' . $display . ' has been returned'),
        'Refund notice uses currency display precision for ' . $display);
}

[$i, $s] = $setup(2000, 20); $deliver($s);
DB::table('ae_stripe_receipt_returns')->delete(); // A pre-upgrade native receipt.
DB::transaction(function () use ($gateway, $i, $s) {
    $a = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:old:1', 500);
    check($a['state'] === 'reserved' && !$s->posts, 'Nested Domains transaction reserves before external I/O');
});
check($status($i, 'domains:old:1')['state'] === 'refunded' && count($s->posts) === 1, 'A verified pre-upgrade receipt is adopted and refunded after the caller commits');
try {
    DB::transaction(function () use ($gateway, $i) {
        $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:rollback', 500);
        throw new RuntimeException('Caller rolled back');
    });
} catch (RuntimeException $e) { check($e->getMessage() === 'Caller rolled back', 'Domains caller controls its transaction'); }
check($status($i, 'domains:rollback') === null && count($s->posts) === 1, 'A rolled-back caller reservation cannot send a refund');

require __DIR__ . '/receipt-reversals.php';
