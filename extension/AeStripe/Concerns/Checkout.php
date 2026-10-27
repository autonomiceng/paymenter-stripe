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
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait Checkout
{
    public function pay($invoice, $total)
    {
        abort_unless($this->gatewayEnabled(), 409);
        abort_unless(Auth::id() == $invoice->user_id, 403);
        $intentData = [
            'description' => __('invoices.payment_for_invoice', ['number' => $invoice->number ?? $invoice->id]),
            'amount' => $this->toMinorUnits($total, $invoice->currency_code),
            'currency' => $invoice->currency_code,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['invoice_id' => $invoice->id, 'ae_stripe_gateway' => '1'],
        ];

        // Do not fall back to an anonymous intent: saved methods require this customer.
        try {
            $customer = $this->createOrGetStripeCustomer($invoice->user, (bool) $this->config('stripe_create_customers'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AeStripe customer setup failed', [
                'invoice_id' => $invoice->id, 'error' => get_class($e),
            ]);
            throw new DisplayException('Could not prepare your payment. Please try again.');
        }
        $intentData['customer'] = $customer->id;
        $intent = $this->checkoutIntent($invoice, $intentData);
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

    private function recordIntentPayment($intent, $status, bool $prepareMethod = true)
    {
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $userId = $invoice->user_id;
        if ($prepareMethod) $intent = $this->prepareTransactionMethod($invoice, $intent, $status);
        DB::transaction(function () use ($intent, $status, $userId) {
            User::where('id', $userId)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('id', $intent->metadata->invoice_id)->lockForUpdate()->firstOrFail();
            if (InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->lockForUpdate()->first()) return;
            if (!empty($intent->customer) && $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment customer mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordRefusedPayment($invoice, $intent, 'customer mismatch');
                return;
            }
            if (strtolower($invoice->currency_code) !== strtolower($intent->currency ?? '')) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment currency mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordRefusedPayment($invoice, $intent, 'currency mismatch');
                return;
            }
            if ($status === 'succeeded' && !in_array($invoice->status, ['pending', 'paid'], true)) {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment received for nonpending invoice', ['invoice_id' => $invoice->id]);
                $this->recordRefusedPayment($invoice, $intent, 'invoice not pending');
                return;
            }
            if ($status === 'succeeded' && $invoice->status === 'paid') {
                \Illuminate\Support\Facades\Log::warning('AeStripe payment received for paid invoice', ['invoice_id' => $invoice->id]);
            }
            $helper = match ($status) {
                'processing' => 'addProcessingPayment', 'failed' => 'addFailedPayment', default => 'addPayment',
            };
            $amount = $status === 'succeeded' ? $intent->amount_received : $intent->amount;
            $transaction = ExtensionHelper::{$helper}($invoice, 'AeStripe', $this->fromMinorUnits($amount, $intent->currency), null, $intent->id);
            if (in_array($status, ['succeeded', 'processing'], true)) {
                $this->recordTransactionMethod($transaction, $intent);
            }
            if ($status === 'failed') {
                // The locked success guard above preserves labels on settled payments.
                DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', $transaction->id)->delete();
            }
        });
    }

    private function recordRefusedPayment($invoice, $intent, string $reason): void
    {
        // The caller holds the user and invoice locks. Keep one staff record per invoice and PaymentIntent.
        if (Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->where('event', 'ae_stripe_payment_review')->where('new_values->payment_intent_id', $intent->id)->lockForUpdate()->first()) return;
        Audit::create([
            'event' => 'ae_stripe_payment_review',
            'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id,
            'old_values' => [],
            'new_values' => [
                'payment_intent_id' => $intent->id,
                'amount_minor' => $intent->amount_received,
                'currency' => $intent->currency ?? '', 'reason' => $reason,
                'resolution' => 'Payment was not applied. Review the charge in Stripe for a refund or record the correct customer credit in Paymenter.',
            ],
        ]);
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
                throw new DisplayException('This payment method belongs to another account or gateway.');
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
                throw new DisplayException('This payment method belongs to another account or gateway.');
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
