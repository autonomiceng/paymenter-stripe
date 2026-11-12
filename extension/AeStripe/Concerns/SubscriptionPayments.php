<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use Paymenter\Extensions\Gateways\AeStripe\ExtensionLocks;

use App\Models\{Invoice, InvoiceTransaction, Service, User};
use Illuminate\Support\Facades\{DB, Log};

trait SubscriptionPayments
{
    private function subscriptionInvoices(Service $service, bool $pendingOnly = true): array
    {
        $invoices = [];
        $items = $service->invoiceItems();
        if ($pendingOnly) $items->whereHas('invoice', fn ($query) => $query->where('status', 'pending'));
        foreach ($items->get() as $item) {
            $invoices[$item->invoice->id] = $item->invoice;
        }
        return $invoices;
    }

    private function finalizeSubscriptionInvoice($stripeInvoice, Service $service): void
    {
        ExtensionLocks::provider('subscription:' . $service->user_id, function () use ($stripeInvoice, $service) {
            if (!$this->bindSubscriptionInvoice($stripeInvoice, $service)) return;
            $active = ExtensionLocks::transaction('user:' . $service->user_id, function () use ($service) {
                $fresh = Service::where('id', $service->id)->lockForUpdate()->firstOrFail();
                return $fresh->user_id === $service->user_id && $fresh->subscription_id === $service->subscription_id
                    && $fresh->subscription_id !== null && $fresh->status !== Service::STATUS_CANCELLED;
            });
            if (!$active) return;
            $this->request('post', '/invoices/' . $stripeInvoice->id . '/finalize', [], 'finalize_' . $stripeInvoice->id);
            $this->request('post', '/invoices/' . $stripeInvoice->id . '/pay', [], 'pay_' . $stripeInvoice->id);
        });
    }

    private function bindSubscriptionInvoice($stripeInvoice, Service $service): bool
    {
        $current = $this->request('get', '/invoices/' . $stripeInvoice->id);
        $property = 'ae_stripe_invoice_binding_' . $stripeInvoice->id;
        $claim = ExtensionLocks::transaction('user:' . $service->user_id, function () use ($stripeInvoice, $service, $current, $property) {
            $fresh = Service::where('id', $service->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->user_id === $service->user_id && $fresh->subscription_id === $service->subscription_id, 409);
            if (!$fresh->subscription_id || $fresh->status === Service::STATUS_CANCELLED) return null;
            $user = User::findOrFail($service->user_id);
            $stored = $user->properties()->where('key', $property)->first();
            $claim = $stored ? json_decode($stored->value, true, flags: JSON_THROW_ON_ERROR) : null;
            $invoices = $this->subscriptionInvoices($fresh);
            $bound = $current->metadata->ae_stripe_invoice_id ?? $claim['invoice_id'] ?? null;
            $invoice = $bound ? ($invoices[$bound] ?? null) : (count($invoices) === 1 ? reset($invoices) : null);
            if (!$invoice || strtolower($invoice->currency_code) !== strtolower($stripeInvoice->currency ?? '')) {
                Log::warning('AeStripe subscription invoice needs reconciliation', ['service_id' => $service->id]);
                return null;
            }
            if ($claim) abort_unless($claim['invoice_id'] === $invoice->id && $claim['service_id'] === $service->id, 409);
            else {
                $claim = ['key' => 'binding_' . bin2hex(random_bytes(16)), 'invoice_id' => $invoice->id, 'service_id' => $service->id];
                $user->properties()->updateOrCreate(['key' => $property], ['value' => json_encode($claim, JSON_THROW_ON_ERROR)]);
            }
            return $claim;
        });
        if (!$claim) return false;
        if (empty($current->metadata->ae_stripe_invoice_id)) $this->request('post', '/invoices/' . $stripeInvoice->id,
            ['metadata' => ['ae_stripe_invoice_id' => $claim['invoice_id']]], $claim['key']);
        return true;
    }

