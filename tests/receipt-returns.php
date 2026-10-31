<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Credit, Gateway, Invoice, Notification, Properties, User};
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $saved = [User::$row, Invoice::$rows, ExtensionHelper::$payments, Http::$handler, Audit::$rows, Notification::$rows, Credit::$rows, Gateway::$enabled];
    $setup = function ($amount = 2000, $total = 10, $currency = 'usd') use ($gateway) {
        User::$row = new User(new Properties('cus_owner'));
        $invoice = new Invoice(User::$row); $invoice->id = 810; $invoice->total = $total; $invoice->currency_code = $currency;
        Invoice::$rows[810] = $invoice; ExtensionHelper::$payments = []; Audit::$rows = []; Notification::$rows = []; Credit::$rows = []; Gateway::$enabled = true;
        $stripe = new class($amount, $currency) {
            public $refunds = [], $posts = [], $lose = false, $drop = false, $failWrite = false, $status = 'succeeded';
            public $intent;
            public function __construct($amount, $currency) {
                $this->intent = (object) ['id' => 'pi_return', 'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
                    'currency' => $currency, 'customer' => 'cus_owner', 'latest_charge' => (object) ['id' => 'ch_return', 'disputed' => false],
                    'metadata' => (object) ['invoice_id' => 810, 'ae_stripe_gateway' => '1']];
            }
            public function request($verb, $path, $data) {
                if ($path === '/payment_intents/pi_return') return $this->intent;
                if ($path === '/refunds' && $verb === 'get') return (object) ['data' => array_values($this->refunds), 'has_more' => false];
                if ($path !== '/refunds' || $verb !== 'post') throw new RuntimeException('Unexpected return fixture request: ' . $path);
                check(DB::$locked && DB::$userLocked, 'Return sends serialize with invoice payments');
                $key = end(Http::$calls)[3]['Idempotency-Key'] ?? null;
                $stored = json_decode(User::$row->props->values['ae_stripe_receipt_pi_return'], true);
                check(!empty($stored['allocations'][array_key_last($stored['allocations'])]['sent_at']), 'First-send marker survives outside the external-write transaction');
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
        $ledger = json_decode(User::$row->props->values['ae_stripe_receipt_pi_return'], true);
        $ledger['allocations']['excess']['sent_at'] = time() - 3 * 86400;
        User::$row->props->values['ae_stripe_receipt_pi_return'] = json_encode($ledger);
        $gateway->retryReceiptReturns(); $deliver($s);
        check($status($i)['state'] === 'refunded' && count($s->posts) === 1 && count($s->refunds) === 1, 'Refund metadata recovers a lost response even after key expiry without another send');

        [$i, $s] = $setup(); $s->drop = true; $deliver($s);
        $key = $status($i)['key']; $s->drop = false; $gateway->retryReceiptReturns();
        check($status($i)['state'] === 'refunded' && count($s->posts) === 2 && $s->posts[0][0] === $key && $s->posts[1][0] === $key,
            'An unsent refund retries the exact payment, amount and idempotency key');

        [$i, $s] = $setup(); $s->drop = true; $deliver($s);
        $ledger = json_decode(User::$row->props->values['ae_stripe_receipt_pi_return'], true);
        $ledger['allocations']['excess']['sent_at'] = time() - 86400;
        User::$row->props->values['ae_stripe_receipt_pi_return'] = json_encode($ledger);
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
            Properties::$beforeWrite = function ($key, $data) {
                if ($key['key'] !== 'ae_stripe_receipt_pi_return') return;
                $a = json_decode($data['value'], true)['allocations']['excess'] ?? [];
                if (!in_array($a['state'] ?? '', ['refunded', 'credited'], true)) return;
                Properties::$beforeWrite = null;
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
            Properties::$beforeWrite = function ($key, $data) {
                if ($key['key'] !== 'ae_stripe_receipt_pi_return') return;
                $a = json_decode($data['value'], true)['allocations']['excess'] ?? [];
                if (!in_array($a['state'] ?? '', ['refunded', 'credited'], true)) return;
                Properties::$beforeWrite = null;
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

        foreach ([['jpy', 20, 10, 10], ['ugx', 2000, 10, 1000]] as [$currency, $received, $total, $excess]) {
            [$i, $s] = $setup($received, $total, $currency); $deliver($s);
            check($status($i)['state'] === 'refunded' && $s->posts[0][1]['amount'] === $excess, 'Return amount uses Stripe minor units for ' . $currency);
        }

        [$i, $s] = $setup(2000, 20); $deliver($s);
        unset(User::$row->props->values['ae_stripe_receipt_pi_return']); // A pre-upgrade native receipt.
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

    } finally {
        Notification::$fail = false; Properties::$beforeWrite = null;
        [User::$row, Invoice::$rows, ExtensionHelper::$payments, Http::$handler, Audit::$rows, Notification::$rows, Credit::$rows, Gateway::$enabled] = $saved;
    }
})();
