<?php
// Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
use App\Helpers\ExtensionHelper;
use App\Models\{Audit, BillingAgreement, Gateway, Invoice, Notification, Properties, Property, User};
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};

(function () use ($gateway) {
    $handler = Http::$handler; $rows = Invoice::$rows; $user = User::$row; $console = $GLOBALS['fakeConsole'] ?? true;
    $payments = ExtensionHelper::$payments; $agreements = ExtensionHelper::$agreements;
    $audits = Audit::$rows; $notices = Notification::$rows; $enabled = Gateway::$enabled;
    $returns = DB::$tables['ae_stripe_receipt_returns'];
    $run = function ($name, $test) use ($gateway) {
        if (($only = getenv('AE_STRIPE_CHARGE_CASE')) && $only !== $name) return;
        $GLOBALS['fakeConsole'] = false;
        User::$row = new User(new Properties('cus_owner'));
        $invoice = new Invoice(User::$row); $invoice->id = 920; Invoice::$rows[920] = $invoice;
        ExtensionHelper::$payments = []; Audit::$rows = []; Notification::$rows = []; Gateway::$enabled = true; DB::$tables['ae_stripe_receipt_returns'] = [];
        ExtensionHelper::$agreements = ['pm_test' => ['id' => 4, 'user_id' => 7, 'gateway_id' => 3],
            'pm_other' => ['id' => 5, 'user_id' => 7, 'gateway_id' => 3]];
        $stripe = new class {
            public array $intents = [], $setups = [], $cache = [], $charges = [], $confirms = [], $refunds = [];
            public $beforeCreate, $beforeConfirm, $failure;
            public string $status = 'succeeded';
            public bool $loseCreate = false, $loseConfirm = false, $dropCreate = false, $strictElements = false;
            public function request($verb, $path, $data) {
                if ($path === '/customers/cus_owner') return ['id' => 'cus_owner'];
                if ($path === '/customer_sessions') return ['client_secret' => 'cuss_test'];
                if ($path === '/setup_intents') {
                    $id = 'seti_display';
                    return $this->setups[$id] = (object) ['id' => $id, 'status' => 'requires_payment_method',
                        'customer' => $data['customer'], 'client_secret' => 'fixture-secret', 'metadata' => (object) $data['metadata']];
                }
                if ($path === '/setup_intents/seti_display') return $this->setups['seti_display'];
                if (str_starts_with($path, '/payment_methods/')) return (object) ['id' => substr($path, 17), 'customer' => 'cus_owner', 'type' => 'card',
                    'card' => (object) ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2030]];
                if ($path === '/payment_intents/search') return (object) ['data' => [], 'has_more' => false];
                if ($this->failure) ($this->failure)($verb, $path, $data);
                if (str_starts_with($path, '/charges/ch_')) return (object) ['id' => substr($path, 9)];
                $key = end(Http::$calls)[3]['Idempotency-Key'] ?? null;
                if ($verb === 'post' && $key && isset($this->cache[$key])) return clone $this->cache[$key];
                if ($path === '/refunds') {
                    if ($verb === 'get') return (object) ['data' => array_values(array_filter($this->refunds, fn ($r) => $r->payment_intent === $data['payment_intent'])), 'has_more' => false];
                    return $this->refunds[$key] ??= (object) ['id' => 're_attempt', 'payment_intent' => $data['payment_intent'],
                        'amount' => $data['amount'], 'currency' => 'usd', 'metadata' => (object) $data['metadata'], 'status' => 'succeeded'];
                }
                if ($path === '/payment_intents') {
                    if (isset($data['automatic_payment_methods'], $data['confirmation_method'])) throw new RequestException(400, ['error' => ['message' => 'You may only specify one of these parameters: automatic_payment_methods, confirmation_method.']]);
                    if ($this->dropCreate) { $this->dropCreate = false; throw new ConnectionException('Never reached Stripe'); }
                    if ($this->beforeCreate) ($this->beforeCreate)();
                    if ($key && isset($this->cache[$key])) return clone $this->cache[$key];
                    $id = 'pi_test' . (count($this->intents) + 1);
                    $intent = (object) ['id' => $id, 'status' => 'requires_payment_method', 'amount_received' => 0,
                        'client_secret' => $id . '_secret', 'confirmation_method' => $data['confirmation_method'] ?? 'automatic',
                        'amount' => $data['amount'], 'currency' => $data['currency'], 'customer' => $data['customer'],
                        'payment_method_types' => $data['payment_method_types'] ?? ['card', 'klarna', 'link', 'cashapp'],
                        'metadata' => (object) $data['metadata']];
                    $this->intents[$id] = $intent;
                    if (!empty($data['confirm'])) $this->settle($intent, $data);
                    $this->cache[$key] = clone $intent;
                    if ($this->loseCreate) { $this->loseCreate = false; throw new ConnectionException('Lost create'); }
                    return clone $intent;
                }
                if (!preg_match('~^/payment_intents/(pi_[a-zA-Z0-9]+)(/confirm|/cancel)?$~', $path, $match)) throw new RuntimeException('Unexpected payment request ' . $path);
                $intent = $this->intents[$match[1]] ?? throw new RequestException(404);
                if ($verb === 'get') {
                    $copy = clone $intent;
                    if (isset($data['expand'])) $copy->latest_charge = (object) ['id' => $intent->latest_charge ?? 'ch_attempt', 'disputed' => false];
                    return $copy;
                }
                if (($match[2] ?? '') === '/confirm') {
                    // Observed on Stripe TEST / 2025-07-30.basil in the live pass; this is only a regression fixture.
                    if (isset($data['confirmation_token']) && $intent->confirmation_method === 'manual') throw new RequestException(400, ['error' => ['param' => 'confirmation_method', 'message' => 'Payment details were collected through Stripe Elements and cannot be confirmed with a Payment Intent configured with manual confirmation_method.']]);
                    // Model the mismatched configuration recovered from r30f, where Elements collected
                    // card/Link but the intent had card/Klarna/Link/Cash App. The original 400 body was not retained.
                    if ($this->strictElements && isset($data['confirmation_token']) && $intent->payment_method_types !== ['card', 'link']) {
                        throw new RequestException(400, ['error' => ['type' => 'invalid_request_error',
                            'param' => 'payment_method_types', 'message' => 'Deferred Elements and PaymentIntent payment method types do not match.']]);
                    }
                    if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation'], true)) throw new RequestException(400, ['error' => ['code' => 'payment_intent_unexpected_state']]);
                    if ($this->beforeConfirm) ($this->beforeConfirm)($intent, $data);
                    $this->confirms[] = [$key, $data, $intent->amount];
                    // A token confirmation attaches the token's new method and the request's per-method options.
                    if (isset($data['confirmation_token'])) $intent->payment_method = 'pm_paid';
                    if (isset($data['payment_method_options'])) $intent->payment_method_options = json_decode(json_encode($data['payment_method_options']));
                    $this->settle($intent, $data);
                    $this->cache[$key] = clone $intent;
                    if ($this->loseConfirm) { $this->loseConfirm = false; throw new ConnectionException('Lost confirm'); }
                    return clone $intent;
                }
                if (($match[2] ?? '') === '/cancel') {
                    if (!in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true)) throw new RequestException(400);
                    $intent->status = 'canceled';
                }
                else {
                    if (isset($data['amount']) && $intent->currency === 'usd' && $data['amount'] < 50) throw new RequestException(400, ['error' => ['code' => 'amount_too_small']]);
                    foreach ($data as $field => $value) {
                        if ($field === 'metadata') $intent->metadata = (object) array_replace((array) $intent->metadata, $value);
                        else $intent->$field = is_array($value) ? json_decode(json_encode($value)) : $value;
                    }
                    $intent->last_payment_error = null; // Stripe clears this on any successful update.
                }
                return clone $intent;
            }
            private function settle($intent, $data) {
                if ($intent->status === 'succeeded') return;
                $intent->latest_charge = 'ch_test' . count($this->confirms);
                $intent->status = $this->status;
                if ($this->status === 'succeeded') { $intent->amount_received = $intent->amount; $this->charges[] = [$intent->id, $intent->amount, $data['payment_method'] ?? null]; }
            }
        };
        Http::$handler = fn ($verb, $path, $data) => $stripe->request($verb, $path, $data);
        $test($invoice, $stripe);
    };
    try {
        require __DIR__ . '/renewal-retries.php';
        foreach (['requires_payment_method', 'requires_action', 'processing', 'partial'] as $state) {
            $run('new-card-old-form-'.$state, function ($invoice, $stripe) use ($gateway, $state) {
                $old = $gateway->pay($invoice, 20)['data']['intent'];
                $stripe->intents[$old->id]->payment_method_types = ['card', 'klarna', 'link', 'cashapp'];
                $stripe->intents[$old->id]->status = $state === 'partial' ? 'requires_payment_method' : $state;
                if ($state === 'partial') $stripe->intents[$old->id]->amount_received = 100;
                $result = null;
                try { $result = $gateway->pay($invoice, 20); }
                catch (\App\Exceptions\DisplayException $e) {
                    if ($state !== 'partial') throw $e;
                    check($e->getMessage() === 'Payment needs review. Contact support to check this payment.',
                        'Partially received money reaches the specific payment-review refusal');
                }
                if ($state === 'processing') check(($result['name'] ?? null) === 'gateways.ae-stripe::payment-status'
                    && $result['data']['message'] === 'Payment pending. Refresh the invoice to check its status.',
                    'Processing reaches the pending payment view without throwing');
                if ($state === 'partial') check($result === null, 'Partially received money never opens a replacement form');
                if (in_array($state, ['requires_payment_method', 'requires_action'], true)) {
                    check($stripe->intents[$old->id]->status === 'canceled' && $result['data']['intent']->id !== $old->id,
                        'Opening an old mismatched form cancels the known unfunded intent and prepares card and Link');
                } else check(count($stripe->intents) === 1 && $stripe->intents[$old->id]->status !== 'canceled',
                    'A method-set change cannot replace processing or partially received funds');
                check(!$stripe->confirms && !$stripe->charges, 'Changing the form never starts a debit');
            });
        }
        $run('new-card-decline', function ($invoice, $stripe) use ($gateway) {
            $stripe->strictElements = true;
            $intent = $gateway->pay($invoice, 20)['data']['intent'];
            $stripe->beforeConfirm = function ($current) {
                $current->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'generic_decline'];
                $current->latest_charge = 'ch_declined';
                throw new RequestException(402, ['error' => ['type' => 'card_error', 'code' => 'card_declined']]);
            };
            $result = $gateway->confirmPayment(new Request(['intent' => $intent->id, 'amount' => 2000,
                'confirmation_token' => 'ctoken_declined', 'save' => false]));
            check($result['status'] === 409 && $result['data']['declined'] === true
                && $result['data']['message'] === 'Your bank declined this card. Try a different card, or contact your bank.'
                && (ExtensionHelper::$payments['920:'.$intent->id]['status'] ?? null) === 'failed' && !$stripe->charges,
                'A new-card refusal reaches the bank decline path and records failure instead of a generic integration 409');
        });
        $run('new-card-missing-types-open', function ($invoice, $stripe) use ($gateway) {
            $old = $gateway->pay($invoice, 20)['data']['intent'];
            unset($stripe->intents[$old->id]->payment_method_types);
            $fresh = $gateway->pay($invoice, 20)['data']['intent'];
            check($stripe->intents[$old->id]->status === 'canceled' && $fresh->id !== $old->id
                && $fresh->payment_method_types === ['card', 'link'], 'Missing method types retire only the unfunded old Elements form');
            check(!$stripe->confirms && !$stripe->charges, 'Preparing a replacement never starts a debit');
        });
        $run('new-card-missing-types-confirm', function ($invoice, $stripe) use ($gateway) {
            $intent = $gateway->pay($invoice, 20)['data']['intent'];
            unset($stripe->intents[$intent->id]->payment_method_types);
            $result = $gateway->confirmPayment(new Request(['intent' => $intent->id, 'amount' => 2000,
                'confirmation_token' => 'ctoken_old', 'save' => false]));
            check($result['status'] === 409 && str_contains($result['data']['message'], 'form changed')
                && !$stripe->confirms && !$stripe->charges, 'Missing method types cannot authorize an open-tab confirmation token');
        });
        $run('new-card-old-tab', function ($invoice, $stripe) use ($gateway) {
            $intent = $gateway->pay($invoice, 20)['data']['intent'];
            $stripe->intents[$intent->id]->payment_method_types = ['card', 'klarna', 'link', 'cashapp'];
            $result = $gateway->confirmPayment(new Request(['intent' => $intent->id, 'amount' => 2000,
                'confirmation_token' => 'ctoken_old', 'save' => false]));
            check($result['status'] === 409 && str_contains($result['data']['message'], 'form changed')
                && !$stripe->confirms && !$stripe->charges, 'An already open mismatched form is refused before any debit');
        });
        foreach ([false, true] as $save) {
            $run('new-card-confirm-'.($save ? 'save' : 'once'), function ($invoice, $stripe) use ($gateway, $save) {
                $invoice->total = 10.99;
                $stripe->strictElements = true;
                $intent = $gateway->pay($invoice, 10.99)['data']['intent'];
                $result = $gateway->confirmPayment(new Request(['intent' => $intent->id, 'amount' => 1099,
                    'confirmation_token' => 'ctoken_card', 'save' => $save]));
                check($result['status'] === 200 && ($result['data']['status'] ?? null) === 'succeeded',
                    'A new-card invoice payment confirms instead of the generic 409: '.json_encode($result));
                check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 1099
                    && (ExtensionHelper::$payments['920:'.$intent->id]['status'] ?? null) === 'succeeded',
                    'The accepted 10.99 is charged and recorded once');
                $method = $stripe->intents[$intent->id]->payment_method_options;
                check($method->card->setup_future_usage === ($save ? 'off_session' : 'none')
                    && $method->link->setup_future_usage === ($save ? 'off_session' : 'none'),
                    'Confirmation keeps the explicit saving choice for card and Link');
            });
        }
        $run('elements-replacement-currency', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $stripe->intents['pi_test1']->status = 'canceled';
            ExtensionHelper::$payments['920:pi_test1'] = ['status' => 'failed', 'amount' => 20];
            $invoice->currency_code = 'eur';
            $result = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'confirmation_token' => 'ctoken_new', 'amount' => 2000]));
            check($result['status'] === 409 && str_contains($result['data']['message'], 'changed') && !$stripe->confirms && !$stripe->charges,
                'An open USD Elements form cannot confirm a replacement in EUR at the same minor-unit amount');
        });
        foreach (['usd' => '20.00 USD', 'jpy' => '20 JPY', 'ugx' => '20 UGX', 'isk' => '20 ISK'] as $currency => $label) {
            $run('displayed-resume-'.$currency, function ($invoice, $stripe) use ($gateway, $currency, $label) {
                $invoice->currency_code = $currency;
                $gateway->pay($invoice, 20);
                $stripe->intents['pi_test1']->status = 'requires_action';
                $stale = clone $invoice; $stale->total = 99;
                $view = $gateway->pay($stale, 99);
                check(($view['data']['paymentAmount'] ?? null) === $label && $view['data']['intent']->id === 'pi_test1',
                    'A resumed payment displays its exact amount and currency despite a stale caller invoice');
            });
        }
        foreach (['unconfirmed', 'failed', 'canceled', 'processing', 'partial', 'unread', 'cancel-unread', 'unknown'] as $outcome) {
            $run('one-time-after-off-'.$outcome, function ($invoice, $stripe) use ($gateway, $outcome) {
                foreach (ExtensionHelper::$agreements as &$row) $row['gateway_id'] = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
                unset($row);
                User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                    'version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4,
                ]);
                $stripe->loseCreate = true;
                paymentStops(fn () => $gateway->chargeAutoPayAccepted($invoice, 20, new BillingAgreement));
                check(isset($stripe->intents['pi_test1']), 'The old creation reached Stripe before its response was lost');
                $old = $stripe->intents['pi_test1'];
                if ($outcome === 'failed') $old->last_payment_error = (object) ['code' => 'card_declined'];
                if (in_array($outcome, ['canceled', 'processing', 'unknown'], true)) $old->status = $outcome;
                if ($outcome === 'partial') $old->amount_received = 100;
                if ($outcome === 'unread') $stripe->failure = function ($verb, $path) {
                    if ($verb === 'get' && $path === '/payment_intents/pi_test1') throw new ConnectionException('Unknown payment outcome');
                };
                if ($outcome === 'cancel-unread') $stripe->failure = function ($verb, $path) {
                    if (str_ends_with($path, '/cancel')) throw new ConnectionException('Cancellation outcome unknown');
                };
                $gateway->saveAutoPayDefault(new Request(['enabled' => false]));
                check($gateway->autoPayDefault(User::$row) === null, 'Off revokes the old account instruction');
                $view = null;
                try { $view = $gateway->pay($invoice, 20); }
                catch (\App\Exceptions\DisplayException $e) {
                    if (in_array($outcome, ['unconfirmed', 'failed', 'canceled'], true)) throw $e;
                }
                check(!$stripe->confirms && !$stripe->charges, 'Opening one-time Pay cannot confirm the revoked instruction');
                if (in_array($outcome, ['unconfirmed', 'failed', 'canceled'], true)) {
                    check($old->status === 'canceled' && $view['data']['intent']->id === 'pi_test2',
                        'One-time checkout retires a known unfunded reservation after Off');
                    $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                    check(!isset($attempt['auto_pay_agreement']) && !isset($attempt['accepted_amount']) && !isset($attempt['accepted_currency']),
                        'Only proven cancellation releases the old quote and Auto Pay constraints');
                    $result = $gateway->confirmPayment(new Request(['intent' => 'pi_test2', 'confirmation_token' => 'ctoken_new', 'amount' => 2000]));
                    check(($result['data']['status'] ?? null) === 'succeeded' && count($stripe->charges) === 1,
                        'The customer can pay once with a new card after disabling Auto Pay');
                } else {
                    check(count($stripe->intents) === 1 && $old->status !== 'canceled',
                        'Processing, partial and unknown outcomes cannot be replaced by one-time checkout');
                    $held = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                    check(($held['auto_pay_agreement'] ?? null) === 4 && ($held['accepted_amount'] ?? null) === 2000
                        && ($held['accepted_currency'] ?? null) === 'usd', 'Uncertain outcomes retain the old payment constraints');
                }
            });
        }
        $run('revoked-native-confirmation', function ($invoice, $stripe) use ($gateway) {
            foreach (ExtensionHelper::$agreements as &$row) $row['gateway_id'] = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
            unset($row);
            User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                'version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4,
            ]);
            $stripe->status = 'requires_action';
            paymentStops(fn () => $gateway->chargeAutoPayAccepted($invoice, 20, new BillingAgreement));
            $gateway->saveAutoPayDefault(new Request(['enabled' => false]));
            $stripe->intents['pi_test1']->status = 'requires_confirmation';
            $result = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'amount' => 2000, 'save' => false]));
            check($result['status'] === 409 && str_contains($result['data']['message'], 'Auto Pay card changed')
                && count($stripe->confirms) === 1 && !$stripe->charges,
                'Allowing one-time inspection never permits confirmation against revoked account authority');
        });
        $run('accepted-currency', function ($invoice, $stripe) use ($gateway) {
            $accepted = clone $invoice;
            $invoice->currency_code = 'eur';
            $message = paymentStops(fn () => $gateway->chargeAccepted($accepted, 20, new BillingAgreement));
            check(str_contains($message, 'changed') && !$stripe->intents && !$stripe->confirms && !$stripe->charges,
                'An accepted USD quote cannot create or confirm EUR under the invoice lock');
        });
        foreach (['recover', 'expired', 'canceled', 'invalid-params', 'legacy-currency'] as $replacement) {
            foreach (['eur', 'jpy'] as $currency) {
                $run('accepted-currency-'.$replacement.'-'.$currency, function ($invoice, $stripe) use ($gateway, $replacement, $currency) {
                    $stripe->loseCreate = true;
                    paymentStops(fn () => $gateway->chargeAccepted($invoice, 20, new BillingAgreement));
                    $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                    check(($attempt['accepted_currency'] ?? null) === 'usd', 'Accepted currency commits before creation');
                    if ($replacement === 'legacy-currency') unset($attempt['accepted_currency']);
                    if ($replacement === 'expired') $attempt['created'] = time() - 24 * 3600;
                    if ($replacement === 'canceled') $stripe->intents['pi_test1']->status = 'canceled';
                    if ($replacement === 'invalid-params') $attempt['params']['automatic_payment_methods'] = ['enabled' => 'true'];
                    User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
                    $invoice->currency_code = $currency;
                    $gateway->retryChargeAttempts();
                    check(!$stripe->confirms && !$stripe->charges && count($stripe->intents) === 1,
                        'Recovery cannot replace or confirm a quote in another currency, including a different minor-unit scale');
                    check(count(Notification::$rows) === 1 && str_contains(Notification::$rows[0]['body'], 'changed'),
                        'Currency refusal gives the customer an invoice refresh action');
                });
            }
        }
        $run('retire-core-event', function ($invoice, $stripe) use ($gateway) {
            $gateway->boot(); $gateway->pay($invoice, 20);
            $invoice->status = 'cancelled'; Gateway::$enabled = false;
            DB::transaction(fn () => \Illuminate\Support\Facades\Event::$listeners[\App\Events\Invoice\Updated::class](new \App\Events\Invoice\Updated($invoice)));
            check($stripe->intents['pi_test1']->status === 'canceled', 'The core invoice event retires a form even while the gateway is disabled or bundled Stripe is present');
            Gateway::$enabled = true; $invoice->status = 'pending';
            ExtensionHelper::$payments['920:pi_oldbank'] = ['amount' => 20, 'status' => 'processing'];
            check(!$gateway->cancelInvoicePayments($invoice), 'Credit also waits for a legacy processing transaction without an attempt property');
        });
        $run('retire-forms', function ($invoice, $stripe) use ($gateway) {
            foreach (['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture'] as $status) {
                $gateway->pay($invoice, 20);
                $intent = $stripe->intents[array_key_last($stripe->intents)]; $intent->status = $status;
                $invoice->status = 'cancelled';
                $gateway->retryChargeAttempts();
                $payable = in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action']);
                check($intent->status === ($payable ? 'canceled' : $status), 'Invoice closure cancels a checkout-only ' . $status . ' form without cancelling in-flight money');
                if (!$payable) {
                    check(!$gateway->cancelInvoicePayments($invoice), 'In-flight money prevents applying credit over the same payment');
                    $intent->status = 'succeeded'; $intent->amount_received = 2000;
                    $gateway->retryChargeAttempts();
                    check($gateway->receiptReturnStatus($invoice, $intent->id, 'excess')['amount_minor'] === 2000
                        && !isset(User::$row->props->values['ae_stripe_checkout_920']),
                        'Closed ' . $status . ' success transfers whole-receipt ownership to the ledger and retires only the checkout attempt');
                }
                User::$row->props->values = []; $invoice->status = 'pending';
            }
            $gateway->pay($invoice, 20); $intent = $stripe->intents[array_key_last($stripe->intents)];
            DB::transaction(function () use ($gateway, $invoice, $intent) {
                check($gateway->cancelInvoicePayments($invoice) && $intent->status === 'canceled', 'Credit caller can retire the form before spending credit in its transaction');
                ExtensionHelper::$payments['920:credit'] = ['amount' => 5, 'status' => 'succeeded'];
            });
            $view = $gateway->pay($invoice, 15);
            check($view['data']['intent']->amount === 1500, 'A fresh form after partial credit uses only the remaining balance');
            ExtensionHelper::$payments['920:credit']['amount'] = 10;
            $gateway->retryChargeAttempts();
            check($stripe->intents[$view['data']['intent']->id]->status === 'canceled', 'Recovery also retires an unchanged pending invoice after partial credit');
        });
        $run('retire-outage', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $invoice->status = 'cancelled';
            $stripe->failure = fn ($verb, $path) => str_ends_with($path, '/cancel') ? throw new RequestException(503) : null;
            $gateway->retryChargeAttempts();
            check(isset(User::$row->props->values['ae_stripe_checkout_920']) && $stripe->intents['pi_test1']->status !== 'canceled', 'A failed cancellation keeps its payment identity');
            $stripe->failure = null; Gateway::$enabled = false; $gateway->retryChargeAttempts();
            check($stripe->intents['pi_test1']->status === 'canceled' && !$stripe->charges, 'Recovery cancels the old form when Stripe access returns even with collection paused');
        });
        $run('retire-3ds-outage-late-money', function ($invoice, $stripe) use ($gateway) {
            $gateway->boot(); $gateway->pay($invoice, 20);
            $intent = $stripe->intents['pi_test1']; $intent->status = 'requires_action';
            $stripe->failure = fn ($verb, $path) => str_ends_with($path, '/cancel') ? throw new ConnectionException('Cancellation transport unavailable') : null;
            DB::transaction(function () use ($invoice) {
                $invoice->status = 'cancelled';
                \Illuminate\Support\Facades\Event::$listeners[\App\Events\Invoice\Updated::class](new \App\Events\Invoice\Updated($invoice));
            });
            check($invoice->status === 'cancelled' && $intent->status === 'requires_action', 'Closure commits during a cancellation outage while an old 3DS tab remains open');
            // Stripe.js can finish authentication directly, without another local confirmation call.
            $intent->status = 'succeeded'; $intent->amount_received = $intent->amount;
            $applications = ExtensionHelper::$applications;
            $gateway->webhook(eventRequest($intent)); $gateway->webhook(eventRequest($intent));
            $gateway->retryChargeAttempts();
            $calls = count(Http::$calls);
            $gateway->retryChargeAttempts();
            $return = $gateway->receiptReturnStatus($invoice, $intent->id, 'excess');
            check($invoice->status === 'cancelled' && count(ExtensionHelper::$payments) === 2 && ExtensionHelper::$applications === $applications + 2
                && array_sum(array_column(ExtensionHelper::$payments, 'amount')) === 0.0,
                'Late 3DS receipt and return post atomically as zero native funding while the closed invoice stays closed');
            check($return['state'] === 'refunded' && $return['amount_minor'] === $intent->amount_received && count($stripe->refunds) === 1,
                'Combined stale-form recovery returns the whole closed-invoice receipt once without a staff step');
            check(!isset(User::$row->props->values['ae_stripe_checkout_920']) && count(Http::$calls) === $calls
                && $gateway->receiptReturnStatus($invoice, $intent->id, 'excess')['key'] === $return['key'],
                'Retiring the checkout attempt preserves its ledger allocation and another attempt sweep makes no Stripe request');
        });
        $run('retire-overlap', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $intent = $stripe->intents['pi_test1'];
            $stripe->failure = function ($verb, $path) use ($intent) {
                if (str_ends_with($path, '/cancel')) { $intent->status = 'processing'; throw new RequestException(400); }
            };
            check(!$gateway->cancelInvoicePayments($invoice) && $intent->status === 'processing', 'A confirmation completed during cancellation is kept for settlement');
        });
        $run('retire-lost-create', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseCreate = true; paymentStops(fn () => $gateway->pay($invoice, 20));
            $invoice->status = 'cancelled'; $gateway->retryChargeAttempts();
            check(count($stripe->intents) === 1 && $stripe->intents['pi_test1']->status === 'canceled', 'Closing an invoice recovers the same lost unconfirmed creation and cancels it');
        });
        $run('resolve-command-error', function () {
            require_once __DIR__ . '/../extension/AeStripe/ResolveChargeCommand.php';
            $rows = Gateway::$rows; Gateway::$rows = [];
            $reported = count($GLOBALS['reportedErrors'] ?? []);
            try {
                $command = new \Paymenter\Extensions\Gateways\AeStripe\ResolveChargeCommand;
                check($command->handle() === 1 && count($GLOBALS['reportedErrors'] ?? []) === $reported + 1
                    && str_contains(implode(' ', $command->output), \RuntimeException::class)
                    && !str_contains(implode(' ', $command->output), 'Missing row'),
                    'An unexpected resolve-command failure is reported and identifies its class without printing its message');
            } finally { Gateway::$rows = $rows; }
        });
        $run('return-secret', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'requires_action'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $intent = $stripe->intents['pi_test1']; $intent->status = 'requires_confirmation'; $stripe->status = 'succeeded';
            $attempt = User::$row->props->values['ae_stripe_checkout_920'];
            foreach ([null, 'wrong-secret'] as $secret) {
                $calls = count(Http::$calls);
                $result = $gateway->finishPayment(new Request(['payment_intent' => $intent->id, 'payment_intent_client_secret' => $secret]));
                check(is_array($result) && !empty($result['data']['invoiceUrl']) && !$stripe->charges
                    && $intent->status === 'requires_confirmation' && !array_filter(array_slice(Http::$calls, $calls), fn ($call) => $call[0] !== 'get')
                    && User::$row->props->values['ae_stripe_checkout_920'] === $attempt,
                    'A missing or wrong redirect secret shows a next step without changing the payment');
            }
            $gateway->finishPayment(new Request(['payment_intent' => $intent->id, 'payment_intent_client_secret' => $intent->client_secret]));
            check(count($stripe->charges) === 1 && isset(ExtensionHelper::$payments['920:' . $intent->id]), 'A bound bank return can finish its original manual payment');
        });
        $run('recovery-refusals', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseCreate = true; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->failure = fn ($verb, $path) => $path === '/payment_intents' ? throw new RequestException(400) : null;
            Notification::$fail = true; $gateway->retryChargeAttempts();
            $notice = json_decode(User::$row->props->values['ae_stripe_checkout_920'] ?? 'null', true);
            check(!$notice && !Notification::$rows && count(array_filter(Audit::$rows, fn ($a) => $a['event'] === 'ae_stripe_payment_refused' && !$a['new_values']['notice_sent'])) === 1, 'A failed refusal notice keeps its pending audit without a saved debit instruction');
            Notification::$fail = false; $stripe->failure = null; $calls = count(Http::$calls);
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(count(Notification::$rows) === 1 && Notification::$rows[0]['user_id'] === 7 && !empty(Notification::$rows[0]['url']) && !isset(User::$row->props->values['ae_stripe_checkout_920']) && !$stripe->charges && count(Http::$calls) === $calls, 'A later pass delivers the refused-create notice once, removes its reminder and never re-debits');
            $stripe->failure = null; $gateway->pay($invoice, 20);
            $GLOBALS['fakeConsole'] = true;
            $other = new Invoice(User::$row); $other->id = 921; Invoice::$rows[921] = $other;
            $allowed = new User(new Properties('')); $allowed->id = 99; $allowed->role_id = 1;
            $allowed->permissions = ['admin.gateways.viewAny', 'admin.gateways.update'];
            $noList = clone $allowed; $noList->id = 98; $noList->permissions = ['admin.gateways.update'];
            $noUpdate = clone $allowed; $noUpdate->id = 97; $noUpdate->permissions = ['admin.gateways.viewAny'];
            User::$noticeStaff = [$noList, $noUpdate, $allowed];
            try {
                $stripe->failure = fn () => throw new RequestException(401);
                check($gateway->charge($other, 20, new BillingAgreement) === true && isset(User::$row->props->values['ae_stripe_checkout_921']), 'A bad API key keeps a cron instruction pending');
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); $attempt['retry'] = true;
                User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
                $stripe->failure = fn () => throw new RequestException(403);
                $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
                $staff = array_filter(Notification::$rows, fn ($notice) => $notice['user_id'] !== 7);
                check(array_column($staff, 'user_id') === [99], 'Persistent refused reads alert only staff who can open the gateway edit destination');
            } finally { User::$noticeStaff = null; }
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); $attempt['retry'] = false;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(count(array_filter(Notification::$rows, fn ($notice) => $notice['user_id'] !== 7)) === 1, 'The same key refusal on another invoice does not add staff alerts');
            $stripe->failure = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1, 'Fixing the key resumes the unsent renewal once');
        });
        $run('recovery-refusals-without-access', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); $attempt['retry'] = true;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $staff = new User(new Properties('')); $staff->id = 98; $staff->role_id = 1;
            $staff->permissions = ['admin.gateways.update'];
            User::$noticeStaff = [$staff]; $GLOBALS['fakeConsole'] = true;
            try {
                $stripe->failure = fn () => throw new RequestException(401);
                $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
                check(array_column(Notification::$rows, 'user_id') === [98], 'An access refusal still reaches staff when nobody can open its destination');
                $staff->permissions[] = 'admin.gateways.viewAny';
                $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
                check(array_column(Notification::$rows, 'user_id') === [98] && !$stripe->charges,
                    'Restoring staff access preserves the same-key warning dedupe without sending a charge');
            } finally { User::$noticeStaff = null; }
        });
        $run('replacement-renewal', function ($invoice, $stripe) use ($gateway) {
            foreach (['canceled', 'automatic'] as $shape) {
                $gateway->pay($invoice, 20); $id = array_key_last($stripe->intents);
                $GLOBALS['fakeConsole'] = true; Gateway::$enabled = false;
                $gateway->charge($invoice, 20, new BillingAgreement); Gateway::$enabled = true;
                if ($shape === 'canceled') $stripe->intents[$id]->status = 'canceled';
                else $stripe->intents[$id]->confirmation_method = 'automatic';
                $gateway->retryChargeAttempts();
                check(count($stripe->charges) === ($shape === 'canceled' ? 1 : 2), 'Replacement preserves the unsent renewal on ' . $shape);
                $invoice->id++; Invoice::$rows[$invoice->id] = $invoice;
            }
        });
        $run('action-release', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'requires_action'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(($attempt['confirmation']['key'] ?? null) === end($stripe->confirms)[0], 'Authentication preserves the sent confirm key for a later missing-payment check');
            $stripe->failure = fn () => throw new RequestException(404);
            paymentStops(fn () => $gateway->pay($invoice, 20));
            $gateway->resolveChargeAttempt($invoice, null, 'Original account support confirms this debit was never sent', '', $attempt['confirmation']['key']);
            check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'An authenticated payment with verified no-charge proof has an audited way out');
        });
        $run('replacement-lock', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $stripe->intents['pi_test1']->status = 'canceled'; $GLOBALS['fakeConsole'] = true;
            $stripe->failure = function ($verb, $path) {
                if ($verb === 'get' && $path === '/payment_intents/pi_test1') DB::$afterTransaction = function () {
                    DB::$afterTransaction = function () {
                        DB::$beforeUserLock = fn () => throw new \Illuminate\Database\QueryException('Lock wait timeout', 1205);
                    };
                };
            };
            try { $gateway->charge($invoice, 20, new BillingAgreement); throw new RuntimeException('An unsaved replacement must surface its lock failure'); }
            catch (\Illuminate\Database\QueryException $e) { check(!isset(User::$row->props->values['ae_stripe_checkout_920']) && !$stripe->charges, 'A prior round cannot claim a retry after its attempt was removed'); }
        });
        $run('removed-recovery-card', function ($invoice, $stripe) use ($gateway) {
            foreach ([false, true] as $confirmation) {
                if ($confirmation) $stripe->beforeConfirm = fn () => throw new ConnectionException('Confirm never arrived');
                else $stripe->dropCreate = true;
                paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
                ExtensionHelper::$agreements = []; $stripe->beforeConfirm = null;
                $gateway->retryChargeAttempts(); $calls = count(Http::$calls); $gateway->retryChargeAttempts();
                $notice = end(Notification::$rows);
                check($notice && $notice['user_id'] === 7 && str_contains($notice['body'], 'removed') && !empty($notice['url']) && count(Http::$calls) === $calls && !$stripe->charges, 'A removed recovery card notifies the customer and stops polling');
                $invoice->id++; Invoice::$rows[$invoice->id] = $invoice;
                ExtensionHelper::$agreements = ['pm_test' => ['id' => 4, 'user_id' => 7, 'gateway_id' => 3]];
            }
        });
        $run('link-display-settings', function ($invoice, $stripe) use ($gateway) {
            $settings = array_column($gateway->getConfig(), null, 'name');
            $setting = $settings['stripe_link_display'] ?? [];
            check(($setting['label'] ?? null) === 'Link at checkout' && ($setting['type'] ?? null) === 'select',
                'The gateway offers one Link at checkout selector');
            check(($setting['options'] ?? null) === ['above_card' => 'Small Link button below the form',
                'inside_card' => 'Inside card form', 'stripe_default' => 'Stripe default']
                && ($setting['default'] ?? null) === 'above_card' && isset($setting['hint']),
                'Link display has three plain choices, hover help and the above-card default');
            foreach ([null, '', 'unknown', 'above_card', 'inside_card', 'stripe_default'] as $value) {
                $configured = new \Paymenter\Extensions\Gateways\AeStripe\AeStripe(array_replace($gateway->config,
                    $value === null ? [] : ['stripe_link_display' => $value]));
                $view = $configured->pay($invoice, 20);
                check(($view['data']['linkDisplay'] ?? null) === (in_array($value, ['inside_card', 'stripe_default'], true) ? $value : 'above_card'),
                    'Checkout reads the saved choice and defaults old or empty gateways to Link above card');
                $setup = $configured->createBillingAgreement($invoice->user);
                check(($setup['data']['linkDisplay'] ?? null) === (in_array($value, ['inside_card', 'stripe_default'], true) ? $value : 'above_card'),
                    'Save-a-card reads the same saved choice and default as checkout');
            }
            check(!$stripe->charges, 'Choosing the Link display never charges on opening checkout');
        });
        $run('business-name', function ($invoice) use ($gateway) {
            $names = fn () => [$gateway->pay($invoice, 20)['data'], $gateway->createBillingAgreement($invoice->user)['data']];
            foreach (['Beco Technologies' => 'Beco Technologies', '  Beco  ' => 'Beco', '' => null, ' ' => null] as $value => $expected) {
                $GLOBALS['fakeConfig']['settings.company_name'] = (string) $value;
                foreach ($names() as $data) check(array_key_exists('businessName', $data) && $data['businessName'] === $expected,
                    'Both Stripe forms name the site\'s Company Name, or nothing when it is empty');
            }
            unset($GLOBALS['fakeConfig']['settings.company_name']);
            foreach ($names() as $data) check(array_key_exists('businessName', $data) && $data['businessName'] === null,
                'An unset Company Name passes no business name');
        });
        $run('create-elements', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20);
            check($view['data']['intent']->confirmation_method === 'automatic' && !$stripe->charges, 'Opening Elements reserves an unconfirmed automatic intent without a debit');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(!empty($attempt['elements']) && !isset($attempt['params']['confirmation_method']), 'Elements uses the default confirmation method in its durable create parameters');
            $gateway->consent(new Request(['intent' => $view['data']['intent']->id, 'save' => false]));
            check($stripe->intents['pi_test1']->status === 'requires_payment_method', 'The old consent endpoint recognizes a current Elements attempt');
            $gateway->charge($invoice, 20, new BillingAgreement);
            check($stripe->intents['pi_test1']->status === 'canceled' && $stripe->intents['pi_test2']->confirmation_method === 'manual' && count($stripe->charges) === 1, 'Native Pay cancels the open Elements intent before creating its manual attempt');
        });
        $run('manual-elements-tab', function ($invoice, $stripe) use ($gateway) {
            $intent = invoke($gateway, 'checkoutIntent', $invoice, invoke($gateway, 'checkoutPaymentData', $invoice, 2000, 'cus_owner'));
            $response = $gateway->confirmPayment(new Request(['intent' => $intent->id, 'save' => false, 'confirmation_token' => 'ctoken_oldtab', 'amount' => 2000]));
            check($response['status'] === 409 && str_contains($response['data']['message'], 'form changed') && !$stripe->confirms
                && $response['data']['declined'] === false,
                'An older manual checkout tab must refresh before it can send an Elements token');
        });
        $run('elements-repeat-decline', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20); $original = $view['data']['intent']->id;
            $stripe->beforeConfirm = function ($intent) {
                $intent->status = 'requires_payment_method'; $intent->latest_charge = 'ch_declined';
                $intent->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'];
                throw new RequestException(402, ['error' => ['code' => 'card_declined']]);
            };
            foreach (['ctoken_first', 'ctoken_second'] as $index => $token) {
                $response = $gateway->confirmPayment(new Request(['intent' => $original, 'save' => false,
                    'confirmation_token' => $token, 'amount' => 2000]));
                check($response['status'] === 409, 'A declined payment returns a customer error');
                check($response['data']['message'] === 'Insufficient funds. Try a different card, or contact your bank.' && $response['data']['declined'] === true,
                    'A decline gives the same reason the invoice records and tells the form to clear the declined card');
                check(count(ExtensionHelper::$payments) === $index + 1, 'Each submit from the same open form records its own decline');
            }
            foreach ($stripe->intents as $failed) {
                $gateway->webhook(eventRequest($failed, type: 'payment_intent.payment_failed'));
                $gateway->webhook(eventRequest($failed, type: 'payment_intent.payment_failed'));
            }
            check(count(ExtensionHelper::$payments) === 2, 'Repeated webhooks do not duplicate declined attempts');
            $stripe->beforeConfirm = null;
            $response = $gateway->confirmPayment(new Request(['intent' => $original, 'save' => false,
                'confirmation_token' => 'ctoken_recovery', 'amount' => 2000]));
            check($response['data']['status'] === 'succeeded' && count($stripe->charges) === 1
                && count(ExtensionHelper::$payments) === 3, 'The same form recovers with one payment and both declines retained');
        });
        $run('elements-authentication', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseCreate = true; paymentStops(fn () => $gateway->pay($invoice, 20));
            $view = $gateway->pay($invoice, 20); $id = $view['data']['intent']->id;
            check(count($stripe->intents) === 1 && !$stripe->charges, 'A lost Elements create reuses its reserved identity without charging');
            $stripe->status = 'requires_action';
            $stripe->beforeConfirm = function ($intent) { check(DB::$locked && DB::$userLocked && $intent->amount === 2000, 'Elements first confirm holds both locks and uses the current amount'); };
            $result = $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            check($result['data']['client_secret'] === $id . '_secret', 'Only an authentication response exposes the PaymentIntent secret');
            $gateway->pay($invoice, 20);
            check($stripe->intents[$id]->status === 'requires_action', 'Reopening checkout preserves a current automatic authentication attempt');
            $gateway->retryChargeAttempts(); $calls = count(Http::$calls); $gateway->retryChargeAttempts();
            check(count(Http::$calls) === $calls && json_decode(User::$row->props->values['ae_stripe_checkout_920'], true)['retry'],
                'Automatic authentication backs off repeated reads while keeping status recovery enabled');
            // Model an already-started authentication completing after an invoice change.
            // This boundary fixture does not execute or validate Stripe.js authentication.
            $invoice->total = 10; $stripe->intents[$id]->status = 'succeeded'; $stripe->intents[$id]->amount_received = 2000;
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $attempt['authentication_check']['at'] = time() - 3601;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $gateway->retryChargeAttempts();
            check(isset(ExtensionHelper::$payments['920:' . $id]), 'Status recovery settles completed authentication without a webhook or customer return');
            $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'amount' => 2000]));
            check(count($stripe->confirms) === 1 && ExtensionHelper::$payments['920:' . $id]['amount'] === 20.0
                && count(array_filter(Audit::$rows, fn ($row) => $row['event'] === 'ae_stripe_receipt_return')) === 1,
                'Automatic authentication is not reconfirmed; a later balance change reserves and attempts the excess return');
        });
        $run('elements-failed-authentication', function ($invoice, $stripe) use ($gateway) {
            foreach (['confirm', 'page', 'return', 'webhook'] as $contact) {
                $view = $gateway->pay($invoice, 20); $id = $view['data']['intent']->id; $stripe->status = 'requires_action';
                $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
                $intent = $stripe->intents[$id]; $intent->status = 'requires_payment_method';
                $intent->last_payment_error = (object) ['code' => 'payment_intent_authentication_failure'];
                if ($contact === 'confirm') {
                    // The open dialog asks right after a bank check fails or is cancelled in Stripe.js.
                    $response = $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'amount' => 2000]));
                    check($response['status'] === 409 && $response['data']['declined'] === true
                        && $response['data']['message'] === 'Your bank did not confirm this payment. You were not charged. Try again or use a different card.'
                        && (ExtensionHelper::$payments[$invoice->id . ':' . $id]['status'] ?? null) === 'failed',
                        'A failed bank check reads as a decline in the dialog and is recorded at once');
                    check($intent->status === 'canceled', 'Failed Elements authentication is canceled on confirm');
                    $stripe->status = 'succeeded';
                    $result = $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'confirmation_token' => 'ctoken_retry', 'amount' => 2000]));
                    check($result['data']['intent'] !== $id && $result['data']['status'] === 'succeeded', 'The same open form pays with the next card after a failed bank check');
                    $invoice->id++; Invoice::$rows[$invoice->id] = $invoice;
                    continue;
                }
                if ($contact === 'page') $gateway->pay($invoice, 20);
                elseif ($contact === 'return') {
                    $gateway->finishPayment(new Request(['payment_intent' => $id]));
                    check($intent->status === 'requires_payment_method', 'A return without its secret cannot cancel failed authentication');
                    $gateway->finishPayment(new Request(['payment_intent' => $id, 'payment_intent_client_secret' => $intent->client_secret]));
                }
                else $gateway->webhook(eventRequest($intent, type: 'payment_intent.payment_failed'));
                check($intent->status === 'canceled', 'Failed Elements authentication is canceled on ' . $contact);
                if ($contact === 'webhook') {
                    // The failure webhook can retire the intent before the open dialog asks for the result.
                    $payments = ExtensionHelper::$payments;
                    $response = $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'amount' => 2000]));
                    check($response['status'] === 409 && $response['data']['declined'] === true
                        && $response['data']['message'] === 'Your bank did not confirm this payment. You were not charged. Try again or use a different card.'
                        && ExtensionHelper::$payments === $payments,
                        'A bank check the failure webhook already retired still reads as a decline in the dialog');
                    unset($intent->last_payment_error);
                    $response = $gateway->confirmPayment(new Request(['intent' => $id, 'save' => false, 'amount' => 2000]));
                    check($response['status'] === 409 && $response['data']['declined'] === true,
                        'A retired refusal without its Stripe error still reads as a decline, so the dialog clears the card');
                }
                $view = $gateway->pay($invoice, 20); $stripe->status = 'succeeded';
                $result = $gateway->confirmPayment(new Request(['intent' => $view['data']['intent']->id, 'save' => false, 'confirmation_token' => 'ctoken_retry', 'amount' => 2000]));
                check($view['data']['intent']->id !== $id && $result['data']['status'] === 'succeeded', 'After failed authentication a fresh attempt can pay on ' . $contact);
                $invoice->id++; Invoice::$rows[$invoice->id] = $invoice;
            }
        });
        $run('r3-invalid-create', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            unset($attempt['intent']); $attempt['params']['confirmation_method'] = 'manual'; $attempt['params']['automatic_payment_methods'] = ['enabled' => 'true'];
            $attempt['agreement_id'] = 4; $attempt['retry'] = true;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && !Notification::$rows, 'An unconfirmed R3 create with the invalid pair is replaced automatically while retaining the saved instruction');
        });
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
            $locks = count(DB::$modelLocks);
            $gateway->resolveChargeAttempt($invoice, null, 'Original Stripe account support case confirms no charge');
            check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'A permanently missing ID has an audited no-charge release');
            // A locking audits read would lock the gap after the invoice's records, shared with neighbouring invoices.
            check(!in_array(Audit::class, array_slice(DB::$modelLocks, $locks), true), 'A no-charge release checks staff reviews without audit range locks');
            $resolved = array_values(array_filter(Audit::$rows, fn ($a) => $a['event'] === 'ae_stripe_attempt_resolved'));
            check(end($resolved)['new_values']['payment_intent_id'] === 'pi_test1', 'Missing-ID release audits the original identity');
        });
        $run('missing-release-money', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'processing'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            rejects(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Support case in the original account says no charge'),
                'This payment may still hold money. Restore access to its Stripe account and account for the funds first.');
            check(isset(User::$row->props->values['ae_stripe_checkout_920']), 'A missing ID cannot release an observed debit that may still hold money');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); unset($attempt['funds_pending']);
            ExtensionHelper::$payments['920:pi_test1'] = ['status' => 'processing', 'amount' => 20, 'gateway' => 'AeStripe'];
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            rejects(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Support case in the original account says no charge'),
                'This payment may still hold money. Restore access to its Stripe account and account for the funds first.');
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
        foreach (['recover', 'expired', 'canceled', 'invalid-params'] as $replacement) {
            $run('accepted-lost-create-'.$replacement, function ($invoice, $stripe) use ($gateway, $replacement) {
                $stripe->loseCreate = true;
                paymentStops(fn () => $gateway->chargeAccepted($invoice, 20, new BillingAgreement));
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                if ($replacement === 'expired') $attempt['created'] = time() - 24 * 3600;
                if ($replacement === 'canceled') $stripe->intents['pi_test1']->status = 'canceled';
                if ($replacement === 'invalid-params') $attempt['params']['automatic_payment_methods'] = ['enabled' => 'true'];
                User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
                $invoice->total = 25;
                $gateway->retryChargeAttempts();
                check(!$stripe->charges && !$stripe->confirms && !ExtensionHelper::$payments,
                    'Lost creation followed by a raised invoice refuses without charging through '.$replacement.' recovery');
                check(count(Notification::$rows) === 1 && str_contains(Notification::$rows[0]['body'], 'amount due changed'),
                    'Recovery explains that the accepted invoice amount changed');
            });
        }
        foreach (['reservation', 'confirmation'] as $stage) {
            foreach (['switched', 'off'] as $change) {
                $run('accepted-card-'.$stage.'-'.$change, function ($invoice, $stripe) use ($gateway, $change, $stage) {
                    foreach (ExtensionHelper::$agreements as &$row) $row['gateway_id'] = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
                    unset($row);
                    User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                        'version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4,
                    ]);
                    check($gateway->autoPayDefault(User::$row)['agreement_id'] === 4, 'The endpoint initially sees the accepted card');
                    $changeCard = function () use ($change) {
                        if ($change === 'off') unset(User::$row->props->values['ae_stripe_auto_pay_default']);
                        else User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                            'version' => 'auto_pay_account_v1', 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 5,
                        ]);
                    };
                    // Another tab commits before the reservation lock, or after it commits but before confirmation.
                    if ($stage === 'reservation') DB::$beforeUserLock = $changeCard;
                    else $stripe->beforeCreate = $changeCard;
                    $refused = false;
                    try { $gateway->chargeAccepted($invoice, 20, new BillingAgreement, true); }
                    catch (\App\Exceptions\DisplayException $e) { $refused = str_contains($e->getMessage(), 'Auto Pay card changed'); }
                    check($refused && !$stripe->charges && !$stripe->confirms && !ExtensionHelper::$payments,
                        'An Auto Pay card '.$change.' before '.$stage.' refuses without charging');
                    if ($stage === 'reservation') check(!isset(User::$row->props->values['ae_stripe_checkout_920']), 'A refused card reserves no payment');
                });
            }
        }
        $run('accepted-auto-pay-recovery', function ($invoice, $stripe) use ($gateway) {
            foreach (ExtensionHelper::$agreements as &$row) $row['gateway_id'] = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
            unset($row);
            User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                'version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4,
            ]);
            $stripe->beforeCreate = function () {
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                check(!DB::$locked && $attempt['accepted_amount'] === 2000 && $attempt['auto_pay_agreement'] === 4,
                    'The accepted amount and Auto Pay card commit before Stripe creation');
            };
            $stripe->loseCreate = true;
            paymentStops(fn () => $gateway->chargeAutoPayAccepted($invoice, 20, new BillingAgreement));
            $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && $stripe->charges[0][1] === 2000 && count($stripe->confirms) === 1,
                'An unchanged accepted Auto Pay payment recovers once after lost creation');
        });
        $run('accepted-settled-recovery', function ($invoice, $stripe) use ($gateway) {
            foreach (ExtensionHelper::$agreements as &$row) $row['gateway_id'] = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
            unset($row);
            User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                'version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4,
            ]);
            $stripe->loseConfirm = true;
            paymentStops(fn () => $gateway->chargeAutoPayAccepted($invoice, 20, new BillingAgreement));
            unset(User::$row->props->values['ae_stripe_auto_pay_default']);
            $invoice->total = 25;
            $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && count($stripe->confirms) === 1
                && (ExtensionHelper::$payments['920:pi_test1']['amount'] ?? null) === 20.0,
                'Recovery still records an already sent payment after Auto Pay is disabled and the balance changes');
        });
        foreach (['card-off', 'card-switched', 'amount', 'declined', 'recovery-card', 'recovery-amount', 'retired', 'decline-unread'] as $refusal) {
            foreach (in_array($refusal, ['retired', 'decline-unread'], true) ? ['elements'] : ['elements', 'saved', 'accepted'] as $next) {
                $run('accepted-refusal-'.$refusal.'-'.$next, function ($invoice, $stripe) use ($gateway, $refusal, $next) {
                    foreach (ExtensionHelper::$agreements as &$row) $row['gateway_id'] = Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
                    unset($row);
                    User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                        'version' => $gateway::AUTO_PAY_CONSENT, 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 4,
                    ]);
                    $change = function () use ($invoice, $refusal) {
                        if (in_array($refusal, ['amount', 'recovery-amount'], true)) $invoice->total = 25;
                        elseif ($refusal === 'card-switched') User::$row->props->values['ae_stripe_auto_pay_default'] = json_encode([
                            'version' => 'auto_pay_account_v1', 'user_id' => 7, 'accepted_at' => time(), 'agreement_id' => 5,
                        ]);
                        else unset(User::$row->props->values['ae_stripe_auto_pay_default']);
                    };
                    if (str_starts_with($refusal, 'recovery-') || $refusal === 'retired') $stripe->loseCreate = true;
                    elseif (in_array($refusal, ['declined', 'decline-unread'], true)) {
                        $stripe->loseConfirm = $refusal === 'decline-unread';
                        $stripe->status = 'requires_payment_method';
                        $stripe->beforeConfirm = function ($intent) { $intent->last_payment_error = (object) ['code' => 'card_declined']; };
                    } else $stripe->beforeCreate = $change;
                    paymentStops(fn () => $gateway->chargeAutoPayAccepted($invoice, 20, new BillingAgreement));
                    $stripe->beforeCreate = $stripe->beforeConfirm = null;
                    if (str_starts_with($refusal, 'recovery-')) $change();
                    if ($refusal === 'declined') unset(User::$row->props->values['ae_stripe_auto_pay_default']);
                    $retiring = in_array($refusal, ['retired', 'decline-unread'], true);
                    if ($retiring) $invoice->total = 25;
                    else $gateway->retryChargeAttempts();
                    $confirms = count($stripe->confirms);
                    if (!$retiring) $gateway->retryChargeAttempts();
                    check(!$stripe->charges && count($stripe->confirms) === $confirms, 'A refused offer cannot debit through recovery before a fresh payment');
                    if ($refusal === 'card-off') {
                        // An earlier build retained these fields after clearing the refused instruction.
                        $stored = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
                        $stored['accepted_amount'] = 2000; $stored['auto_pay_agreement'] = 4;
                        User::$row->props->values['ae_stripe_checkout_920'] = json_encode($stored);
                    }
                    $stripe->status = 'succeeded';
                    try {
                        if ($next === 'elements') {
                            $view = $gateway->pay($invoice, $invoice->total);
                            $response = $gateway->confirmPayment(new Request(['intent' => $view['data']['intent']->id,
                                'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => (int) ($invoice->total * 100)]));
                            check(($response['data']['status'] ?? null) === 'succeeded', 'Fresh Pay now confirms the newly accepted total: '.json_encode($response));
                        } else {
                            $agreement = new BillingAgreement(['id' => 5, 'external_reference' => 'pm_other']);
                            check(($next === 'accepted' ? $gateway->chargeAccepted($invoice, $invoice->total, $agreement)
                                : $gateway->charge($invoice, $invoice->total, $agreement)) === true, 'Fresh saved-card payment succeeds after offer refusal');
                        }
                    } catch (\App\Exceptions\DisplayException $e) {
                        check(false, 'A '.$refusal.' refusal must permit a fresh '.$next.' payment: '.$e->getMessage());
                    }
                    $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
                    check(count($stripe->charges) === 1 && $stripe->charges[0][1] === (int) ($invoice->total * 100)
                        && count($stripe->confirms) === $confirms + 1,
                        'Only the fresh payment charges, once, at its current total after '.$refusal.' refusal');
                });
            }
        }
        $run('charge-accepted-amount', function ($invoice, $stripe) use ($gateway) {
            // The customer accepted 20.00, then the invoice was raised before the charge reached the payment lock.
            $invoice->total = 25;
            check(paymentStops(fn () => $gateway->chargeAccepted($invoice, 20, new BillingAgreement)) === 'The amount due changed. Refresh the invoice before paying.'
                && $stripe->confirms === [] && $stripe->charges === [] && !ExtensionHelper::$payments, 'An accepted-amount charge refuses a balance other than the amount the customer accepted');
            $invoice->total = 20;
            check($gateway->chargeAccepted($invoice, 20, new BillingAgreement) === true && array_column($stripe->charges, 1) === [2000], 'The accepted amount still charges once');
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
        $run('missing-refused-success', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $intent = $stripe->intents['pi_test1'];
            $intent->status = 'succeeded'; $intent->amount_received = 2000; $intent->customer = 'cus_other';
            $gateway->webhook(eventRequest($intent));
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            rejects(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Original account support evidence says no charge'),
                'This payment may still hold money. Restore access to its Stripe account and account for the funds first.');
            check(isset(User::$row->props->values['ae_stripe_checkout_920']), 'A refused success still protects known funds when Stripe later answers 404');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); unset($attempt['funds_pending']);
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            rejects(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Original account support evidence says no charge'),
                'This payment may still hold money. Restore access to its Stripe account and account for the funds first.');
            check(isset(User::$row->props->values['ae_stripe_checkout_920']), 'An older refused-success audit protects funds without the new property field');
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
        $run('saved-decline-kept', function ($invoice, $stripe) use ($gateway) {
            // An Auto Pay or saved-card refusal leaves the intent open, and the next saved-card Pay confirms that same intent.
            $stripe->failure = function ($verb, $path) use ($stripe) {
                if (!str_ends_with($path, '/confirm')) return;
                $intent = $stripe->intents['pi_test1']; $intent->latest_charge = 'ch_refused';
                $intent->last_payment_error = (object) ['code' => 'card_declined', 'decline_code' => 'generic_decline'];
                throw new RequestException(402, ['error' => ['type' => 'card_error', 'code' => 'card_declined']]);
            };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check((ExtensionHelper::$payments['920:pi_test1']['status'] ?? null) === 'failed', 'A saved-card refusal records a Failed payment');
            $stripe->failure = null;
            check($gateway->charge($invoice, 20, new BillingAgreement) === true && count($stripe->intents) === 1, 'The next saved-card Pay confirms the same intent');
            $statuses = array_count_values(array_column(ExtensionHelper::$payments, 'status'));
            check(($statuses['failed'] ?? 0) === 1 && ($statuses['succeeded'] ?? 0) === 1
                && (ExtensionHelper::$payments['920:pi_test1']['status'] ?? null) === 'succeeded'
                && isset(ExtensionHelper::$payments['920:pi_test1:ch_refused']),
                'A later payment on the same intent keeps the earlier Failed attempt on the invoice');
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
            try { $gateway->charge($invoice, 20, new BillingAgreement); throw new RuntimeException('First lock must surface before any retry row exists'); }
            catch (\Illuminate\Database\QueryException $e) { check(!$stripe->intents && !isset(User::$row->props->values['ae_stripe_checkout_920']), 'First-lock failure surfaces without falsely claiming an automatic retry'); }
        });
        $run('lock-after-create', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true;
            $stripe->beforeCreate = function () { DB::$beforeUserLock = fn () => throw new \Illuminate\Database\QueryException('Lock wait timeout', 1205); };
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'A reserved saved payment stays pending on lock contention');
            $stripe->beforeCreate = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1, 'Recovery resumes a saved instruction after its intent ID write was interrupted');
        });
        $run('lock-before-confirm', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true;
            $stripe->beforeCreate = function () { DB::$afterTransaction = function () { DB::$beforeUserLock = fn () => throw new \Illuminate\Database\QueryException('Deadlock', 1213); }; };
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'An unsent saved confirmation stays pending on lock contention');
            $stripe->beforeCreate = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1, 'Recovery resumes the saved instruction before confirmation reservation');
        });
        foreach ([false, true] as $knownId) $run($knownId ? 'r3-stranded-known' : 'r3-stranded-create', function ($invoice, $stripe) use ($gateway, $knownId) {
            $gateway->pay($invoice, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $attempt['agreement_id'] = 4;
            if ($knownId) $attempt['retry'] = false;
            else { unset($attempt['intent'], $attempt['retry']); }
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1, 'An older saved instruction stranded without retry:true resumes automatically');
        });
        $run('disabled-cron', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true; Gateway::$enabled = false;
            check($gateway->charge($invoice, 20, new BillingAgreement) === true && !$stripe->intents, 'A paused gateway sends no Stripe requests');
            Gateway::$enabled = true; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1, 'An instruction saved while paused resumes automatically when the gateway is enabled');
        });
        $run('database-after-confirm', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true;
            $stripe->beforeConfirm = function () { DB::$beforeUserLock = fn () => throw new \Illuminate\Database\QueryException('Lost connection', 2013); };
            check($gateway->charge($invoice, 20, new BillingAgreement) === true && count($stripe->charges) === 1, 'A database error after a landed confirm cannot reach cron as payment failed');
            $stripe->beforeConfirm = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && (ExtensionHelper::$payments['920:pi_test1']['status'] ?? null) === 'succeeded', 'The committed confirmation is settled automatically after database recovery');
        });
        $run('decline-then-lock', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeConfirm = function ($intent) { $intent->last_payment_error = (object) ['code' => 'card_declined']; $intent->latest_charge = 'ch_declined'; throw new RequestException(402, ['error' => ['code' => 'card_declined']]); };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $stripe->beforeConfirm = null;
            $reads = 0;
            $stripe->failure = function ($verb, $path) use (&$reads) { if ($verb === 'get' && $path === '/payment_intents/pi_test1' && ++$reads === 1) DB::$afterTransaction = function () { DB::$beforeUserLock = fn () => throw new \Illuminate\Database\QueryException('Deadlock', 1213); }; };
            $GLOBALS['fakeConsole'] = true; $gateway->charge($invoice, 20, new BillingAgreement);
            $stripe->failure = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1, 'A new saved Pay interrupted before confirm still resumes after an earlier decline');
        });
        $run('database-commit-after-confirm', function ($invoice, $stripe) use ($gateway) {
            $GLOBALS['fakeConsole'] = true;
            $stripe->beforeConfirm = function () { DB::$afterTransaction = fn () => throw new \PDOException('Server has gone away', 2006); };
            check($gateway->charge($invoice, 20, new BillingAgreement) === true, 'A lost database commit response after confirm cannot reach cron as payment failed');
            $stripe->beforeConfirm = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && (ExtensionHelper::$payments['920:pi_test1']['status'] ?? null) === 'succeeded', 'Recovery settles the known payment after a lost database commit response');
        });
        $run('decline-then-timeout', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeConfirm = function ($intent) { $intent->last_payment_error = (object) ['code' => 'card_declined']; $intent->latest_charge = 'ch_declined'; throw new RequestException(402, ['error' => ['code' => 'card_declined']]); };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->beforeConfirm = fn () => throw new ConnectionException('New confirm did not arrive');
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(empty($stripe->intents['pi_test1']->last_payment_error), 'The successful update cleared the old Stripe error');
            $stripe->beforeConfirm = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && !Notification::$rows, 'An earlier failed row cannot cancel recovery of a later unknown confirmation');
        });
        $run('decline-then-update-timeout', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeConfirm = function ($intent) { $intent->last_payment_error = (object) ['code' => 'card_declined']; $intent->latest_charge = 'ch_declined'; throw new RequestException(402, ['error' => ['code' => 'card_declined']]); };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)); $stripe->beforeConfirm = null;
            $once = true;
            $stripe->failure = function ($verb, $path) use (&$once) { if ($once && $verb === 'post' && $path === '/payment_intents/pi_test1') { $once = false; throw new ConnectionException('Update did not arrive'); } };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->failure = null; $gateway->retryChargeAttempts();
            check(count($stripe->charges) === 1 && !Notification::$rows, 'An old error left by an unsent update cannot send a false recovery decline');
        });
        foreach ([false, true] as $oldRecord) $run($oldRecord ? 'old-unknown-decline' : 'new-unknown-decline', function ($invoice, $stripe) use ($gateway, $oldRecord) {
            $stripe->beforeConfirm = function ($intent) { $intent->last_payment_error = (object) ['code' => 'card_declined']; $intent->latest_charge = 'ch_failed'; throw new ConnectionException('Decline response lost'); };
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            if ($oldRecord) {
                $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); unset($attempt['confirmation']['previous_charge']);
                User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            }
            $stripe->beforeConfirm = null; $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            check(!$stripe->charges && count(Notification::$rows) === 1 && (ExtensionHelper::$payments['920:pi_test1']['status'] ?? null) === 'failed', 'A definite failure after an unknown confirm never retries a failed saved debit, including older records');
        });
        $run('unknown-debit-release', function ($invoice, $stripe) use ($gateway) {
            $stripe->loseConfirm = true; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            paymentStops(fn () => $gateway->pay($invoice, 20));
            $message = paymentStops(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Support says no charge'));
            check(str_contains($message, 'debit was sent') && isset(User::$row->props->values['ae_stripe_checkout_920']), 'Free text plus 404 cannot release an unknown sent debit');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(str_contains($message, 'pi_test1') && str_contains($message, 'Inspector') && str_contains($message, 'Logs') && str_contains($message, 'cover') && str_contains($message, 'support'), 'The refusal names the payment and a dated original-account log or support check');
            check(str_contains($attempt['reason'], 'debit was sent'), 'The staff hold explains that a debit was sent');
            paymentStops(fn () => $gateway->resolveChargeAttempt($invoice, null, 'Old account request log checked', '', 'confirm_wrong'));
            check(isset(User::$row->props->values['ae_stripe_checkout_920']), 'A different checked confirm key cannot release the held payment');
        });
        $run('checked-confirm-release', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeConfirm = fn () => throw new ConnectionException('Confirm did not reach Stripe');
            paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->failure = fn ($verb, $path) => $verb === 'get' && $path === '/payment_intents/pi_test1' ? throw new RequestException(404) : null;
            paymentStops(fn () => $gateway->pay($invoice, 20));
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            $gateway->resolveChargeAttempt($invoice, null, 'Original account request log confirms this key never reached Stripe', '', $attempt['confirmation']['key']);
            check(!isset(User::$row->props->values['ae_stripe_checkout_920']) && array_values(array_filter(Audit::$rows, fn ($a) => $a['event'] === 'ae_stripe_attempt_resolved'))[0]['new_values']['checked_confirm_key'] === $attempt['confirmation']['key'], 'No-charge resolution audits the exact confirm key staff checked in the original account');
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            $notice = end(Notification::$rows);
            check(!$stripe->charges && $notice['user_id'] === 7 && $notice['title'] === 'Payment not completed' && !empty($notice['url']), 'A staff release ends the renewal without re-debiting and tells the customer how to pay');
        });
        $run('customer-words', function ($invoice, $stripe) use ($gateway) {
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode(['key' => 'legacy', 'created' => time() - 90000, 'params' => ['confirm' => 'true']]);
            $message = paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            check(!str_contains($message, 'request log') && !str_contains($message, 'releasing') && str_contains($message, 'support'), 'Customers never receive staff reconciliation instructions');
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
            check(count($stripe->charges) === 1 && $stripe->intents['pi_test1']->status === 'canceled' && $stripe->charges[0][1] === 1100 && $stripe->charges[0][2] === 'pm_other', 'Confirm recovery reads the known intent and checks the new balance and card');
        });
        $run('between-locks', function ($invoice, $stripe) use ($gateway) {
            $stripe->beforeCreate = function () use ($invoice) { $invoice->total = 12; };
            $stripe->beforeConfirm = function ($intent) { check(DB::$locked && DB::$userLocked && $intent->amount === 1200, 'Confirmation holds user and invoice locks with the rechecked balance'); };
            $gateway->charge($invoice, 20, new BillingAgreement);
            check($stripe->charges[0][1] === 1200, 'Balance changes between creation and confirmation cannot overcharge');
        });
        $run('old-tab', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20); $id = $view['data']['intent']->id; $invoice->total = 10;
            check(!str_contains(file_get_contents(__DIR__ . '/../extension/AeStripe/resources/views/pay.blade.php'), '$intent->client_secret'), 'Rendering checkout never exposes its PaymentIntent client secret');
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
            check(paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement)) === 'Your bank declined this card. Try a different card, or contact your bank.', 'Definite 402 without an intent gives the real refusal');
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
        $run('recovery-priority-query', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); Property::$lastRawOrder = null;
            $gateway->retryChargeAttempts();
            check(Property::$lastRawOrder === [
                'CASE WHEN value LIKE ? OR value NOT LIKE ? OR (value LIKE ? AND value NOT LIKE ?) THEN 0 ELSE 1 END',
                ['%"retry":true%', '%"version":2%', '%"agreement_id":%', '%"agreement_id":null%'],
            ], 'The production recovery priority SQL and four bindings match the dependency-free fake');
        });
        $run('idle-scan-progress', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $before = User::$row->props->values['ae_stripe_checkout_920'];
            $gateway->retryChargeAttempts();
            $after = User::$row->props->values['ae_stripe_checkout_920']; $attempt = json_decode($after, true);
            check($after !== $before && ($attempt['checks'] ?? 0) === 0 && isset($attempt['scanned_at']),
                'An idle scan changes the stored value so updated_at advances without counting an active check');
            $gateway->retryChargeAttempts();
            check(User::$row->props->values['ae_stripe_checkout_920'] !== $after,
                'Another idle scan changes the stored value even within the same second');
        });
        $run('paused-active-scan', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); $attempt['retry'] = true;
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            Gateway::$enabled = false;
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(($attempt['checks'] ?? 0) === 0 && isset($attempt['scanned_at']), 'Paused active scans leave the recovery check count unchanged');
            Gateway::$enabled = true;
            $stripe->failure = fn ($verb, $path) => str_starts_with($path, '/payment_intents/') ? throw new RequestException(403) : null;
            $gateway->retryChargeAttempts(); DB::transaction(fn () => null);
            check(!Notification::$rows, 'The first refusal after paused scans does not send a repeated-refusal staff alert');
        });
        $run('idle-access-refusal', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $gateway->retryChargeAttempts(); $gateway->retryChargeAttempts();
            $stripe->failure = fn ($verb, $path) => str_starts_with($path, '/payment_intents/') ? throw new RequestException(403) : null;
            paymentStops(fn () => $gateway->pay($invoice, 20));
            DB::transaction(fn () => null); // Flush the fake's queued after-commit staff alert.
            check(!Notification::$rows, 'Two idle retirement checks cannot turn the first real access refusal into a staff alert');
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true);
            check(($attempt['checks'] ?? 0) === 0, 'Idle forms do not accumulate active recovery checks');
        });
        $run('active-before-idle', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $idle = User::$row->props->values['ae_stripe_checkout_920'];
            for ($id = 921; $id <= 1020; ++$id) {
                $other = clone $invoice; $other->id = $id; Invoice::$rows[$id] = $other;
                $attempt = json_decode($idle, true); $attempt['agreement_id'] = null;
                User::$row->props->values['ae_stripe_checkout_' . $id] = json_encode($attempt);
            }
            $active = clone $invoice; $active->id = 1021; Invoice::$rows[1021] = $active;
            $gateway->pay($active, 20);
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_1021'], true); $attempt['agreement_id'] = 4;
            User::$row->props->values['ae_stripe_checkout_1021'] = json_encode($attempt);
            $gateway->retryChargeAttempts();
            $paid = array_filter(array_keys(ExtensionHelper::$payments), fn ($key) => str_starts_with($key, '1021:'));
            check(count($stripe->charges) === 1 && count($paid) === 1,
                'An active saved-method attempt behind 101 idle forms is recovered in the first 100-row batch');
            $untouched = json_decode(User::$row->props->values['ae_stripe_checkout_1020'], true);
            check(!isset($untouched['checks']), 'An explicit null agreement stays idle and cannot outrank an active attempt');
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
            $gateway->pay($invoice, 20); $intent = $stripe->intents['pi_test1'];
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); unset($attempt['elements']);
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
            $invoice->total = 10;
            $response = $gateway->consent(new Request(['intent' => $intent->id, 'save' => false]));
            check($response['status'] === 409 && $intent->status === 'canceled' && !$stripe->charges,
                'An old automatic checkout tab gets a refresh step and cannot confirm the stale amount');
        });
        $run('redirect', function ($invoice, $stripe) use ($gateway) {
            $view = $gateway->pay($invoice, 20); $intent = $stripe->intents[$view['data']['intent']->id];
            $stripe->status = 'requires_action';
            $gateway->confirmPayment(new Request(['intent' => $intent->id, 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            $intent->status = 'succeeded'; $intent->amount_received = $intent->amount;
            $gateway->finishPayment(new Request(['payment_intent' => $intent->id]));
            check(!ExtensionHelper::$payments, 'A read-only successful return leaves settlement to the webhook or recovery');
            $gateway->webhook(eventRequest($intent));
            check(isset(ExtensionHelper::$payments['920:' . $intent->id]), 'The webhook settles a payment after an unbound read-only return');
            check(str_contains($gateway->finishPayment(new Request(['payment_intent' => $intent->id, 'payment_intent_client_secret' => $intent->client_secret])), 'checkPayment=true')
                && count($stripe->confirms) === 1, 'An automatic redirect reads settlement without confirming again');
        });
        $run('checkout-consent', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $stripe->beforeConfirm = function ($intent, $params) {
                check(!isset($params['metadata']) && !isset($params['setup_future_usage'])
                    && $intent->metadata->ae_stripe_gateway === '1' && $intent->metadata->ae_stripe_consent === '1',
                    'Server confirmation updates consent first and sends only supported confirmation parameters');
            };
            $response = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => true, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            check($response['data']['status'] === 'succeeded' && $response['data']['client_secret'] === null && count($stripe->charges) === 1, 'Checkout token confirms once with explicit saving consent and no client secret on success');
            // Demo r28: paying with "Save this card for next time" saved nothing when the webhook did not save it.
            check((ExtensionHelper::$agreements['pm_paid']['name'] ?? null) === 'Visa **** 4242', 'Paying with Save ticked saves the card at once, without waiting for the webhook');
        });
        $run('checkout-notice-return', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20); $stripe->status = 'requires_action';
            $token = str_repeat('c', 32);
            $response = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => true,
                'confirmation_token' => 'ctoken_test', 'amount' => 2000, 'saved_notice' => $token]));
            check(($response['data']['saved_notice'] ?? null) === $token, 'Authentication keeps the checkout notice token');
            $intent = $stripe->intents['pi_test1']; $intent->status = 'succeeded'; $intent->amount_received = $intent->amount;
            $destination = $gateway->finishPayment(new Request(['payment_intent' => $intent->id,
                'payment_intent_client_secret' => $intent->client_secret, 'saved_notice' => $token]));
            check(is_string($destination) && str_contains($destination, 'saved_notice=' . $token), 'The authenticated bank return preserves the checkout-tab notice destination');
            $notice = json_decode(User::$row->props->values['ae_stripe_notice_' . hash('sha256', $token)] ?? 'null', true);
            check(($notice['invoice_id'] ?? null) === 920 && !($notice['consumed'] ?? true), 'Bank return stores a claimable notice for the paid invoice');
        });
        $run('checkout-no-save', function ($invoice, $stripe) use ($gateway) {
            $gateway->pay($invoice, 20);
            $response = $gateway->confirmPayment(new Request(['intent' => 'pi_test1', 'save' => false, 'confirmation_token' => 'ctoken_test', 'amount' => 2000]));
            check($response['data']['status'] === 'succeeded' && !isset(ExtensionHelper::$agreements['pm_paid']), 'Paying without Save ticked saves no card');
        });
        $run('bank-verification', function ($invoice, $stripe) use ($gateway) {
            $stripe->status = 'requires_action'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $gateway->retryChargeAttempts();
            check(json_decode(User::$row->props->values['ae_stripe_checkout_920'], true)['retry'], 'Manual bank authentication keeps automatic recovery');
            $stripe->intents['pi_test1']->status = 'requires_confirmation'; $stripe->status = 'succeeded';
            $gateway->finishPayment(new Request(['payment_intent' => 'pi_test1']));
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
            $stripe->status = 'requires_action'; paymentStops(fn () => $gateway->charge($invoice, 20, new BillingAgreement));
            $stripe->intents['pi_test1']->status = 'requires_confirmation'; $invoice->total = 30;
            $gateway->pay($invoice, 30); $stripe->status = 'succeeded';
            check(str_contains($gateway->finishPayment(new Request(['payment_intent' => 'pi_test1', 'payment_intent_client_secret' => $stripe->intents['pi_test1']->client_secret]))['data']['message'], 'amount due changed')
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
            check(count($stripe->charges) === 1 && $stripe->intents['pi_test1']->status === 'canceled' && $creates[0][3]['Idempotency-Key'] === $creates[1][3]['Idempotency-Key'],
                'Overlapping checkout shares the create key; a native caller follows proven replacements and charges once');
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
            $gateway->pay($invoice, 20); $invoice->total = 10;
            $attempt = json_decode(User::$row->props->values['ae_stripe_checkout_920'], true); unset($attempt['elements']);
            User::$row->props->values['ae_stripe_checkout_920'] = json_encode($attempt);
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
            invoke($gateway, 'checkoutIntent', $invoice, invoke($gateway, 'checkoutPaymentData', $invoice, 2000, 'cus_owner'));
            $gets = 0; $GLOBALS['fakeConsole'] = true;
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
            check(count($stripe->charges) === 1 && $stripe->intents['pi_test1']->status === 'canceled', 'Restored access resolves the known-ID hold and retires Elements before native Pay');
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
            $gateway->retryReceiptReturns();
            $gateway->retryChargeAttempts();
            check(count(Notification::$rows) === 1 && count($stripe->refunds) === 1, 'Scheduled recovery retries the failed return notice without repeating its refund');
        });
        $run('overpay-alert', function ($invoice, $stripe) use ($gateway) {
            $invoice->total = 15;
            $intent = (object) ['id' => 'pi_extra', 'status' => 'succeeded', 'customer' => 'cus_owner', 'currency' => 'usd', 'amount' => 2000, 'amount_received' => 2000, 'metadata' => (object) ['invoice_id' => 920, 'ae_stripe_gateway' => '1']];
            $stripe->intents[$intent->id] = $intent;
            DB::transaction(function () use ($gateway, $intent, $stripe) { $gateway->webhook(eventRequest($intent)); check(!Notification::$rows && !$stripe->refunds, 'Overpay return waits for commit'); });
            $gateway->webhook(eventRequest($intent));
            check(count(Notification::$rows) === 1 && count(Audit::$rows) === 1, 'Overpayment on a pending invoice returns excess automatically with one customer notice');
        });
    } finally {
        Http::$handler = $handler; Invoice::$rows = $rows; User::$row = $user; Gateway::$enabled = $enabled;
        ExtensionHelper::$payments = $payments; ExtensionHelper::$agreements = $agreements; Audit::$rows = $audits;
        DB::$tables['ae_stripe_receipt_returns'] = $returns;
        Notification::$rows = $notices; Notification::$fail = false; DB::$beforeUserLock = null; DB::$afterTransaction = null; $GLOBALS['fakeConsole'] = $console;
    }
})();
