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
Http::$handler = fn () => ['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_bound']]]];
$stripeInvoice->metadata = (object) [];
$before = ExtensionHelper::$applications;
$deliver($stripeInvoice);
check(ExtensionHelper::$applications === $before, 'Unbound subscription success cannot guess between invoices');
check(!invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service), 'Ambiguous invoice creation cannot finalize or pay');
$stripeInvoice->metadata->stripe_saved_invoice_id = 12;
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
$stripeInvoice->metadata->stripe_saved_invoice_id = 12;
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
    && end($invoiceUpdates)[1]['metadata']['stripe_saved_invoice_id'] === 12, 'Invoice creation records stable local identity before payment');
$service->invoiceRows = [(object) ['invoice' => $secondInvoice, 'created_at' => 2]];
$invoiceUpdates = [];
Http::$handler = function ($verb, $path, $data) use (&$invoiceUpdates) {
    $invoiceUpdates[] = [$verb, $path, $data];
    return ['metadata' => ['stripe_saved_invoice_id' => 12]];
};
check(!invoke($gateway, 'bindSubscriptionInvoice', $stripeInvoice, $service)
    && count($invoiceUpdates) === 1 && $invoiceUpdates[0][0] === 'get', 'Replayed unbound event cannot overwrite the current Stripe binding to a settled invoice');
Invoice::$rows = [];

$attemptProperty = 'stripe_saved_subscription_' . $service->id;
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

$intent->status = 'requires_action';
$intent->metadata->stripe_saved_consent = '0';
Http::$handler = fn () => $intent;
$gateway->consent(new Request(['intent' => 'pi_test', 'save' => false]));
check(end(Http::$calls)[0] === 'get', '3DS resume preserves existing consent without mutation');
$intent->metadata->stripe_saved_consent = '1';
$gateway->consent(new Request(['intent' => 'pi_test', 'save' => true]));
check(end(Http::$calls)[0] === 'get', 'Saved-choice 3DS resume preserves acceptance without mutation');
$intent->metadata->stripe_saved_consent = '0';
rejects(fn () => $gateway->consent(new Request(['intent' => 'pi_test', 'save' => true])), 'HTTP 409');
$intent->status = 'succeeded';
Http::$handler = $oldHandler;
Gateway::$enabled = false;
