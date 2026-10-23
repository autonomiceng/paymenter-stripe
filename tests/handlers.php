<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, Service};
use Illuminate\Support\Facades\{DB, Event, Http, Log};

Gateway::$enabled = true;
$oldHandler = Http::$handler;
$user->props->values = [];
$posts = 0;
$lost = true;
Http::$handler = function ($verb, $path, $data) use (&$posts, &$lost, $intent) {
    if ($path === '/payment_intents' && $verb === 'post') {
        ++$posts;
        if ($lost) { $lost = false; throw new RuntimeException('Lost response'); }
        return $intent;
    }
    if ($path === '/payment_intents/pi_test') return $intent;
    throw new RuntimeException('Unexpected intent request');
};
$data = ['amount' => 29, 'currency' => 'usd', 'customer' => 'cus_owner'];
rejects(fn () => invoke($gateway, 'checkoutIntent', $invoice, $data), 'Lost response');
$key = end(Http::$calls)[3]['Idempotency-Key'];
invoke($gateway, 'checkoutIntent', $invoice, $data);
check(end(Http::$calls)[3]['Idempotency-Key'] === $key, 'Lost response retries persisted checkout key');
$intent->status = 'requires_payment_method';
invoke($gateway, 'checkoutIntent', $invoice, $data);
check($posts === 2 && end(Http::$calls)[0] === 'get', 'Later render retrieves the same intent');
rejects(fn () => invoke($gateway, 'checkoutIntent', $invoice, ['amount' => 30]), 'HTTP 409');
$stored = json_decode($user->props->values['stripe_saved_checkout_12'], true);
unset($stored['intent']);
$stored['created'] = time() - 86400;
$user->props->values['stripe_saved_checkout_12'] = json_encode($stored);
rejects(fn () => invoke($gateway, 'checkoutIntent', $invoice, $data), 'HTTP 409');

$service = Service::$row = new Service;
$service->subscription_id = null;
$service->properties->values['has_stripe_subscription'] = '1';
$before = count(Http::$calls);
$gateway->cancelSubscription($service);
check(count(Http::$calls) === $before, 'Empty subscription id stops cancellation even with marker');
$setup = (object) ['id' => 'seti_subscription', 'customer' => 'cus_owner', 'payment_method' => 'pm_test', 'metadata' => (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1']];
$invoice->items = [(object) ['reference_type' => Service::class, 'reference' => $service, 'price' => '20.99']];
Http::$handler = function ($verb, $path, $data) {
    return match ($path) {
        '/customers/cus_owner' => ['id' => 'cus_owner'],
        '/payment_methods/pm_test' => ['id' => 'pm_test', 'customer' => 'cus_owner'],
        '/products/search' => ['data' => [['id' => 'prod_test']]],
        '/subscription_schedules' => ['subscription' => 'sub_created'],
        default => throw new RuntimeException('Unexpected setup request: ' . $path),
    };
};
invoke($gateway, 'setupSubscription', $setup);
$schedule = end(Http::$calls);
check($schedule[3]['Idempotency-Key'] === 'sched_seti_subscription_41', 'Schedule key binds setup intent and service');
check($schedule[2]['phases'][0]['items'][0]['price_data']['unit_amount'] === 2099, 'Setup fee uses minor units');
check($schedule[2]['phases'][1]['items'][0]['price_data']['unit_amount'] === 5997, 'Setup amount retains quantity');
$before = count(array_filter(Http::$calls, fn ($call) => $call[1] === '/products/search'));
unset($service->properties->values['has_stripe_subscription']); // Interrupted between the two local writes.
invoke($gateway, 'setupSubscription', $setup);
check($service->properties->values['has_stripe_subscription'] === true, 'Replay repairs a missing subscription listener marker');
check(count(array_filter(Http::$calls, fn ($call) => $call[1] === '/products/search')) === $before, 'Existing subscription skips products and schedule on replay');
$user->props->customer = '';
try { invoke($gateway, 'setupSubscription', $setup); throw new RuntimeException('Missing expected exception'); }
catch (Exception $error) { check($error->getMessage() === 'Stripe customer not found', 'Missing customer throws message-only exception'); }
$user->props->customer = 'cus_owner';
$before = count(Http::$calls);
$gateway->webhook(eventRequest((object) ['metadata' => (object) ['stripe_saved_gateway' => '1']], type: 'setup_intent.succeeded'));
check(count(Http::$calls) === $before, 'Unrelated SetupIntent is acknowledged without effects');
$service->subscription_id = 'sub_created';
$gateway->webhook(eventRequest((object) ['id' => 'sched_test', 'subscription' => 'sub_created', 'metadata' => (object) ['stripe_saved_gateway' => '1']], type: 'subscription_schedule.canceled'));
check($service->subscription_id === null, 'Schedule cancellation uses subscription id');

$service->subscription_id = 'sub_test';
$service->invoiceRows = [(object) ['invoice' => $invoice, 'created_at' => 1]];
$stripeInvoice = (object) ['id' => 'in_test', 'currency' => 'usd', 'amount_paid' => 1999,
    'metadata' => (object) ['stripe_saved_invoice_id' => 12],
    'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_test', 'metadata' => (object) ['stripe_saved_gateway' => '1']]]];
