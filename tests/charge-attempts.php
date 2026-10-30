<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, BillingAgreement, Gateway, Invoice, Notification, Properties, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $handler = Http::$handler; $rows = Invoice::$rows; $user = User::$row; $console = $GLOBALS['fakeConsole'] ?? true;
    $payments = ExtensionHelper::$payments; $agreements = ExtensionHelper::$agreements;
    $audits = Audit::$rows; $notices = Notification::$rows; $enabled = Gateway::$enabled;
    $run = function ($name, $test) use ($gateway) {
        if (($only = getenv('AE_STRIPE_CHARGE_CASE')) && $only !== $name) return;
        $GLOBALS['fakeConsole'] = false;
        User::$row = new User(new Properties('cus_owner'));
        $invoice = new Invoice(User::$row); $invoice->id = 920; Invoice::$rows[920] = $invoice;
        ExtensionHelper::$payments = []; Audit::$rows = []; Notification::$rows = []; Gateway::$enabled = true;
        ExtensionHelper::$agreements = ['pm_test' => ['id' => 4, 'user_id' => 7, 'gateway_id' => 3],
            'pm_other' => ['id' => 5, 'user_id' => 7, 'gateway_id' => 3]];
        $stripe = new class {
            public array $intents = [], $cache = [], $charges = [], $confirms = [];
            public $beforeCreate, $beforeConfirm, $failure;
            public string $status = 'succeeded';
            public bool $loseCreate = false, $loseConfirm = false, $dropCreate = false;
            public function request($verb, $path, $data) {
                if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
                if ($path === '/customer_sessions') return ['client_secret' => 'cuss_test'];
                if (str_starts_with($path, '/payment_methods/')) return (object) ['id' => substr($path, 17), 'customer' => 'cus_owner', 'type' => 'card'];
                if ($path === '/payment_intents/search') return (object) ['data' => [], 'has_more' => false];
                if ($this->failure) ($this->failure)($verb, $path, $data);
                $key = end(Http::$calls)[3]['Idempotency-Key'] ?? null;
                if ($verb === 'post' && $key && isset($this->cache[$key])) return clone $this->cache[$key];
                if ($path === '/payment_intents') {
                    if ($this->dropCreate) { $this->dropCreate = false; throw new ConnectionException('Never reached Stripe'); }
                    if ($this->beforeCreate) ($this->beforeCreate)();
                    if ($key && isset($this->cache[$key])) return clone $this->cache[$key];
                    $id = 'pi_test' . (count($this->intents) + 1);
                    $intent = (object) ['id' => $id, 'status' => 'requires_payment_method', 'amount_received' => 0,
                        'client_secret' => $id . '_secret', 'confirmation_method' => $data['confirmation_method'] ?? 'automatic',
                        'amount' => $data['amount'], 'currency' => $data['currency'], 'customer' => $data['customer'],
                        'metadata' => (object) $data['metadata']];
                    $this->intents[$id] = $intent;
                    if (!empty($data['confirm'])) $this->settle($intent, $data);
                    $this->cache[$key] = clone $intent;
                    if ($this->loseCreate) { $this->loseCreate = false; throw new ConnectionException('Lost create'); }
                    return clone $intent;
                }
                if (!preg_match('~^/payment_intents/(pi_[a-zA-Z0-9]+)(/confirm|/cancel)?$~', $path, $match)) throw new RuntimeException('Unexpected payment request ' . $path);
                $intent = $this->intents[$match[1]] ?? throw new RequestException(404);
                if ($verb === 'get') return clone $intent;
                if (($match[2] ?? '') === '/confirm') {
                    if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) throw new RequestException(400, ['error' => ['code' => 'payment_intent_unexpected_state']]);
                    if ($this->beforeConfirm) ($this->beforeConfirm)($intent, $data);
                    $this->confirms[] = [$key, $data, $intent->amount];
                    $this->settle($intent, $data);
                    $this->cache[$key] = clone $intent;
                    if ($this->loseConfirm) { $this->loseConfirm = false; throw new ConnectionException('Lost confirm'); }
                    return clone $intent;
                }
                if (($match[2] ?? '') === '/cancel') {
                    if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true)) throw new RequestException(400);
                    $intent->status = 'canceled';
                }
                else foreach ($data as $field => $value) {
                    if ($field === 'amount' && $intent->currency === 'usd' && $value < 50) throw new RequestException(400, ['error' => ['code' => 'amount_too_small']]);
                    if ($field === 'metadata') $intent->metadata = (object) array_replace((array) $intent->metadata, $value);
                    else $intent->$field = is_array($value) ? json_decode(json_encode($value)) : $value;
                }
                return clone $intent;
            }
            private function settle($intent, $data) {
                if ($intent->status === 'succeeded') return;
                $intent->status = $this->status;
                if ($this->status === 'succeeded') { $intent->amount_received = $intent->amount; $this->charges[] = [$intent->id, $intent->amount, $data['payment_method'] ?? null]; }
            }
        };
        Http::$handler = fn ($verb, $path, $data) => $stripe->request($verb, $path, $data);
        $test($invoice, $stripe);
    };
    try {
        $run('no-redebit', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'processing';
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $intent = $stripe->intents['pi_test1']; $intent->status = 'requires_payment_method';
            $intent->last_payment_error = (object) ['code' => 'payment_method_provider_decline'];
            $gateway->webhook(eventRequest($intent, type: 'payment_intent.payment_failed'));
            $stripe->status = 'succeeded';
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(count($stripe->confirms) === 1 && !$stripe->charges, 'A failed saved debit is never retried by recovery after its failure webhook');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(empty($attempt['agreement_id']) && empty($attempt['confirmation']), 'A definite debit result removes automatic charge instructions');
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'A customer can explicitly pay after a failed debit');
        });
        $run('old-redebit', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $intent = $stripe->intents['pi_test1']; $intent->status = 'requires_payment_method';
            $intent->last_payment_error = (object) ['code' => 'card_declined'];
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $attempt['agreement_id'] = 4; $attempt['retry'] = true;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(!$stripe->confirms, 'An older failed saved-card record cannot trigger recovery confirmation');
        });
        $run('missing-release', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $gateway->resolveChargeAttempt($invoice, null, 'Original Stripe account support case confirms no charge');
            check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'A permanently missing ID has an audited no-charge release');
            check(end(Audit::$rows)['new_values']['payment_intent_id'] === 'pi_test1', 'Missing-ID release audits the original identity');
        });
        $run('missing-release-money', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'processing'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            rejects(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Support case in the original account says no charge'),
                'This payment may still hold money. Restore access to its Stripe account and account for the funds first.');
            check(isset(User::$row->props->values['ae_stripe_checkout_920']), 'A missing ID cannot release an observed debit that may still hold money');
        });
        $run('refusal-read-outage', function ($invoice, $stripe) use ($gateway) {
            $refused = false;
            $stripe->failure = function ($verb, $path) use (&$refused) {
                if (str_ends_with($path, '/confirm')) { $refused = true; throw new RequestException(400); }
                if ($refused && $verb === 'get') throw new ConnectionException('Cannot check actual outcome');
            };
            $GLOBALS['fakeConsole'] = true;
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'A refusal whose status read fails stays pending in cron');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($attempt['retry'] && !empty($attempt['agreement_id']) && !ExtensionHelper::$payments, 'An unknown post-refusal read retains recovery and cannot record failure');
        });
        $run('recovery-decline-body', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeConfirm = fn () => throw new ConnectionException('Confirm not sent');
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $stripe->beforeConfirm = null;
            $stripe->failure = function ($verb, $path) use ($stripe) {
                if (str_ends_with($path, '/confirm')) {
                    $intent = $stripe->intents['pi_test1']; $intent->last_payment_error = (object) ['code' => 'card_declined'];
                    throw new RequestException(402, ['error' => ['payment_intent' => json_decode(json_encode($intent), true)]]);
                }
            };
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(count(Notification::$rows) === 1 && str_contains(Notification::$rows[0]['body'], 'declined')
                && ExtensionHelper::$payments['920:pi_test1']['status'] === 'failed' && !$stripe->confirms,
                'A recovery decline with a Stripe intent body records failure and tells the customer without another debit');
        });
        $run('intent-swap', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $intent = $stripe->intents['pi_test1']; $intent->amount_received = 500;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $other = clone $intent; $other->id = 'pi_other'; $other->amount_received = 0; $other->status = 'canceled'; $stripe->intents['pi_other'] = $other;
            try { $gateway->resolveChargeAttempt($invoice, 'pi_other'); }
            catch (\App\Exceptions\DisplayException $e) { check(str_contains($e->getMessage(), 'held payment'), 'Staff cannot hide held money by swapping identities'); return; }
            check(false, 'Staff cannot replace a held payment with an unrelated canceled intent');
        });
        $run('minimum', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $invoice->total = 0.25;
            $stripe->failure = function ($verb, $path) { if ($verb === 'post' && $path === '/payment_intents/pi_test1') throw new RequestException(400, ['error' => ['code' => 'amount_too_small']]); };
            $message = paymentStops(fn () => $gateway->charge($invoice, 0.25, new BillingAgreement));
            check(str_contains($message, 'minimum') && str_contains($message, 'support') && !str_contains($message, 'unavailable'), 'A below-minimum balance has a real reason and next step');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(empty($attempt['retry']) && empty($attempt['agreement_id']), 'A permanent amount refusal cannot loop through recovery');
        });
        $run('changed-customer', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); User::$row->props->customer = 'cus_new';
            $handler = Http::$handler;
            Http::$handler = fn ($verb, $path, $data) => $path === '/customers/cus_new' ? ['id' => 'cus_new'] : $handler($verb, $path, $data);
            $view = $gateway->pay($invoice, 20);
            check($view['data']['intent']->customer === 'cus_new' && $stripe->intents['pi_test1']->status === 'canceled', 'An untouched intent follows a changed Stripe customer automatically');
            check(!$stripe->charges && count($stripe->intents) === 2, 'Customer repair cancels before replacement and takes no money');
        });
        $run('recovery-decline', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeConfirm = fn () => throw new ConnectionException('Confirm not sent');
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $stripe->beforeConfirm = null;
            $stripe->failure = function ($verb, $path) { if (str_ends_with($path, '/confirm')) throw new RequestException(402, ['error' => ['code' => 'card_declined']]); };
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            $customer = array_filter(Notification::$rows, fn ($notice) => $notice['user_id'] == $invoice->user_id);
            check(count($customer) === 1 && str_contains(reset($customer)['body'], 'declined'), 'Recovery decline tells the customer the reason once');
            check(count(Notification::$rows) === 1, 'A card decline gives staff no task');
            check((ExtensionHelper::$payments['920:pi_test1']['status'] ?? null) === 'failed', 'Recovery records a definite decline for the native failed-payment notice');
            $stripe->failure = null;
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'Recovery decline leaves the invoice payable');
        });
        $run('confirm-processing-refusal', function ($invoice, $stripe) use ($gateway) {
            $stripe->failure = function ($verb, $path) use ($stripe) {
                if (str_ends_with($path, '/confirm')) { $stripe->intents['pi_test1']->status = 'processing'; throw new RequestException(400, ['error' => ['code' => 'payment_intent_unexpected_state']]); }
            };
            check(str_contains(paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)), 'pending'), 'A confirm refusal re-reads Stripe and shows its processing status');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($attempt['retry'] && empty($attempt['agreement_id']), 'Processing after confirm refusal remains polled without re-debit instructions');
        });
        $run('confirm-processing-402', function ($invoice, $stripe) use ($gateway) {
            $stripe->failure = function ($verb, $path) use ($stripe) {
                if (str_ends_with($path, '/confirm')) {
                    $stale = clone $stripe->intents['pi_test1']; $stale->last_payment_error = (object) ['code' => 'card_declined'];
                    $stripe->intents['pi_test1']->status = 'processing';
                    throw new RequestException(402, ['error' => ['payment_intent' => json_decode(json_encode($stale), true)]]);
                }
            };
            check(str_contains(paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)), 'pending') && !ExtensionHelper::$payments,
                'A stale 402 body cannot override a fresh processing status or send a failure notice');
        });
        $run('adopted-no-redebit', function ($invoice, $stripe) use ($gateway) {
            $stripe->intents['pi_old'] = (object) ['id' => 'pi_old', 'status' => 'processing', 'customer' => 'cus_owner', 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 0, 'metadata' => (object) ['invoice_id' => 920]];
            ExtensionHelper::$payments['920:pi_old'] = ['status' => 'processing', 'amount' => 20, 'gateway' => 'AeStripe'];
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->intents['pi_old']->status = 'requires_payment_method';
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(!$stripe->confirms && !$stripe->charges, 'An adopted old debit that fails without a webhook is never debited again by recovery');
        });
        $run('return-outage', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $stripe->failure = fn () => throw new ConnectionException('Outage');
            $result = $gateway->finishPayment(new Request(['payment_intent' => 'pi_test1', 'invoice' => 920]));
            check($result['name'] === 'gateways.ae-stripe::payment-return' && str_contains($result['data']['message'], 'check'), 'A return-page outage shows a message and a link back to the invoice');
        });
        $run('bounded-http', function ($invoice, $stripe) use ($gateway) {
            $gateway->charge($invoice, 20, new BillingAgreement);
            check(end(Http::$calls)[4] === 5, 'Stripe calls have a short explicit timeout while locks are held');
        });
        $run('lock-timeout', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true;
            DB::$beforeUserLock = fn () => throw new \Illuminate\Database\QueryException('Lock wait timeout', 1205);
            check($gateway->charge($invoice, 20, new BillingAgreement) === true && !$stripe->charges, 'Cron lock contention cannot send a false payment-failed email');
        });
        $run('customer-words', function ($invoice, $stripe) use ($gateway) {
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode(['key' => 'legacy', 'created' => time() - 90000, 'params' => ['confirm' => 'true']]);
            $message = paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(!str_contains($message, 'request log') && !str_contains($message, 'releasing') && str_contains($message, 'support'), 'Customers never receive staff reconciliation instructions');
        });
        $run('documented-elements', function () {
            $source = file_get_contents(__DIR__ . '/../extension/AeStripe/resources/views/pay.blade.php');
            check(str_contains($source, "mode: 'payment'") && str_contains($source, 'amount: @js($intent->amount)') && str_contains($source, 'currency: @js(strtolower($intent->currency))') && !str_contains($source, 'clientSecret: @js($intent->client_secret)'), 'Checkout uses the documented deferred form with matching minor amount and currency');
        });
        $run('lost-response', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseConfirm = true;
            $message = paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); $attempt['created'] -= 90000;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $before = count($stripe->confirms);
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'Lost confirmation recovers by intent ID before a webhook');
            check(count($stripe->intents) === 1 && count($stripe->charges) === 1 && count($stripe->confirms) === 1 && $before === 1,
                'Second Pay before webhook makes one intent and one confirmation key with no replay');
            check(!str_contains(strtolower($message), 'try again'), 'Lost result does not invite another payment');
        });
        $run('stale-create', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseCreate = true;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $invoice->total = 15;
            $gateway->pay($invoice, 20);
            check($stripe->charges === [], 'Opening checkout after a lost response never starts a charge');
            $gateway->charge($invoice, 20, new BillingAgreement(['id' => 5, 'external_reference' => 'pm_other']));
            check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 1500 && $stripe->charges[0][2] === 'pm_other',
                'Lost create uses current balance and current card rather than frozen confirmation data');
            $creates = array_values(array_filter(Http::$calls, fn ($call) => $call[1] === '/payment_intents' && isset($call[2]['confirmation_method'])));
            check(!isset(end($creates)[2]['confirm']) && !isset(end($creates)[2]['payment_method']), 'Creation cannot take money');
        });
        $run('stale-never-created', function ($invoice, $stripe) use ($gateway) {
            $stripe->dropCreate = true; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $invoice->total = 15; $gateway->pay($invoice, 20);
            check(!$stripe->charges, 'A stale replay whose first request never reached Stripe cannot charge merely by opening checkout');
            $gateway->charge($invoice, 20, new BillingAgreement(['id' => 5, 'external_reference' => 'pm_other']));
            check($stripe->charges[0][1] === 1500 && $stripe->charges[0][2] === 'pm_other', 'Only the current balance and selected card are confirmed after a never-sent create');
        });
        $run('stale-confirm', function ($invoice, $stripe) use ($gateway) {
            $once = true;
            $stripe->beforeConfirm = function () use (&$once) { if ($once) { $once = false; throw new ConnectionException('Confirm never arrived'); } };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $invoice->total = 11; $gateway->pay($invoice, 20);
            check(!$stripe->charges, 'Opening checkout never replays an uncertain confirmation');
            $gateway->charge($invoice, 20, new BillingAgreement(['id' => 5, 'external_reference' => 'pm_other']));
            check(count($stripe->intents) === 1 && $stripe->charges[0][1] === 1100 && $stripe->charges[0][2] === 'pm_other', 'Confirm recovery reads the known intent and checks the new balance and card');
        });
        $run('between-locks', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeCreate = function () use ($invoice) { $invoice->total = 12; };
            $stripe->beforeConfirm = function ($intent) { check(DB::$locked && DB::$userLocked && $intent->amount === 1200, 'Confirmation holds user and invoice locks with the rechecked balance'); };
            $gateway->charge($invoice, 20, new BillingAgreement);
            check($stripe->charges[0][1] === 1200, 'Balance changes between creation and confirmation cannot overcharge');
        });
        $run('old-tab', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20); $id = $view['data']['intent']->id; $invoice->total = 10;
            check($stripe->intents[$id]->confirmation_method === 'manual', 'Client secret cannot authorize an old tab to confirm directly');
            $result = $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            check($result['status'] === 409 && $stripe->charges === [], 'Old checkout must refresh the changed amount before confirmation');
        });
        $run('upgrade-processing', function ($invoice, $stripe) use ($gateway) {
            $stripe->intents['pi_old'] = (object) ['id' => 'pi_old', 'status' => 'processing', 'customer' => 'cus_owner', 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 0, 'metadata' => (object) ['invoice_id' => 920]];
            ExtensionHelper::$payments['920:pi_old'] = ['status' => 'processing', 'amount' => 20, 'gateway' => 'AeStripe'];
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(count($stripe->intents) === 1 && !$stripe->charges, 'Pre-upgrade processing debit blocks another debit without an attempt property');
            $stripe->intents['pi_old']->status = 'requires_payment_method';
            $gateway->charge($invoice, 20, new BillingAgreement);
            check(count($stripe->charges) === 1, 'A failed pre-upgrade debit automatically permits payment');
        });
        $run('refusal', function ($invoice, $stripe) use ($gateway) {
            $stripe->failure = function ($verb, $path) { if ($path === '/payment_intents') throw new RequestException(400, ['error' => ['message' => 'The currency provided is not supported']]); };
            check(paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)) === "This card can't be charged in usd. Use another payment method.", 'Currency refusal retains the base message');
            $stripe->failure = null;
            check($gateway->charge($invoice, 20, new BillingAgreement(['id' => 5, 'external_reference' => 'pm_other'])) === true, 'Definite refusal cannot wedge another card or a repaired setup');
        });
        $run('confirm-refusal', function ($invoice, $stripe) use ($gateway) {
            $stripe->failure = function ($verb, $path) { if (str_ends_with($path, '/confirm')) throw new RequestException(402, ['error' => ['code' => 'card_declined']]); };
            check(paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)) === 'This card was declined. Choose another payment method.', 'Definite 402 without an intent gives the real refusal');
            $stripe->failure = null;
            check($gateway->charge($invoice, 20, new BillingAgreement(['id' => 5, 'external_reference' => 'pm_other'])) === true && count($stripe->intents) === 1,
                'A different card can pay the refused intent without a permanent hold');
        });
        $run('remainder', function ($invoice, $stripe) use ($gateway) {
            $gateway->charge($invoice, 20, new BillingAgreement); $invoice->total = 30;
            $view = $gateway->pay($invoice, 30);
            check($view['data']['intent']->amount === 1000 && count($stripe->charges) === 1, 'Recorded success opens a new unpaid remainder without reporting the old charge');
            $gateway->charge($invoice, 30, new BillingAgreement);
            check(array_column($stripe->charges, 1) === [2000, 1000], 'A remaining balance can actually be paid');
        });
        $run('cron', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true; $stripe->status = 'processing';
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'Cron does not email payment failed for a processing bank debit');
            $stripe->failure = fn () => throw new ConnectionException('Stripe outage');
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'Cron does not email payment failed for a possibly landed charge');
            $GLOBALS['fakeConsole'] = false;
        });
        $run('recovery', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseConfirm = true; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $gateway->retryChargeAttempts();
            check(isset(ExtensionHelper::$payments['920:pi_test1']) && count($stripe->charges) === 1, 'Scheduled recovery settles a lost confirmation without customer input');
        });
        $run('outage-batch', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $other = clone $invoice; $other->id = 921; Invoice::$rows[921] = $other;
            $gateway->pay($other, 20);
            foreach ([920, 921] as $id) {
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_' . $id], true); $attempt['retry'] = true;
                User::$row->props->values['ae_stripe_checkout_' . $id] = json_encode($attempt);
            }
            $lookups = 0;
            $stripe->failure = function ($verb, $path) use (&$lookups) {
                if ($verb === 'get' && str_starts_with($path, '/payment_intents/')) { ++$lookups; throw new ConnectionException('Outage'); }
            };
            $gateway->retryChargeAttempts();
            check($lookups === 1 && !Notification::$rows && !$stripe->charges, 'An outage stops the recovery batch after one failed lookup without creating staff holds');
            foreach ([920, 921] as $id) {
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_' . $id], true); $attempt['agreement_id'] = 4;
                User::$row->props->values['ae_stripe_checkout_' . $id] = json_encode($attempt);
            }
            $GLOBALS['fakeConsole'] = true; $confirms = 0;
            $stripe->failure = function ($verb, $path) use (&$confirms) {
                if (str_ends_with($path, '/confirm')) { ++$confirms; throw new ConnectionException('Outage'); }
            };
            $gateway->retryChargeAttempts();
            check($confirms === 1 && !$stripe->charges, 'Console pending handling still stops the worker after one failed confirmation');
        });
        $run('reconcile', function ($invoice, $stripe) use ($gateway) {
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode(['key' => 'legacy', 'created' => time() - 90000, 'params' => ['confirm' => 'true', 'amount' => 2000]]);
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(count(Notification::$rows) === 1 && isset(Notification::$rows[0]['url']) && !$stripe->charges, 'Unresolvable legacy charge alerts staff with an invoice link');
            $gateway->resolveChargeAttempt($invoice, null, 'Stripe request log req_test proves creation was rejected');
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'Staff can release a proven no-charge hold through the supported action');
        });
        $run('accounted-funds', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $intent = $stripe->intents['pi_test1']; $intent->amount_received = 500;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            rejects(fn () => $gateway->resolveChargeAttempt($invoice, null, '', 'Refund or credit checked by staff'),
                'Finish or cancel the Stripe payment and provide proof that its funds were refunded or credited.');
            $intent->status = 'canceled';
            $gateway->resolveChargeAttempt($invoice, null, '', 'Stripe refund re_verified accounts for the received funds');
            $gateway->webhook(eventRequest($intent));
            check(!ExtensionHelper::$payments, 'A late delivery cannot apply funds staff already refunded or credited');
            check($gateway->charge($invoice, 20, new BillingAgreement) === true && count($stripe->charges) === 1,
                'Staff can release accounted final funds through an audited action, then the unpaid invoice can be paid');
        });
        $run('authentication', function ($invoice, $stripe) use ($gateway) {
            $once = true;
            $stripe->failure = function ($verb, $path) use ($stripe, &$once) {
                if (str_ends_with($path, '/confirm') && $once) {
                    $once = false; $intent = $stripe->intents['pi_test1']; $intent->status = 'requires_action';
                    throw new RequestException(402, ['error' => ['payment_intent' => json_decode(json_encode($intent), true)]]);
                }
            };
            $message = paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(str_contains($message, 'authentication'), '402 action-required explains authentication rather than a decline');
            $view = $gateway->pay($invoice, 20);
            check($view['data']['intent']->id === 'pi_test1' && count($stripe->intents) === 1, 'Checkout resumes the same 3DS intent');
            $stripe->intents['pi_test1']->status = 'requires_confirmation';
            $result = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => false, 'amount' => 2000]));
            check($result['data']['status'] === 'succeeded' && count($stripe->charges) === 1, 'After 3DS the server rechecks and confirms the same intent');
        });
        $run('legacy-tab', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $intent = $stripe->intents['pi_test1']; $intent->confirmation_method = 'automatic';
            $invoice->total = 10;
            $response = $gateway->consent(new Request(['intent' => $intent->id, 'save' => false]));
            check($response['status'] === 409 && $intent->status === 'canceled' && !$stripe->charges,
                'An old automatic checkout tab gets a refresh step and cannot confirm the stale amount');
        });
        $run('redirect', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20); $intent = $stripe->intents[$view['data']['intent']->id];
            $stripe->status = 'requires_action';
            $gateway->confirmPayment(new Request(['intent' => $intent->id, 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            $stripe->status = 'succeeded'; $intent->status = 'requires_confirmation';
            check(str_contains($gateway->finishPayment(new Request(['payment_intent' => $intent->id])), 'checkPayment=true')
                && count($stripe->charges) === 1, 'A bank or 3DS redirect completes server confirmation without another Pay');
        });
        $run('checkout-consent', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $stripe->beforeConfirm = function ($intent, $params) {
                check(!isset($params['metadata']) && !isset($params['setup_future_usage'])
                    && $intent->metadata->ae_stripe_gateway === '1' && $intent->metadata->ae_stripe_consent === '1',
                    'Server confirmation updates consent first and sends only supported confirmation parameters');
            };
            $response = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => true, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            check($response['data']['status'] === 'succeeded' && count($stripe->charges) === 1, 'Checkout token confirms once with explicit saving consent');
        });
        $run('bank-verification', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $stripe->status = 'requires_action';
            $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            $stripe->intents['pi_test1']->status = 'requires_confirmation'; $stripe->status = 'succeeded';
            $stripe->beforeConfirm = function ($intent, $params) { check(!isset($params['confirmation_token']), 'Async bank verification does not replay a consumed token'); };
            $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && isset(ExtensionHelper::$payments['920:pi_test1']), 'Bank verification finishes through recovery without another customer Pay or browser return');
        });
        $run('authentication-removed-card', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'requires_action'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            ExtensionHelper::$agreements = []; $stripe->intents['pi_test1']->status = 'requires_confirmation';
            $result = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => false, 'amount' => 2000]));
            check($result['status'] === 409 && str_contains($result['data']['message'], 'removed') && !$stripe->charges,
                'Authentication cannot revive a saved card removed before reconfirmation');
        });
        $run('redirect-amount', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $stripe->status = 'requires_action';
            $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            $stripe->intents['pi_test1']->status = 'requires_confirmation'; $invoice->total = 30;
            $gateway->pay($invoice, 30); $stripe->status = 'succeeded';
            check(str_contains($gateway->finishPayment(new Request(['payment_intent' => 'pi_test1']))['data']['message'], 'amount due changed')
                && !$stripe->charges, 'A bank redirect preserves the amount authorized before authentication even if another tab updated the intent');
        });
        $run('decimal-charge', function ($invoice, $stripe) use ($gateway) {
            $invoice->total = '19.99'; $gateway->charge($invoice, '19.99', new BillingAgreement);
            check($stripe->charges[0][1] === 1999, 'Saved-card confirmation preserves decimal invoice cents');
        });
        $run('overlap', function ($invoice, $stripe) use ($gateway) {
            $entered = false; $calls = count(Http::$calls);
            $stripe->beforeCreate = function () use (&$entered, $gateway, $invoice) {
                check(!DB::$locked && isset(User::$row->props->values['ae_stripe_checkout_920']), 'Attempt reservation commits before creation HTTP');
                if (!$entered) { $entered = true; $gateway->pay($invoice, 20); }
            };
            $gateway->charge($invoice, 20, new BillingAgreement);
            $creates = array_values(array_filter(array_slice(Http::$calls, $calls), fn ($call) => $call[1] === '/payment_intents'));
            check(count($stripe->intents) === 1 && count($stripe->charges) === 1 && $creates[0][3]['Idempotency-Key'] === $creates[1][3]['Idempotency-Key'],
                'Checkout overlapping saved-card or cron creation shares the committed key and one intent');
        });
        $run('expired-create', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseCreate = true; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); $attempt['created'] -= 90000;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt); $invoice->total = 9;
            $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 900 && !Notification::$rows,
                'An expired unconfirmed creation recovers automatically at the new balance, without staff holds');
        });
        $run('conflict', function ($invoice, $stripe) use ($gateway) {
            $once = true;
            $stripe->failure = function ($verb, $path) use (&$once) { if (str_ends_with($path, '/confirm') && $once) { $once = false; throw new RequestException(409); } };
            $message = paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $gateway->retryChargeAttempts();
            check(str_contains($message, 'automatically') && count($stripe->intents) === 1 && count($stripe->charges) === 1,
                '409 keeps the known intent and gives an automatic recovery path');
        });
        $run('cancel-retry', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $stripe->intents['pi_test1']->confirmation_method = 'automatic'; $invoice->total = 10;
            $keys = []; $failedKey = null;
            $stripe->failure = function ($verb, $path) use (&$keys, &$failedKey) {
                if (!str_ends_with($path, '/cancel')) return;
                $key = end(Http::$calls)[3]['Idempotency-Key'] ?? null; $keys[] = $key;
                if (count($keys) === 1) { $failedKey = $key; throw new RequestException(503); }
                if ($key && $key === $failedKey) throw new RequestException(503); // Stripe replays the cached failure.
            };
            paymentStops(fn () => $gateway->pay($invoice, 20));
            $view = $gateway->pay($invoice, 20);
            check($view['data']['intent']->amount === 1000 && $stripe->intents['pi_test1']->status === 'canceled'
                && $keys === [null, null] && !$stripe->charges, 'A failed cancellation is retried without replaying a cached failure or charging');
        });
        $run('confirm-lookup-outage', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $gets = 0; $GLOBALS['fakeConsole'] = true;
            $stripe->failure = function ($verb, $path) use (&$gets) {
                if ($verb === 'get' && $path === '/payment_intents/pi_test1' && ++$gets === 2) throw new RequestException(403);
            };
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'A refused confirmation lookup cannot trigger cron payment-failed mail');
            $stripe->failure = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && count($stripe->intents) === 1, 'Recovery resumes the known intent after lookup access is restored');
        });
        $run('legacy-checkout', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); unset($attempt['version']);
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $stripe->intents['pi_test1']->confirmation_method = 'automatic';
            $gateway->charge($invoice, 20, new BillingAgreement);
            check($stripe->intents['pi_test1']->status === 'canceled' && count($stripe->charges) === 1
                && count($stripe->intents) === 2, 'Known legacy automatic checkout is canceled before a safe replacement can charge');
        });
        $run('removed-card', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeCreate = function () { ExtensionHelper::$agreements = []; };
            check(str_contains(paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)), 'removed') && !$stripe->charges,
                'A card removed between creation and confirmation cannot be charged');
        });
        $run('known-missing', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(count(Notification::$rows) === 1, 'Known-ID 404 alerts staff to restore the original Stripe account');
            $stripe->failure = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && count($stripe->intents) === 1, 'Restored access automatically resolves a known-ID hold');
        });
        $run('webhook-identity', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseCreate = true; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $intent = $stripe->intents['pi_test1']; $intent->status = 'processing';
            $gateway->webhook(eventRequest($intent, type: 'payment_intent.processing'));
            $stored = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check($stored['intent'] === 'pi_test1' && $stored['key'] === $attempt['key'], 'Matching webhook metadata fills a lost creation identity');
        });
        $run('legacy-search', function ($invoice, $stripe) use ($gateway) {
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode(['key' => 'old', 'created' => time() - 90000, 'params' => ['confirm' => 'true']]);
            $handler = Http::$handler;
            Http::$handler = function ($verb, $path, $data) use ($handler) {
                if ($path === '/payment_intents/search') return ['data' => [(object) ['id' => 'pi_unrelated', 'customer' => 'cus_owner', 'status' => 'succeeded', 'metadata' => (object) ['invoice_id' => 920]]]];
                return $handler($verb, $path, $data);
            };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(!isset(ExtensionHelper::$payments['920:pi_unrelated']) && !$stripe->charges, 'Invoice metadata alone cannot prove an unknown legacy attempt finished');
        });
        $run('legacy-search-refusal', function ($invoice, $stripe) use ($gateway) {
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode(['key' => 'unsafe_old', 'created' => time() - 90000, 'params' => ['confirm' => 'true']]);
            $handler = Http::$handler;
            Http::$handler = function ($verb, $path, $data) use ($handler) { if ($path === '/payment_intents/search') throw new RequestException(403); return $handler($verb, $path, $data); };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(json_decode(User::$row->props->values['ae_stripe_checkout_920'], true)['key'] === 'unsafe_old' && !$stripe->charges,
                'A refused lookup is not a refused charge and cannot release a legacy unknown');
        });
        $run('refused-alert', function ($invoice, $stripe) use ($gateway) {
            $intent = (object) ['id' => 'pi_refused', 'customer' => 'cus_foreign', 'status' => 'succeeded', 'amount' => 2000, 'amount_received' => 2000, 'currency' => 'usd', 'metadata' => (object) ['invoice_id' => 920, 'ae_stripe_gateway' => '1']];
            DB::transaction(function () use ($gateway, $intent) { $gateway->webhook(eventRequest($intent)); check(!Notification::$rows, 'Refused success alert waits for commit'); });
            $gateway->webhook(eventRequest($intent));
            check(count(Notification::$rows) === 1 && isset(Notification::$rows[0]['url']) && !ExtensionHelper::$payments, 'Refused success alerts staff once with an invoice link and no money movement');
        });
        $run('cleanup', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(!isset($attempt['digest']), 'New attempts omit the unused legacy digest');
            $gateway->charge($invoice, 20, new BillingAgreement);
            check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'A fully recorded payment removes its finished attempt property');
        });
        $run('notification-failure', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true;
            $stripe->beforeConfirm = function () use ($invoice) { $invoice->status = 'paid'; Notification::$fail = true; };
            check($gateway->charge($invoice, 20, new BillingAgreement) === true && isset(ExtensionHelper::$payments['920:pi_test1']),
                'Inbox failure after committed settlement cannot send cron payment-failed email');
            Notification::$fail = false;
            $gateway->retryChargeAttempts();
            check(count(Notification::$rows) === 1, 'Scheduled recovery retries the failed alert once without another webhook');
        });
        $run('overpay-alert', function ($invoice, $stripe) use ($gateway) {
            $invoice->total = 15;
            $intent = (object) ['id' => 'pi_extra', 'status' => 'succeeded', 'customer' => 'cus_owner', 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000, 'metadata' => (object) ['invoice_id' => 920, 'ae_stripe_gateway' => '1']];
            DB::transaction(function () use ($gateway, $intent) { $gateway->webhook(eventRequest($intent)); check(!Notification::$rows, 'Overpay alert waits for commit'); });
            $gateway->webhook(eventRequest($intent));
            check(count(Notification::$rows) === 1 && count(Audit::$rows) === 1, 'Overpayment on a pending invoice sends one staff alert');
        });
    } finally {
        Http::$handler = $handler; Invoice::$rows = $rows; User::$row = $user; Gateway::$enabled = $enabled;
        ExtensionHelper::$payments = $payments; ExtensionHelper::$agreements = $agreements; Audit::$rows = $audits;
        Notification::$rows = $notices; Notification::$fail = false; DB::$beforeUserLock = null; $GLOBALS['fakeConsole'] = $console;
    }
})();
