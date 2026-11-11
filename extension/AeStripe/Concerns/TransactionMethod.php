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
        if (!is_string($charge) || !in_array($status, ['succeeded', 'processing', 'failed'], true)
            || (!empty($intent->customer) && $intent->customer !== $invoice->user->properties()->where('key', 'stripe_id')->first()?->value)
            || strtolower($invoice->currency_code) !== strtolower($intent->currency ?? '')
            || ($status === 'succeeded' && !in_array($invoice->status, ['pending', 'paid'], true))
            || InvoiceTransaction::where('transaction_id', $intent->id)->where('status', 'succeeded')->exists()) return $intent;
        $existing = InvoiceTransaction::where('transaction_id', $intent->id)->first();
        // Failure can be recorded from confirmation while its locks are already held.
        // The error's embedded method is enough there; optional reads must not extend the lock.
        if ($status === 'failed') {
            $stored = $existing ? DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', $existing->id)->first() : null;
            if (DB::transactionLevel() > 0 || !empty($stored->failure_reason) && !empty($stored->label)) return $intent;
        }
        // A failed attempt may replace this label before success acquires its locks.
        if ($status === 'processing' && $existing && DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', $existing->id)->exists()) return $intent;
        if (is_string($charge) && preg_match('/^(?:ch|py)_[a-zA-Z0-9]+$/D', $charge)) {
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

    private function recordTransactionMethod($transaction, $intent, bool $succeeded, bool $failed = false): void
    {
        $table = DB::table('ae_stripe_transaction_methods')->where('invoice_transaction_id', $transaction->id);
        $stored = $failed ? $table->first() : null;
        // Settlement holds the invoice lock that every writer here holds, so a plain read finds the row. A delete of a
        // missing row would lock the index gap that this payment and a neighbouring one then insert into: a deadlock.
        $exists = $stored || $table->exists();
        // The locked success guard permits only the first successful settlement here.
        if ($succeeded || $failed) { if ($exists) $table->delete(); }
        elseif ($exists) return;
        $charge = $intent->latest_charge ?? null;
        $details = is_object($charge) ? ($charge->payment_method_details ?? null) : null;
        if ($failed) $details ??= $intent->last_payment_error->payment_method ?? null;
        $label = $this->transactionMethodLabel($details);
        if ($failed && !empty($stored->failure_reason)) $label ??= $stored->label;
        if ($label === null && !$failed) return;
        $reason = !empty($intent->last_payment_error) ? $this->transactionFailureReason($intent)
            : ($stored->failure_reason ?? $this->transactionFailureReason($intent));
        DB::table('ae_stripe_transaction_methods')->insertOrIgnore([
            'invoice_transaction_id' => $transaction->id, 'label' => $label ?? '',
        ] + ($failed ? ['failure_reason' => $reason] : []));
    }

    private function transactionMethodLabel($details): ?string
    {
        if (!is_object($details) || !is_string($details->type ?? null) || $details->type === '') return null;
        $type = $details->type;
        // Charge details can omit expiry and billing details present on a saved PaymentMethod.
        if ($type === 'card' && (!is_string($details->card->brand ?? null)
            || !preg_match('/^[0-9]{4}$/D', $details->card->last4 ?? ''))) return null;
        if (in_array($type, ['sepa_debit', 'us_bank_account', 'bacs_debit', 'au_becs_debit'], true)
            && !preg_match('/^[0-9]{4}$/D', $details->{$type}->last4 ?? '')) return null;
        if ($type === 'ideal' && !isset($details->ideal->bank_code)) return null;
        if ($type === 'bancontact' && !isset($details->bancontact->bank_code)) return null;
        if ($type === 'sofort' && !isset($details->sofort->country)) return null;
        $label = $this->getPaymentDetails($details)['name'];
        return strlen($label) <= 255 ? $label : null;
    }

    private function transactionFailureReason($intent): string
    {
        return match ($intent->last_payment_error->decline_code ?? $intent->last_payment_error->code ?? null) {
            'insufficient_funds' => 'Insufficient funds.',
            'expired_card' => 'This card has expired.',
            'incorrect_cvc', 'invalid_cvc' => 'The card security code is incorrect.',
            'incorrect_zip' => 'The billing postal code is incorrect.',
            'authentication_required', 'payment_intent_authentication_failure' => 'Card authentication was not completed.',
            'processing_error', 'issuer_not_available', 'try_again_later' => 'The card issuer could not process this payment.',
            default => 'This payment was declined.',
        };
    }
}
