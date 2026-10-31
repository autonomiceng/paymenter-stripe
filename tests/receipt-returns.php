<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit, Gateway, Invoice, Notification, Properties, User};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $saved = [User::$row, Invoice::$rows, ExtensionHelper::$payments, Http::$handler, Audit::$rows, Notification::$rows, Credit::$rows, Gateway::$enabled, DB::$tables['ae_stripe_receipt_returns']];
    $setup = function ($amount = 2000, $total = 10, $currency = 'usd') use ($gateway) {
        User::$row = new User(new Properties('cus_owner'));
        $invoice = new Invoice(User::$row); $invoice->id = 810; $invoice->total = $total; $invoice->currency_code = $currency;
        Invoice::$rows[810] = $invoice; ExtensionHelper::$payments = []; Audit::$rows = []; Notification::$rows = []; Credit::$rows = []; Gateway::$enabled = true; DB::$tables['ae_stripe_receipt_returns'] = [];
        $stripe = new class($amount, $currency) {
            public $refunds = [], $posts = [], $lose = false, $drop = false, $failWrite = false, $status = 'succeeded';
            public $intent, $dispute;
            public function __construct($amount, $currency) {
                $this->intent = (object) ['id' => 'pi_return', 'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
                    'currency' => $currency, 'customer' => 'cus_owner', 'latest_charge' => (object) ['id' => 'ch_return', 'disputed' => false],
                    'metadata' => (object) ['invoice_id' => 810, 'ae_stripe_gateway' => '1']];
            }
            public function request($verb, $path, $data) {
                if ($path === '/payment_intents/pi_return') return $this->intent;
                if ($path === '/charges/ch_return') return (object) ['id' => 'ch_return', 'payment_intent' => 'pi_return', 'customer' => 'cus_owner', 'currency' => $this->intent->currency];
                if ($path === '/disputes/dp_return') return $this->dispute;
                if (str_starts_with($path, '/refunds/')) {
                    foreach ($this->refunds as $refund) if ($path === '/refunds/' . $refund->id) return $refund;
                }
                if ($path === '/refunds' && $verb === 'get') return (object) ['data' => array_values($this->refunds), 'has_more' => false];
                if ($path !== '/refunds' || $verb !== 'post') throw new RuntimeException('Unexpected return fixture request: ' . $path);
                check(DB::$locked && DB::$userLocked, 'Return sends serialize with invoice payments');
                $key = end(Http::$calls)[3]['Idempotency-Key'] ?? null;
                $stored = DB::$tables['ae_stripe_receipt_returns'];
                check(!empty($stored[array_key_last($stored)]['sent_at']), 'First-send marker survives outside the external-write transaction');
                $this->posts[] = [$key, $data];
                if ($this->drop) throw new ConnectionException('Not sent');
                $this->refunds[$key] ??= (object) ['id' => 're_' . count($this->refunds), 'payment_intent' => $data['payment_intent'],
                    'amount' => $data['amount'], 'currency' => $this->intent->currency, 'metadata' => (object) $data['metadata'], 'status' => $this->status];
                if ($this->lose) { $this->lose = false; throw new ConnectionException('Lost response'); }
                return $this->refunds[$key];
            }
        };
        Http::$handler = fn ($verb, $path, $data) => $stripe->request($verb, $path, $data);
        return [$invoice, $stripe];
    };
    $deliver = fn ($s) => $gateway->webhook(eventRequest($s->intent));
    $status = fn ($i, $key = 'excess') => $gateway->receiptReturnStatus($i, 'pi_return', $key);
    try {
        [$i, $s] = $setup(); $deliver($s); $deliver($s); $gateway->retryReceiptReturns();
        $a = $status($i);
        check($a['state'] === 'refunded' && $a['amount_minor'] === 1000 && count($s->refunds) === 1 && count($s->posts) === 1,
            'Duplicate success and scheduled retries return only the excess once');
        check(ExtensionHelper::$payments['810:pi_return']['amount'] === 20.0 && ExtensionHelper::$payments['810:' . $a['key']]['amount'] === -10.0
            && !Credit::$rows && count(Notification::$rows) === 1, 'Native receipt, return adjustment and customer notice remain distinct and deduplicated');
        $r = $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:1', 1000);
        $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:1', 1000);
        check($r['state'] === 'refunded' && count($s->refunds) === 2, 'Domains and excess share one receipt budget and immutable allocations');
        rejects(fn () => $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:2', 1), 'HTTP 409');
        rejects(fn () => $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:failed:1', 900), 'HTTP 409');

        [$i, $s] = $setup(); $s->lose = true; $deliver($s);
        check($status($i)['state'] === 'review' && count($s->refunds) === 1 && !Credit::$rows, 'A lost refund response cannot create spendable credit');
        DB::$tables['ae_stripe_receipt_returns']['pi_return:excess']['sent_at'] = time() - 3 * 86400;
        $gateway->retryReceiptReturns(); $deliver($s);
        check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count($s->refunds) === 1, 'Refund metadata recovers a lost response even after key expiry without another send');

        [$i, $s] = $setup(); $s->drop = true; $deliver($s);
        $key = $status($i)['key']; $s->drop = false; $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'refunded' && count($s->posts) === 2 && $s->posts[0][0] === $key && $s->posts[1][0] === $key,
            'An unsent refund retries the exact payment, amount and idempotency key');

        [$i, $s] = $setup(); $s->drop = true; $deliver($s);
        DB::$tables['ae_stripe_receipt_returns']['pi_return:excess']['sent_at'] = time() - 86400;
        $s->drop = false; $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'review' && count($s->posts) === 1 && !Credit::$rows, 'An expired unknown send stops with a resolution path instead of a duplicate refund or credit');

        [$i, $s] = $setup(); $s->status = 'pending'; $deliver($s); $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'pending' && !Credit::$rows && count($s->posts) === 1, 'A pending refund never becomes account credit');
        current($s->refunds)->status = 'failed';
        $gateway->retryReceiptReturns(); $deliver($s);
        check($status($i)['state'] === 'credited' && Credit::$rows['7:usd']->amount === 10.0 && count($s->posts) === 1,
            'A proven failed refund becomes account credit exactly once');

        foreach (['succeeded', 'failed'] as $remoteStatus) {
            [$i, $s] = $setup(); $s->status = $remoteStatus;
            ReceiptReturnQuery::$beforeWrite = function ($a) {
                if (!in_array($a['state'] ?? '', ['refunded', 'credited'], true)) return;
                ReceiptReturnQuery::$beforeWrite = null;
                throw new RuntimeException('Commit interrupted after remote refund');
            };
            $deliver($s);
            check($status($i)['state'] === 'review' && !Credit::$rows && count(ExtensionHelper::$payments) === 1,
                'Interrupted local settlement rolls back credit and the return adjustment');
            $gateway->retryReceiptReturns(); $deliver($s);
            check(count($s->posts) === 1 && count(ExtensionHelper::$payments) === 2
                && ($remoteStatus === 'failed' ? Credit::$rows['7:usd']->amount === 10.0 : !Credit::$rows),
                'Recovery reconciles the remote outcome after local rollback and completes only once');
        }

        foreach (['succeeded', 'failed'] as $remoteStatus) {
            [$i, $s] = $setup(); $s->status = $remoteStatus;
            ReceiptReturnQuery::$beforeWrite = function ($a) {
                if (!in_array($a['state'] ?? '', ['refunded', 'credited'], true)) return;
                ReceiptReturnQuery::$beforeWrite = null;
                DB::$afterTransaction = fn () => throw new PDOException('Lost commit acknowledgement');
            };
            $deliver($s); $gateway->retryReceiptReturns(); $deliver($s);
            check($status($i)['state'] === ($remoteStatus === 'failed' ? 'credited' : 'refunded')
                && count($s->posts) === 1 && count(ExtensionHelper::$payments) === 2
                && ($remoteStatus === 'failed' ? Credit::$rows['7:usd']->amount === 10.0 : !Credit::$rows),
                'A lost commit acknowledgement preserves a completed return instead of crediting it twice');
        }

        [$i, $s] = $setup(); $s->intent->latest_charge->disputed = true; $deliver($s); $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'review' && !$s->posts && !Credit::$rows && count(Notification::$rows) === 2,
            'A disputed receipt surfaces the invoice and Stripe path without refunding or crediting it');

        [$i, $s] = $setup(); $s->refunds['external'] = (object) ['id' => 're_external', 'amount' => 1000]; $deliver($s);
        check($status($i)['state'] === 'review' && !$s->posts && !Credit::$rows, 'Unallocated external returns cannot cause duplicate reimbursement');

        [$i, $s] = $setup(); $s->status = 'pending'; $deliver($s);
        $i->currency_code = 'eur'; current($s->refunds)->status = 'failed'; $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'review' && !Credit::$rows && count(ExtensionHelper::$payments) === 1,
            'A changed native currency cannot credit or adjust the wrong currency');

        [$i, $s] = $setup();
        $normal = Http::$handler;
        Http::$handler = fn ($verb, $path, $data) => $path === '/refunds' ? (object) ['data' => [], 'has_more' => true] : $normal($verb, $path, $data);
        $deliver($s);
        check($status($i)['state'] === 'review' && $status($i)['sent_at'] === null && !$s->posts && !Credit::$rows,
            'Incomplete history cannot start the replay timer, send money or create credit');
        Http::$handler = $normal; $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'refunded' && count($s->posts) === 1, 'Restoring readable history automatically returns the reserved excess');

        [$i, $s] = $setup(); Notification::$fail = true; $deliver($s); Notification::$fail = false;
        $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count(Notification::$rows) === 1,
            'Notification failure retries the notice without repeating the completed return');

        [$i, $s] = $setup(); $s->status = 'failed'; Notification::$fail = true; $deliver($s); Notification::$fail = false;
        unset(Invoice::$rows[$i->id]); $gateway->retryReceiptReturns();
        $held = DB::$tables['ae_stripe_receipt_returns']['pi_return:excess'];
        Invoice::$rows[$i->id] = $i; $deliver($s); $gateway->retryReceiptReturns();
        check(Credit::$rows['7:usd']->amount === 10.0 && $held['state'] === 'credited' && !$held['retry']
            && $status($i)['state'] === 'credited' && count($s->posts) === 1 && count(Notification::$rows) === 1,
            'An unavailable invoice cannot erase completed credit or add it twice when its binding is restored');

        foreach (['succeeded', 'failed'] as $later) {
            [$i, $s] = $setup(); $s->status = 'pending'; $deliver($s);
            current($s->refunds)->status = $later; Notification::$fail = true;
            $gateway->retryReceiptReturns(); Notification::$fail = false;
            $gateway->retryReceiptReturns();
            check(count(Notification::$rows) === 2 && str_contains(end(Notification::$rows)['body'], $later === 'failed' ? 'account credit' : 'original payment method'),
                'A pending notice cannot hide a completion notice after settlement crashes; sweep alone recovers it');
        }

        [$i, $s] = $setup(); $deliver($s);
        check(!array_filter(array_keys(User::$row->props->values), fn ($key) => str_starts_with($key, 'ae_stripe_receipt_')),
            'Native customer profile properties contain no receipt history or return reasons');
        $firstKey = $status($i)['key']; current($s->refunds)->status = 'failed';
        $failed = clone current($s->refunds);
        $gateway->webhook(eventRequest($failed, type: 'refund.failed'));
        $gateway->webhook(eventRequest($failed, type: 'refund.failed'));
        $gateway->webhook(eventRequest($failed, type: 'refund.updated'));
        $a = $status($i);
        check($a['state'] === 'credited' && $a['key'] === $firstKey && Credit::$rows['7:usd']->amount === 10.0
            && count($s->posts) === 1 && count(ExtensionHelper::$payments) === 2 && count(Notification::$rows) === 2,
            'An already-notified successful refund can later fail and become credit once, with the same adjustment');
        check(ExtensionHelper::$payments['810:' . $firstKey]['is_credit_transaction'], 'Credit fallback reclassifies the existing adjustment as credit');
        User::$row->props->updateOrCreate(['key' => 'ae_stripe_receipt_pi_return'], ['value' => json_encode(['allocations' => ['excess' => ['state' => 'pending']]])]);
        $gateway->retryReceiptReturns(); $deliver($s);
        check(Credit::$rows['7:usd']->amount === 10.0 && count($s->posts) === 1,
            'A stale stock-admin property write cannot overwrite the authoritative credit-once row');
        unset(User::$row->props->values['ae_stripe_receipt_pi_return']);
        $cash = fn () => array_sum(array_column(array_filter(ExtensionHelper::$payments, fn ($p) => $p['status'] === 'succeeded' && !$p['is_credit_transaction']), 'amount'));
        check($cash() === 20.0, 'Credit fallback preserves all cash received in native revenue');
        $next = clone $i; $next->id = 811;
        DB::transaction(fn () => ExtensionHelper::addPayment($next, 'AeStripe', 10.0, null, 'credit_spend', isCreditTransaction: true));
        check($cash() === 20.0, 'Later account-credit spending does not double-count cash revenue');

        [$i, $s] = $setup(); $deliver($s);
        check(!ExtensionHelper::$payments['810:' . $status($i)['key']]['is_credit_transaction'], 'A cash refund remains a cash-revenue adjustment');
        current($s->refunds)->status = 'failed'; current($s->refunds)->failure_reason = 'charge_for_pending_refund_disputed';
        $gateway->webhook(eventRequest(current($s->refunds), type: 'refund.failed'));
        $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'review' && !Credit::$rows && count(ExtensionHelper::$payments) === 2,
            'A later disputed refund is held and surfaced without credit or a second adjustment');

        [$i, $s] = $setup(); $i->status = 'cancelled'; $s->status = 'pending';
        ExtensionHelper::$simulateNativeListeners = true; $activations = ExtensionHelper::$activations;
        $deliver($s); $i->status = 'pending'; $deliver($s);
        check(!ExtensionHelper::$payments && $i->status === 'pending' && ExtensionHelper::$activations === $activations,
            'Reopening an invoice cannot spend a closed receipt already reserved for a whole return');
        current($s->refunds)->status = 'succeeded'; $gateway->retryReceiptReturns(); $deliver($s);
        check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count(ExtensionHelper::$payments) === 2
            && array_sum(array_column(ExtensionHelper::$payments, 'amount')) === 0.0 && $i->status === 'pending'
            && ExtensionHelper::$activations === $activations, 'A reopened invoice retains one whole return without paid processing');
        ExtensionHelper::$simulateNativeListeners = false;

        foreach (['succeeded', 'failed'] as $outcome) {
            [$i, $s] = $setup(); $i->status = 'cancelled'; $s->status = 'pending';
            ExtensionHelper::$simulateNativeListeners = true; $activations = ExtensionHelper::$activations;
            $deliver($s); $deliver($s);
            check($status($i)['amount_minor'] === 2000 && $status($i)['state'] === 'pending' && !ExtensionHelper::$payments
                && !Credit::$rows && $i->status === 'cancelled', 'Closed-invoice money stays outside native settled funds while its full return is pending');
            current($s->refunds)->status = $outcome; $gateway->retryReceiptReturns(); $deliver($s); $gateway->retryChargeAttempts();
            $net = array_sum(array_column(array_filter(ExtensionHelper::$payments, fn ($p) => $p['status'] === 'succeeded'), 'amount'));
            check($status($i)['state'] === ($outcome === 'failed' ? 'credited' : 'refunded') && $net === 0.0
                && count(ExtensionHelper::$payments) === 2 && count($s->posts) === 1 && $i->status === 'cancelled'
                && ExtensionHelper::$activations === $activations, 'Whole closed-invoice receipt returns once without native paid events, activation or Domains late-money funding');
            check($outcome === 'failed' ? Credit::$rows['7:usd']->amount === 20.0 : !Credit::$rows, 'Closed receipt fallback credits only a proved failed refund');
            ExtensionHelper::$simulateNativeListeners = false;
        }

        foreach ([new ConnectionException('Offline'), new \Illuminate\Http\Client\RequestException(401), new \Illuminate\Http\Client\RequestException(403)] as $outage) {
            [$i, $s] = $setup(); $s->status = 'pending'; $deliver($s);
            $gateway->allocateReceiptReturn($i, 'pi_return', 'domains:outage', 1000);
            DB::$tables['ae_stripe_receipt_returns']['pi_return:excess']['updated_at'] = 1;
            DB::$tables['ae_stripe_receipt_returns']['pi_return:domains:outage']['updated_at'] = 2;
            $calls = 0; Http::$handler = function () use (&$calls, $outage) { ++$calls; throw $outage; };
            $gateway->retryReceiptReturns();
            check($calls === 1 && DB::$tables['ae_stripe_receipt_returns']['pi_return:domains:outage']['updated_at'] === 2,
                'Receipt sweep stops its indexed due batch after the first connection or authentication failure');
        }

        foreach ([['jpy', 20, 10, 10], ['ugx', 2000, 10, 1000]] as [$currency, $received, $total, $excess]) {
            [$i, $s] = $setup($received, $total, $currency); $deliver($s);
            check($status($i)['state'] === 'refunded' && $s->posts[0][1]['amount'] === $excess, 'Return amount uses Stripe minor units for ' . $currency);
        }

        foreach ([['usd', 1050, 10, '0.50 USD'], ['usd', 2000, 10, '10.00 USD'], ['jpy', 20, 10, '10 JPY'],
            ['isk', 2000, 10, '10 ISK'], ['ugx', 2000, 10, '10 UGX']] as [$currency, $received, $total, $display]) {
            [$i, $s] = $setup($received, $total, $currency); $deliver($s);
            check(str_contains(Notification::$rows[0]['body'], 'A payment of ' . $display . ' has been returned'),
                'Refund notice uses currency display precision for ' . $display);
        }

        [$i, $s] = $setup(2000, 20); $deliver($s);
        DB::$tables['ae_stripe_receipt_returns'] = []; // A pre-upgrade native receipt.
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
    } finally {
        Notification::$fail = false; ReceiptReturnQuery::$beforeWrite = null; ExtensionHelper::$simulateNativeListeners = false;
        [User::$row, Invoice::$rows, ExtensionHelper::$payments, Http::$handler, Audit::$rows, Notification::$rows, Credit::$rows, Gateway::$enabled, DB::$tables['ae_stripe_receipt_returns']] = $saved;
    }
})();
