<?php
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Gateway, Invoice, Notification, User};
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $tables = DB::$tables; $payments = ExtensionHelper::$payments; $handler = Http::$handler;
    $audits = Audit::$rows; $enabled = Gateway::$enabled; $bundled = Gateway::$bundled;
    Gateway::$enabled = true; Gateway::$bundled = false;
    DB::$tables['stripe_saved_payment_fees'] = [];
    $charge = (object) ['id' => 'ch_review', 'payment_intent' => 'pi_review', 'balance_transaction' => 'bt_review'];
    $intent = (object) ['id' => 'pi_review', 'metadata' => (object) []];
    $balance = ['fee' => 42, 'currency' => 'usd', 'status' => 'pending'];
    $boundInvoice = ['metadata' => ['stripe_saved_invoice_id' => 12], 'parent' => ['type' => 'subscription_details']];
    Http::$handler = function ($verb, $path) use (&$intent, &$balance, &$boundInvoice) {
        check(!DB::$locked, 'Fee requests run before settlement locks: ' . $path);
        return match (true) {
            str_starts_with($path, '/payment_intents/') => $intent,
            str_starts_with($path, '/charges/') => ['payment_intent' => 'pi_review', 'balance_transaction' => 'bt_review'],
            str_starts_with($path, '/invoices/') => $boundInvoice,
            default => $balance,
        };
    };
    $deliver = fn ($type = 'charge.succeeded') => $gateway->webhook(eventRequest($charge, type: $type));
    foreach (['charge.succeeded', 'charge.updated'] as $type) {
        check($deliver($type)['data'] === ['ignored' => true], 'Foreign charge is acknowledged: ' . $type);
        $intent->metadata = (object) ['stripe_saved_gateway' => '1', 'invoice_id' => 12];
        rejects(fn () => $deliver($type), 'HTTP 409');
        Audit::$rows[] = ['event' => 'stripe_saved_payment_review', 'new_values' => ['payment_intent_id' => 'pi_review']];
        check($deliver($type)['data'] === ['ignored' => true], 'Refused payment is acknowledged: ' . $type);
        Audit::$rows = $audits;
        $charge->invoice = 'in_review';
        Audit::$rows[] = ['event' => 'stripe_saved_subscription_review', 'new_values' => ['stripe_invoice_id' => 'in_review']];
        check($deliver($type)['data'] === ['ignored' => true], 'Subscription held for review is acknowledged: ' . $type);
        Audit::$rows = $audits; unset($charge->invoice); $intent->metadata = (object) [];
    }
    $intent->id = 'pi_wrong'; rejects($deliver, 'HTTP 409'); $intent->id = 'pi_review';
    Gateway::$bundled = true; $intent->metadata = (object) ['invoice_id' => 12];
    check($deliver()['data'] === ['ignored' => true], 'Bundled gateway retains unmarked charges awaiting settlement');
    $intent->metadata = (object) [];
    $charge->metadata = (object) ['stripe_saved_gateway' => '1', 'invoice_id' => 12];
    check($deliver()['data'] === ['ignored' => true], 'Charge metadata alone cannot settle an unbound PaymentIntent');
    $intent->metadata = (object) ['stripe_saved_gateway' => '1'];
    check($deliver()['data'] === ['ignored' => true], 'Gateway marker alone has no PaymentIntent settlement path');
    unset($charge->metadata); Gateway::$bundled = false; $intent->metadata = (object) [];
    $charge->invoice = 'in_bound';
    rejects($deliver, 'HTTP 409');
    Gateway::$bundled = true; $boundInvoice['metadata']['stripe_saved_gateway'] = '1';
    rejects($deliver, 'HTTP 409');
    foreach (['parent', 'binding', 'ownership'] as $missing) {
        $valid = $boundInvoice;
        if ($missing === 'parent') $boundInvoice['parent'] = null;
        elseif ($missing === 'binding') unset($boundInvoice['metadata']['stripe_saved_invoice_id']);
        else unset($boundInvoice['metadata']['stripe_saved_gateway']);
        check($deliver()['data'] === ['ignored' => true], 'Subscription settlement requires ' . $missing);
        $boundInvoice = $valid;
    }
    Gateway::$bundled = false;
    unset($charge->invoice);
    ExtensionHelper::$payments['12:pi_review'] = ['gateway' => 'StripeSaved', 'amount' => 20.0, 'status' => 'succeeded'];
    foreach ([['paid' => false, 'status' => 'succeeded'], ['paid' => true, 'status' => 'pending'], ['paid' => true, 'status' => 'failed']] as $state) {
        $charge->paid = $state['paid']; $charge->status = $state['status']; $calls = count(Http::$calls);
        check($deliver('charge.updated')['data'] === ['ignored' => true] && DB::$tables['stripe_saved_payment_fees'] === []
            && count(Http::$calls) === $calls, 'Unsettled charge cannot create a terminal fee record');
    }
    $charge->paid = true; $charge->status = 'succeeded';
    $deliver();
    check(DB::$tables['stripe_saved_payment_fees']['pi_review']['state'] === 'completed', 'Pending balance funds have a final fee');
    DB::$tables['stripe_saved_payment_fees'] = [];
    $charge->balance_transaction = null;
    $deliver();
    $pending = DB::$tables['stripe_saved_payment_fees']['pi_review'];
    $pending['state'] = 'pending'; $pending['attempts'] = 0;
    $seed = function ($count = 2) use ($pending) {
        DB::$tables['stripe_saved_payment_fees'] = [];
        for ($i = 0; $i < $count; ++$i) {
            $id = 'pi_batch' . $i;
            ExtensionHelper::$payments['12:' . $id] = ['gateway' => 'StripeSaved', 'amount' => 20.0, 'status' => 'succeeded'];
            DB::$tables['stripe_saved_payment_fees'][$id] = array_merge($pending, ['payment_intent_id' => $id]);
        }
    };
    $seed();
    DB::$beforeUserLock = fn () => throw new RuntimeException('Lock wait timeout');
    $reports = count($GLOBALS['reportedErrors'] ?? []);
    $gateway->retryPaymentFees();
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['attempts'] === 1
        && str_contains(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['last_error'], 'RuntimeException')
        && DB::$tables['stripe_saved_payment_fees']['pi_batch1']['state'] === 'completed'
        && count($GLOBALS['reportedErrors']) === $reports + 1, 'Failed row records an attempt and does not abort the batch');
    $seed();
    DB::$beforeUserLock = function () {
        FeeQuery::$beforeRead = function () { DB::$tables['stripe_saved_payment_fees']['pi_batch0']['state'] = 'completed'; };
        throw new RuntimeException('Lock wait timeout');
    };
    $gateway->retryPaymentFees();
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['attempts'] === 0, 'Failure accounting respects a concurrently completed record');
    $seed();
    $user = User::$row; User::$row = null;
    $gateway->retryPaymentFees(); User::$row = $user;
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['attempts'] === 1
        && DB::$tables['stripe_saved_payment_fees']['pi_batch1']['attempts'] === 1, 'Deleted user still advances every row attempt');
    foreach (['attempts', 'age'] as $limit) {
        $seed();
        DB::$tables['stripe_saved_payment_fees']['pi_batch0'][$limit === 'attempts' ? 'attempts' : 'created_at'] =
            $limit === 'attempts' ? 23 : time() - 73 * 3600;
        User::$row = null; $notices = count(Notification::$rows);
        $gateway->retryPaymentFees(); $gateway->retryPaymentFees(); User::$row = $user;
        check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['state'] === 'review'
            && count(Notification::$rows) === $notices + 1, 'Repeated missing-user failures reach review once: ' . $limit);
    }
    $seed(); DB::$tables['stripe_saved_payment_fees']['pi_batch0']['attempts'] = 23;
    unset(DB::$tables['stripe_saved_payment_fees']['pi_batch1']);
    User::$row = null; $notices = count(Notification::$rows);
    FeeQuery::$beforeRead = function () {
        FeeQuery::$beforeRead = function () { DB::$tables['stripe_saved_payment_fees']['pi_batch0']['state'] = 'completed'; };
    };
    $gateway->retryPaymentFees(); User::$row = $user;
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['state'] === 'completed'
        && count(Notification::$rows) === $notices, 'Failure escalation respects a completed record');
    $seed(); DB::$tables['stripe_saved_payment_fees']['pi_batch0']['attempts'] = 24;
    Notification::$fail = true;
    $gateway->retryPaymentFees(); Notification::$fail = false;
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['attempts'] === 25
        && DB::$tables['stripe_saved_payment_fees']['pi_batch1']['state'] === 'completed', 'Failed notice does not roll back later fees');
    $seed(101);
    foreach (DB::$tables['stripe_saved_payment_fees'] as &$row) $row['attempts'] = 10;
    unset($row);
    DB::$tables['stripe_saved_payment_fees']['pi_batch100']['attempts'] = 0;
    $gateway->retryPaymentFees();
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch100']['state'] === 'completed', 'Less attempted rows cannot be starved by the first hundred');
    $seed();
    DB::$beforeUserLock = function () { DB::$tables['stripe_saved_payment_fees']['pi_batch0']['balance_transaction_id'] = 'bt_new'; };
    $writes = ExtensionHelper::$feeWrites;
    $gateway->retryPaymentFees();
    check(DB::$tables['stripe_saved_payment_fees']['pi_batch0']['balance_transaction_id'] === 'bt_new'
        && DB::$tables['stripe_saved_payment_fees']['pi_batch0']['state'] === 'pending'
        && ExtensionHelper::$feeWrites === $writes + 1, 'Changed identifiers cannot accept a stale remote snapshot');
    $other = clone Invoice::$row; $other->id = 13; Invoice::$rows[13] = $other;
    DB::$tables['stripe_saved_payment_fees'] = [];
    DB::$beforeUserLock = function () {
        ExtensionHelper::$payments['13:pi_review'] = ExtensionHelper::$payments['12:pi_review'];
        unset(ExtensionHelper::$payments['12:pi_review']);
    };
    rejects($deliver, 'HTTP 409');
    unset(Invoice::$rows[13]);
    Http::$handler = $handler; Gateway::$enabled = $enabled; Gateway::$bundled = $bundled;
    DB::$tables = $tables; ExtensionHelper::$payments = $payments; Audit::$rows = $audits;
})();
