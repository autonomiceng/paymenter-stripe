<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
// Native suite: runs on tests/native-app.php (real Paymenter models, listeners and SQLite).
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;

// Each native payment write, as ExtensionHelper::addPayment saves one transaction per call.
$applications = 0;
InvoiceTransaction::saved(function () use (&$applications) { ++$applications; });
// Invoices that native listeners mark paid.
$activations = 0;
Invoice::updated(function ($invoice) use (&$activations) { if ($invoice->wasChanged('status') && $invoice->status === 'paid') ++$activations; });
$reset = function () use ($owner) {
    foreach (['ae_stripe_outbox', 'ae_stripe_settlements', 'invoice_transactions', 'ae_stripe_receipt_returns', 'audits', 'notifications', 'credits', 'invoice_items', 'invoices', 'services'] as $table) DB::table($table)->delete();
    $owner->properties()->where('key', '!=', 'stripe_id')->delete();
};
$lastAudit = fn () => App\Models\Audit::orderByDesc('id')->first();

// Upgrade creates the outbox before converting legacy unsent audits and property markers.
(function () use ($gateway, $reset, $owner) {
    $reset(); $invoice = nativeInvoice(9901);
    if (!is_dir(base_path('extensions/Gateways/AeStripe'))) symlink(realpath(__DIR__ . '/../extension/AeStripe'), base_path('extensions/Gateways/AeStripe'));
    DB::transaction(function () use ($invoice) {
        foreach ([false, true] as $sent) App\Models\Audit::create(['event' => 'ae_stripe_payment_refused',
            'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id, 'old_values' => [],
            'new_values' => ['notice_sent' => $sent, 'notice_source' => $sent ? 'legacy-sent' : 'legacy-unsent',
                'notices' => [['user_id' => 7, 'title' => 'Payment declined', 'body' => 'Fixture', 'url' => '/invoices/9901']]]]);
        App\Models\Audit::create(['event' => 'ae_stripe_payment_review', 'auditable_type' => $invoice->getMorphClass(),
            'auditable_id' => $invoice->id, 'old_values' => [], 'new_values' => ['payment_intent_id' => 'pi_legacyalert']]);
    });
    $owner->properties()->create(['key' => 'ae_stripe_payment_alert_9901_pi_legacyalert',
        'value' => json_encode(['sent' => false, 'reason' => 'Fixture review'])]);
    Illuminate\Support\Facades\Schema::drop('ae_stripe_outbox');
    $repository = app(Illuminate\Database\Migrations\Migrator::class)->getRepository();
    foreach (glob(__DIR__ . '/../extension/AeStripe/database/migrations/*.php') as $file) {
        if (!str_contains($file, 'create_ae_stripe_outbox')) $repository->log(basename($file, '.php'), 1);
    }
    $GLOBALS['stripeHandler'] = fn () => throw new RuntimeException('Upgrade must not call Stripe');
    beforeWrite('notifications', fn () => throw new RuntimeException('Inbox unavailable during upgrade'));
    try { $gateway->upgraded('legacy'); $gateway->upgraded('legacy'); }
    finally { beforeWrite('notifications', null); }
    check(DB::table('ae_stripe_outbox')->count() === 2 && !DB::table('ae_stripe_outbox')->where('source', 'legacy-sent')->exists(),
        'Repeated upgrade backfills each legacy unsent notice once and excludes sent notices');
    DB::table('ae_stripe_outbox')->update(['next_at' => 0]);
    invoke($gateway, 'retryPaymentNotices'); invoke($gateway, 'retryPaymentNotices');
    check(DB::table('ae_stripe_outbox')->where('state', 'sent')->count() === 2 && count(notices()) === 2
        && json_decode($owner->properties()->where('key', 'ae_stripe_payment_alert_9901_pi_legacyalert')->value('value'), true)['sent'],
        'Legacy property and audit notices recover after upgrade without duplicates');
    $reset();
})();