    private function recordSubscriptionReview($stripeInvoice, string $reason): void
    {
        // No invoice can be inferred safely. Keep a gateway audit entry for the Stripe invoice.
        DB::afterCommit(function () use ($stripeInvoice, $reason) {
            $id = \App\Models\Gateway::where('extension', 'AeStripe')->firstOrFail()->id;
            ExtensionLocks::transaction('gateway', function () use ($stripeInvoice, $reason, $id) {
                // Lock the existing gateway before the first plain read. A fresh transaction after settlement avoids an
                // older invoice snapshot; the gateway serializes dedupe without locking another customer's property audits.
                $gateway = \App\Models\Gateway::where('extension', 'AeStripe')->where('id', $id)->firstOrFail();
                if (\App\Models\Audit::where('auditable_type', \App\Models\Gateway::class)->where('auditable_id', $gateway->id)
                    ->where('event', 'ae_stripe_subscription_review')
                    ->where('new_values->stripe_invoice_id', $stripeInvoice->id)->first()) return;
                \App\Models\Audit::create([
                    'event' => 'ae_stripe_subscription_review', 'auditable_type' => \App\Models\Gateway::class,
                    'auditable_id' => $gateway->id, 'old_values' => [], 'new_values' => [
                        'stripe_invoice_id' => $stripeInvoice->id, 'amount_minor' => $stripeInvoice->amount_paid,
                        'currency' => $stripeInvoice->currency, 'reason' => $reason,
                        'subscription_id' => $stripeInvoice->parent->subscription_details->subscription ?? null,
                    ],
                ]);
            });
        });
    }

    private function recordSubscriptionPayment($stripeInvoice)
    {
        if (($stripeInvoice->parent->type ?? null) !== 'subscription_details') return;
        $subscriptionId = $stripeInvoice->parent->subscription_details->subscription ?? null;
        if (!$subscriptionId) { $this->recordSubscriptionReview($stripeInvoice, 'Subscription missing'); return; }
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
            $this->recordSubscriptionReview($stripeInvoice, 'Payment list incomplete or split');
            return;
        }
        $paymentId = $paymentIds[0];
        // Expand before settlement locks; no optional charge request belongs inside them.
        $intent = $this->request('get', '/payment_intents/' . $paymentId, ['expand' => ['latest_charge']]);
        abort_unless($intent->id === $paymentId && $intent->status === 'succeeded', 409);
        $this->ensureReceiptReturnSchema();
        // Read the owner before the transaction. MariaDB takes a transaction's read view at its first plain read, so
        // the user lock must come first: plain reads after it then see attempts committed while settlement waited.
        $ownerId = Service::where('subscription_id', $subscriptionId)->first()?->user_id;
        if (!$ownerId) { $this->recordSubscriptionReview($stripeInvoice, 'Service missing'); return; }
        ExtensionLocks::transaction('user:' . $ownerId, function () use ($stripeInvoice, $subscriptionId, $paymentId, $intent, $ownerId) {
            // Match PaymentIntent settlement: serialize on the customer before other rows.
            User::where('id', $ownerId)->firstOrFail();
            // services has no subscription_id index, so a locking read by it locks every row it scans, other customers'
            // services too. Find the row with a plain read under the owner lock, then lock only it by primary key.
            $id = Service::where('subscription_id', $subscriptionId)->first()?->id;
            $service = $id ? Service::where('id', $id)->lockForUpdate()->first() : null;
            if ($service?->subscription_id !== $subscriptionId) $service = null;
            if ($service) abort_unless($service->user_id === $ownerId, 409);
            if (!$service) { $this->recordSubscriptionReview($stripeInvoice, 'Service missing'); return; }
            if ($settled = $this->lockedTransactions(InvoiceTransaction::where('transaction_id', $paymentId), 'succeeded')[0] ?? null) {
                $this->schedulePaymentReviewAlert($settled->invoice, $paymentId);
                return;
            }
            $invoiceId = $stripeInvoice->metadata->ae_stripe_invoice_id ?? null;
            $invoice = $invoiceId ? $service->invoiceItems()->where('invoice_id', $invoiceId)->first()?->invoice : null;
            if (!$invoice) {
                $this->recordSubscriptionReview($stripeInvoice, 'Invoice binding missing or unrelated');
                return;
            }
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();
            if (!$invoice) { $this->recordSubscriptionReview($stripeInvoice, 'Invoice missing'); return; }
            abort_unless($invoice->user_id === $ownerId, 409);
            // Subscription metadata owns the local binding, not PaymentIntent metadata.
            $intent->metadata = (object) ['invoice_id' => $invoice->id];
            if (strtolower($invoice->currency_code) !== strtolower($stripeInvoice->currency ?? '')) {
                Log::warning('AeStripe subscription payment currency mismatch', ['service_id' => $service->id]);
                $this->recordPaymentReview($invoice, $intent, 'currency mismatch');
                return;
            }
            // Use the same locked transaction path as PaymentIntent events, so either delivery order is safe.
            $this->recordIntentPayment($intent, 'succeeded', false, $stripeInvoice->id);
        }, self::SETTLEMENT_ATTEMPTS);
    }
}