$paymentRows = [];
Http::$handler = function ($verb, $path, $data) use (&$paymentRows, $stripeInvoice) {
    if ($path === '/invoice_payments') return ['data' => $paymentRows];
    if ($verb === 'get' && str_starts_with($path, '/payment_intents/')) return [
        'id' => substr($path, strlen('/payment_intents/')), 'status' => 'succeeded', 'customer' => 'cus_owner',
        'amount' => 2500, 'amount_received' => 1999, 'currency' => $stripeInvoice->currency];
    throw new RuntimeException('Unexpected payment request: ' . $path);
};
$deliver = fn ($object) => $gateway->webhook(eventRequest($object, type: 'invoice.payment_succeeded'));
check($deliver((object) ['parent' => null])['status'] === 200, 'Null parent is a 2xx no-op');
check($deliver($stripeInvoice)['status'] === 200, 'Empty invoice payments is a 2xx no-op');
$paymentRows = [(object) ['payment' => (object) ['type' => 'payment_intent', 'payment_intent' => 'pi_subscription']]];
$service->invoiceRows = [];
check($deliver($stripeInvoice)['status'] === 200, 'No matching invoice is a 2xx no-op');
$service->invoiceRows = [(object) ['invoice' => $invoice, 'created_at' => 1]];
$invoice->status = 'paid';
$paymentRows[0]->payment->payment_intent = 'pi_subscription_late';
$before = ExtensionHelper::$applications;
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before + 1 && $invoice->status === 'paid', 'Paid bound subscription invoice retains its late payment');
$before = ExtensionHelper::$applications;
$paymentRows[0]->payment->payment_intent = 'pi_subscription';
$invoice->status = 'pending';
$stripeInvoice->currency = 'eur';
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before && end(Log::$warnings)[1] === ['service_id' => 41], 'Currency mismatch is logged without applying money');
$stripeInvoice->currency = 'usd';
\Illuminate\Support\Facades\DB::$modelLocks = [];
$deliver($stripeInvoice);
check(array_slice(\Illuminate\Support\Facades\DB::$modelLocks, 0, 4) === [\App\Models\User::class, Service::class, \App\Models\InvoiceTransaction::class, Invoice::class],
    'Subscription settlement locks customer before service and invoice');
check(ExtensionHelper::$payments['12:pi_subscription']['amount'] === 19.99, 'Subscription invoice records major units');
$before = ExtensionHelper::$applications;
$deliver($stripeInvoice);
$paymentIntent = (object) ['id' => 'pi_subscription', 'amount' => 1999, 'amount_received' => 1999, 'currency' => 'usd', 'metadata' => (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1']];
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$applications === $before, 'Subscription then PaymentIntent delivery cannot reapply payment');
$paymentIntent->id = 'pi_first';
$gateway->webhook(eventRequest($paymentIntent));
$before = ExtensionHelper::$applications;
$paymentRows[0]->payment->payment_intent = 'pi_first';
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before, 'PaymentIntent then subscription delivery cannot reapply payment');
$paymentIntent->currency = 'JPY';
$paymentIntent->id = 'pi_wrong_currency';
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$applications === $before, 'PaymentIntent currency mismatch cannot apply payment');

$invoice->status = 'paid';
$paymentIntent->id = 'pi_second_settled';
$paymentIntent->currency = 'usd';
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$applications === $before + 1 && $invoice->status === 'paid', 'A different settled intent is recorded on the paid invoice');
$invoice->status = 'pending';