// Subscription PaymentIntents have no checkout binding; the subscription invoice owns it.
(function () use ($gateway, $reset, &$applications, &$activations) {
    $reset();
    $invoice = nativeInvoice(113, 20, 'USD');
    $invoice->forceFill(['status' => 'cancelled'])->save();
    nativeService('sub_closed', [$invoice]);
    $intent = (object) ['id' => 'pi_closed_subscription', 'status' => 'succeeded', 'amount_received' => 1250,
        'currency' => 'usd', 'customer' => 'cus_owner', 'metadata' => (object) [],
        'latest_charge' => (object) ['id' => 'ch_closed', 'disputed' => false]];
    $remote = (object) ['id' => 'in_closed', 'status' => 'paid', 'customer' => 'cus_owner', 'currency' => 'usd', 'amount_paid' => 1250,
        'metadata' => (object) ['ae_stripe_invoice_id' => 113],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) [
            'subscription' => 'sub_closed', 'metadata' => (object) ['ae_stripe_gateway' => '1']]]];
    $available = false; $member = $intent->id; $refunds = []; $posts = 0;
    $GLOBALS['stripeHandler'] = function ($verb, $path, $data, $request) use ($intent, $remote, &$available, &$member, &$refunds, &$posts) {
        if ($path === '/payment_intents/' . $intent->id) return $intent;
        if ($path === '/invoice_payments') return ['has_more' => false, 'data' => [
            ['status' => 'paid', 'amount_paid' => 1250, 'payment' => ['type' => 'payment_intent', 'payment_intent' => $member]]]];
        if ($path === '/invoices/in_closed') {
            if (!$available) throw new ConnectionException('Subscription binding read interrupted');
            return $remote;
        }
        if ($path === '/refunds' && $verb === 'get') return ['has_more' => false, 'data' => array_values($refunds)];
        if ($path === '/refunds' && $verb === 'post') {
            ++$posts;
            return $refunds[idempotencyKey($request)] ??= (object) ['id' => 're_closed', 'payment_intent' => $intent->id, 'amount' => $data['amount'],
                'currency' => 'usd', 'metadata' => (object) $data['metadata'], 'status' => 'succeeded'];
        }
        throw new RuntimeException('Unexpected closed subscription request: ' . $path);
    };
    $deliver = fn () => $gateway->webhook(eventRequest($remote, type: 'invoice.payment_succeeded'));
    $returnStatus = fn () => $gateway->receiptReturnStatus($invoice, $intent->id, 'excess');
    $activated = $activations;
    $deliver(); $deliver();
    check($returnStatus() !== null && $returnStatus()['amount_minor'] === 1250 && !payments() && !$posts,
        'Bound closed subscription reserves its whole receipt before interrupted recovery, without native funding');
    $available = true; $remote->metadata->ae_stripe_invoice_id = 999;
    $gateway->retryReceiptReturns();
    check($returnStatus()['state'] === 'review' && !$posts && !credits(),
        'Closed subscription recovery refuses a changed remote invoice binding');
    $remote->metadata->ae_stripe_invoice_id = 113; $member = 'pi_unrelated';
    $gateway->retryReceiptReturns();
    check($returnStatus()['state'] === 'review' && !$posts, 'Closed subscription recovery verifies exact current invoice payment membership');
    $member = $intent->id; $gateway->retryReceiptReturns();
    $deliver(); $gateway->webhook(eventRequest($intent)); $gateway->retryReceiptReturns();
    check($returnStatus()['state'] === 'refunded' && $posts === 1 && count($refunds) === 1
        && count(payments()) === 2 && array_sum(array_column(payments(), 'amount')) === 0.0,
        'Duplicate subscription and metadata-free intent deliveries plus sweep return the whole receipt once');
    check($invoice->fresh()->status === 'cancelled' && $activations === $activated
        && !credits() && !isset($intent->metadata->invoice_id),
        'Closed subscription return preserves closure, no activation or Domains funding, and needs no checkout metadata');
})();

