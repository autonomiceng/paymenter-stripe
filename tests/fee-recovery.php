<?php
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Gateway, Invoice, Notification, User};
use Illuminate\Support\Facades\{DB, Http, Schedule};

(function () use ($gateway) {
    $handler = Http::$handler;
    $enabled = Gateway::$enabled;
    $tables = DB::$tables;
    unset(DB::$tables['stripe_saved_payment_fees']);
    (new ReflectionProperty($gateway, 'paymentFeeSchemaReady'))->setValue(null, false);
    \Illuminate\Database\Migrations\Migrator::$ran = [];
    \Illuminate\Database\Schema\Blueprint::$primary = [];
    $gateway->boot();
    Gateway::$enabled = true;
    $gateway->retryPaymentFees();
    check(\Illuminate\Database\Schema\Blueprint::$primary === ['payment_intent_id'], 'Migration makes PaymentIntent unique');
    DB::$tables['stripe_saved_payment_fees']['sentinel'] = ['state' => 'completed'];
    $gateway->installed();
    check(isset(DB::$tables['stripe_saved_payment_fees']['sentinel']), 'Upgrade and install reruns preserve existing records');
    DB::$tables['stripe_saved_payment_fees'] = [];
    Gateway::$enabled = true;
    $invoice = Invoice::$row;
    $invoice->currency_code = 'usd';
    $balance = ['fee' => 100, 'currency' => 'jpy', 'exchange_rate' => null, 'status' => 'available'];
    $balanceId = 'bt_recovery';
    Http::$handler = function ($verb, $path) use (&$balance, &$balanceId) {
        if ($path === '/charges/ch_recovery') return ['payment_intent' => 'pi_recovery', 'balance_transaction' => $balanceId];
        if ($path === '/balance_transactions/bt_recovery') return $balance;
        throw new RuntimeException('Unexpected recovery request: ' . $path);
    };
    $charge = (object) ['id' => 'ch_recovery', 'payment_intent' => 'pi_recovery', 'balance_transaction' => 'bt_recovery'];
    ExtensionHelper::$payments['12:pi_recovery'] = ['gateway' => 'StripeSaved', 'amount' => 20.0, 'status' => 'succeeded'];
    $deliver = fn () => $gateway->webhook(eventRequest($charge, type: 'charge.succeeded'));
    $row = fn () => DB::$tables['stripe_saved_payment_fees']['pi_recovery'];
    $writes = ExtensionHelper::$feeWrites;
    check($deliver()['status'] === 200 && $row()['state'] === 'pending' && $row()['attempts'] === 1
        && $row()['charge_id'] === 'ch_recovery' && $row()['balance_transaction_id'] === 'bt_recovery'
        && ExtensionHelper::$feeWrites === $writes, 'Missing rate persists fee identifiers without a money write');
    $balance['exchange_rate'] = 150;
    $locks = count(DB::$modelLocks);
    end(Schedule::$callbacks)();
    check($row()['state'] === 'completed' && ExtensionHelper::$fees['pi_recovery'] === 0.67
        && ExtensionHelper::$feeWrites === $writes + 1, 'Registered task recovers the converted fee once');
    check(array_slice(DB::$modelLocks, $locks, 3) === [User::class, Invoice::class, App\Models\InvoiceTransaction::class],
        'Fee worker uses the settlement lock order');
    $calls = count(Http::$calls);
    $deliver(); $gateway->retryPaymentFees();
    check(count(Http::$calls) === $calls, 'Completed fee replays do not repeat remote lookups');
    check(ExtensionHelper::$feeWrites === $writes + 1, 'Completed fee blocks webhook and task replays');

    $reset = function () { DB::$tables['stripe_saved_payment_fees'] = []; };
    $reset(); $charge->balance_transaction = $balanceId = null;
    $writes = ExtensionHelper::$feeWrites;
    check($deliver()['status'] === 200 && $row()['state'] === 'pending' && ExtensionHelper::$feeWrites === $writes,
        'Missing balance transaction remains pending without a zero fee');
    $oldLookup = Http::$handler;
    Http::$handler = fn () => ['payment_intent' => 'pi_wrong', 'balance_transaction' => 'bt_recovery'];
    $attempts = $row()['attempts'];
    $gateway->retryPaymentFees();
    check($row()['attempts'] === $attempts + 1, 'Failed lookup still advances the attempt');
    check($row()['state'] === 'pending', 'A different charge intent cannot supply a fee');
    Http::$handler = $oldLookup;
    $balanceId = 'bt_recovery';
    $gateway->retryPaymentFees();
    check($row()['state'] === 'completed' && ExtensionHelper::$feeWrites === $writes + 1, 'Task retrieves a later charge balance transaction');
    $reset(); $charge->balance_transaction = 'bt_recovery'; $balance['status'] = 'pending';
    $deliver();
    check($row()['state'] === 'completed', 'Pending funds already have a final fee');
    $reset(); $balance['exchange_rate'] = null; $deliver(); $balance['exchange_rate'] = 150;
    $balance['status'] = 'available';
    $writes = ExtensionHelper::$feeWrites;
    // Start the task between the webhook snapshot and lock acquisition.
    DB::$beforeUserLock = fn () => $gateway->retryPaymentFees();
    $deliver();
    check($row()['state'] === 'completed' && ExtensionHelper::$feeWrites === $writes + 1, 'Task wins concurrent webhook delivery without a duplicate fee');
    $reset(); $balance['exchange_rate'] = null; $deliver(); $balance['exchange_rate'] = 150;
    $writes = ExtensionHelper::$feeWrites;
    DB::$beforeUserLock = $deliver;
    $gateway->retryPaymentFees();
    check(ExtensionHelper::$feeWrites === $writes + 1, 'Webhook wins concurrent task delivery without a duplicate fee');
    foreach (['attempts', 'age'] as $limit) {
        $reset(); $balance['exchange_rate'] = null; $deliver();
        DB::$tables['stripe_saved_payment_fees']['pi_recovery'][$limit === 'attempts' ? 'attempts' : 'created_at'] =
            $limit === 'attempts' ? 23 : time() - 72 * 3600;
        $notices = count(Notification::$rows); $audits = count(Audit::$rows); $calls = count(Http::$calls);
        Notification::$fail = true;
        $attempts = $row()['attempts'];
        $gateway->retryPaymentFees();
        check($row()['attempts'] === $attempts + 1, 'Failed notice advances the attempt outside rollback');
        check($row()['state'] === 'pending' && count(Audit::$rows) === $audits, 'Notice failure rolls review transition back');
        Notification::$fail = false;
        $gateway->retryPaymentFees(); $deliver(); $gateway->retryPaymentFees();
        check($row()['state'] === 'review' && count(Notification::$rows) === $notices + 1 && count(Audit::$rows) === $audits + 1,
            'Give up retains one audit and one staff inbox notice: ' . $limit);
        check(str_contains(end(Notification::$rows)['body'], 'invoice 12, PaymentIntent pi_recovery'), 'Staff notice identifies invoice and PaymentIntent');
        if ($limit === 'age') check(count(Http::$calls) === $calls, '72 hour deadline prevents another remote attempt');
    }
    $reset(); $balance = ['fee' => 42, 'currency' => 'usd'];
    $deliver();
    check(ExtensionHelper::$fees['pi_recovery'] === 0.42, 'Same-currency fee needs no rate');
    foreach ([['fee' => 'invalid', 'currency' => 'usd'], ['fee' => -1, 'currency' => 'usd'],
        ['fee' => 10], ['currency' => 'usd'], ['fee' => 10, 'currency' => 'invalid', 'exchange_rate' => 150],
        ['fee' => 10, 'currency' => []],
        ['fee' => 10, 'currency' => 123]] as $invalidBalance) {
        $reset(); $balance = $invalidBalance; $writes = ExtensionHelper::$feeWrites;
        $deliver();
        check($row()['state'] === 'pending' && ExtensionHelper::$feeWrites === $writes, 'Malformed fee never writes money');
    }
    $reset(); $oldHandler = Http::$handler;
    Http::$handler = fn () => throw new \Illuminate\Http\Client\RequestException(404);
    check($deliver()['status'] === 200 && $row()['last_error'] === 'Balance transaction lookup failed', 'Missing remote balance persists a retry');
    Http::$handler = $oldHandler; $balance = ['fee' => 42, 'currency' => 'usd'];
    $gateway->retryPaymentFees();
    check($row()['state'] === 'completed', 'Remote balance recovers after lookup failure');
    $reset();
    $balance = ['fee' => 42, 'currency' => 'jpy']; $deliver();
    Gateway::$enabled = false; $calls = count(Http::$calls); $gateway->retryPaymentFees();
    check(count(Http::$calls) === $calls && $row()['attempts'] === 1, 'Disabled task performs no recovery');
    Gateway::$enabled = true; $reset();
    $gateway->webhook(eventRequest((object) ['id' => 'ch_no_intent'], type: 'charge.updated'));
    check(DB::$tables['stripe_saved_payment_fees'] === [], 'Non-PaymentIntent charge has no fee record');
    DB::$beforeUserLock = function () { ExtensionHelper::$payments['12:pi_recovery']['gateway'] = 'Stripe'; };
    rejects($deliver, 'HTTP 409');
    check(DB::$tables['stripe_saved_payment_fees'] === [], 'Ownership is reread under the settlement lock');
    check($deliver()['status'] === 200 && DB::$tables['stripe_saved_payment_fees'] === [], 'Bundled fee remains owned by bundled gateway');
    ExtensionHelper::$payments['12:pi_recovery']['gateway'] = 'StripeSaved';
    $balance['exchange_rate'] = null; $balance['currency'] = 'jpy'; $deliver();
    DB::$tables['stripe_saved_payment_fees']['pi_recovery']['attempts'] = 24; $calls = count(Http::$calls);
    $gateway->retryPaymentFees();
    check($row()['state'] === 'review' && count(Http::$calls) === $calls, 'Exhausted attempt count prevents another lookup');
    $reset(); $balance['exchange_rate'] = null; $deliver();
    $pending = $row();
    DB::$tables['stripe_saved_payment_fees'] = [
        'pi_deleted' => array_merge($pending, ['payment_intent_id' => 'pi_deleted', 'created_at' => time() - 1]),
        'pi_recovery' => $pending,
    ];
    $balance = ['fee' => 42, 'currency' => 'usd']; $notices = count(Notification::$rows);
    $gateway->retryPaymentFees(); $gateway->retryPaymentFees();
    check(DB::$tables['stripe_saved_payment_fees']['pi_deleted']['state'] === 'review'
        && count(Notification::$rows) === $notices + 1 && $row()['state'] === 'completed',
        'Deleted transaction notifies once without blocking other fee records');
    DB::$tables['stripe_saved_payment_fees']['pi_deleted']['state'] = 'pending';
    FeeQuery::$beforeRead = function () { DB::$tables['stripe_saved_payment_fees']['pi_deleted']['state'] = 'completed'; };
    $notices = count(Notification::$rows); $gateway->retryPaymentFees();
    check(count(Notification::$rows) === $notices, 'Stale orphan snapshot respects the completed record');
    DB::$tables['stripe_saved_payment_fees']['pi_deleted']['state'] = 'pending';
    FeeQuery::$beforeRead = function () { unset(DB::$tables['stripe_saved_payment_fees']['pi_deleted']); };
    $gateway->retryPaymentFees();
    check(count(Notification::$rows) === $notices, 'Stale orphan snapshot respects a removed record');
    $charge->payment_intent = 'pi_before_settlement';
    $charge->metadata = (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1'];
    Http::$handler = fn () => (object) ['id' => 'pi_before_settlement', 'metadata' => $charge->metadata];
    rejects($deliver, 'HTTP 409');
    Http::$handler = $handler; Gateway::$enabled = $enabled; DB::$tables = $tables;
})();