// Currency-specific reverse conversion also applies to recorded payments and charge fees.
$invoice->currency_code = 'JPY';
$paymentIntent->id = 'pi_yen';
$paymentIntent->currency = 'jpy';
$paymentIntent->amount = $paymentIntent->amount_received = 123;
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$payments['12:pi_yen']['amount'] === 123.0, 'JPY webhook records yen without dividing by 100');
Http::$handler = fn () => ['fee' => 5, 'currency' => 'jpy'];
$gateway->webhook(eventRequest((object) ['payment_intent' => 'pi_yen', 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
check(ExtensionHelper::$fees['pi_yen'] === 5.0, 'Same-currency JPY fee remains in invoice currency');
// Use separate payments for each currency fixture. Completed fee records are immutable.
ExtensionHelper::$payments['12:pi_yen_cross'] = ExtensionHelper::$payments['12:pi_yen'];
// Settlement USD -> invoice JPY: convert major units then round to whole yen.
Http::$handler = fn () => ['fee' => 333, 'currency' => 'usd', 'exchange_rate' => 0.007];
$gateway->webhook(eventRequest((object) ['payment_intent' => 'pi_yen_cross', 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
check(ExtensionHelper::$fees['pi_yen_cross'] === 476.0, 'Cross-currency fee rounds to invoice JPY minor units');
$invoice->currency_code = 'usd';
ExtensionHelper::$payments['12:pi_usd_cross'] = ExtensionHelper::$payments['12:pi_yen'];
Http::$handler = fn () => ['fee' => 100, 'currency' => 'jpy', 'exchange_rate' => 150];
$gateway->webhook(eventRequest((object) ['payment_intent' => 'pi_usd_cross', 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
check(ExtensionHelper::$fees['pi_usd_cross'] === 0.67, 'Settlement JPY fee converts back to invoice USD cents');
foreach ([null, 0, -1, 'invalid', 'INF', 'NaN', '1e309'] as $rateIndex => $invalidRate) {
    foreach ([false, true] as $hasFee) {
        $feeIntent = (object) ['id' => 'pi_fee_' . $rateIndex . '_' . (int) $hasFee,
            'amount' => 2000, 'amount_received' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner',
            'metadata' => (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1']];
        $applications = ExtensionHelper::$applications;
        check($gateway->webhook(eventRequest($feeIntent))['status'] === 200
            && ExtensionHelper::$applications === $applications + 1,
            'Payment settles before fee conversion: ' . $feeIntent->id);
        if ($hasFee) ExtensionHelper::$fees[$feeIntent->id] = 0.67;
        $fees = ExtensionHelper::$fees;
        $payment = ExtensionHelper::$payments['12:' . $feeIntent->id];
        Http::$handler = function ($verb, $path) use ($invalidRate) {
            if ($verb === 'get' && $path === '/balance_transactions/bt_test') {
                return ['fee' => 100, 'currency' => 'jpy', 'exchange_rate' => $invalidRate];
            }
            throw new RuntimeException('Unexpected fee request: ' . $path);
        };
        for ($delivery = 0; $delivery < 2; ++$delivery) {
            $result = $gateway->webhook(eventRequest((object) [
                'payment_intent' => $feeIntent->id, 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
            check($result['status'] === 200 && $result['data']['received'] === true,
                'Unavailable fee conversion acknowledges each delivery: ' . $feeIntent->id);
            check(ExtensionHelper::$fees === $fees,
                'Unavailable fee conversion preserves existing or absent fee: ' . $feeIntent->id);
            check(ExtensionHelper::$payments['12:' . $feeIntent->id] === $payment
                && $payment === ['gateway' => 'StripeSaved', 'amount' => 20.0, 'status' => 'succeeded']
                && ExtensionHelper::$applications === $applications + 1,
                'Unavailable fee conversion preserves the settled payment: ' . $feeIntent->id);
            $pending = DB::$tables['stripe_saved_payment_fees'][$feeIntent->id];
            check($pending['state'] === 'pending' && $pending['last_error'] === 'Fee conversion unavailable',
                'Unavailable conversion persists a pending fee: ' . $feeIntent->id);
        }
        Http::$handler = fn () => ['fee' => 100, 'currency' => 'jpy', 'exchange_rate' => 150];
        $result = $gateway->webhook(eventRequest((object) [
            'payment_intent' => $feeIntent->id, 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
        check($result['status'] === 200 && ExtensionHelper::$fees[$feeIntent->id] === 0.67,
            'A valid rate records the converted fee after unavailable conversion: ' . $feeIntent->id);
    }
}
Http::$handler = function ($verb, $path) {
    return match ($path) {
        '/subscriptions/sub_test' => ['schedule' => 'sched_test'],
        '/subscription_schedules/sched_test' => ['phases' => [
            ['items' => [(object) []], 'start_date' => 1, 'end_date' => 2]]],
        '/payment_methods/pm_test' => ['id' => 'pm_test', 'customer' => 'cus_owner'],
        '/products/search' => ['data' => [['id' => 'prod_test']]],
        default => throw new RuntimeException('Unexpected update request: ' . $path),
    };
};
$gateway->updateSubscription($service);
check(end(Http::$calls)[2]['phases'][0]['items'][0]->price_data['unit_amount'] === 5997, 'Schedule price updates preserve quantity');

// Listener failures must expose only service identity, even if the exception contains sensitive text.
$service->dirty = ['price'];
Http::$handler = fn () => throw new RuntimeException('sensitive request payload');
$service->properties->values['has_stripe_subscription'] = '1';
Event::$listeners[0](new App\Events\Service\Updated($service));
check(end(Log::$warnings) === ['StripeSaved subscription sync failed', ['service_id' => 41]], 'Listener failure logs service id only');
Http::$handler = $oldHandler;
Gateway::$enabled = false;