(function () use ($gateway, $reset, $lastAudit, &$applications) {
    $reset();
    nativeInvoice(12, 20, 'USD', 'INV-12');
    $invoice = nativeInvoice(112, 20, 'USD');
    $service = nativeService('sub_settlement', [$invoice]);
    $setStatus = fn ($value) => $invoice->forceFill(['status' => $value])->save();
    $intent = (object) ['id' => 'pi_received', 'status' => 'succeeded', 'amount' => 2000,
        'amount_received' => 1250, 'currency' => 'usd', 'customer' => 'cus_owner',
        'metadata' => (object) ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1']];
    $stripeInvoice = (object) ['id' => 'in_settlement', 'currency' => 'usd', 'amount_paid' => 1250,
        'metadata' => (object) ['ae_stripe_invoice_id' => $invoice->id],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) [
            'subscription' => $service->subscription_id, 'metadata' => (object) ['ae_stripe_gateway' => '1']]]];
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($intent) {
        if ($verb === 'get' && $path === '/invoices/in_settlement') return ['metadata' => ['ae_stripe_invoice_id' => 112]];
        if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [
            ['status' => 'paid', 'amount_paid' => 1250,
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $intent->id]]]];
        if ($verb === 'get' && $path === '/payment_intents/' . $intent->id) {
            check(DB::transactionLevel() === 0 || holdsLock('ae_stripe_locks'), 'Return verification also serializes on the receipt owner');
            return $intent;
        }
        throw new RuntimeException('Unexpected settlement request: ' . $path);
    };
    $deliver = fn () => $gateway->webhook(eventRequest($stripeInvoice, type: 'invoice.payment_succeeded'));

    $gateway->webhook(eventRequest($intent));
    check(payments()['112:pi_received']['amount'] === 12.5,
        'Successful payment records received amount instead of intended amount');
    $intent->id = 'pi_processing_received';
    $intent->amount_received = 0;
    $gateway->webhook(eventRequest($intent, type: 'payment_intent.processing'));
    check(payments()['112:pi_processing_received']['amount'] === 20.0,
        'Processing payment keeps its intended amount before settlement');
    $gateway->webhook(eventRequest($intent, type: 'payment_intent.payment_failed'));
    check(payments()['112:pi_processing_received']['amount'] === 20.0,
        'Failed payment keeps its intended amount before settlement');
    $intent->amount_received = 1250;
    $gateway->webhook(eventRequest($intent));
    check(payments()['112:pi_processing_received']['amount'] === 12.5,
        'Processing payment updates to the actual received amount on success');
    $intent->id = 'pi_zero_received';
    $intent->amount_received = 0;
    $gateway->webhook(eventRequest($intent));
    check(payments()['112:pi_zero_received']['amount'] === 0.0,
        'A zero received amount never falls back to the intended amount');
    $intent->amount_received = 1250;

    $setStatus('paid');
    $intent->id = 'pi_subscription_paid';
    $intent->metadata->invoice_id = 12;
    $before = $applications;
    $warnings = count(warnings());
    $locks = count(lockLog());
    $calls = count($GLOBALS['stripeCalls']);
    $deliver();
    $deliver();
    check(count(array_filter(array_slice($GLOBALS['stripeCalls'], $calls), fn ($call) => $call[0] === 'get'
        && $call[1] === '/payment_intents/pi_subscription_paid')) === 3,
        'Subscription deliveries fetch the settled intent, and the first delivery also attempts return verification');
    check($applications === $before + 1 && $invoice->fresh()->status === 'paid'
        && payments()['112:pi_subscription_paid']['amount'] === 12.5,
        'Paid bound subscription invoice retains one successful charge with received units');
    $intent->metadata->invoice_id = $invoice->id;
    $gateway->webhook(eventRequest($intent));
    check($applications === $before + 1,
        'Subscription then PaymentIntent success cannot duplicate the late charge');
    $logged = warnings();
    check(count($logged) === $warnings + 1 && end($logged) === [
        'AeStripe payment received for paid invoice', ['invoice_id' => $invoice->id]],
        'Late subscription payment logs only the invoice ID');
    check(array_slice(lockLog(), $locks, 3) === ['ae_stripe_locks', 'services', 'invoices'],
        'Subscription success keeps user-first settlement lock order');
    check(count(array_filter(array_slice($GLOBALS['stripeCalls'], $calls), fn ($call) => $call[0] !== 'get')) === 0,
        'Late subscription success makes no remote changes');
    check(!invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service),
        'Invoice creation still refuses an already paid binding');

    foreach (['currency mismatch', 'invoice currency mismatch', 'intent currency mismatch', 'customer mismatch', 'closed currency mismatch', 'closed customer mismatch', 'paid currency mismatch'] as $reason) {
        $setStatus(str_starts_with($reason, 'closed ') ? 'cancelled' : ($reason === 'paid currency mismatch' ? 'paid' : 'pending'));
        $intent->id = 'pi_subscription_review_' . str_replace(' ', '_', $reason);
        $intent->currency = $stripeInvoice->currency = str_contains($reason, 'currency') ? 'jpy' : 'usd';
        if ($reason === 'intent currency mismatch') $stripeInvoice->currency = 'usd';
        if ($reason === 'invoice currency mismatch') $intent->currency = 'usd';
        $intent->customer = str_contains($reason, 'customer') ? 'cus_foreign' : 'cus_owner';
        $records = count(audits());
        $before = $applications;
        $locks = count(lockLog());
        beforeLock('ae_stripe_outbox', fn () => check(!holdsLock('ae_stripe_locks') && !holdsLock('invoices'), 'Staff delivery locks its marker after the owner commits'));
        $deliver();
        $deliver();
        check($applications === $before && count(audits()) === $records + 1,
            'Refused subscription success leaves one durable staff record: ' . $reason);
        $record = $lastAudit();
        check($record->auditable_type === Invoice::class && $record->auditable_id === 112
            && $record->new_values['payment_intent_id'] === $intent->id
            && $record->new_values['amount_minor'] === 1250
            && $record->new_values['currency'] === $intent->currency
            && $record->new_values['reason'] === (str_contains($reason, 'currency') ? 'currency mismatch' : 'customer mismatch'),
            'Subscription staff record preserves binding, received units and refusal reason: ' . $reason);
        check(array_slice(lockLog(), $locks, 3) === ['ae_stripe_locks', 'services', 'invoices']
            && count(array_filter(array_slice(lockLog(), $locks), fn ($table) => $table === 'ae_stripe_outbox')) === 1,
            'Subscription refusal locks the owner and invoice before its separate notice delivery: ' . $reason . ': ' . json_encode(array_slice(lockLog(), $locks)));
        check(!str_contains(json_encode($record->toArray()), 'cus_foreign') && !str_contains(json_encode($record->toArray()), $invoice->user->email),
            'Subscription review excludes customer details');
    }
    // Both delivery paths share the same PaymentIntent review key.
    $records = count(audits());
    $gateway->webhook(eventRequest($intent));
    check(count(audits()) === $records, 'Subscription then PaymentIntent refusal reuses the staff record');
    $intent->id = 'pi_review_intent_first';
    $gateway->webhook(eventRequest($intent));
    $records = count(audits());
    $deliver();
    check(count(audits()) === $records, 'PaymentIntent then subscription refusal reuses the staff record');

    $intent->id = 'pi_subscription_review_retry';
    beforeWrite('audits', function () { beforeWrite('audits', null); throw new RuntimeException('Review record unavailable'); });
    rejects($deliver, 'Review record unavailable');
    $records = count(audits());
    $deliver();
    $deliver();
    check(count(audits()) === $records + 1, 'Subscription audit failure stays retryable and recovers once');
    $setStatus('pending');
    $intent->currency = $stripeInvoice->currency = 'usd';
    $intent->id = 'pi_subscription_next_cycle';
    $before = $applications;
    $deliver();
    $deliver();
    check($applications === $before + 1
        && payments()['112:pi_subscription_next_cycle']['amount'] === 12.5,
        'A refused subscription charge does not block the next settlement');
    $intent->id = 'pi_subscription_intent_first';
    $gateway->webhook(eventRequest($intent));
    $before = $applications;
    $deliver();
    check($applications === $before, 'PaymentIntent then subscription success remains deduplicated');

    $intent->id = 'pi_subscription_not_settled';
    $handler = $GLOBALS['stripeHandler'];
    $GLOBALS['stripeHandler'] = function (...$request) use ($handler) {
        $result = $handler(...$request);
        if (str_starts_with($request[1], '/payment_intents/')) { $result = clone $result; $result->id = 'pi_different'; }
        return $result;
    };
    $payments = payments();
    $records = audits();
    $locks = count(lockLog());
    rejects($deliver, 'HTTP 409');
    check(payments() === $payments && audits() === $records
        && count(lockLog()) === $locks,
        'A mismatched subscription intent is refused before row locks without payment or audit rows');
    $GLOBALS['stripeHandler'] = $handler;
    foreach (['requires_payment_method', 'requires_confirmation', 'processing', 'requires_action', 'requires_capture', 'canceled'] as $intentStatus) {
        $intent->status = $intentStatus;
        $before = $applications;
        $records = count(audits());
        $locks = count(lockLog());
        rejects($deliver, 'HTTP 409');
        check(payments() === $payments && count(lockLog()) === $locks
            && $applications === $before && count(audits()) === $records,
            'An unsettled subscription intent cannot be recorded as success: ' . $intentStatus);
    }
    $intent->status = 'succeeded';
    // The client raises its own exception for a refused connection or an error status; any other failure is rethrown as is.
    $decode = new RuntimeException('decode failure');
    foreach ([
        'connection' => [new ConnectionException('connection'), fn ($e) => $e instanceof ConnectionException && $e->getMessage() === 'connection'],
        'timeout' => [new ConnectionException('timeout'), fn ($e) => $e instanceof ConnectionException && $e->getMessage() === 'timeout'],
        'status 503' => [new StripeFixtureError(503), fn ($e) => $e instanceof RequestException && $e->response->status() === 503],
        'decode' => [$decode, fn ($e) => $e === $decode],
    ] as $label => [$failure, $expected]) {
        $handler = $GLOBALS['stripeHandler'];
        $GLOBALS['stripeHandler'] = function (...$request) use ($handler, $failure) {
            if (str_starts_with($request[1], '/payment_intents/')) throw $failure;
            return $handler(...$request);
        };
        $caught = null;
        try { $deliver(); } catch (Throwable $e) { $caught = $e; }
        check($caught && $expected($caught), 'Subscription intent lookup failure remains retryable: ' . $label);
        check($applications === $before && count(audits()) === $records,
            'A failed intent lookup cannot apply or audit a payment');
        $GLOBALS['stripeHandler'] = $handler;
    }
    $deliver();
    $deliver();
    check($applications === $before + 1,
        'Subscription intent lookup recovers without duplicating the payment');
    $intent->id = 'pi_subscription_invalid_binding';
    $records = count(audits());
    $before = $applications;
    foreach ([null, 999] as $binding) {
        $stripeInvoice->metadata->ae_stripe_invoice_id = $binding;
        $deliver();
        check($applications === $before && count(audits()) === $records + 1,
            'An unbound or unrelated subscription payment retains one gateway review record');
    }
    $stripeInvoice->metadata->ae_stripe_invoice_id = $invoice->id;
    $records = count(audits());
    $invoice->forceFill(['user_id' => 99])->save();
    rejects($deliver, 'HTTP 409');
    check($applications === $before && count(audits()) === $records,
        'Subscription binding cannot apply money or write audits to another owner');

    $invoice->forceFill(['user_id' => 7])->save();
    $serviceRow = (array) DB::table('services')->where('id', $service->id)->first();
    $removeService = fn () => DB::table('services')->where('id', $service->id)->delete();
    foreach (['empty', 'split', 'amount', 'service', 'subscription', 'service race', 'invoice race'] as $case) {
        $stripeInvoice->id = 'in_review_' . $case;
        $records = count(audits());
        $savedHandler = $GLOBALS['stripeHandler'];
        $GLOBALS['stripeHandler'] = function (...$request) use ($savedHandler, $case) {
            if ($request[1] === '/invoice_payments' && in_array($case, ['empty', 'split', 'amount'])) {
                $entry = ['status' => 'paid', 'amount_paid' => $case === 'amount' ? 1200 : 1250,
                    'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_unbound']];
                return ['data' => $case === 'empty' ? [] : ($case === 'split' ? [$entry, $entry] : [$entry])];
            }
            return $savedHandler(...$request);
        };
        if ($case === 'service') $removeService();
        // Another process removes the row after the unlocked read and commits before this transaction locks it.
        if ($case === 'service race') beforeLock('users', $removeService);
        if ($case === 'invoice race') beforeLock('invoices', fn () => DB::table('invoices')->where('id', $invoice->id)->delete());
        if ($case === 'subscription') $stripeInvoice->parent->subscription_details->subscription = null;
        $deliver();
        check(count(audits()) === $records + 1, 'First unresolved subscription delivery persists review: ' . $case);
        $deliver();
        check(count(audits()) === $records + 1 && $lastAudit()->new_values['stripe_invoice_id'] === $stripeInvoice->id,
            'Unresolved subscription success retains one durable gateway review: ' . $case);
        if (!DB::table('services')->where('id', $service->id)->exists()) DB::table('services')->insert($serviceRow);
        $GLOBALS['stripeHandler'] = $savedHandler;
        $stripeInvoice->parent->subscription_details->subscription = $service->subscription_id;
    }
})();

