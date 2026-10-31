<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Notification, Service, User};
use Illuminate\Http\Client\ConnectionException;

$renewalFixture = function ($invoice, $stripe) {
    $GLOBALS['fakeConsole'] = true;
    $service = new Service;
    $service->subscription_id = null;
    $service->product->server = null;
    $service->plan->type = 'recurring';
    $service->billing_agreement_id = 4;
    $service->expires_at = $invoice->due_at = new DateTimeImmutable('2026-11-07');
    Service::$row = $service;
    $invoice->items = [(object) ['reference_type' => Service::class, 'reference_id' => $service->id]];
    $stripe->status = 'requires_payment_method';
    $stripe->beforeConfirm = fn ($intent) => $intent->last_payment_error = (object) ['code' => 'card_declined'];
    return $service;
};
$dueRetry = function () {
    $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
    $attempt['renewal']['next_at'] = time() - 1;
    User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
};
$savedService = Service::$row;
try {
    $run('renewal-cadence-cap', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry) {
        $renewalFixture($invoice, $stripe);
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
        $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
        check(($attempt['renewal']['state'] ?? null) === 'waiting' && $attempt['renewal']['next_at'] >= time() + 48 * 3600 - 2,
            'A native renewal decline durably schedules a new debit after 48 hours');
        check(count(Notification::$rows) === 1 && str_contains(Notification::$rows[0]['url'], '920'),
            'A declined renewal gives the customer one invoice payment link');
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 1, 'Early ticks never repeat a definite refusal');
        for ($retry = 1; $retry <= 3; $retry++) {
            $dueRetry(); $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(count($stripe->confirms) === $retry + 1 && count($stripe->intents) === $retry + 1,
                'Each due retry owns one new intent and one debit, including repeated ticks');
        }
        $dueRetry(); $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 4 && count(Notification::$rows) === 4,
            'Three retries is the cap and each refusal has one customer notice');
    });
    $run('renewal-balance-crash', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry) {
        $renewalFixture($invoice, $stripe);
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
        $dueRetry();
        ExtensionHelper::$payments['920:credit'] = ['amount' => 5, 'status' => 'succeeded'];
        $stripe->status = 'succeeded'; $stripe->beforeConfirm = null; $stripe->loseConfirm = true;
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 1500 && count($stripe->confirms) === 2,
            'A lost renewal retry response resumes that debit at the current balance without another debit');
        check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'Settled renewal stops its retry schedule');
    });
    foreach (['opt-out', 'agreement-change', 'account-default-only', 'cancelled', 'paid', 'cutoff', 'service-cancelled', 'new-calendar'] as $stop) {
        $run('renewal-stop-' . $stop, function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $stop) {
            $service = $renewalFixture($invoice, $stripe);
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $dueRetry();
            match ($stop) {
                'opt-out', 'account-default-only' => $service->billing_agreement_id = null,
                'agreement-change' => $service->billing_agreement_id = 5,
                'cancelled' => $invoice->status = 'cancelled',
                'paid' => ExtensionHelper::$payments['920:credit'] = ['amount' => 20, 'status' => 'succeeded'],
                'cutoff' => $GLOBALS['fakeToday'] = '2026-11-07',
                'service-cancelled' => $service->cancellation = (object) [],
                'new-calendar' => $service->expires_at = new DateTimeImmutable('2026-12-07'),
            };
            User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode(['version' => 'auto_pay_new_services_v2',
                'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4]);
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            unset($GLOBALS['fakeToday']);
            check(count($stripe->confirms) === 1, 'Renewal retry stops on ' . $stop . ' despite a new-service account default');
        });
    }
    $run('renewal-reservation-crash-optout', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry) {
        $service = $renewalFixture($invoice, $stripe);
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $dueRetry();
        $stripe->dropCreate = true;
        $gateway->retryChargeAttempts();
        $reserved = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
        check($reserved['renewal']['retries'] === 1 && $reserved['agreement_id'] === 4 && empty($reserved['intent']),
            'A crash before retry creation retains one reserved shared instruction');
        $service->billing_agreement_id = null;
        $stripe->status = 'succeeded'; $stripe->beforeConfirm = null;
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 1, 'Recovery rechecks service consent after retry reservation, before confirming');
    });
    $run('renewal-overlapping-tick', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry) {
        $renewalFixture($invoice, $stripe);
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $dueRetry();
        // A second tick runs after the first has committed the new reservation, before its HTTP creation.
        $stripe->beforeCreate = function () use ($gateway, $stripe) {
            $stripe->beforeCreate = null;
            $gateway->retryChargeAttempts();
        };
        $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 2, 'Overlapping ticks cannot turn one due retry into two declined debits');
    });
    $run('renewal-hard-refusal', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry) {
        $renewalFixture($invoice, $stripe);
        $stripe->beforeConfirm = fn ($intent) => $intent->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'stolen_card'];
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $dueRetry();
        $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 1 && count(Notification::$rows) === 1,
            'A hard card refusal stops automatic collection and gives the customer a payment link');
    });
    $run('renewal-customer-pay', function ($invoice, $stripe) use ($gateway, $renewalFixture) {
        $renewalFixture($invoice, $stripe);
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
        $GLOBALS['fakeConsole'] = false;
        $stripe->status = 'succeeded'; $stripe->beforeConfirm = null;
        check($gateway->charge($invoice, 20, new BillingAgreement) && count($stripe->charges) === 1,
            'An explicit customer Pay can pay immediately while the automatic renewal retry is waiting');
    });
    $run('renewal-domains-boundary', function ($invoice, $stripe) use ($gateway, $renewalFixture) {
        $renewalFixture($invoice, $stripe);
        $invoice->items = [(object) ['reference_type' => null, 'reference_id' => null]];
        paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
        $gateway->retryChargeAttempts();
        $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
        check(empty($attempt['renewal']) && count($stripe->confirms) === 1,
            'Retained Domains invoices cannot acquire a guessed native renewal authority');
    });
    $run('renewal-processing-uncertain', function ($invoice, $stripe) use ($gateway, $renewalFixture) {
        $renewalFixture($invoice, $stripe); $stripe->status = 'processing'; $stripe->beforeConfirm = null;
        $gateway->charge($invoice, 20, new BillingAgreement);
        $stripe->failure = fn ($verb, $path) => throw new ConnectionException('Cannot read Stripe');
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
        check(count($stripe->confirms) === 1 && $attempt['renewal']['state'] === 'active' && $attempt['funds_pending'],
            'Processing money and unreadable results stay in existing recovery, without arming a new debit');
    });
} finally {
    Service::$row = $savedService;
    unset($GLOBALS['fakeToday']);
}
