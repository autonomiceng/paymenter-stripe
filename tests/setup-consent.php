<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Http};

Gateway::$enabled = true;
$previousHandler = Http::$handler;
$agreementIntent = (object) ['id' => 'seti_consent', 'status' => 'requires_payment_method',
    'customer' => 'cus_owner', 'payment_method' => 'pm_consent', 'latest_attempt' => null,
    'metadata' => (object) ['is_billing_agreement' => '1', 'user_id' => '7']];
Http::$handler = function ($verb, $path, $data) use ($agreementIntent) {
    if ($path === '/setup_intents/seti_consent') {
        if ($verb === 'post') foreach ($data['metadata'] as $key => $value) $agreementIntent->metadata->$key = $value;
        return $agreementIntent;
    }
    if ($path === '/payment_methods/pm_consent') return ['id' => 'pm_consent', 'type' => 'link',
        'customer' => 'cus_owner', 'link' => ['email' => 'consent@example.test']];
    throw new RuntimeException('Unexpected setup consent request: ' . $path);
};
$before = count(Http::$calls);
rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_consent'])), 'HTTP 422');
rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_consent', 'consent' => false])), 'HTTP 422');
check(count(Http::$calls) === $before, 'Missing or false consent cannot reach Stripe');
$agreementIntent->status = 'succeeded';
$gateway->webhook(eventRequest($agreementIntent, type: 'setup_intent.succeeded'));
check(!isset(ExtensionHelper::$agreements['pm_consent']), 'Setup success without recorded consent cannot persist an agreement');
rejects(fn () => $gateway->setupAgreement(new Request(['setup_intent' => 'seti_consent'])), 'HTTP 409');
$agreementIntent->status = 'requires_payment_method';
Auth::$id = 99;
rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_consent', 'consent' => true])), 'HTTP 403');
Auth::$id = 7;
$agreementIntent->customer = 'cus_other';
rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_consent', 'consent' => true])), 'HTTP 403');
$agreementIntent->customer = 'cus_owner';
$gateway->setupConsent(new Request(['intent' => 'seti_consent', 'consent' => true]));
check($agreementIntent->metadata->stripe_saved_setup_consent === 'off_session_v1'
    && $agreementIntent->metadata->stripe_saved_setup_consent_user === '7'
    && preg_match('/^[0-9]+$/D', $agreementIntent->metadata->stripe_saved_setup_consent_at) === 1, 'Consent records terms, authenticated user and server timestamp');
$agreementIntent->status = 'succeeded';
$gateway->webhook(eventRequest($agreementIntent, type: 'setup_intent.succeeded'));
$gateway->setupAgreement(new Request(['setup_intent' => 'seti_consent']));
check(isset(ExtensionHelper::$agreements['pm_consent']), 'Recorded consent permits agreement on webhook and redirect');
$agreementIntent->metadata->stripe_saved_setup_consent_user = '99';
check(invoke($gateway, 'setupBillingAgreement', $agreementIntent) === false, 'Consent recorded for another user cannot persist');
rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_consent', 'consent' => true])), 'HTTP 409');
Gateway::$enabled = false;
rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_consent', 'consent' => true])), 'HTTP 409');
Http::$handler = $previousHandler;