(function () use ($gateway, $reset, $lastAudit, &$applications) {
    $reset();
    $invoice = nativeInvoice(412, 20, 'USD');
    $unrelatedHistory = nativeInvoice(411, 20, 'USD');
    nativeInvoice(999, 20, 'USD');
    $service = nativeService('sub_bound_query', [$unrelatedHistory, $invoice]);
    // Settlement may read only the bound invoice item, never the rest of the service's billing history.
    $guard = function ($model) { if (($model->invoice_id ?? $model->id) === 411) throw new RuntimeException('Bound settlement must not load unrelated invoice history'); };
    $guarding = true;
    App\Models\InvoiceItem::retrieved(function ($item) use ($guard, &$guarding) { if ($guarding) $guard($item); });
    $intent = (object) ['id' => 'pi_bound_query', 'status' => 'succeeded', 'amount' => 2000,
        'amount_received' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner'];
    $stripeInvoice = (object) ['id' => 'in_bound_query', 'currency' => 'usd', 'amount_paid' => 2000,
        'metadata' => (object) ['ae_stripe_invoice_id' => 412],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) [
            'subscription' => $service->subscription_id]]];
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($intent) {
        if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [
            ['status' => 'paid', 'amount_paid' => 2000,
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $intent->id]]]];
        if ($verb === 'get' && $path === '/payment_intents/' . $intent->id) return $intent;
        throw new RuntimeException('Unexpected bound query request: ' . $path);
    };
    try {
        foreach (['pending', 'paid'] as $invoiceStatus) {
            $invoice->forceFill(['status' => $invoiceStatus])->save();
            $intent->id = 'pi_bound_query_' . $invoiceStatus;
            $before = $applications;
            invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice);
            invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice);
            check($applications === $before + 1
                && payments()['412:' . $intent->id]['amount'] === 20.0,
                'Bound settlement loads only the requested related invoice: ' . $invoiceStatus);
        }
        $intent->id = 'pi_bound_query_absent';
        $before = $applications;
        $records = count(audits());
        foreach ([null, 999] as $binding) {
            $stripeInvoice->metadata->ae_stripe_invoice_id = $binding;
            invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice);
            check($applications === $before && count(audits()) === $records + 1
                && $lastAudit()->new_values['stripe_invoice_id'] === $stripeInvoice->id
                && $lastAudit()->new_values['reason'] === 'Invoice binding missing or unrelated',
                'Absent or unrelated binding cannot settle and retains one review through the filtered relation');
        }
    } finally {
        $guarding = false;
    }
})();

