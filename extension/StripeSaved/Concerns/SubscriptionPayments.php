<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

use App\Models\{Invoice, InvoiceTransaction, Service, User};
use Illuminate\Support\Facades\{DB, Log};

trait SubscriptionPayments
{
    private function pendingSubscriptionInvoices(Service $service): array
    {
        $invoices = [];
        foreach ($service->invoiceItems()->whereHas('invoice', fn ($query) => $query->where('status', 'pending'))->get() as $item) {
            $invoices[$item->invoice->id] = $item->invoice;
        }
        return $invoices;
    }

    private function bindSubscriptionInvoice($stripeInvoice, Service $service): bool
    {
        return DB::transaction(function () use ($stripeInvoice, $service) {
            Service::where('id', $service->id)->lockForUpdate()->firstOrFail();
            // Event snapshots are immutable: recover the current remote binding on every retry.
            $current = $this->request('get', '/invoices/' . $stripeInvoice->id);
            $invoices = $this->pendingSubscriptionInvoices($service);
            $bound = $current->metadata->stripe_saved_invoice_id ?? null;
            $invoice = $bound ? ($invoices[$bound] ?? null) : (count($invoices) === 1 ? reset($invoices) : null);
            if (!$invoice || strtolower($invoice->currency_code) !== strtolower($stripeInvoice->currency ?? '')) {
                Log::warning('StripeSaved subscription invoice needs reconciliation', ['service_id' => $service->id]);
                return false;
            }
            if (!$bound) $this->request('post', '/invoices/' . $stripeInvoice->id, ['metadata' => ['stripe_saved_invoice_id' => $invoice->id]]);
            return true;
        });
    }

    private function recordSubscriptionPayment($stripeInvoice)
    {
        if (($stripeInvoice->parent->type ?? null) !== 'subscription_details') return;
        $subscriptionId = $stripeInvoice->parent->subscription_details->subscription ?? null;
        if (!$subscriptionId) return;
        $payments = $this->request('get', '/invoice_payments', ['invoice' => $stripeInvoice->id]);
        $paymentIds = [];
        $paidAmount = null;
        foreach ($payments->data ?? [] as $entry) {
            if (($entry->payment->type ?? null) === 'payment_intent' && !empty($entry->payment->payment_intent)) {
                if (($entry->status ?? 'paid') !== 'paid') continue;
                $paymentIds[] = $entry->payment->payment_intent;
                $paidAmount = $entry->amount_paid ?? null;
            }
        }
        if (count($paymentIds) !== 1 || ($paidAmount !== null && $paidAmount !== $stripeInvoice->amount_paid)) {
            if ($paymentIds) Log::warning('StripeSaved split payment needs reconciliation');
            return;
        }
        $paymentId = $paymentIds[0];
        DB::transaction(function () use ($stripeInvoice, $subscriptionId, $paymentId) {
            $ownerId = Service::where('subscription_id', $subscriptionId)->first()?->user_id;
            if (!$ownerId) return;
            // Match PaymentIntent settlement: serialize on the customer before other rows.
            User::where('id', $ownerId)->lockForUpdate()->firstOrFail();
            $service = Service::where('subscription_id', $subscriptionId)->lockForUpdate()->first();
            if ($service) abort_unless($service->user_id === $ownerId, 409);
            if (!$service || InvoiceTransaction::where('transaction_id', $paymentId)->where('status', 'succeeded')->lockForUpdate()->first()) return;
            $invoiceId = $stripeInvoice->metadata->stripe_saved_invoice_id ?? null;
            $invoice = $invoiceId ? ($this->pendingSubscriptionInvoices($service)[$invoiceId] ?? null) : null;
            if (!$invoice) {
                Log::warning('StripeSaved subscription invoice needs reconciliation', ['service_id' => $service->id]);
                return;
            }
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
            if (!$invoice || $invoice->status !== 'pending') return;
            if (strtolower($invoice->currency_code) !== strtolower($stripeInvoice->currency ?? '')) {
                Log::warning('StripeSaved subscription payment currency mismatch', ['service_id' => $service->id]);
                return;
            }
            // Use the same locked transaction path as PaymentIntent events, so either delivery order is safe.
            $this->recordIntentPayment((object) ['id' => $paymentId, 'amount' => $stripeInvoice->amount_paid,
                'currency' => $stripeInvoice->currency, 'metadata' => (object) ['invoice_id' => $invoice->id]], 'succeeded');
        });
    }
}
