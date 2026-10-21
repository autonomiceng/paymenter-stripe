<?php

namespace Paymenter\Extensions\Gateways\StripeSaved;

use App\Helpers\ExtensionHelper;
use App\Models\{InvoiceTransaction, Service};
use Illuminate\Http\Request;

trait Webhooks
{
    private function ownsWebhookObject($object, string $type): bool
    {
        $metadata = $object->metadata ?? null;
        $subscriptionMetadata = $object->parent->subscription_details->metadata ?? null;
        if (($metadata->stripe_saved_gateway ?? null) === '1'
            || ($subscriptionMetadata->stripe_saved_gateway ?? null) === '1'
            || !\App\Models\Gateway::where('extension', 'Stripe')->first()?->enabled) return true;
        \Illuminate\Support\Facades\Log::info('StripeSaved webhook ignored: bundled gateway owns unmarked event', [
            'event_type' => $type, 'invoice_id' => $metadata->invoice_id ?? $metadata->stripe_saved_invoice_id ?? null,
        ]);
        return false;
    }

    public function webhook(Request $request)
    {
        if (!$this->gatewayEnabled()) {
            \Illuminate\Support\Facades\Log::info('StripeSaved webhook ignored: gateway disabled or missing');
            return response()->json(['ignored' => true]);
        }
        if (!$this->isValidSignature($request->getContent(), $request->header('Stripe-Signature'), $this->config('stripe_webhook_secret'))) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $event = json_decode($request->getContent());
        if (!is_object($event) || !isset($event->type, $event->data->object)) {
            return response()->json(['error' => 'Invalid event'], 400);
        }

        $object = $event->data->object;
        if ((str_starts_with($event->type, 'payment_intent.') || $event->type === 'setup_intent.succeeded'
            || in_array($event->type, ['invoice.created', 'invoice.payment_succeeded', 'subscription_schedule.canceled']))
            && !$this->ownsWebhookObject($object, $event->type)) {
            return response()->json(['ignored' => true]);
        }

        // Handle the event
        switch ($event->type) {
            case 'payment_intent.processing':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $this->recordIntentPayment($paymentIntent, 'processing');
                break;
                // Normal payment
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $this->recordIntentPayment($paymentIntent, 'succeeded');
                $this->savePaidMethod($paymentIntent);
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $this->recordIntentPayment($paymentIntent, 'failed');
                break;
            case 'charge.updated':
                $charge = $event->data->object; // contains a StripeCharge
                $invoiceTransaction = InvoiceTransaction::where('transaction_id', $charge->payment_intent)->first();
                if (($invoiceTransaction->gateway->extension ?? null) !== 'StripeSaved') {
                    break;
                }
                // Get fee from charge
                $fee = 0;
                if ($charge->balance_transaction) {
                    $balanceTransaction = $this->request('get', '/balance_transactions/' . $charge->balance_transaction);
                    $fee = $this->fromMinorUnits($balanceTransaction->fee, $balanceTransaction->currency);
                }
                ExtensionHelper::addPaymentFee($charge->payment_intent, $fee);

                break;
            case 'setup_intent.succeeded':
                $setupIntent = $event->data->object; // contains a StripeSetupIntent
                // If it's a billing agreement, call setupBillingAgreement
                if (($setupIntent->metadata->is_billing_agreement ?? null) === '1') {
                    $this->setupBillingAgreement($setupIntent);
                } elseif (isset($setupIntent->metadata->invoice_id)) {
                    $this->setupSubscription($setupIntent);
                }
                break;
            case 'subscription_schedule.canceled':
                $subscriptionSchedule = $event->data->object; // contains a StripeSubscriptionSchedule
                $subscriptionId = $subscriptionSchedule->subscription ?? null;
                $service = $subscriptionId ? Service::where('subscription_id', $subscriptionId)->first() : null;
                if ($service) {
                    $service->update(['subscription_id' => null]);
                }
                break;
            case 'invoice.created':
                $invoice = $event->data->object; // contains a StripeInvoice

                if ($this->config('stripe_use_subscriptions') !== true) {
                    break;
                }

                // Check if its draft and does exist in our database
                if ($invoice->status === 'draft' && ($invoice->parent->type ?? null) === 'subscription_details') {
                    $service = Service::where('subscription_id', $invoice->parent->subscription_details->subscription)->first();

                    if ($service && $this->bindSubscriptionInvoice($invoice, $service)) {
                        $this->request('post', '/invoices/' . $invoice->id . '/finalize');
                        // Pay the invoice using Stripe
                        $this->request('post', '/invoices/' . $invoice->id . '/pay');
                    }
                }
                break;
            case 'invoice.payment_succeeded':
                $this->recordSubscriptionPayment($event->data->object);
                break;
            default:
                // Not a event type we care about, just return 200
        }

        return response()->json(['received' => true]);
    }

}