// Subscription settlement must lock the customer before any plain read. MariaDB REPEATABLE READ takes a transaction's
// read view at its first plain read, so a plain read before the user lock hides an attempt another process commits while
// settlement waits for that lock: the completed attempt would then never be retired.
(function () use ($gateway, $reset, $owner, &$applications) {
    $reset();
    $invoice = nativeInvoice(421, 20, 'USD');
    nativeService('sub_adopted', [$invoice]);
    $intent = (object) ['id' => 'pi_adopted_processing', 'status' => 'succeeded', 'amount' => 2000,
        'amount_received' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner'];
    $stripeInvoice = (object) ['id' => 'in_adopted', 'currency' => 'usd', 'amount_paid' => 2000,
        'metadata' => (object) ['ae_stripe_invoice_id' => 421],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_adopted']]];
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($intent) {
        if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [
            ['status' => 'paid', 'amount_paid' => 2000, 'payment' => ['type' => 'payment_intent', 'payment_intent' => $intent->id]]]];
        if ($verb === 'get' && $path === '/payment_intents/' . $intent->id) return $intent;
        throw new RuntimeException('Unexpected adopted attempt request: ' . $verb . ' ' . $path);
    };
    // The bank debit was recorded as processing; Pay adopts it into the invoice's attempt.
    App\Helpers\ExtensionHelper::addProcessingPayment($invoice, 'AeStripe', 20, null, $intent->id);
    $first = null; $tracing = true; $concurrent = false;
    DB::connection()->beforeExecuting(function ($sql) use (&$first, &$tracing, &$concurrent) {
        if ($tracing && !$concurrent && $first === null && DB::transactionLevel() > 0) $first = $sql;
    });
    beforeLock('users', function () use ($owner, $intent, &$concurrent) {
        $concurrent = true;
        $owner->properties()->create(['key' => 'ae_stripe_checkout_421', 'value' => json_encode(['version' => 2,
            'key' => 'adopt_' . $intent->id, 'created' => time(), 'intent' => $intent->id, 'retry' => true, 'funds_pending' => true])]);
        $concurrent = false;
    });
    $before = $applications;
    try { invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice); } finally { $tracing = false; }
    check(is_string($first) && str_starts_with($first, 'select * from "ae_stripe_locks"') && str_ends_with($first, '/* for update */'),
        'Subscription settlement locks the customer before its first read, so it sees an attempt committed while it waited');
    check(!$owner->properties()->where('key', 'ae_stripe_checkout_421')->exists()
        && payments()['421:' . $intent->id]['status'] === 'succeeded' && moneyRows()['payments'] === ['421:' . $intent->id => 1]
        && $applications === $before + 1,
        'Subscription settlement pays the adopted processing debit once and retires its completed attempt');
    invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice);
    check($applications === $before + 1 && moneyRows()['payments'] === ['421:' . $intent->id => 1],
        'A repeated subscription delivery records nothing more');
})();

