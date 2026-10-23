<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Gateway, Invoice, InvoiceTransaction, Service, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{DB, Http, Log};

(function () use ($gateway) {
    $oldHandler = Http::$handler;
    $oldEnabled = Gateway::$enabled;
    $oldService = Service::$row;
    $oldRows = Invoice::$rows;
    $invoice = clone Invoice::$row;
    $invoice->id = 112;
    $invoice->currency_code = 'usd';
    $invoice->status = 'pending';
    Invoice::$rows[$invoice->id] = $invoice;
    Gateway::$enabled = true;
    $service = Service::$row = new Service;
    $service->subscription_id = 'sub_settlement';
    $service->invoiceRows = [(object) ['invoice' => $invoice]];
    $intent = (object) ['id' => 'pi_received', 'status' => 'succeeded', 'amount' => 2000,
        'amount_received' => 1250, 'currency' => 'usd', 'customer' => 'cus_owner',
        'metadata' => (object) ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1']];
    $stripeInvoice = (object) ['id' => 'in_settlement', 'currency' => 'usd', 'amount_paid' => 1250,
        'metadata' => (object) ['stripe_saved_invoice_id' => $invoice->id],
        'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) [
            'subscription' => $service->subscription_id, 'metadata' => (object) ['stripe_saved_gateway' => '1']]]];
    Http::$handler = function ($verb, $path) use ($intent) {
        if ($verb === 'get' && $path === '/invoices/in_settlement') return ['metadata' => ['stripe_saved_invoice_id' => 112]];
        if ($verb === 'get' && $path === '/invoice_payments') return ['data' => [
            ['status' => 'paid', 'amount_paid' => 1250,
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $intent->id]]]];
        if ($verb === 'get' && $path === '/payment_intents/' . $intent->id) {
            check(!DB::$locked, 'Subscription PaymentIntent lookup runs before the settlement transaction');
            return $intent;
        }
        throw new RuntimeException('Unexpected settlement request: ' . $path);
    };
    $deliver = fn () => $gateway->webhook(eventRequest($stripeInvoice, type: 'invoice.payment_succeeded'));

    $gateway->webhook(eventRequest($intent));
    check(ExtensionHelper::$payments['112:pi_received']['amount'] === 12.5,
        'Successful payment records received amount instead of intended amount');
    $intent->id = 'pi_processing_received';
    $intent->amount_received = 0;
    $gateway->webhook(eventRequest($intent, type: 'payment_intent.processing'));
    check(ExtensionHelper::$payments['112:pi_processing_received']['amount'] === 20.0,
        'Processing payment keeps its intended amount before settlement');
    $gateway->webhook(eventRequest($intent, type: 'payment_intent.payment_failed'));
    check(ExtensionHelper::$payments['112:pi_processing_received']['amount'] === 20.0,
        'Failed payment keeps its intended amount before settlement');
    $intent->amount_received = 1250;
    $gateway->webhook(eventRequest($intent));
    check(ExtensionHelper::$payments['112:pi_processing_received']['amount'] === 12.5,
        'Processing payment updates to the actual received amount on success');
    $intent->id = 'pi_zero_received';
    $intent->amount_received = 0;
    $gateway->webhook(eventRequest($intent));
    check(ExtensionHelper::$payments['112:pi_zero_received']['amount'] === 0.0,
        'A zero received amount never falls back to the intended amount');
    $intent->amount_received = 1250;

    $invoice->status = 'paid';
    $intent->id = 'pi_subscription_paid';
    $intent->metadata->invoice_id = 12;
    $before = ExtensionHelper::$applications;
    $warnings = count(Log::$warnings);
    $locks = count(DB::$modelLocks);
    $calls = count(Http::$calls);
    $deliver();
    $deliver();
    check(count(array_filter(array_slice(Http::$calls, $calls), fn ($call) => $call[0] === 'get'
        && $call[1] === '/payment_intents/pi_subscription_paid')) === 2,
        'Each subscription delivery fetches the settled PaymentIntent before checking local rows');
    check(ExtensionHelper::$applications === $before + 1 && $invoice->status === 'paid'
        && ExtensionHelper::$payments['112:pi_subscription_paid']['amount'] === 12.5,
        'Paid bound subscription invoice retains one successful charge with received units');
    $intent->metadata->invoice_id = $invoice->id;
    $gateway->webhook(eventRequest($intent));
    check(ExtensionHelper::$applications === $before + 1,
        'Subscription then PaymentIntent success cannot duplicate the late charge');
    check(count(Log::$warnings) === $warnings + 1 && end(Log::$warnings) === [
        'StripeSaved payment received for paid invoice', ['invoice_id' => $invoice->id]],
        'Late subscription payment logs only the invoice ID');
    check(array_slice(DB::$modelLocks, $locks, 4) === [User::class, Service::class, InvoiceTransaction::class, Invoice::class],
        'Subscription success keeps user-first settlement lock order');
    check(count(array_filter(array_slice(Http::$calls, $calls), fn ($call) => $call[0] !== 'get')) === 0,
        'Late subscription success makes no remote changes');
    check(!invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service),
        'Invoice creation still refuses an already paid binding');

    foreach (['invoice not pending', 'currency mismatch', 'invoice currency mismatch', 'intent currency mismatch', 'customer mismatch', 'paid currency mismatch'] as $reason) {
        $invoice->status = $reason === 'invoice not pending' ? 'cancelled' : ($reason === 'paid currency mismatch' ? 'paid' : 'pending');
        $intent->id = 'pi_subscription_review_' . str_replace(' ', '_', $reason);
        $intent->currency = $stripeInvoice->currency = str_contains($reason, 'currency') ? 'jpy' : 'usd';
        if ($reason === 'intent currency mismatch') $stripeInvoice->currency = 'usd';
        if ($reason === 'invoice currency mismatch') $intent->currency = 'usd';
        $intent->customer = $reason === 'customer mismatch' ? 'cus_foreign' : 'cus_owner';
        $records = count(Audit::$rows);
        $before = ExtensionHelper::$applications;
        $locks = count(DB::$modelLocks);
        $deliver();
        $deliver();
        check(ExtensionHelper::$applications === $before && count(Audit::$rows) === $records + 1,
            'Refused subscription success leaves one durable staff record: ' . $reason);
        $record = end(Audit::$rows);
        check($record['auditable_type'] === Invoice::class && $record['auditable_id'] === 112
            && $record['new_values']['payment_intent_id'] === $intent->id
            && $record['new_values']['amount_minor'] === 1250
            && $record['new_values']['currency'] === $intent->currency
            && $record['new_values']['reason'] === (str_contains($reason, 'currency') ? 'currency mismatch' : $reason),
            'Subscription staff record preserves binding, received units and refusal reason: ' . $reason);
        check(array_slice(DB::$modelLocks, $locks, 4) === [User::class, Service::class, InvoiceTransaction::class, Invoice::class]
            && in_array(Audit::class, array_slice(DB::$modelLocks, $locks), true),
            'Subscription refusal locks owner and bound invoice before the shared audit guard');
        check(!str_contains(json_encode($record), 'cus_foreign') && !str_contains(json_encode($record), $invoice->user->email),
            'Subscription review excludes customer details');
    }
    // Both delivery paths share the same PaymentIntent review key.
    $records = count(Audit::$rows);
    $gateway->webhook(eventRequest($intent));
    check(count(Audit::$rows) === $records, 'Subscription then PaymentIntent refusal reuses the staff record');
    $intent->id = 'pi_review_intent_first';
    $gateway->webhook(eventRequest($intent));
    $records = count(Audit::$rows);
    $deliver();
    check(count(Audit::$rows) === $records, 'PaymentIntent then subscription refusal reuses the staff record');

    $intent->id = 'pi_subscription_review_retry';
    Audit::$fail = true;
    rejects($deliver, 'Review record unavailable');
    $records = count(Audit::$rows);
    $deliver();
    $deliver();
    check(count(Audit::$rows) === $records + 1, 'Subscription audit failure stays retryable and recovers once');
    $invoice->status = 'pending';
    $intent->currency = $stripeInvoice->currency = 'usd';
    $intent->id = 'pi_subscription_next_cycle';
    $before = ExtensionHelper::$applications;
    $deliver();
    $deliver();
    check(ExtensionHelper::$applications === $before + 1
        && ExtensionHelper::$payments['112:pi_subscription_next_cycle']['amount'] === 12.5,
        'A refused subscription charge does not block the next settlement');
    $intent->id = 'pi_subscription_intent_first';
    $gateway->webhook(eventRequest($intent));
    $before = ExtensionHelper::$applications;
    $deliver();
    check(ExtensionHelper::$applications === $before, 'PaymentIntent then subscription success remains deduplicated');

    $intent->id = 'pi_subscription_not_settled';
    $handler = Http::$handler;
    Http::$handler = function ($verb, $path) use ($handler) {
        $result = $handler($verb, $path);
        if (str_starts_with($path, '/payment_intents/')) { $result = clone $result; $result->id = 'pi_different'; }
        return $result;
    };
    $payments = ExtensionHelper::$payments;
    $records = Audit::$rows;
    $locks = count(DB::$modelLocks);
    rejects($deliver, 'HTTP 409');
    check(ExtensionHelper::$payments === $payments && Audit::$rows === $records
        && count(DB::$modelLocks) === $locks,
        'A mismatched subscription intent is refused before row locks without payment or audit rows');
    Http::$handler = $handler;
    foreach (['requires_payment_method', 'requires_confirmation', 'processing', 'requires_action', 'requires_capture', 'canceled'] as $status) {
        $intent->status = $status;
        $before = ExtensionHelper::$applications;
        $records = count(Audit::$rows);
        $locks = count(DB::$modelLocks);
        rejects($deliver, 'HTTP 409');
        check(ExtensionHelper::$payments === $payments && count(DB::$modelLocks) === $locks
            && ExtensionHelper::$applications === $before && count(Audit::$rows) === $records,
            'An unsettled subscription intent cannot be recorded as success: ' . $status);
    }
    $intent->status = 'succeeded';
    foreach ([new ConnectionException('connection'), new ConnectionException('timeout'), new RequestException(503),
        new RuntimeException('decode failure')] as $failure) {
        $handler = Http::$handler;
        Http::$handler = function ($verb, $path) use ($handler, $failure) {
            if (str_starts_with($path, '/payment_intents/')) throw $failure;
            return $handler($verb, $path);
        };
        $caught = null;
        try { $deliver(); } catch (Throwable $e) { $caught = $e; }
        check($caught === $failure, 'Subscription intent lookup failure remains retryable: ' . get_class($failure));
        check(ExtensionHelper::$applications === $before && count(Audit::$rows) === $records,
            'A failed intent lookup cannot apply or audit a payment');
        Http::$handler = $handler;
    }
    $deliver();
    $deliver();
    check(ExtensionHelper::$applications === $before + 1,
        'Subscription intent lookup recovers without duplicating the payment');
    $intent->id = 'pi_subscription_invalid_binding';
    $records = count(Audit::$rows);
    $before = ExtensionHelper::$applications;
    foreach ([null, 999] as $binding) {
        $stripeInvoice->metadata->stripe_saved_invoice_id = $binding;
        $deliver();
        check(ExtensionHelper::$applications === $before && count(Audit::$rows) === $records + 1,
            'An unbound or unrelated subscription payment retains one gateway review record');
    }
    $stripeInvoice->metadata->stripe_saved_invoice_id = $invoice->id;
    $records = count(Audit::$rows);
    $invoice->user_id = 99;
    rejects($deliver, 'HTTP 409');
    check(ExtensionHelper::$applications === $before && count(Audit::$rows) === $records,
        'Subscription binding cannot apply money or write audits to another owner');

    $invoice->user_id = 7;
    foreach (['empty', 'split', 'amount', 'service', 'subscription', 'service race', 'invoice race'] as $case) {
        $stripeInvoice->id = 'in_review_' . $case;
        $records = count(Audit::$rows);
        $savedHandler = Http::$handler;
        Http::$handler = function ($verb, $path) use ($savedHandler, $case) {
            if ($path === '/invoice_payments' && in_array($case, ['empty', 'split', 'amount'])) {
                $entry = ['status' => 'paid', 'amount_paid' => $case === 'amount' ? 1200 : 1250,
                    'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_unbound']];
                return ['data' => $case === 'empty' ? [] : ($case === 'split' ? [$entry, $entry] : [$entry])];
            }
            return $savedHandler($verb, $path);
        };
        if ($case === 'service') Service::$row = null;
        if ($case === 'service race') DB::$beforeUserLock = function () { Service::$row = null; };
        if ($case === 'invoice race') DB::$beforeUserLock = function () use ($invoice) { unset(Invoice::$rows[$invoice->id]); };
        if ($case === 'subscription') $stripeInvoice->parent->subscription_details->subscription = null;
        $deliver();
        check(count(Audit::$rows) === $records + 1, 'First unresolved subscription delivery persists review: ' . $case);
        $deliver();
        check(count(Audit::$rows) === $records + 1 && end(Audit::$rows)['new_values']['stripe_invoice_id'] === $stripeInvoice->id,
            'Unresolved subscription success retains one durable gateway review: ' . $case);
        Invoice::$rows[$invoice->id] = $invoice;
        Service::$row = $service; Http::$handler = $savedHandler;
        $stripeInvoice->parent->subscription_details->subscription = $service->subscription_id;
    }
    Http::$handler = $oldHandler;
    Gateway::$enabled = $oldEnabled;
    Service::$row = $oldService;
    Invoice::$rows = $oldRows;
})();
