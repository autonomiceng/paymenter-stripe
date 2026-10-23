<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

use App\Helpers\ExtensionHelper;
use App\Exceptions\DisplayException;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use App\Models\Audit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use Illuminate\Http\Request;
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
            'metadata' => ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1'],
        ];

        // Do not fall back to an anonymous intent: saved methods require this customer.
        try {
            $customer = $this->createOrGetStripeCustomer($invoice->user, (bool) $this->config('stripe_create_customers'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('StripeSaved customer setup failed', [
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
            \Illuminate\Support\Facades\Log::warning('StripeSaved customer session unavailable', [
                'invoice_id' => $invoice->id, 'http_status' => $e instanceof RequestException ? $e->response->status() : null,
            ]);
        }

        return view('gateways.stripe-saved::pay', [
            'invoice' => $invoice, 'total' => $total, 'intent' => $intent,
            'customerSessionSecret' => $session?->client_secret,
            'stripePublishableKey' => $this->config('stripe_publishable_key'),
        ]);
    }

    private function gatewayEnabled(): bool
    {
        return (bool) \App\Models\Gateway::where('extension', 'StripeSaved')->first()?->enabled;
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
            && ($intent->metadata->stripe_saved_gateway ?? null) === '1', 403);
        $save = $request->boolean('save');
        if ($intent->status === 'requires_action') {
            // Resume 3DS without changing the consent already used for confirmation.
            abort_unless($save === (($intent->metadata->stripe_saved_consent ?? null) === '1'), 409);
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
            'metadata' => ['stripe_saved_consent' => $save ? '1' : '0'],
        ]);

        return response()->json(['ok' => true]);
    }

    private function recordIntentPayment($intent, $status)
    {
        $userId = Invoice::findOrFail($intent->metadata->invoice_id)->user_id;
        DB::transaction(function () use ($intent, $status, $userId) {
            User::where('id', $userId)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('id', $intent->metadata->invoice_id)->lockForUpdate()->firstOrFail();
            if (InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->lockForUpdate()->first()) return;
            if (!empty($intent->customer) && $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value) {
                \Illuminate\Support\Facades\Log::warning('StripeSaved payment customer mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordRefusedPayment($invoice, $intent, 'customer mismatch');
                return;
            }
            if (strtolower($invoice->currency_code) !== strtolower($intent->currency ?? '')) {
                \Illuminate\Support\Facades\Log::warning('StripeSaved payment currency mismatch', ['invoice_id' => $invoice->id]);
                if ($status === 'succeeded') $this->recordRefusedPayment($invoice, $intent, 'currency mismatch');
                return;
            }
            if ($status === 'succeeded' && !in_array($invoice->status, ['pending', 'paid'], true)) {
                \Illuminate\Support\Facades\Log::warning('StripeSaved payment received for nonpending invoice', ['invoice_id' => $invoice->id]);
                $this->recordRefusedPayment($invoice, $intent, 'invoice not pending');
                return;
            }
            if ($status === 'succeeded' && $invoice->status === 'paid') {
                \Illuminate\Support\Facades\Log::warning('StripeSaved payment received for paid invoice', ['invoice_id' => $invoice->id]);
            }
            $helper = match ($status) {
                'processing' => 'addProcessingPayment', 'failed' => 'addFailedPayment', default => 'addPayment',
            };
            $amount = $status === 'succeeded' ? $intent->amount_received : $intent->amount;
            ExtensionHelper::{$helper}($invoice, 'StripeSaved', $this->fromMinorUnits($amount, $intent->currency), null, $intent->id);
        });
    }

    private function recordRefusedPayment($invoice, $intent, string $reason): void
    {
        // The caller holds the user and invoice locks. Keep one staff record per invoice and PaymentIntent.
        if (Audit::where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->where('event', 'stripe_saved_payment_review')->where('new_values->payment_intent_id', $intent->id)->lockForUpdate()->first()) return;
        Audit::create([
            'event' => 'stripe_saved_payment_review',
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
        if (($intent->metadata->stripe_saved_gateway ?? null) !== '1'
            || ($intent->metadata->stripe_saved_consent ?? null) !== '1') {
            return;
        }
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($intent->customer ?? null) !== $customer || empty($intent->payment_method)) {
            \Illuminate\Support\Facades\Log::warning('StripeSaved saved method customer mismatch', ['invoice_id' => $invoice->id]);
            return;
        }
        $method = $this->request('get', '/payment_methods/' . $intent->payment_method);
        if (!in_array($method->type, ['card', 'link'])) {
            return; // The checkout opt-in authorizes saving a card or Link only.
        }
        if (($intent->payment_method_options->{$method->type}->setup_future_usage ?? null) !== 'off_session') return;
        $this->persistAgreement($invoice->user, $method);
    }

    private function methodCustomerMatches($user, $method): bool
    {
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($method->customer ?? null) !== $customer) {
            \Illuminate\Support\Facades\Log::warning('StripeSaved saved method customer mismatch', ['user_id' => $user->id]);
            return false;
        }
        return true;
    }

    private function persistAgreement($user, $method)
    {
        if (!$this->methodCustomerMatches($user, $method)) return false;
        // Explicit checkout opt-in or account setup authorizes future redisplay.
        // https://docs.stripe.com/api/payment_methods/update#update_payment_method-allow_redisplay
        $this->request('post', '/payment_methods/' . $method->id, ['allow_redisplay' => 'always']);
        $data = $this->getPaymentDetails($method);
        // Native updateOrCreate has no unique index: serialize webhook/redirect retries.
        DB::transaction(function () use ($user, $method, $data) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            ExtensionHelper::makeBillingAgreement($user, 'StripeSaved', $data['name'],
                $method->id, $data['type'], $data['expiry']);
        });
        return true; // Never assign a service billing_agreement_id or enable Auto Pay.
    }
}
