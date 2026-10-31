<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, Service, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Http};
use Paymenter\Extensions\Gateways\AeStripe\AeStripe;

(function () {
    $handler = Http::$handler; $enabled = Gateway::$enabled; $bundled = Gateway::$bundled;
    $agreements = ExtensionHelper::$agreements; $payments = ExtensionHelper::$payments;
    $invoiceRows = Invoice::$rows; $serviceRow = Service::$row; $properties = User::$row->props->values;
    $auth = Auth::$id;
    Gateway::$enabled = Gateway::$bundled = true; Auth::$id = 7;
    $gateway = new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test']);
    $setup = (object) ['id' => 'seti_converted', 'status' => 'succeeded', 'customer' => 'cus_owner',
        'payment_method' => 'pm_converted', 'latest_attempt' => null,
        'metadata' => (object) ['user_id' => '7', 'is_billing_agreement' => '1', 'stripe_saved_gateway' => '1',
            'stripe_saved_setup_consent' => 'off_session_v1', 'stripe_saved_setup_consent_at' => '1700000000', 'stripe_saved_setup_consent_user' => '7']];
    Http::$handler = function ($verb, $path) use ($setup) {
        if ($path === '/setup_intents/seti_converted') return $setup;
        if ($path === '/payment_methods/pm_converted') return ['id' => 'pm_converted', 'customer' => 'cus_owner', 'type' => 'link', 'link' => ['email' => 'converted@example.test']];
        throw new RuntimeException('Unexpected converted metadata request: ' . $path);
    };
    try {
        $gateway->webhook(eventRequest($setup, type: 'setup_intent.succeeded'));
        check(isset(ExtensionHelper::$agreements['pm_converted']), 'Converted sandbox SetupIntent keeps routing and recorded consent');
        [$returnClass, $returnMethod] = \Illuminate\Support\Facades\Route::$definitions['/extensions/stripe-saved/setup-agreement']['action'];
        (new $returnClass(['stripe_secret_key' => 'fake-secret']))->$returnMethod(new Request(['setup_intent' => 'seti_converted']));
        check(ExtensionHelper::$agreements['pm_converted']['gateway'] === 'AeStripe', 'Converted setup redirect retains the new local gateway identity');
        $setup->status = 'requires_action';
        $setup->metadata->stripe_saved_setup_consent_user = '8';
        rejects(fn () => $gateway->setupConsent(new Request(['intent' => 'seti_converted', 'consent' => true])), 'HTTP 409');
        $setup->metadata->stripe_saved_setup_consent_user = '7';
        check($gateway->setupConsent(new Request(['intent' => 'seti_converted', 'consent' => true]))['data']['ok'], 'Converted setup authentication retry keeps the original consent');
        $invoice = clone Invoice::$row; $invoice->id = 912; Invoice::$rows[912] = $invoice;
        $legacyData = ['description' => 'invoices.payment_for_invoice', 'amount' => 2000, 'currency' => 'usd',
            'automatic_payment_methods' => ['enabled' => 'true'], 'metadata' => ['invoice_id' => 912, 'stripe_saved_gateway' => '1'], 'customer' => 'cus_owner'];
        $attempt = ['key' => 'checkout_converted', 'digest' => hash('sha256', json_encode($legacyData, JSON_THROW_ON_ERROR)), 'created' => time()];
        User::$row->props->values['ae_stripe_checkout_912'] = json_encode($attempt);
        $intent = (object) ['id' => 'pi_converted', 'confirmation_method' => 'automatic', 'currency' => 'usd', 'amount' => 2000, 'status' => 'requires_payment_method', 'customer' => 'cus_owner', 'client_secret' => 'pi_converted_secret',
            'metadata' => (object) ['invoice_id' => 912, 'stripe_saved_gateway' => '1', 'stripe_saved_consent' => '1']];
        Http::$handler = function ($verb, $path, $data) use (&$intent, $legacyData) {
            if ($path === '/payment_intents/search') return ['data' => []];
            if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
            if ($path === '/customer_sessions') return ['client_secret' => 'cuss_converted'];
            if ($path === '/payment_intents') {
                if (end(Http::$calls)[3]['Idempotency-Key'] === 'checkout_converted') {
                    check($data === $legacyData, 'Converted unresolved checkout retries its exact original Stripe payload and key');
                    return $intent;
                }
                check($intent->status === 'canceled' && !isset($data['confirmation_method']), 'Converted checkout is canceled before replacement with the automatic Elements flow');
                $intent = (object) array_replace((array) $intent, ['id' => 'pi_replacement', 'status' => 'requires_payment_method', 'metadata' => (object) $data['metadata']]);
                return $intent;
            }
            if ($path === '/payment_intents/' . $intent->id . '/cancel') { $intent->status = 'canceled'; return $intent; }
            if ($path === '/payment_intents/' . $intent->id) {
                if ($verb === 'post') check($data['metadata'] === ['ae_stripe_consent' => '0'], 'New consent writes use only AE Stripe metadata');
                return $intent;
            }
            throw new RuntimeException('Unexpected converted checkout request: ' . $path);
        };
        $storedAttempt = User::$row->props->values['ae_stripe_checkout_912'];
        User::$row->props->values['ae_stripe_checkout_912'] = json_encode(array_replace($attempt, ['digest' => str_repeat('0', 64)]));
        paymentStops(fn () => $gateway->pay($invoice, 20));
        User::$row->props->values['ae_stripe_checkout_912'] = $storedAttempt;
        check($gateway->pay($invoice, 20)['data']['customerSessionSecret'] === 'cuss_converted', 'Converted unresolved checkout can render again');
        check($gateway->pay($invoice, 20)['data']['customerSessionSecret'] === 'cuss_converted', 'Converted resolved checkout reuses its existing intent');
        $gateway->consent(new Request(['intent' => $intent->id, 'save' => false]));
        $intent->metadata->ae_stripe_gateway = '0';
        rejects(fn () => $gateway->consent(new Request(['intent' => $intent->id, 'save' => true])), 'HTTP 403');
        unset($intent->metadata->ae_stripe_gateway);
        $intent->metadata->stripe_saved_gateway = '0';
        rejects(fn () => $gateway->consent(new Request(['intent' => $intent->id, 'save' => true])), 'HTTP 403');
        $intent->metadata->stripe_saved_gateway = '1';
        $intent->id = 'pi_converted_subscription'; $intent->status = 'succeeded';
        $intent->amount = $intent->amount_received = 2000; $intent->currency = 'usd';
        $service = Service::$row = new Service;
        $service->invoiceRows = [(object) ['invoice' => $invoice]];
        $subscriptionInvoice = (object) ['id' => 'in_converted', 'status' => 'draft', 'currency' => 'usd', 'amount_paid' => 2000,
            'metadata' => (object) ['stripe_saved_invoice_id' => 912],
            'parent' => (object) ['type' => 'subscription_details', 'subscription_details' => (object) ['subscription' => $service->subscription_id,
                'metadata' => (object) ['stripe_saved_gateway' => '1']]]];
        Http::$handler = function ($verb, $path) use ($subscriptionInvoice, $intent) {
            if ($verb === 'get' && $path === '/invoices/in_converted') return $subscriptionInvoice;
            if ($path === '/invoice_payments') return ['data' => [['status' => 'paid', 'amount_paid' => 2000, 'payment' => ['type' => 'payment_intent', 'payment_intent' => $intent->id]]]];
            if ($path === '/payment_intents/' . $intent->id) return $intent;
            if (in_array($path, ['/invoices/in_converted/finalize', '/invoices/in_converted/pay'], true)) return [];
            throw new RuntimeException('Unexpected converted subscription request: ' . $path);
        };
        $subscriptions = new AeStripe(['stripe_secret_key' => 'fake-secret', 'stripe_webhook_secret' => 'whsec_test', 'stripe_use_subscriptions' => true]);
        $calls = count(Http::$calls);
        $subscriptions->webhook(eventRequest($subscriptionInvoice, type: 'invoice.created'));
        check(count(Http::$calls) === $calls + 3, 'Converted subscription keeps its existing invoice binding and can finalize');
        $subscriptions->webhook(eventRequest($subscriptionInvoice, type: 'invoice.payment_succeeded'));
        check(ExtensionHelper::$payments['912:pi_converted_subscription'] === ['gateway' => 'AeStripe', 'amount' => 20.0, 'status' => 'succeeded', 'is_credit_transaction' => false], 'Converted nested subscription marker and invoice binding settle the same local invoice');
    } finally {
        Http::$handler = $handler; Gateway::$enabled = $enabled; Gateway::$bundled = $bundled; Auth::$id = $auth;
        ExtensionHelper::$agreements = $agreements; ExtensionHelper::$payments = $payments;
        Invoice::$rows = $invoiceRows; Service::$row = $serviceRow; User::$row->props->values = $properties;
    }
})();
