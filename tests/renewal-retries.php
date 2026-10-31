<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{BillingAgreement, Notification, Service, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\DB;

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
// Initial failures may be delivered by native cron or consumed after the extension notifies.
$initialRefusal = function ($invoice) use ($gateway) {
    try { $gateway->charge($invoice, 20, new BillingAgreement); }
    catch (\App\Exceptions\DisplayException $e) {}
};
$savedService = Service::$row;
try {
    foreach (['2026-10-31', '2026-10-30'] as $date) {
        foreach (['succeeded', 'requires_payment_method'] as $status) {
            $run('renewal-overdue-once-' . $date . '-' . $status, function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $date, $status) {
                $service = $renewalFixture($invoice, $stripe);
                $service->expires_at = $invoice->due_at = new DateTimeImmutable($date);
                $stripe->status = $status;
                if ($status === 'succeeded') $stripe->beforeConfirm = null;
                $cron = require __DIR__ . '/fixtures/native-cron-charge.php';
                \App\Helpers\NotificationHelper::$failures = 0;
                DB::transaction(fn () => $cron->run($invoice, (object) ['billingAgreement' => new BillingAgreement]));
                check(count($stripe->confirms) === 1 && count($stripe->charges) === ($status === 'succeeded' ? 1 : 0),
                    'A renewal due today or earlier gets its one native debit attempt: ' . $date . '/' . $status);
                if ($status === 'requires_payment_method') $dueRetry();
                $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
                check(count($stripe->confirms) === 1, 'An overdue renewal never starts an automatic retry');
                check(count(Notification::$rows) === ($status === 'succeeded' ? 0 : 1)
                    && \App\Helpers\NotificationHelper::$failures === 0,
                    'An overdue refusal gets exactly one linked notice through the native cron catch');
            });
        }
    }
    foreach (['opted-out', 'authorized', 'missing-context'] as $mode) {
        $run('renewal-expired-create-' . $mode, function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal, $mode) {
            $service = $renewalFixture($invoice, $stripe); $initialRefusal($invoice); $dueRetry();
            $stripe->dropCreate = true; $gateway->retryChargeAttempts();
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $expectedRenewal = $attempt['renewal']; $oldKey = $attempt['key'];
            $attempt['created'] = time() - 24 * 3600;
            if ($mode === 'missing-context') unset($attempt['renewal']);
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            if ($mode === 'opted-out') $service->billing_agreement_id = null;
            $stripe->dropCreate = true; $gateway->retryChargeAttempts(); // Crash again, after rotation commits.
            $rotated = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($rotated['key'] !== $oldKey && $rotated['automatic_renewal']
                && ($rotated['renewal'] ?? null) === ($mode === 'missing-context' ? null : $expectedRenewal),
                'An interrupted creation rotation durably preserves automatic authority and the original retry count');
            $stripe->status = 'succeeded'; $stripe->beforeConfirm = function () use ($expectedRenewal) {
                $current = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                check($current['renewal'] === $expectedRenewal, 'Expired creation carries the original renewal authority, cutoff and retry count to confirm');
            };
            $gateway->retryChargeAttempts();
            if ($mode === 'authorized') {
                check(count($stripe->charges) === 1 && count($stripe->confirms) === 2,
                    'An authorized expired creation resumes one scheduled renewal retry');
            } else {
                check(!$stripe->charges && count($stripe->confirms) === 1,
                    'Expired automatic creation cannot charge with ' . $mode . ' renewal authority');
            }
        });
    }
    foreach (['result', 'http-402'] as $path) {
        $run('renewal-refusal-lock-' . $path, function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal, $path) {
            $renewalFixture($invoice, $stripe); $initialRefusal($invoice); $dueRetry();
            $stripe->beforeConfirm = function ($intent) use ($gateway, $invoice, $stripe) {
                $stripe->beforeConfirm = null;
                $intent->last_payment_error = (object) ['code' => 'card_declined', 'advice_code' => 'do_not_try_again'];
                $compete = function () use (&$compete, $gateway, $invoice) {
                    if (DB::$locked) { DB::$afterTransaction = $compete; return; }
                    try { $gateway->charge($invoice, 20, new BillingAgreement); }
                    catch (\App\Exceptions\DisplayException $e) {}
                };
                DB::$afterTransaction = $compete;
            };
            if ($path === 'http-402') {
                $handler = \Illuminate\Support\Facades\Http::$handler;
                \Illuminate\Support\Facades\Http::$handler = function ($verb, $url, $data) use ($handler) {
                    $result = $handler($verb, $url, $data);
                    if (str_ends_with($url, '/confirm')) throw new RequestException(402, ['error' => ['code' => 'card_declined']]);
                    return $result;
                };
            }
            $gateway->retryChargeAttempts();
            check(count($stripe->confirms) === 2,
                'A ' . $path . ' refusal commits no-retry state before a competing automatic caller can confirm');
        });
    }
    foreach (['result', 'http-402'] as $path) {
        $run('renewal-final-refusal-crash-' . $path, function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal, $path) {
            $renewalFixture($invoice, $stripe); $initialRefusal($invoice);
            for ($retry = 1; $retry <= 2; ++$retry) { $dueRetry(); $gateway->retryChargeAttempts(); }
            $dueRetry();
            $stripe->beforeConfirm = fn ($intent) => $intent->last_payment_error = (object) [
                'code' => 'card_declined', 'advice_code' => 'do_not_try_again',
            ];
            $handler = \Illuminate\Support\Facades\Http::$handler;
            \Illuminate\Support\Facades\Http::$handler = function ($verb, $url, $data) use ($handler, $path) {
                $result = $handler($verb, $url, $data);
                if (str_ends_with($url, '/confirm')) {
                    // Stripe has refused; fail the next local attempt write, rolling back the HTTP transaction.
                    \App\Models\Properties::$beforeWrite = function ($key) {
                        if (($key['key'] ?? null) !== 'ae_stripe_checkout_920') return;
                        \App\Models\Properties::$beforeWrite = null;
                        throw new \PDOException('Connection lost before refusal commit');
                    };
                    if ($path === 'http-402') throw new RequestException(402, ['error' => ['code' => 'card_declined']]);
                }
                return $result;
            };
            $gateway->retryChargeAttempts();
            $claimed = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(count($stripe->confirms) === 4 && $claimed['renewal']['retries'] === 3
                && $claimed['renewal']['state'] === 'active' && !empty($claimed['confirmation']['key']),
                'A failed final-refusal commit preserves the fourth confirmation claim for ' . $path);
            \Illuminate\Support\Facades\Http::$handler = $handler;
            $stripe->status = 'succeeded'; $stripe->beforeConfirm = null;
            // The worker already selected its agreement. A second DB loss while consuming refusal must keep that claim too.
            \App\Models\Properties::$beforeWrite = function ($key, $data) {
                if (($key['key'] ?? null) !== 'ae_stripe_checkout_920' || !empty(json_decode($data['value'], true)['confirmation'])) return;
                \App\Models\Properties::$beforeWrite = null;
                throw new \PDOException('Connection lost while consuming refusal');
            };
            $gateway->charge($invoice, 20, new BillingAgreement);
            $afterRollback = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($afterRollback['confirmation']['key'] === $claimed['confirmation']['key'] && !$stripe->charges,
                'Failed refusal consumption retains the committed confirmation identity for ' . $path);
            $gateway->charge($invoice, 20, new BillingAgreement); // Competing caller resumes at the public gateway boundary.
            check(count($stripe->confirms) === 4 && count(array_unique(array_column($stripe->confirms, 0))) === 4 && !$stripe->charges,
                'A competing automatic caller cannot send a fifth debit after final-refusal rollback for ' . $path);
            $stopped = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($stopped['renewal']['state'] === 'stopped' && $stopped['renewal']['retries'] === 3
                && empty($stopped['confirmation']) && $stopped['failure_notified'],
                'The retrieved final refusal commits stopped collection and its notice for ' . $path);
        });
    }
    $run('renewal-confirmation-claim', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $renewalFixture($invoice, $stripe); $initialRefusal($invoice); $dueRetry();
        $stripe->beforeCreate = function () use ($gateway, $invoice, $stripe) {
            $stripe->beforeCreate = null;
            $compete = function () use (&$compete, $gateway, $invoice, $stripe) {
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                if (DB::$locked || empty($attempt['confirmation'])) { DB::$afterTransaction = $compete; return; }
                $claimed = $attempt['confirmation']['key'];
                $gateway->charge($invoice, 20, new BillingAgreement);
                check(end($stripe->confirms)[0] === $claimed, 'A competing automatic caller resumes the committed confirmation identity');
            };
            DB::$afterTransaction = $compete;
        };
        $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 2, 'The claimed retry has one refusal despite an overlapping automatic caller');
    });
    $run('renewal-credit-contract', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $renewalFixture($invoice, $stripe); $initialRefusal($invoice);
        $original = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true)['renewal'];
        DB::transaction(function () use ($gateway, $invoice) {
            User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            \App\Models\Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            check($gateway->cancelInvoicePayments($invoice), 'Credit retires the refused payable intent');
            ExtensionHelper::addPayment($invoice, null, 5, null, 'credit', isCreditTransaction: true);
        });
        $after = json_decode(User::$row->props->values['ae_stripe_checkout_920'] ?? 'null', true);
        check(($after['renewal'] ?? null) === $original, 'Partial credit preserves the waiting schedule, count and cutoff');
        check($stripe->intents['pi_test1']->status === 'canceled', 'Partial credit still invalidates the old payable intent');
        $dueRetry(); $stripe->status = 'succeeded'; $stripe->beforeConfirm = null;
        $gateway->retryChargeAttempts();
        check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 1500, 'The due retry collects only the balance after contract-based partial credit');
    });
    $run('renewal-full-credit-contract', function ($invoice, $stripe) use ($gateway, $renewalFixture, $initialRefusal) {
        $renewalFixture($invoice, $stripe); $initialRefusal($invoice);
        ExtensionHelper::$simulateNativeListeners = true;
        DB::transaction(function () use ($gateway, $invoice) {
            User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
            \App\Models\Invoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();
            check($gateway->cancelInvoicePayments($invoice), 'Full credit safely retires the refused payable intent');
            ExtensionHelper::addPayment($invoice, null, 20, null, 'credit', isCreditTransaction: true);
        });
        ExtensionHelper::$simulateNativeListeners = false;
        $gateway->retryChargeAttempts();
        check(!isset(User::$row->props->values['ae_stripe_checkout_920']) && count($stripe->confirms) === 1,
            'Full credit ends the retained renewal schedule without a new debit');
    });
    $run('renewal-native-cron-notice', function ($invoice, $stripe) use ($gateway, $renewalFixture, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $cron = require __DIR__ . '/fixtures/native-cron-charge.php';
        \App\Helpers\NotificationHelper::$failures = 0;
        DB::transaction(fn () => $cron->run($invoice, (object) ['billingAgreement' => new BillingAgreement]));
        check(count(Notification::$rows) === 1 && \App\Helpers\NotificationHelper::$failures === 0,
            'The native cron catch sends no second notice for an already-notified renewal refusal');
        $invoice->status = 'cancelled';
        DB::transaction(fn () => $cron->run($invoice, (object) ['billingAgreement' => new BillingAgreement]));
        check(\App\Helpers\NotificationHelper::$failures === 1, 'An unrelated exception keeps the native catch despite an older notified renewal refusal');
        $invoice->status = 'pending';
        // A non-renewal failure still goes through that same native catch.
        unset(User::$row->props->values['ae_stripe_checkout_920']); $invoice->items = [];
        DB::transaction(fn () => $cron->run($invoice, (object) ['billingAgreement' => new BillingAgreement]));
        check(\App\Helpers\NotificationHelper::$failures === 2, 'Unhandled failures keep the native cron notification path');
    });
    $run('renewal-cadence-cap', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $initialRefusal($invoice);
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
    $run('renewal-balance-crash', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $initialRefusal($invoice);
        $dueRetry();
        ExtensionHelper::$payments['920:credit'] = ['amount' => 5, 'status' => 'succeeded'];
        $stripe->status = 'succeeded'; $stripe->beforeConfirm = null; $stripe->loseConfirm = true;
        $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
        check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 1500 && count($stripe->confirms) === 2,
            'A lost renewal retry response resumes that debit at the current balance without another debit');
        check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'Settled renewal stops its retry schedule');
    });
    foreach (['opt-out', 'agreement-change', 'account-default-only', 'cancelled', 'paid', 'cutoff', 'service-cancelled', 'new-calendar'] as $stop) {
        $run('renewal-stop-' . $stop, function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $stop, $initialRefusal) {
            $service = $renewalFixture($invoice, $stripe);
            $initialRefusal($invoice); $dueRetry();
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
    $run('renewal-reservation-crash-optout', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $service = $renewalFixture($invoice, $stripe);
        $initialRefusal($invoice); $dueRetry();
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
    $run('renewal-overlapping-tick', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $initialRefusal($invoice); $dueRetry();
        // A second tick runs after the first has committed the new reservation, before its HTTP creation.
        $stripe->beforeCreate = function () use ($gateway, $stripe) {
            $stripe->beforeCreate = null;
            $gateway->retryChargeAttempts();
        };
        $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 2, 'Overlapping ticks cannot turn one due retry into two declined debits');
    });
    $run('renewal-hard-refusal', function ($invoice, $stripe) use ($gateway, $renewalFixture, $dueRetry, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $stripe->beforeConfirm = fn ($intent) => $intent->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'stolen_card'];
        $initialRefusal($invoice); $dueRetry();
        $gateway->retryChargeAttempts();
        check(count($stripe->confirms) === 1 && count(Notification::$rows) === 1,
            'A hard card refusal stops automatic collection and gives the customer a payment link');
    });
    $run('renewal-customer-pay', function ($invoice, $stripe) use ($gateway, $renewalFixture, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $initialRefusal($invoice);
        $GLOBALS['fakeConsole'] = false;
        $stripe->status = 'succeeded'; $stripe->beforeConfirm = null;
        check($gateway->charge($invoice, 20, new BillingAgreement) && count($stripe->charges) === 1,
            'An explicit customer Pay can pay immediately while the automatic renewal retry is waiting');
    });
    $run('renewal-domains-revoked', function ($invoice, $stripe) use ($gateway, $renewalFixture, $initialRefusal, $dueRetry) {
        require_once __DIR__ . '/fixtures/domains-autopay.php';
        $service = $renewalFixture($invoice, $stripe);
        $invoice->items = [(object) ['reference_type' => null, 'reference_id' => null]];
        TestDomainsAutoPay::$context = ['service_id' => $service->id, 'agreement_id' => 4,
            'operation_id' => 81, 'operation_key' => 'renewal-fixture', 'generation' => 2,
            'cutoff' => $invoice->due_at->getTimestamp()];
        TestDomainsAutoPay::$ownerOn = true; TestDomainsAutoPay::$checks = 0;
        try {
            $initialRefusal($invoice);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(($attempt['renewal']['domains'] ?? null) === TestDomainsAutoPay::$context
                && $attempt['renewal']['state'] === 'waiting', 'A Domains decline freezes retained authority and schedules the shared retry');
            $dueRetry();
            $stripe->beforeCreate = function () { TestDomainsAutoPay::$ownerOn = false; };
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(TestDomainsAutoPay::$checks >= 2 && count($stripe->confirms) === 1 && !$stripe->charges
                && !empty($attempt['renewal']), 'Owner Auto-renew Off after the frozen context blocks retry confirmation and retains the attempt');
        } finally { TestDomainsAutoPay::$context = null; TestDomainsAutoPay::$ownerOn = true; }
    });
    $run('renewal-domains-boundary', function ($invoice, $stripe) use ($gateway, $renewalFixture, $initialRefusal) {
        $renewalFixture($invoice, $stripe);
        $invoice->items = [(object) ['reference_type' => null, 'reference_id' => null]];
        $initialRefusal($invoice);
        $gateway->retryChargeAttempts();
        $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
        check(empty($attempt['renewal']) && !empty($attempt['automatic_renewal']) && !$stripe->confirms,
            'Retained automatic renewal invoices cannot debit without Domains authority');
    });
    $run('renewal-processing-uncertain', function ($invoice, $stripe) use ($gateway, $renewalFixture, $initialRefusal) {
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
