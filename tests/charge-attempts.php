<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, BillingAgreement, Gateway, Invoice, Notification, Properties, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $handler = Http::$handler; $enabled = Gateway::$enabled; $rows = Invoice::$rows; $user = User::$row;
    $payments = ExtensionHelper::$payments; $audits = Audit::$rows; $notices = Notification::$rows;
    $run = function ($name, $test) use ($gateway) {
        if (($only = getenv('AE_STRIPE_CHARGE_CASE')) && $only !== $name) return;
        User::$row = new User(new Properties('cus_owner'));
        $invoice = new Invoice(User::$row); $invoice->id = 920; Invoice::$rows[920] = $invoice;
        ExtensionHelper::$payments = []; Audit::$rows = []; Notification::$rows = [];
        Gateway::$enabled = true;
        $intent = (object) ['id' => 'pi_attempt', 'status' => 'requires_payment_method', 'client_secret' => 'pi_attempt_secret',
            'customer' => 'cus_owner', 'amount' => 2000, 'amount_received' => 0, 'currency' => 'usd',
            'metadata' => (object) ['invoice_id' => 920, 'ae_stripe_gateway' => '1']];
        Http::$handler = function ($verb, $path, $data) use ($intent) {
            if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
            if ($path === '/customer_sessions') return ['client_secret' => 'cuss_attempt'];
            if ($path === '/payment_intents') { $intent->amount = $data['amount']; $intent->currency = $data['currency']; return $intent; }
            if ($path === '/payment_intents/pi_attempt') return $intent;
            throw new RuntimeException('Unexpected attempt request: ' . $path);
        };
        $test($invoice, $intent);
    };
    try {
        $run('lost-response', function ($invoice, $intent) use ($gateway) {
            $lost = true; $intents = []; $keys = []; $params = [];
            Http::$handler = function ($verb, $path, $data) use (&$lost, &$intents, &$keys, &$params, $intent) {
                if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
                if ($path === '/payment_intents') {
                    $key = end(Http::$calls)[3]['Idempotency-Key'] ?? 'missing_' . count($keys);
                    $keys[] = $key; $params[] = $data;
                    $intents[$key] ??= $intent;
                    if ($lost) { $lost = false; throw new ConnectionException('Response lost'); }
                    return $intents[$key];
                }
                throw new RuntimeException('Unexpected lost response request');
            };
            $message = paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stored = json_decode(User::$row->props->values['ae_stripe_checkout_920'] ?? 'null', true);
            if ($stored) {
                $stored['created'] = time() - 22 * 3600;
                User::$row->props->values['ae_stripe_checkout_920'] = json_encode($stored);
            }
            // No webhook has arrived. A second Pay still sees an unpaid invoice, with changed caller data.
            $otherCard = new BillingAgreement(['external_reference' => 'pm_other']);
            try { $gateway->charge($invoice, 999, $otherCard); } catch (DisplayException $e) {}
            check(count($intents) === 1 && count(array_unique($keys)) === 1 && count($keys) === 2,
                'Lost first charge response then second Pay before webhook creates one intent with one key');
            check($params[0] === $params[1] && $stored['params'] === $params[0], 'Retry replays committed parameters across date and card changes');
            check(!str_contains(strtolower($message), 'try again'), 'Unknown result never invites another charge');
        });
        $run('overlap', function ($invoice, $intent) use ($gateway) {
            $intent->status = 'processing'; $handler = Http::$handler; $entered = false; $keys = [];
            Http::$handler = function ($verb, $path, $data) use ($handler, &$entered, &$keys, $gateway, $invoice) {
                if ($path === '/payment_intents') {
                    $keys[] = end(Http::$calls)[3]['Idempotency-Key'] ?? null;
                    check(!DB::$locked && isset(User::$row->props->values['ae_stripe_checkout_920']), 'Reservation commits before HTTP');
                    if (!$entered) {
                        $entered = true;
                        // Checkout overlaps the saved-card/cron call while its HTTP response is outstanding.
                        paymentStops(fn () => $gateway->pay($invoice, 20));
                    }
                }
                return $handler($verb, $path, $data);
            };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(count($keys) === 2 && $keys[0] !== null && $keys[0] === $keys[1], 'Overlapping checkout and saved-card/cron share one key');
        });
        $run('checkout-first', function ($invoice, $intent) use ($gateway) {
            $gateway->pay($invoice, 20); $calls = count(Http::$calls);
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(array_column(array_slice(Http::$calls, $calls), 1) === ['/payment_intents/pi_attempt'], 'Saved-card/cron recovers an existing checkout instead of charging beside it');
        });
        $run('balance', function ($invoice, $intent) use ($gateway) {
            $stale = clone $invoice;
            DB::$beforeUserLock = function () use ($invoice) {
                ExtensionHelper::$payments['920:credit'] = ['status' => 'succeeded', 'amount' => 5];
            };
            paymentStops(fn () => $gateway->charge($stale, 20, new BillingAgreement));
            $stored = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($stored['params']['amount'] === 1500, 'Payment amount uses current successful payments under the invoice lock');
            $invoice->status = 'paid'; $calls = count(Http::$calls);
            paymentStops(fn () => $gateway->charge($stale, 20, new BillingAgreement));
            check(count(Http::$calls) === $calls, 'A stale pending caller cannot charge a now-paid invoice');
        });
        $run('processing', function ($invoice, $intent) use ($gateway) {
            $intent->status = 'processing';
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            paymentStops(fn () => $gateway->pay($invoice, 20));
            check(ExtensionHelper::$payments === [], 'Processing never returns a success or records a successful payment');
            $intent->status = 'succeeded'; $intent->amount_received = 2000;
            check($gateway->charge($invoice, 20, new BillingAgreement) === true
                && ExtensionHelper::$payments['920:pi_attempt']['status'] === 'succeeded', 'Confirmed success settles before returning true');
        });
        $run('authentication', function ($invoice, $intent) use ($gateway) {
            $handler = Http::$handler; $intent->status = 'requires_action';
            Http::$handler = function ($verb, $path, $data) use ($handler, $intent) {
                if ($path === '/payment_intents') throw new RequestException(402, ['error' => ['payment_intent' => json_decode(json_encode($intent), true)]]);
                return $handler($verb, $path, $data);
            };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $calls = count(Http::$calls);
            $view = $gateway->pay($invoice, 20);
            check($view['data']['intent']->id === 'pi_attempt' && $view['data']['intent']->status === 'requires_action'
                && !in_array('/payment_intents', array_column(array_slice(Http::$calls, $calls), 1), true),
                'HTTP 402 retains the same intent and opens checkout for authentication without creating another');
        });
        $run('expired', function ($invoice, $intent) use ($gateway) {
            foreach ([false, true] as $legacy) {
                $attempt = ['key' => 'original', 'digest' => 'unknown', 'created' => time() - 86400];
                if (!$legacy) $attempt['params'] = ['confirm' => 'true', 'amount' => 2000];
                User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
                $calls = count(Http::$calls);
                paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
                paymentStops(fn () => $gateway->pay($invoice, 20));
                $stored = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                check($stored['key'] === 'original' && $stored['state'] === 'reconcile'
                    && !in_array('/payment_intents', array_column(array_slice(Http::$calls, $calls), 1), true),
                    'Expired unknown creation stays in readable reconcile state without searching or charging');
            }
            $attempt['intent'] = 'pi_attempt';
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            check($gateway->pay($invoice, 20)['data']['intent']->id === 'pi_attempt', 'A known old intent recovers beyond key expiry');
        });
        $run('legacy-checkout', function ($invoice, $intent) use ($gateway) {
            $data = ['description' => 'invoices.payment_for_invoice', 'amount' => 2000, 'currency' => 'usd',
                'automatic_payment_methods' => ['enabled' => 'true'],
                'metadata' => ['invoice_id' => 920, 'ae_stripe_gateway' => '1'], 'customer' => 'cus_owner'];
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode([
                'key' => 'legacy_checkout', 'digest' => hash('sha256', json_encode($data)), 'created' => time(),
            ]);
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stored = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($stored['key'] === 'legacy_checkout' && $stored['params'] === $data
                && end(Http::$calls)[3]['Idempotency-Key'] === 'legacy_checkout',
                'Saved-card/cron recovers a pre-upgrade unknown checkout with its original request and key');
        });
        $run('changed-invoice', function ($invoice, $intent) use ($gateway) {
            $gateway->pay($invoice, 20); $invoice->total = 15; $handler = Http::$handler; $canceled = false;
            Http::$handler = function ($verb, $path, $data) use ($handler, $intent, &$canceled) {
                if ($path === '/payment_intents/pi_attempt/cancel') { $canceled = true; $old = clone $intent; $old->status = 'canceled'; return $old; }
                if ($path === '/payment_intents') {
                    check($canceled && $data['amount'] === 1500, 'Replacement creation follows cancellation and uses the current balance');
                    $new = clone $intent; $new->id = 'pi_changed'; $new->amount = 1500; return $new;
                }
                return $handler($verb, $path, $data);
            };
            $intent->amount_received = 100; $calls = count(Http::$calls);
            paymentStops(fn () => $gateway->pay($invoice, 20));
            check(!$canceled && !in_array('/payment_intents', array_column(array_slice(Http::$calls, $calls), 1), true),
                'Received funds prevent automatic cancellation or replacement');
            $intent->amount_received = 0;
            check($gateway->pay($invoice, 20)['data']['intent']->id === 'pi_changed', 'Changed invoice automatically recovers after invalidating its old checkout');
        });
        $run('canceled', function ($invoice, $intent) use ($gateway) {
            $gateway->pay($invoice, 20);
            $original = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $intent->status = 'canceled'; $handler = Http::$handler;
            Http::$handler = function ($verb, $path, $data) use ($handler, $intent) {
                if ($path === '/payment_intents') { $new = clone $intent; $new->id = 'pi_replacement'; $new->status = 'requires_payment_method'; return $new; }
                return $handler($verb, $path, $data);
            };
            $view = $gateway->pay($invoice, 20);
            $replacement = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($view['data']['intent']->id === 'pi_replacement' && $replacement['key'] !== $original['key'], 'Proven cancellation allows a fresh attempt');
        });
        $run('alerts', function ($invoice, $intent) use ($gateway) {
            $intent->status = 'succeeded'; $intent->amount_received = 2000; $intent->customer = 'cus_other';
            $deliver = fn () => $gateway->webhook(eventRequest($intent));
            DB::transaction(function () use ($deliver) {
                $deliver();
                check(Notification::$rows === [], 'Refused-success alert waits for the outer settlement commit');
            });
            $deliver();
            check(count(Notification::$rows) === 1 && count(Audit::$rows) === 1 && ExtensionHelper::$payments === [], 'Refused success sends one staff alert and never applies money');
            $intent->id = 'pi_extra'; $intent->customer = 'cus_owner'; $invoice->status = 'paid';
            Notification::$fail = true;
            rejects($deliver, 'Inbox unavailable');
            check(isset(ExtensionHelper::$payments['920:pi_extra']), 'Notification failure cannot roll back a committed extra payment');
            Notification::$fail = false;
            $deliver(); $deliver();
            check(count(Notification::$rows) === 2 && count(Audit::$rows) === 2
                && ExtensionHelper::$payments['920:pi_extra']['amount'] === 20.0,
                'Extra charge keeps the payment and sends exactly one staff alert after inbox recovery');
            $intent->id = 'pi_rollback'; $intent->customer = 'cus_other'; $notices = count(Notification::$rows);
            rejects(function () use ($deliver) { DB::transaction(function () use ($deliver) { $deliver(); throw new RuntimeException('Rollback'); }); }, 'Rollback');
            check(count(Notification::$rows) === $notices, 'Rolled back settlement sends no staff alert');
        });
    } finally {
        Http::$handler = $handler; Gateway::$enabled = $enabled; Invoice::$rows = $rows; User::$row = $user;
        ExtensionHelper::$payments = $payments; Audit::$rows = $audits; Notification::$rows = $notices;
        Notification::$fail = false; DB::$beforeUserLock = null;
    }
})();
