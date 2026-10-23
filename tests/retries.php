<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

Gateway::$enabled = true;
$oldHandler = Http::$handler;
$secondInvoice = clone $invoice;
$secondInvoice->id = 13;
Invoice::$rows = [12 => $invoice, 13 => $secondInvoice];
$service->invoiceRows = [(object) ['invoice' => $invoice, 'created_at' => 1], (object) ['invoice' => $secondInvoice, 'created_at' => 2]];
Http::$handler = function ($verb, $path) {
    if ($verb === 'get' && $path === '/invoices/in_test') return ['metadata' => []];
    if ($path === '/invoice_payments') return ['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_bound']]]];
    if ($path === '/payment_intents/pi_bound') return ['id' => 'pi_bound', 'status' => 'succeeded',
        'amount' => 2500, 'amount_received' => 1999, 'currency' => 'usd', 'customer' => 'cus_owner'];
    throw new RuntimeException('Unexpected bound subscription request: ' . $path);
};
$stripeInvoice->metadata = (object) [];
$before = ExtensionHelper::$applications;
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before, 'Unbound subscription success cannot guess between invoices');
check(!invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service), 'Ambiguous invoice creation cannot finalize or pay');
$stripeInvoice->metadata->ae_stripe_invoice_id = 12;
$deliver($stripeInvoice);
check(isset(ExtensionHelper::$payments['12:pi_bound']) && !isset(ExtensionHelper::$payments['13:pi_bound']), 'Stable subscription binding selects the older correct invoice');
$paymentIntent->id = 'pi_bound';
$paymentIntent->metadata->invoice_id = 13;
$before = ExtensionHelper::$applications;
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$applications === $before, 'The same payment cannot settle a second local invoice');
$paymentIntent->id = 'pi_customer_mismatch';
$paymentIntent->metadata->invoice_id = 12;
$paymentIntent->customer = 'cus_other';
$gateway->webhook(eventRequest($paymentIntent));
check(ExtensionHelper::$applications === $before, 'A payment for another customer cannot settle a local invoice');
$stripeInvoice->metadata->ae_stripe_invoice_id = 12;
Http::$handler = fn () => ['data' => [
    ['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_split1']],
    ['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_split2']]]];
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before, 'Split payments cannot be credited as a single full invoice payment');
$service->invoiceRows = [(object) ['invoice' => $invoice, 'created_at' => 1]];
$stripeInvoice->metadata = (object) [];
$invoiceUpdates = [];
Http::$handler = function ($verb, $path, $data) use (&$invoiceUpdates) { $invoiceUpdates[] = [$path, $data]; return []; };
check(invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service)
    && end($invoiceUpdates)[1]['metadata']['ae_stripe_invoice_id'] === 12, 'Invoice creation records stable local identity before payment');
$service->invoiceRows = [(object) ['invoice' => $secondInvoice, 'created_at' => 2]];
$invoiceUpdates = [];
Http::$handler = function ($verb, $path, $data) use (&$invoiceUpdates) {
    $invoiceUpdates[] = [$verb, $path, $data];
    return ['metadata' => ['ae_stripe_invoice_id' => 12]];
};
check(!invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service)
    && count($invoiceUpdates) === 1 && $invoiceUpdates[0][0] === 'get', 'Replayed unbound event cannot overwrite the current Stripe binding to a settled invoice');
Invoice::$rows = [];

$attemptProperty = 'ae_stripe_subscription_' . $service->id;
unset($user->props->values[$attemptProperty]);
$lost = true;
Http::$handler = function ($verb, $path, $data) use (&$lost) {
    if ($lost) { $lost = false; throw new RuntimeException('Schedule response lost'); }
    return ['subscription' => 'sub_recovered'];
};
$data = ['start_date' => 'now', 'phases' => [['price' => 1999]]];
rejects(fn () => invoke($gateway, 'subscriptionAttempt', $user, $setup, $service, $data), 'Schedule response lost');
$stored = json_decode($user->props->values[$attemptProperty], true);
$changed = ['start_date' => 'next day', 'phases' => [['price' => 9999]]];
check(invoke($gateway, 'subscriptionAttempt', $user, $setup, $service, $changed) === 'sub_recovered'
    && end(Http::$calls)[2] === $data, 'Schedule retry uses committed immutable payload despite later changes');
