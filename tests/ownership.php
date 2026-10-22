<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, BillingAgreement};
use Illuminate\Support\Facades\{Http, Log};

Gateway::$enabled = Gateway::$bundledEnabled = true;
$invoice->status = 'pending';
$invoice->currency_code = 'usd';
$user->props->customer = 'cus_owner';
$owned = (object) ['id' => 'pi_ownership', 'amount' => 2000, 'currency' => 'usd', 'customer' => 'cus_owner',
    'metadata' => (object) ['invoice_id' => 12, 'stripe_saved_gateway' => '1']];
$before = ExtensionHelper::$applications;
check($gateway->webhook(eventRequest($owned))['status'] === 200 && ExtensionHelper::$applications === $before + 1,
    'Marked intent is recorded while bundled is enabled');
unset($owned->metadata->stripe_saved_gateway);
$owned->id = 'pi_bundled';
foreach (['payment_intent.succeeded', 'payment_intent.processing', 'payment_intent.payment_failed'] as $type) {
    $before = ExtensionHelper::$applications;
    check($gateway->webhook(eventRequest($owned, type: $type))['status'] === 200
        && ExtensionHelper::$applications === $before, 'Unmarked intent ignored while bundled enabled: ' . $type);
    check(end(Log::$contexts) === ['event_type' => $type, 'invoice_id' => 12], 'Ignored event logs only type and invoice id');
}
$before = count(Http::$calls);
$bundledSetup = (object) ['id' => 'seti_bundled', 'metadata' => (object) ['user_id' => 7, 'is_billing_agreement' => '1']];
check($gateway->webhook(eventRequest($bundledSetup, type: 'setup_intent.succeeded'))['status'] === 200
    && count(Http::$calls) === $before, 'Bundled-style setup is not adopted while bundled enabled');
foreach (['invoice.created', 'invoice.payment_succeeded', 'subscription_schedule.canceled'] as $type) {
    check($gateway->webhook(eventRequest((object) ['metadata' => (object) []], type: $type))['data']['ignored'],
        'Unmarked subscription event ignored while bundled enabled: ' . $type);
}
check(invoke($gateway, 'ownsWebhookObject', (object) ['parent' => (object) ['subscription_details' => (object) [
    'metadata' => (object) ['stripe_saved_gateway' => '1']]]], 'invoice.created'), 'Subscription phase metadata identifies fork invoice');
Gateway::$bundledEnabled = false;
$gateway->webhook(eventRequest($owned));
$before = ExtensionHelper::$applications;
$gateway->webhook(eventRequest($owned));
check(isset(ExtensionHelper::$payments['12:pi_bundled']) && ExtensionHelper::$applications === $before,
    'Sole owner records an in-flight bundled intent exactly once');
Gateway::$bundled = false;
check(invoke($gateway, 'ownsWebhookObject', $owned, 'payment_intent.succeeded'), 'Missing bundled row permits in-flight intent');
Gateway::$bundled = true;

$oldHandler = Http::$handler;
Http::$handler = fn () => ['fee' => 10, 'currency' => 'usd'];
ExtensionHelper::$payments['12:pi_foreign'] = ['gateway' => 'Stripe', 'status' => 'succeeded'];
$before = count(Http::$calls);
$gateway->webhook(eventRequest((object) ['payment_intent' => 'pi_foreign', 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
check(count(Http::$calls) === $before && !isset(ExtensionHelper::$fees['pi_foreign']), 'Fee updates never touch bundled transactions after cutover');
Gateway::$bundledEnabled = true;
$gateway->webhook(eventRequest((object) ['payment_intent' => 'pi_ownership', 'balance_transaction' => 'bt_test'], type: 'charge.updated'));
check(ExtensionHelper::$fees['pi_ownership'] === 0.1, 'Recorded fork ownership permits fee update even without charge metadata');
Http::$handler = fn () => ['id' => 'cus_owner'];
$gateway->charge($invoice, 20, new BillingAgreement);
check(end(Http::$calls)[2]['metadata'] === ['invoice_id' => 12, 'billing_agreement_id' => 4, 'stripe_saved_gateway' => '1'],
    'Off-session intent preserves metadata and marks gateway');
Http::$handler = fn () => ['id' => 'seti_created'];
$gateway->createBillingAgreement($user);
check(end(Http::$calls)[2]['metadata'] === ['user_id' => 7, 'is_billing_agreement' => '1', 'stripe_saved_gateway' => '1'],
    'Account setup marks gateway and preserves purpose');
Http::$handler = $oldHandler;
Gateway::$bundledEnabled = false;
Gateway::$enabled = false;