// A settlement chosen as a deadlock victim runs again. MariaDB rolls back the whole transaction with SQLSTATE 40001 when
// the payment insert waits on a neighbouring invoice's balance lock in a cycle; the rerun pays once and retires the attempt.
(function () use ($gateway, $reset, $owner, &$applications) {
    $reset();
    $victims = 0;
    beforeWrite('invoice_transactions', function ($values, $sql) use (&$victims) {
        if ($victims <= 0 || !str_starts_with($sql, 'insert')) return;
        --$victims;
        throw new Illuminate\Database\QueryException('mysql', $sql, [],
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    });
    $settle = function (callable $deliver) {
        try { $deliver(); return true; } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
    };
    try {
        $invoice = nativeInvoice(431, 20, 'USD');
        $intent = (object) ['id' => 'pi_deadlock_victim', 'status' => 'succeeded', 'amount' => 2000, 'amount_received' => 2000,
            'currency' => 'usd', 'customer' => 'cus_owner', 'metadata' => (object) ['invoice_id' => '431']];
        $owner->properties()->create(['key' => 'ae_stripe_checkout_431', 'value' => json_encode(['version' => 2, 'key' => 'checkout_victim',
            'created' => time(), 'intent' => $intent->id, 'retry' => true])]);
        $before = $applications;
        $victims = 1;
        $result = $settle(fn () => invoke($gateway, 'recordIntentPayment', $intent, 'succeeded', false));
        check($result === true && $victims === 0 && $applications === $before + 1
            && moneyRows()['payments'] === ['431:' . $intent->id => 1] && !$owner->properties()->where('key', 'ae_stripe_checkout_431')->exists(),
            'A deadlocked PaymentIntent settlement runs again, pays once and retires its attempt: ' . var_export($result, true));
        invoke($gateway, 'recordIntentPayment', $intent, 'succeeded', false);
        check($applications === $before + 1 && moneyRows()['payments'] === ['431:' . $intent->id => 1], 'A repeated delivery after the rerun records nothing more');

        $invoice = nativeInvoice(432, 20, 'USD');
        nativeService('sub_deadlock', [$invoice]);
        $payment = (object) ['id' => 'pi_deadlock_subscription', 'status' => 'succeeded', 'amount' => 2000, 'amount_received' => 2000,
            'currency' => 'usd', 'customer' => 'cus_owner'];
        $stripeInvoice = (object) ['id' => 'in_deadlock', 'currency' => 'usd', 'amount_paid' => 2000, 'metadata' => (object) ['ae_stripe_invoice_id' => 432],
            'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_deadlock']]];
        $GLOBALS['stripeHandler'] = function ($verb, $path) use ($payment) {
            if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [
                ['status' => 'paid', 'amount_paid' => 2000, 'payment' => ['type' => 'payment_intent', 'payment_intent' => $payment->id]]]];
            if ($verb === 'get' && $path === '/payment_intents/' . $payment->id) return $payment;
            throw new RuntimeException('Unexpected deadlock subscription request: ' . $verb . ' ' . $path);
        };
        $before = $applications;
        $victims = 1;
        $result = $settle(fn () => invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice));
        check($result === true && $victims === 0 && $applications === $before + 1
            && (moneyRows()['payments']['432:' . $payment->id] ?? 0) === 1,
            'A deadlocked subscription settlement runs again and pays once: ' . var_export($result, true));
        invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice);
        check($applications === $before + 1 && moneyRows()['payments']['432:' . $payment->id] === 1, 'A repeated subscription delivery after the rerun records nothing more');
    } finally {
        beforeWrite('invoice_transactions', null);
    }
})();