$before = count(Http::$calls);
check(invoke($gateway, 'subscriptionAttempt', $user, $setup, $service, $changed) === 'sub_recovered'
    && count(Http::$calls) === $before, 'Recorded remote subscription recovers after a local service-write failure');
$stored['created'] = time() - 86400;
$user->props->values[$attemptProperty] = json_encode($stored);
rejects(fn () => invoke($gateway, 'subscriptionAttempt', $user, $setup, $service, $data), 'HTTP 409');
check(count(Http::$calls) === $before, 'Expired unresolved schedule attempt cannot create a duplicate');
$newSetup = clone $setup;
$newSetup->id = 'seti_resubscribe';
$newSetup->created = 1700000101;
rejects(fn () => invoke($gateway, 'subscriptionAttempt', $user, $newSetup, $service, $data), 'HTTP 409');
check(count(Http::$calls) === $before, 'Different setup key cannot replace an unresolved schedule attempt');
// A completed attempt survives cancellation; a new SetupIntent starts a new lifecycle.
$stored['subscription'] = 'sub_recovered';
$user->props->values[$attemptProperty] = json_encode($stored);
$service->subscription_id = 'sub_recovered';
$service->properties->values['has_stripe_subscription'] = '1';
Http::$handler = function ($verb, $path) {
    return match ($path) {
        '/subscriptions/sub_recovered' => ['id' => 'sub_recovered', 'status' => 'canceled', 'created' => 1700000100],
        '/customers/cus_owner' => ['id' => 'cus_owner'],
        '/payment_methods/pm_test' => ['id' => 'pm_test', 'customer' => 'cus_owner'],
        '/products/search' => ['data' => [['id' => 'prod_test']]],
        '/subscription_schedules' => ['subscription' => 'sub_resubscribed'],
        default => throw new RuntimeException('Unexpected resubscribe request: ' . $path),
    };
};
$gateway->cancelSubscription($service);
check($service->subscription_id === null, 'Cancellation clears the subscription while retaining its completed attempt');
$before = count(Http::$calls);
invoke($gateway, 'setupSubscription', $newSetup);
$newAttempt = json_decode($user->props->values[$attemptProperty], true);
check($service->subscription_id === 'sub_resubscribed' && $newAttempt['key'] === 'sched_seti_resubscribe_41'
    && count(array_filter(array_slice(Http::$calls, $before), fn ($call) => $call[1] === '/subscription_schedules')) === 1,
    'Cancel then resubscribe with a new SetupIntent starts exactly one new schedule');
$before = count(Http::$calls);
check(invoke($gateway, 'subscriptionAttempt', $user, $newSetup, $service, $data) === 'sub_resubscribed'
    && count(Http::$calls) === $before, 'New completed lifecycle also remains idempotent');
$service->subscription_id = 'sub_test';

$intent->status = 'requires_action';
$intent->metadata->ae_stripe_consent = '0';
Http::$handler = fn () => $intent;
$gateway->consent(new Request(['intent' => 'pi_test', 'save' => false]));
check(end(Http::$calls)[0] === 'get', '3DS resume preserves existing consent without mutation');
$intent->metadata->ae_stripe_consent = '1';
$gateway->consent(new Request(['intent' => 'pi_test', 'save' => true]));
check(end(Http::$calls)[0] === 'get', 'Saved-choice 3DS resume preserves acceptance without mutation');
$intent->metadata->ae_stripe_consent = '0';
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 409');
$intent->status = 'succeeded';
Http::$handler = $oldHandler;
Gateway::$enabled = false;
