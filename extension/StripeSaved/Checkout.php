<?php

namespace Paymenter\Extensions\Gateways\StripeSaved;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
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
            'amount' => $total * 100,
            'currency' => $invoice->currency_code,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['invoice_id' => $invoice->id, 'stripe_saved_gateway' => '1'],
        ];

        // Do not fall back to an anonymous intent: saved methods require this customer.
        $customer = $this->createOrGetStripeCustomer($invoice->user, (bool) $this->config('stripe_create_customers'));
        $intentData['customer'] = $customer->id;
        $intent = $this->request('post', '/payment_intents', $intentData);
        // API assumptions must be verified against the pinned Basil version in a sandbox.
        // https://docs.stripe.com/api/customer_sessions/create
        // Removal is disabled: native agreements may still reference a removed method.
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

        return view('gateways.stripe-saved::pay', [
            'invoice' => $invoice, 'total' => $total, 'intent' => $intent,
            'customerSessionSecret' => $session->client_secret,
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
        abort_unless(in_array($intent->status, ['requires_payment_method', 'requires_confirmation']), 409);
        $save = $request->boolean('save');
        // https://docs.stripe.com/api/payment_intents/update#update_payment_intent-setup_future_usage
        $this->request('post', '/payment_intents/' . $intent->id, [
            'setup_future_usage' => $save ? 'off_session' : '',
            'metadata' => ['stripe_saved_consent' => $save ? '1' : '0'],
        ]);

        return response()->json(['ok' => true]);
    }

    private function savePaidMethod($intent)
    {
        if (($intent->setup_future_usage ?? null) !== 'off_session'
            || ($intent->metadata->stripe_saved_gateway ?? null) !== '1'
            || ($intent->metadata->stripe_saved_consent ?? null) !== '1') {
            return;
        }
        $invoice = Invoice::findOrFail($intent->metadata->invoice_id);
        $customer = $invoice->user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($intent->customer ?? null) !== $customer || empty($intent->payment_method)) {
            throw new \RuntimeException('Saved payment customer mismatch');
        }
        $method = $this->request('get', '/payment_methods/' . $intent->payment_method);
        if (!in_array($method->type, ['card', 'link'])) {
            return; // The checkout opt-in authorizes saving a card or Link only.
        }
        $this->assertMethodCustomer($invoice->user, $method);
        // Explicit opt-in permits redisplay; do not expose legacy unspecified-consent methods.
        // https://docs.stripe.com/api/payment_methods/update#update_payment_method-allow_redisplay
        $this->request('post', '/payment_methods/' . $method->id, ['allow_redisplay' => 'always']);
        $this->persistAgreement($invoice->user, $method);
    }

    private function assertMethodCustomer($user, $method)
    {
        $customer = $user->properties()->where('key', 'stripe_id')->first()?->value;
        if (!$customer || ($method->customer ?? null) !== $customer) {
            throw new \RuntimeException('Saved payment customer mismatch');
        }
    }

    private function persistAgreement($user, $method)
    {
        $this->assertMethodCustomer($user, $method);
        $data = $this->getPaymentDetails($method);
        // Native updateOrCreate has no unique index: serialize webhook/redirect retries.
        DB::transaction(function () use ($user, $method, $data) {
            User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            ExtensionHelper::makeBillingAgreement($user, 'StripeSaved', $data['name'],
                $method->id, $data['type'], $data['expiry']);
        }); // Never assign a service billing_agreement_id or enable Auto Pay.
    }
}
