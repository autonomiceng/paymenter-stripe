<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

use App\Exceptions\DisplayException;
use App\Models\Service;
use Illuminate\Http\Request;

trait Webhooks
{
    private function ownsWebhookObject($object, string $type): bool
    {
        $metadata = $object->metadata ?? null;
        $subscriptionMetadata = $object->parent->subscription_details->metadata ?? null;
        if (($metadata->stripe_saved_gateway ?? null) === '1'
            || ($subscriptionMetadata->stripe_saved_gateway ?? null) === '1'
            || $this->bundledInactive(requireAbsent: true)) return true;
        \Illuminate\Support\Facades\Log::info('StripeSaved webhook ignored: bundled gateway owns unmarked event', [
            'event_type' => $type, 'invoice_id' => $metadata->invoice_id ?? $metadata->stripe_saved_invoice_id ?? null,
        ]);
        return false;
    }

    public function webhook(Request $request)
    {
        if (!$this->isValidSignature($request->getContent(), $request->header('Stripe-Signature'), $this->config('stripe_webhook_secret'))) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }
        // Non-2xx so Stripe keeps retrying; a 200 here would discard the event.
        if (!$this->gatewayEnabled()) {
            \Illuminate\Support\Facades\Log::info('StripeSaved webhook deferred: gateway disabled or missing');
            return response()->json(['error' => 'Gateway disabled'], 503);
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
                try {
                    $this->savePaidMethod($paymentIntent);
                } catch (DisplayException $e) {
                    \Illuminate\Support\Facades\Log::warning('StripeSaved saved method refused', ['reason' => $e->getMessage()]);
                }
                break;
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object; // contains a StripePaymentIntent
                if (!isset($paymentIntent->metadata->invoice_id)) {
                    break;
                }
                $this->recordIntentPayment($paymentIntent, 'failed');
                break;
            case 'charge.succeeded':
            case 'charge.updated':
                if (!$this->recordChargeFee($object)) return response()->json(['ignored' => true]);
                break;
            case 'setup_intent.succeeded':
                $setupIntent = $event->data->object; // contains a StripeSetupIntent
                // If it's a billing agreement, call setupBillingAgreement
                if (($setupIntent->metadata->is_billing_agreement ?? null) === '1') {
                    try {
                        $this->setupBillingAgreement($setupIntent);
                    } catch (DisplayException $e) {
                        \Illuminate\Support\Facades\Log::warning('StripeSaved saved method refused', ['reason' => $e->getMessage()]);
                    }
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