// Internally nested settlement must not regain range locks that block another customer's first payment insert.
(function () use ($gateway, $reset) {
    $reset();
    $invoice = nativeInvoice(481, 20, 'USD');
    nativeService('sub_owned', [$invoice]);
    $intent = (object) ['id' => 'pi_owned', 'status' => 'succeeded', 'amount' => 2000,
        'amount_received' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner'];
    $stripeInvoice = (object) ['id' => 'in_owned', 'currency' => 'usd', 'amount_paid' => 2000,
        'metadata' => (object) ['ae_stripe_invoice_id' => 481],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_owned']]];
    $GLOBALS['stripeHandler'] = function ($verb, $path) use ($intent) {
        if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [
            ['status' => 'paid', 'amount_paid' => 2000, 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_owned']]]];
        if ($verb === 'get' && $path === '/payment_intents/pi_owned') return $intent;
        throw new RuntimeException('Unexpected subscription request: ' . $verb . ' ' . $path);
    };
    $recording = true; $rangeLocks = [];
    DB::listen(function ($query) use (&$recording, &$rangeLocks) {
        if ($recording && str_contains($query->sql, 'from "invoice_transactions"')
            && str_contains($query->sql, '/* for update */') && !preg_match('/where (?:"invoice_transactions"\.)?"id" = \?/', $query->sql)) $rangeLocks[] = $query->sql;
    });
    try { invoke($gateway, 'recordSubscriptionPayment', $stripeInvoice); }
    finally { $recording = false; }
    check(!$rangeLocks && payments()['481:pi_owned']['amount'] === 20.0,
        'Owned subscription settlement records its payment using only exact payment-row locks');
})();
