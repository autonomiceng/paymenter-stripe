<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, Service};
use Illuminate\Support\Facades\{Event, Http, Log};

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
$setup = (object) ['id' => 'seti_subscription', 'payment_method' => 'pm_test', 'metadata' => (object) ['invoice_id' => 12]];
$invoice->items = [(object) ['reference_type' => Service::class, 'reference' => $service, 'price' => '20.99']];
Http::$handler = function ($verb, $path, $data) {
    return match ($path) {
        '/customers/cus_owner' => ['id' => 'cus_owner'],
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
$gateway->webhook(eventRequest((object) ['metadata' => (object) []], type: 'setup_intent.succeeded'));
check(count(Http::$calls) === $before, 'Unrelated SetupIntent is acknowledged without effects');
$service->subscription_id = 'sub_created';
$gateway->webhook(eventRequest((object) ['id' => 'sched_test', 'subscription' => 'sub_created'], type: 'subscription_schedule.canceled'));
check($service->subscription_id === null, 'Schedule cancellation uses subscription id');

$service->subscription_id = 'sub_test';
$service->invoiceRows = [(object) ['invoice' => $invoice, 'created_at' => 1]];
$stripeInvoice = (object) ['id' => 'in_test', 'currency' => 'usd', 'amount_paid' => 1999,
    'metadata' => (object) ['stripe_saved_invoice_id' => 12],
    'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => 'sub_test']]];
$paymentRows = [];
Http::$handler = function ($verb, $path, $data) use (&$paymentRows) {
    if ($path === '/invoice_payments') return ['data' => $paymentRows];
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
$before = ExtensionHelper::$applications;
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before, 'Paid invoice receives no payment');
$invoice->status = 'pending';
$stripeInvoice->currency = 'eur';
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before && end(Log::$warnings)[1] === ['service_id' => 41], 'Currency mismatch is logged without applying money');
$stripeInvoice->currency = 'usd';
$deliver($stripeInvoice);
check(ExtensionHelper::$payments['12:pi_subscription']['amount'] === 19.99, 'Subscription invoice records major units');
$before = ExtensionHelper::$applications;
$deliver($stripeInvoice);
$paymentIntent = (object) ['id' => 'pi_subscription', 'amount' => 1999, 'currency' => 'usd', 'metadata' => (object) ['invoice_id' => 12]];
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
check(ExtensionHelper::$applications === $before, 'A different intent cannot apply to an already paid invoice');
$invoice->status = 'pending';

// Currency-specific reverse conversion also applies to recorded payments and charge fees.
$invoice->currency_code = 'JPY';
$paymentIntent->id = 'pi_yen';
$paymentIntent->currency = 'jpy';
$paymentIntent->amount = 123;
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$payments['12:pi_yen']['amount'] === 123.0, 'JPY webhook records yen without dividing by 100');
Http::$handler = fn () => ['fee' => 5, 'currency' => 'jpy'];
$gateway->webhook(eventRequest((object) ['payment_intent' => 'pi_yen', 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
check(ExtensionHelper::$fees['pi_yen'] === 5.0, 'JPY balance transaction fee uses its own currency');
$invoice->currency_code = 'usd';
Http::$handler = function ($verb, $path) {
    return match ($path) {
        '/subscriptions/sub_test' => ['schedule' => 'sched_test'],
        '/subscription_schedules/sched_test' => ['phases' => [
            ['items' => [(object) []], 'start_date' => 1, 'end_date' => 2]]],
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
