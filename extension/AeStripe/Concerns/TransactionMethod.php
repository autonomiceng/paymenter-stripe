<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Models\InvoiceTransaction;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\{DB, Log};

trait TransactionMethod
{
    private function prepareTransactionMethod($invoice, $intent, string $status)
    {
        $charge = $intent->latest_charge ?? null;
        // This read only avoids optional I/O. Settlement repeats its checks under locks.
        if (!is_string($charge) || !in_array($status, ['succeeded', 'processing'], true)
            || (!empty($intent->customer) && $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value)
            || strtolower($invoice->currency_code) !== strtolower($intent->currency ?? '')
            || ($status === 'succeeded' && !in_array($invoice->status, ['pending', 'paid'], true))
            || InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) return $intent;
        $existing = InvoiceTransaction::where('transaction_id', $intent->id)->first();
        if ($existing && DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', $existing->id)->exists()) return $intent;
        if (is_string($charge) && preg_match('/^ch_[a-zA-Z0-9]+$/D', $charge)) {
            try {
                $charge = $this->request('get', '/charges/' . $charge);
            } catch (ConnectionException | RequestException $e) {
                Log::warning('AeStripe transaction method lookup failed', ['invoice_id' => $invoice->id]);
                return $intent;
            }
        }
        $prepared = clone $intent;
        $prepared->latest_charge = $charge;
        return $prepared;
    }

    private function recordTransactionMethod($transaction, $intent): void
    {
        $table = DB::table('ae_stripe_transaction_methods');
        if ($table->where('invoice_transaction_id', $transaction->id)->exists()) return;
        $charge = $intent->latest_charge ?? null;
        $details = is_object($charge) ? ($charge->payment_method_details ?? null) : null;
        if (!is_object($details) || !is_string($details->type ?? null) || $details->type === '') return;
        $type = $details->type;
        // Charge details can omit expiry and billing details present on a saved PaymentMethod.
        if ($type === 'card' && (!is_string($details->card->brand ?? null)
            || !preg_match('/^[0-9]{4}$/D', $details->card->last4 ?? ''))) return;
        if (in_array($type, ['sepa_debit', 'us_bank_account', 'bacs_debit', 'au_becs_debit'], true)
            && !preg_match('/^[0-9]{4}$/D', $details->{$type}->last4 ?? '')) return;
        if ($type === 'ideal' && !isset($details->ideal->bank_code)) return;
        if ($type === 'bancontact' && !isset($details->bancontact->bank_code)) return;
        if ($type === 'sofort' && !isset($details->sofort->country)) return;
        $label = $this->getPaymentDetails($details)['name'];
        if (strlen($label) > 255) return;
        // The payment handler holds the customer and invoice locks. Keep the first label.
        DB::table('ae_stripe_transaction_methods')->insertOrIgnore([
            'invoice_transaction_id' => $transaction->id, 'label' => $label,
        ]);
    }
}
