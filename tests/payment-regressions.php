<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Gateway, Invoice};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Log};

(function () use ($gateway) {
    $invoice = Invoice::$row;
    $user = $invoice->user;
    $oldStatus = $invoice->status;
    $oldCurrency = $invoice->currency_code;
    $oldCustomer = $user->props->customer;
    $oldHandler = Http::$handler;
    $oldEnabled = Gateway::$enabled;
    Gateway::$enabled = true;
    $user->props->customer = 'cus_owner';
    $invoice->currency_code = 'usd';
    $invoice->status = 'paid';
    $settled = (object) ['id' => 'pi_late_success', 'status' => 'succeeded', 'customer' => 'cus_owner',
        'amount' => 2000, 'currency' => 'usd',
        'metadata' => (object) ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1']];
    $before = ExtensionHelper::$applications;
    $warnings = count(Log::$warnings);
    $calls = count(Http::$calls);
    $gateway->webhook(eventRequest($settled));
    $gateway->webhook(eventRequest($settled));
    $gateway->webhook(eventRequest($settled, type: 'payment_intent.processing'));
    $gateway->webhook(eventRequest($settled, type: 'payment_intent.payment_failed'));
    check((ExtensionHelper::$payments[$invoice->id . ':pi_late_success'] ?? null) === [
        'gateway' => 'StripeSaved', 'amount' => 20.0, 'status' => 'succeeded',
    ], 'Late successful charge remains visible as a native transaction');
    check(ExtensionHelper::$applications === $before + 1 && $invoice->status === 'paid',
        'Late success and delayed events preserve one payment and the paid invoice');
    check(count(Log::$warnings) === $warnings + 1 && end(Log::$warnings) === [
        'StripeSaved payment received for paid invoice', ['invoice_id' => $invoice->id],
    ], 'Late charge warns once without customer data');
    check(count(Http::$calls) === $calls, 'Late success never refunds or changes Stripe');

    $invoice->status = 'pending';
    foreach (['customer mismatch', 'currency mismatch'] as $reason) {
        $settled->id = $reason === 'customer mismatch' ? 'pi_review_customer' : 'pi_review_currency';
        $settled->customer = $reason === 'customer mismatch' ? 'cus_foreign' : 'cus_owner';
        $settled->currency = $reason === 'currency mismatch' ? 'jpy' : 'usd';
        $settled->amount = 200;
        $settled->amount_received = 123;
        $before = ExtensionHelper::$applications;
        $records = count(Audit::$rows);
        $locks = count(DB::$modelLocks);
        $gateway->webhook(eventRequest($settled));
        $gateway->webhook(eventRequest($settled));
        $gateway->webhook(eventRequest($settled, type: 'payment_intent.processing'));
        $gateway->webhook(eventRequest($settled, type: 'payment_intent.payment_failed'));
        check(ExtensionHelper::$applications === $before && $invoice->status === 'pending',
            'Refused successful payment cannot settle the invoice: ' . $reason);
        check(count(Audit::$rows) === $records + 1, 'One durable staff record per refused success: ' . $reason);
        check(array_slice(DB::$modelLocks, $locks, 4) === [\App\Models\User::class, Invoice::class, \App\Models\InvoiceTransaction::class, Audit::class],
            'Refused success uses the settlement lock order and a current audit read');
        $record = end(Audit::$rows);
        check($record['event'] === 'stripe_saved_payment_review' && $record['new_values']['payment_intent_id'] === $settled->id
            && $record['auditable_type'] === Invoice::class && $record['auditable_id'] === $invoice->id
            && $record['new_values']['amount_minor'] === 123
            && $record['new_values']['currency'] === $settled->currency
            && $record['new_values']['reason'] === $reason,
            'Staff record preserves the payment ID, owner reference and original units: ' . $reason);
        foreach ([$settled->id, '123', $settled->currency, $reason, 'refund', 'credit'] as $needle) {
            check(str_contains(implode(' ', $record['new_values']), $needle), 'Native invoice audit detail contains ' . $needle);
        }
        check(!str_contains(json_encode($record), 'cus_foreign') && !str_contains(json_encode($record), $user->email),
            'Staff record excludes customer details');
    }
    foreach (['processing', 'payment_failed'] as $event) {
        $settled->id = 'pi_unsettled_' . $event;
        $records = count(Audit::$rows);
        $before = ExtensionHelper::$applications;
        $gateway->webhook(eventRequest($settled, type: 'payment_intent.' . $event));
        check(count(Audit::$rows) === $records && ExtensionHelper::$applications === $before,
            'An unsettled currency mismatch cannot create a successful charge review');
        $settled->customer = 'cus_foreign';
        $gateway->webhook(eventRequest($settled, type: 'payment_intent.' . $event));
        check(count(Audit::$rows) === $records && ExtensionHelper::$applications === $before,
            'An unsettled customer mismatch cannot create a successful charge review');
        $settled->customer = 'cus_owner';
    }
    // A persistence failure must remain retryable instead of acknowledging lost money.
    $settled->id = 'pi_review_retry';
    Audit::$fail = true;
    rejects(fn () => $gateway->webhook(eventRequest($settled)), 'Review record unavailable');
    $records = count(Audit::$rows);
    $gateway->webhook(eventRequest($settled));
    $gateway->webhook(eventRequest($settled));
    check(count(Audit::$rows) === $records + 1, 'Staff record persistence recovers once on retry');
    // A later valid payment has its own cycle, even after another payment was refused.
    unset($settled->amount_received);
    $settled->id = 'pi_after_review';
    $settled->currency = 'USD';
    $settled->amount = 500;
    $before = ExtensionHelper::$applications;
    $gateway->webhook(eventRequest($settled));
    $gateway->webhook(eventRequest($settled));
    check(ExtensionHelper::$applications === $before + 1
        && ExtensionHelper::$payments[$invoice->id . ':pi_after_review']['amount'] === 5.0,
        'A refused charge does not block the next valid payment');

    foreach ([new ConnectionException('connection'), new ConnectionException('timeout'), new RequestException(401),
        new RequestException(403), new RequestException(429), new RequestException(500), new RequestException(503),
        new RequestException(404), new RequestException(404, ['error' => ['code' => 'invalid_request']]),
        new RequestException(400, ['error' => ['code' => 'resource_missing']]), new RuntimeException('decode failure')] as $failure) {
        Http::$handler = function ($verb, $path) use ($failure) {
            if ($verb === 'get' && $path === '/customers/cus_owner') throw $failure;
            if ($verb === 'post' && $path === '/customers') return ['id' => 'cus_replacement'];
            throw new RuntimeException('Unexpected customer request');
        };
        $before = count(Http::$calls);
        $caught = null;
        try { invoke($gateway, 'createOrGetStripeCustomer', $user, false); } catch (Throwable $e) { $caught = $e; }
        check($caught === $failure, 'Customer lookup propagates the original failure: ' . get_class($failure));
        check($user->props->customer === 'cus_owner' && count(Http::$calls) === $before + 1,
            'Failed lookup preserves customer binding and makes no creation request');
    }
    foreach (['deleted', 'missing', 'active', 'deleted_false'] as $state) {
        $user->props->customer = 'cus_owner';
        Http::$handler = function ($verb, $path) use ($state) {
            if ($verb === 'get' && $path === '/customers/cus_owner') {
                if ($state === 'missing') throw new RequestException(404, ['error' => ['code' => 'resource_missing']]);
                return $state === 'active' ? ['id' => 'cus_owner'] : ['id' => 'cus_owner', 'deleted' => $state === 'deleted'];
            }
            if ($verb === 'get' && $path === '/customers/cus_replacement') return ['id' => 'cus_replacement'];
            if ($verb === 'post' && $path === '/customers') return ['id' => 'cus_replacement'];
            throw new RuntimeException('Unexpected customer request');
        };
        $before = count(Http::$calls);
        $customer = invoke($gateway, 'createOrGetStripeCustomer', $user, false);
        $replace = in_array($state, ['deleted', 'missing']);
        check($customer->id === ($replace ? 'cus_replacement' : 'cus_owner')
            && $user->props->customer === $customer->id, 'Only definitive absence replaces the customer: ' . $state);
        invoke($gateway, 'createOrGetStripeCustomer', $user, false);
        check(count(Http::$calls) === $before + ($replace ? 3 : 2), 'Repeated lookup creates at most one customer: ' . $state);
    }
    $user->props->customer = 'cus_owner';
    $legacy = (object) ['id' => 'pi_legacy_consent', 'status' => 'requires_confirmation', 'customer' => 'cus_owner',
        'setup_future_usage' => 'off_session',
        'metadata' => (object) ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1', 'stripe_saved_consent' => '1']];
    Http::$handler = function ($verb, $path, $data) use ($legacy) {
        if ($path !== '/payment_intents/pi_legacy_consent') throw new RuntimeException('Unexpected consent request');
        if ($verb === 'post') {
            if (array_key_exists('setup_future_usage', $data)) $legacy->setup_future_usage = $data['setup_future_usage'] === '' ? null : $data['setup_future_usage'];
            $legacy->metadata->stripe_saved_consent = $data['metadata']['stripe_saved_consent'];
            $legacy->payment_method_options = json_decode(json_encode($data['payment_method_options']));
        }
        return $legacy;
    };
    foreach ([false, false, true, false] as $save) {
        $gateway->consent(new Request(['intent' => $legacy->id, 'save' => $save]));
        check($legacy->setup_future_usage === null, 'Consent update clears previous-release top-level future usage');
        check($legacy->metadata->stripe_saved_consent === ($save ? '1' : '0')
            && $legacy->payment_method_options->card->setup_future_usage === ($save ? 'off_session' : '')
            && $legacy->payment_method_options->link->setup_future_usage === ($save ? 'off_session' : ''),
            'Consent cycles preserve card and Link preferences');
    }
    $legacy->status = 'requires_action';
    $legacy->setup_future_usage = 'off_session';
    $legacy->metadata->stripe_saved_consent = '1';
    $before = count(Http::$calls);
    rejects(fn () => $gateway->consent(new Request(['intent' => $legacy->id, 'save' => false])), 'HTTP 409');
    $gateway->consent(new Request(['intent' => $legacy->id, 'save' => true]));
    check(count(Http::$calls) === $before + 2 && $legacy->setup_future_usage === 'off_session',
        'Legacy authentication refuses withdrawal and resumes without any update');
    $invoice->status = $oldStatus;
    $invoice->currency_code = $oldCurrency;
    $user->props->customer = $oldCustomer;
    Http::$handler = $oldHandler;
    Gateway::$enabled = $oldEnabled;
})();
