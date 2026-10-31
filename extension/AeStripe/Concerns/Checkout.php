<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Helpers\ExtensionHelper;
use App\Exceptions\DisplayException;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use App\Models\Audit;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ReceiptReturns.php';

trait Checkout
{
    use ReceiptReturns;

    public function pay($invoice, $total)
    {
        abort_unless($this->gatewayEnabled(), 409);
        abort_unless(Auth::id() == $invoice->user_id, 403);
        // Do not fall back to an anonymous intent: saved methods require this customer.
        try {
            $customer = $this->createOrGetStripeCustomer($invoice->user, (bool) $this->config('stripe_create_customers'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AeStripe customer setup failed', [
                'invoice_id' => $invoice->id, 'error' => get_class($e),
            ]);
            throw new DisplayException('Could not prepare your payment. Try again.');
        }
        $intentData = $this->checkoutPaymentData($invoice, $this->toMinorUnits($total, $invoice->currency_code), $customer->id);
        $intent = $this->checkoutIntent($invoice, $intentData, elements: true);
        if ($intent->status === 'succeeded') {
            $this->recordIntentPayment($intent, 'succeeded');
            if (!InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) {
                $this->paymentReconcileMessage();
            }
            return view('gateways.ae-stripe::payment-status', ['message' => 'Payment received. Refresh the invoice to check its status.']);
        }
        if (in_array($intent->status, ['processing', 'requires_capture'], true)) {
            return view('gateways.ae-stripe::payment-status', ['message' => 'Payment pending. Refresh the invoice to check its status.']);
        }
        // API assumptions must be verified against the pinned Basil version in a sandbox.
        // https://docs.stripe.com/api/customer_sessions/create
        // Removal is disabled: native agreements may still reference a removed method.
        $session = null;
        try {
            $session = $this->request('post', '/customer_sessions', [
                'customer' => $customer->id,
                'components' => ['payment_element' => [
                    'enabled' => 'true',
                    'features' => [
                        'payment_method_redisplay' => 'enabled',
                        'payment_method_save' => 'disabled',
                        'payment_method_remove' => 'disabled',
                    ],
                ]],
            ]);
        } catch (RequestException | ConnectionException $e) {
            \Illuminate\Support\Facades\Log::warning('AeStripe customer session unavailable', [
                'invoice_id' => $invoice->id, 'http_status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);
        }

        return view('gateways.ae-stripe::pay', [
            'invoice' => $invoice, 'total' => $total, 'intent' => $intent,
            'customerSessionSecret' => $session?->client_secret,
            'stripePublishableKey' => $this->config('stripe_publishable_key'),
        ]);
    }

    private function checkoutPaymentData($invoice, int $amount, string $customer): array
    {
        return [
            'description' => __('invoices.payment_for_invoice', ['number' => $invoice->number ?? $invoice->id]),
            'amount' => $amount, 'currency' => $invoice->currency_code,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1'],
            'customer' => $customer,
        ];
    }

    private function gatewayEnabled(): bool
    {
        return (bool) \App\Models\Gateway::where('extension', 'AeStripe')->first()?->enabled;
    }

    public function consent(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'intent' => 'required|string|regex:/^pi_[a-zA-Z0-9]+$/|max:255',
            'save' => 'required|boolean',
        ]);
        $intent = $this->request('get', '/payment_intents/' . $request->input('intent'));
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id ?? null);
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        abort_unless(Auth::id() == $invoice->user_id && $customer && $intent->customer === $customer
            && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 403);
        $elements = $this->withPaymentLock($invoice, fn ($user, $current) => $this->isElementsAttempt($this->readPaymentAttempt($user, $current) ?? [], $intent));
        if (($intent->confirmation_method ?? 'automatic') !== 'manual' && !$elements) {
            // An already-open legacy tab must not confirm outside the invoice lock.
            $this->withPaymentLock($invoice, function () use ($intent) {
                if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
                    && ($intent->amount_received ?? 0) === 0) $this->request('post', '/payment_intents/' . $intent->id . '/cancel');
            });
            return response()->json(['message' => 'The payment form changed. Refresh the invoice before paying.'], 409);
        }
        $save = $request->boolean('save');
        if ($intent->status === 'requires_action') {
            // Resume 3DS without changing the consent already used for confirmation.
            abort_unless($save === (($intent->metadata->ae_stripe_consent ?? null) === '1'), 409);
            return response()->json(['ok' => true]);
        }
        abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation']), 409);
        // Clear legacy top-level usage; consent applies only to card and Link.
        $this->request('post', '/payment_intents/' . $intent->id, [
            'setup_future_usage' => '',
            'payment_method_options' => [
                'card' => ['setup_future_usage' => $save ? 'off_session' : ''],
                'link' => ['setup_future_usage' => $save ? 'off_session' : ''],
            ],
            'metadata' => ['ae_stripe_consent' => $save ? '1' : '0'],
        ]);

        return response()->json(['ok' => true]);
    }

    public function confirmPayment(Request $request)
    {
        abort_unless($this->gatewayEnabled(), 409);
        $request->validate([
            'intent' => 'required|string|regex:/^pi_[a-zA-Z0-9]+$/|max:255',
            'confirmation_token' => 'nullable|string|regex:/^ctoken_[a-zA-Z0-9]+$/|max:255',
            'amount' => 'required|integer|min:1', 'save' => 'required|boolean',
        ]);
        try {
            $intent = $this->request('get', '/payment_intents/' . $request->input('intent'));
            $invoice = Invoice::findOrFail($intent->metadata->invoice_id ?? null);
            $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
            abort_unless(Auth::id() == $invoice->user_id && $customer && $intent->customer === $customer
                && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 403);
            $intent = $this->retireFailedElementsPayment($invoice, $intent);
            if ($intent->status === 'canceled') throw new DisplayException('This payment was not completed. Refresh the invoice to try another payment.');
            $agreement = null;
            $params = ['return_url' => route('extensions.gateways.ae-stripe.payment-return', ['invoice' => $invoice->id]), 'use_stripe_sdk' => 'true'];
            if ($request->input('confirmation_token')) {
                $params += ['confirmation_token' => $request->input('confirmation_token'), 'setup_future_usage' => '',
                    'payment_method_options' => ['card' => ['setup_future_usage' => $request->boolean('save') ? 'off_session' : ''],
                        'link' => ['setup_future_usage' => $request->boolean('save') ? 'off_session' : '']],
                    'metadata' => ['ae_stripe_consent' => $request->boolean('save') ? '1' : '0']];
            } else {
                abort_unless(in_array($intent->status, ['requires_action', 'requires_confirmation', 'processing', 'succeeded'], true), 409);
                abort_unless($request->boolean('save') === (($intent->metadata->ae_stripe_consent ?? null) === '1'), 409);
                $agreement = $this->paymentResumeAgreement($invoice);
                if ($agreement) $params['payment_method'] = $agreement->external_reference;
            }
            // Automatic authentication completes in Stripe.js. Only native manual payments reconfirm.
            if ($request->input('confirmation_token') || $intent->status === 'requires_confirmation' && ($intent->confirmation_method ?? null) === 'manual') {
                $intent = $this->confirmAttempt($invoice, $intent->id, $params, $agreement, expectedAmount: (int) $request->input('amount'));
            }
            if ($intent->status === 'succeeded') $this->recordIntentPayment($intent, 'succeeded');
            return response()->json(['status' => $intent->status, 'client_secret' => $intent->status === 'requires_action' ? $intent->client_secret : null]);
        } catch (DisplayException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RequestException | ConnectionException $e) {
            return response()->json(['message' => 'Stripe is unavailable. We will check this payment automatically.'], 503);
        }
    }

    public function finishPayment(Request $request)
    {
        // Keep a local invoice link even when Stripe cannot be reached on the bank return.
        $invoice = null;
        if ($request->input('invoice')) {
            $invoice = Invoice::findOrFail($request->input('invoice'));
            abort_unless(Auth::id() == $invoice->user_id, 403);
        }
        try {
            if (!$this->gatewayEnabled()) throw new DisplayException('Payments are paused. Please check back later.');
            $id = $request->input('payment_intent');
            if (!is_string($id) || !preg_match('/^pi_[a-zA-Z0-9]+$/', $id)) throw new DisplayException('Could not check this payment. Open the invoice to check its status.');
            $intent = $this->request('get', '/payment_intents/' . $id);
            $owner = Invoice::findOrFail($intent->metadata->invoice_id ?? null);
            abort_unless(Auth::id() == $owner->user_id && (!$invoice || $invoice->id === $owner->id)
                && $intent->customer === $owner->user->properties()->where('key', 'stripe_id')->first()?->value
                && ($intent->metadata->ae_stripe_gateway ?? null) === '1', 403);
            $invoice = $owner;
            $secret = $request->input('payment_intent_client_secret');
            if (!is_string($secret) || !is_string($intent->client_secret ?? null) || !hash_equals($intent->client_secret, $secret)) {
                // A link without Stripe's redirect binding may read status, but cannot change it.
                throw new DisplayException(match ($intent->status) {
                    'succeeded' => 'Payment received. Open the invoice to check its status.',
                    'requires_action', 'requires_confirmation' => 'This payment is not finished. Open the invoice to continue.',
                    'requires_payment_method', 'canceled' => 'This payment was not completed. Choose another payment method on the invoice.',
                    default => 'Payment pending. Open the invoice to check its status.',
                });
            }
            $intent = $this->retireFailedElementsPayment($invoice, $intent);
            if ($intent->status === 'requires_confirmation') {
                $amount = $this->withPaymentLock($invoice, function ($user, $current) {
                    $attempt = $this->readPaymentAttempt($user, $current);
                    return $attempt['checkout_amount'] ?? $attempt['confirmation']['amount'] ?? null;
                });
                if ($amount === null) throw new DisplayException('Refresh the invoice before finishing this payment.');
                $agreement = $this->paymentResumeAgreement($invoice);
                $params = ['return_url' => route('extensions.gateways.ae-stripe.payment-return', ['invoice' => $invoice->id]), 'use_stripe_sdk' => 'true'];
                if ($agreement) $params['payment_method'] = $agreement->external_reference;
                $intent = $this->confirmAttempt($invoice, $id, $params, $agreement, expectedAmount: $amount);
            }
            if ($intent->status === 'succeeded') $this->recordIntentPayment($intent, 'succeeded');
            if (in_array($intent->status, ['requires_payment_method', 'canceled'], true)) throw new DisplayException('This payment was not completed. Choose another payment method on the invoice.');
            if ($intent->status === 'requires_action') throw new DisplayException('This payment still needs authentication. Open the invoice to finish it.');
            return redirect()->to(route('invoices.show', $invoice) . '?checkPayment=true');
        } catch (DisplayException $e) {
            $message = $e->getMessage();
        } catch (RequestException | ConnectionException $e) {
            $message = 'We are checking this payment automatically. Open the invoice for its status.';
        } catch (\Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) throw $e;
            report($e);
            $message = 'Could not finish checking this payment. Open the invoice to check its status.';
        }
        return view('gateways.ae-stripe::payment-return', ['message' => $message,
            'invoiceUrl' => $invoice ? route('invoices.show', $invoice) : route('invoices')]);
    }

    private function paymentResumeAgreement($invoice): ?BillingAgreement
    {
        $id = $this->withPaymentLock($invoice, fn ($user, $current) => $this->readPaymentAttempt($user, $current)['confirmation']['agreement'] ?? null);
        if (!$id) return null;
        $agreement = BillingAgreement::where('id', $id)->first();
        if (!$agreement) throw new DisplayException('This payment method was removed. Choose another payment method.');
        return $agreement;
    }

    private function recordIntentPayment($intent, $status, bool $prepareMethod = true)
    {
        $this->ensureReceiptReturnSchema();
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $userId = $invoice->user_id;
        if ($prepareMethod) $intent = $this->prepareTransactionMethod($invoice, $intent, $status);
        DB::transaction(function () use ($intent, $status, $userId) {
            $user = User::where('id', $userId)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('id', $intent->metadata->invoice_id)->lockForUpdate()->firstOrFail();
            $this->bindWebhookAttempt($user, $invoice, $intent);
            if (InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->lockForUpdate()->first()) {
                $this->schedulePaymentReviewAlert($invoice, $intent->id);
                DB::afterCommit(fn () => $this->processReceiptReturns($invoice, $intent->id));
                return;
            }
            if ($this->paymentManuallyAccounted($invoice, $intent->id)) return;
            if (!empty($intent->customer) && $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment customer mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordPaymentReview($invoice, $intent, 'customer mismatch');
                return;
            }
            if (strtolower($invoice->currency_code) !== strtolower($intent->currency ?? '')) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment currency mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordPaymentReview($invoice, $intent, 'currency mismatch');
                return;
            }
            if ($status === 'succeeded' && !in_array($invoice->status, ['pending', 'paid'], true)) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment received for nonpending invoice', ['invoice_id' => $invoice->id]);
                $this->recordReceipt($invoice, $intent, $intent->amount_received, closed: true);
                return;
            }
            if ($status === 'succeeded' && ($invoice->status === 'paid' || $intent->amount_received > $this->paymentBalance($invoice))) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment received for paid invoice', ['invoice_id' => $invoice->id]);
            }
            if ($status === 'succeeded') $this->recordReceipt($invoice, $intent,
                max(0, $intent->amount_received - ($invoice->status === 'paid' ? 0 : max(0, $this->paymentBalance($invoice)))));
            $helper = match ($status) {
                'processing' => 'addProcessingPayment', 'failed' => 'addFailedPayment', default => 'addPayment',
            };
            $amount = $status === 'succeeded' ? $intent->amount_received : $intent->amount;
            $transaction = ExtensionHelper::{$helper}($invoice, 'AeStripe', $this->fromMinorUnits($amount, $intent->currency), null, $intent->id);
            if (in_array($status, ['succeeded', 'processing'], true)) {
                $this->recordTransactionMethod($transaction, $intent, $status === 'succeeded');
            }
            if ($status === 'succeeded') {
                $attempt = $this->readPaymentAttempt($user, $invoice);
                if (($attempt['intent'] ?? null) === $intent->id) {
                    if ($this->paymentBalance($invoice) <= 0) $user->properties()->where('key', 'ae_stripe_checkout_' . $invoice->id)->delete();
                    else $this->writePaymentAttempt($user, $invoice, ['version' => 2, 'key' => $attempt['key'],
                        'created' => $attempt['created'], 'intent' => $intent->id, 'retry' => false]);
                }
            }
            if (in_array($status, ['processing', 'failed'], true)) {
                $attempt = $this->readPaymentAttempt($user, $invoice);
                if (($attempt['intent'] ?? null) === $intent->id) $this->writePaymentAttempt($user, $invoice,
                    array_replace($attempt, ['agreement_id' => null, 'confirmation' => null, 'retry' => $status === 'processing', 'funds_pending' => $status === 'processing' || ($intent->amount_received ?? 0) > 0]));
            }
            if ($status === 'failed') {
                // The locked success guard above preserves labels on settled payments.
                DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', $transaction->id)->delete();
            }
        });
    }

    private function recordPaymentReview($invoice, $intent, string $reason): void
    {
        // The caller holds the user and invoice locks. Keep one staff record per invoice and PaymentIntent.
        if (in_array($reason, ['customer mismatch', 'currency mismatch', 'invoice not pending'], true)) {
            $attempt = $this->readPaymentAttempt($invoice->user, $invoice);
            if (($attempt['intent'] ?? null) === $intent->id) $this->writePaymentAttempt($invoice->user, $invoice,
                array_replace($attempt, ['state' => 'reconcile', 'reason' => $reason, 'retry' => true, 'alert_sent' => true, 'funds_pending' => true]));
        }
        $this->schedulePaymentReviewAlert($invoice, $intent->id);
        $review = Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->where('event', 'ae_stripe_payment_review')->where('new_values->payment_intent_id', $intent->id)->lockForUpdate()->first();
        if (!$review) Audit::create([
            'event' => 'ae_stripe_payment_review',
            'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id,
            'old_values' => [],
            'new_values' => [
                'payment_intent_id' => $intent->id,
                'amount_minor' => $intent->amount_received,
                'currency' => $intent->currency ?? '', 'reason' => $reason,
                'resolution' => 'Check Stripe and choose a refund or account credit; this needs your decision.',
            ],
        ]);
        $key = 'ae_stripe_payment_alert_' . $invoice->id . '_' . $intent->id;
        if (!$invoice->user->properties()->where('key', $key)->lockForUpdate()->first()) {
            $invoice->user->properties()->updateOrCreate(['key' => $key],
                ['value' => json_encode(['reason' => $reason, 'sent' => false], JSON_THROW_ON_ERROR)]);
        }
    }

    private function schedulePaymentReviewAlert($invoice, string $intentId): void
    {
        // Settlement commits before any notification event can reach staff. Retrying a delivery
        // retries the durable pending notice, including after the payment is already recorded.
        DB::afterCommit(function () use ($invoice, $intentId) {
            DB::transaction(function () use ($invoice, $intentId) {
                $user = User::where('id', $invoice->user_id)->lockForUpdate()->firstOrFail();
                $key = 'ae_stripe_payment_alert_' . $invoice->id . '_' . $intentId;
                $stored = $user->properties()->where('key', $key)->lockForUpdate()->first()?->value;
                if (!$stored) return;
                $alert = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
                if ($alert['sent']) return;
                foreach (User::whereNotNull('role_id')->get() as $staff) {
                    Notification::create([
                        'user_id' => $staff->id, 'title' => 'Stripe payment needs review',
                        'url' => url('/admin/invoices/' . $invoice->id . '/edit'),
                        'body' => 'Invoice ' . $invoice->id . ', payment ' . $intentId . ': ' . $alert['reason']
                            . '. Check Stripe and choose a refund or account credit; this needs your decision.',
                    ]);
                }
                $alert['sent'] = true;
                $user->properties()->updateOrCreate(['key' => $key], ['value' => json_encode($alert, JSON_THROW_ON_ERROR)]);
            });
        });
    }

    private function savePaidMethod($intent)
    {
        if (($intent->metadata->ae_stripe_gateway ?? null) !== '1'
            || ($intent->metadata->ae_stripe_consent ?? null) !== '1') {
            return;
        }
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($intent->customer ?? null) !== $customer || empty($intent->payment_method)) {
            \Illuminate\Support\Facades\Log::warning('AeStripe saved method customer mismatch', ['invoice_id' => $invoice->id]);
            return;
        }
        $method = $this->request('get', '/payment_methods/' . $intent->payment_method);
        if (!in_array($method->type, ['card', 'link'])) {
            return; // The checkout opt-in authorizes saving a card or Link only.
        }
        if (($intent->payment_method_options->{$method->type}->setup_future_usage ?? null) !== 'off_session') return;
        $this->persistAgreement($invoice->user, $method);
    }

    private function methodCustomerMatches($user, $method, bool $current = false): bool
    {
        $property = $user->properties()->where('key', 'stripe_id');
        $customer = ($current ? $property->lockForUpdate() : $property)->first()?->value;
        if (!$customer || ($method->customer ?? null) !== $customer) {
            \Illuminate\Support\Facades\Log::warning('AeStripe saved method customer mismatch', ['user_id' => $user->id]);
            return false;
        }
        return true;
    }

    private function persistAgreement($user, $method, bool $deduplicate = false)
    {
        if (empty($method->id)) return false;
        // Native external_reference is globally unique, including soft-deleted rows.
        $duplicate = DB::transaction(function () use ($user, $method, $deduplicate) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            $method = $this->request('get', '/payment_methods/' . $method->id);
            $receipt = $deduplicate ? $this->duplicateReceipt($user, $method) : null;
            if (!(($method->customer ?? null) === null && $receipt)
                && !$this->methodCustomerMatches($user, $method, current: true)) return false;
            $data = $this->getPaymentDetails($method);
            $gateway = Gateway::where('extension', 'AeStripe')->firstOrFail();
            // Locking reads see concurrent commits even under REPEATABLE READ.
            $find = fn () => BillingAgreement::withTrashed()->where('external_reference', $method->id)->lockForUpdate()->first();
            $agreement = $find();
            if ($agreement && ($agreement->user_id != $user->id || $agreement->gateway_id != $gateway->id)) {
                throw new DisplayException('This payment method can\'t be used on this account. Add a new payment method.');
            }
            if ($agreement?->trashed() && !$receipt) {
                throw new DisplayException('This payment method was removed. Add a new payment method.');
            }
            $duplicate = null;
            if ($deduplicate) {
                try {
                    $duplicate = $this->duplicateCard($user, $method, $gateway, $agreement);
                } catch (RequestException $e) {
                    if ($e->response->status() !== 404 || $e->response->json('error.code') !== 'resource_missing') throw $e;
                    // A missing comparison card cannot prevent saving the verified incoming method.
                    \Illuminate\Support\Facades\Log::warning('ae_stripe_duplicate_candidate_missing', ['error' => get_class($e)]);
                }
            }
            if ($duplicate) {
                [$kept] = $duplicate;
                $this->rememberDuplicate($user, $method, $kept);
                return [...$duplicate, $agreement?->id];
            }
            if (!$this->methodCustomerMatches($user, $method, current: true)) return false;
            if (!$agreement) {
                try {
                    $agreement = ExtensionHelper::makeBillingAgreement($user, 'AeStripe', $data['name'],
                        $method->id, $data['type'], $data['expiry']);
                } catch (UniqueConstraintViolationException $e) {
                    // Another writer may not take our user lock. Recheck its committed row.
                    $agreement = $find();
                    if (!$agreement) throw $e;
                }
            }
            if ($agreement->user_id != $user->id || $agreement->gateway_id != $gateway->id) {
                throw new DisplayException('This payment method can\'t be used on this account. Add a new payment method.');
            }
            if ($agreement->trashed()) {
                throw new DisplayException('This payment method was removed. Add a new payment method.');
            }
            $agreement->update(['name' => $data['name'], 'type' => $data['type'], 'expiry' => $data['expiry']]);
            // Explicit checkout opt-in or account setup authorizes future redisplay.
            $this->request('post', '/payment_methods/' . $method->id, ['allow_redisplay' => 'always']);
        });
        if ($duplicate === false) return false;
        if ($duplicate) {
            if ($this->finalizeDuplicate($user, $method, consented: true, incomingId: $duplicate[2])) return 'duplicate';
            return $this->persistAgreement($user, $method);
        }
        return true; // Never assign a service billing_agreement_id or enable Auto Pay.
    }
}
