<?php

namespace Paymenter\Extensions\Gateways\AeStripe\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\{Audit, Invoice, InvoiceTransaction, Notification, User};
use Illuminate\Support\Facades\DB;

trait PaymentFees
{
    public function installed()
    {
        $this->upgraded();
    }

    public function upgraded($oldVersion = null)
    {
        app(\Illuminate\Database\Migrations\Migrator::class)->run(base_path('extensions/Gateways/AeStripe/database/migrations'));
    }

    private static bool $paymentFeeSchemaReady = false;

    private function ensurePaymentFeeSchema(): void
    {
        if (self::$paymentFeeSchemaReady) return;
        if (!\Illuminate\Support\Facades\Schema::hasTable('ae_stripe_payment_fees')) $this->upgraded();
        self::$paymentFeeSchemaReady = true;
    }

    private function feeRows()
    {
        $this->ensurePaymentFeeSchema();
        return DB::table('ae_stripe_payment_fees');
    }

    public function retryPaymentFees(): void
    {
        if (!$this->gatewayEnabled()) return;
        foreach ($this->feeRows()->where('state', 'pending')->orderBy('attempts')->orderBy('created_at')->limit(100)->get() as $row) {
            try {
                $transaction = InvoiceTransaction::where('transaction_id', $row->payment_intent_id)->first();
                if (!$transaction || !$transaction->invoice || ($transaction->gateway->extension ?? null) !== 'AeStripe') {
                    DB::transaction(function () use ($row) {
                        $current = $this->feeRows()->where('payment_intent_id', $row->payment_intent_id)->lockForUpdate()->first();
                        if (($current->state ?? null) !== 'pending') return;
                        $current->last_error = 'Payment transaction unavailable';
                        $this->stopPaymentFee($current, null);
                    });
                    continue;
                }
                $this->recordChargeFee((object) [
                    'payment_intent' => $row->payment_intent_id, 'id' => $row->charge_id,
                    'balance_transaction' => $row->balance_transaction_id, 'paid' => true, 'status' => 'succeeded',
                ]);
            } catch (\Throwable $e) {
                report($e);
                // The failed transaction rolled back. Count this attempt separately.
                try {
                    $failed = DB::transaction(function () use ($row, $e) {
                        $current = $this->feeRows()->where('payment_intent_id', $row->payment_intent_id)->lockForUpdate()->first();
                        if (($current->state ?? null) !== 'pending') return;
                        $current->attempts++;
                        $current->last_error = 'Fee recovery failed: ' . get_class($e);
                        $this->feeRows()->where('payment_intent_id', $row->payment_intent_id)->update([
                            'attempts' => $current->attempts, 'last_error' => $current->last_error,
                        ]);
                        return $current;
                    });
                    if ($failed && ($failed->attempts >= 24 || time() - $failed->created_at >= 72 * 3600)) {
                        DB::transaction(function () use ($failed) {
                            $current = $this->feeRows()->where('payment_intent_id', $failed->payment_intent_id)->lockForUpdate()->first();
                            if (($current->state ?? null) !== 'pending') return;
                            $this->stopPaymentFee($current, null);
                        });
                    }
                } catch (\Throwable $failure) { report($failure); }
            }
        }
    }

    private function feeSettlementExpected($charge, string $paymentId): bool
    {
        $intent = $this->request('get', '/payment_intents/' . $paymentId);
        abort_unless(($intent->id ?? null) === $paymentId, 409);
        if (Audit::where('event', 'ae_stripe_payment_review')->where('new_values->payment_intent_id', $paymentId)->first()) return false;
        $invoiceId = $charge->invoice ?? $intent->invoice ?? null;
        if (is_object($invoiceId)) $invoiceId = $invoiceId->id ?? null;
        if (!$invoiceId && !isset($intent->metadata->invoice_id)) {
            $payments = $this->request('get', '/invoice_payments', [
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentId],
                'status' => 'paid', 'limit' => 2,
            ]);
            if (($payments->has_more ?? false) || !is_array($payments->data ?? null) || count($payments->data) !== 1) return false;
            $payment = $payments->data[0];
            if (($payment->status ?? null) !== 'paid' || ($payment->payment->type ?? null) !== 'payment_intent'
                || ($payment->payment->payment_intent ?? null) !== $paymentId) return false;
            $invoiceId = $payment->invoice ?? null;
            if (is_object($invoiceId)) $invoiceId = $invoiceId->id ?? null;
        }
        if ($invoiceId && Audit::where('event', 'ae_stripe_subscription_review')
            ->where('new_values->stripe_invoice_id', $invoiceId)->first()) return false;
        if (isset($intent->metadata->invoice_id) && $this->ownsWebhookObject($intent, 'charge.succeeded')) return true;
        if (!$invoiceId) return false;
        $invoice = $this->request('get', '/invoices/' . $invoiceId);
        return ($invoice->parent->type ?? null) === 'subscription_details'
            && isset($invoice->metadata->ae_stripe_invoice_id) && $this->ownsWebhookObject($invoice, 'charge.succeeded');
    }

    private function recordChargeFee($charge): bool
    {
        $paymentId = $charge->payment_intent ?? null;
        if (!$paymentId || !($charge->paid ?? false) || ($charge->status ?? null) !== 'succeeded') return false;
        $transaction = InvoiceTransaction::where('transaction_id', $paymentId)->first();
        if (!$transaction) {
            // Retry only a payment whose local settlement is still expected.
            if ($this->feeSettlementExpected($charge, $paymentId)) abort(409);
            return false;
        }
        if (($transaction->gateway->extension ?? null) !== 'AeStripe') return false;
        $snapshot = $this->feeRows()->where('payment_intent_id', $paymentId)->get()[0] ?? null;
        if ($snapshot && $snapshot->state !== 'pending') return true;
        $balanceId = $charge->balance_transaction ?? $snapshot?->balance_transaction_id;
        $chargeId = $charge->id ?? $snapshot?->charge_id;
        $balance = null;
        $error = 'Balance transaction unavailable';
        if (!$snapshot || ($snapshot->attempts < 24 && time() - $snapshot->created_at < 72 * 3600)) {
            try {
                if (!$balanceId && $chargeId) {
                    $latest = $this->request('get', '/charges/' . $chargeId);
                    abort_unless(($latest->payment_intent ?? null) === $paymentId, 409);
                    $balanceId = $latest->balance_transaction ?? null;
                }
                if ($balanceId) $balance = $this->request('get', '/balance_transactions/' . $balanceId);
            } catch (\Illuminate\Http\Client\RequestException | \Illuminate\Http\Client\ConnectionException $e) {
                $error = 'Balance transaction lookup failed';
            }
        }
        DB::transaction(function () use ($charge, $paymentId, $transaction, $snapshot, $balanceId, $chargeId, $balance, $error) {
            User::where('id', $transaction->invoice->user_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('id', $transaction->invoice->id)->lockForUpdate()->firstOrFail();
            $current = InvoiceTransaction::where('transaction_id', $paymentId)->lockForUpdate()->first();
            abort_unless(($current->gateway->extension ?? null) === 'AeStripe'
                && $current->invoice->id === $invoice->id, 409);
            $row = $this->feeRows()->where('payment_intent_id', $paymentId)->lockForUpdate()->first();
            if ($row && $row->state !== 'pending') return;
            if ($row && ($row->charge_id !== ($snapshot ? $snapshot->charge_id : $chargeId)
                || $row->balance_transaction_id !== ($snapshot ? $snapshot->balance_transaction_id : $balanceId))) return;
            if (!$row) {
                $this->feeRows()->insert([
                    'payment_intent_id' => $paymentId, 'invoice_id' => $invoice->id,
                    'charge_id' => $chargeId, 'balance_transaction_id' => $balanceId,
                    'state' => 'pending', 'attempts' => 0, 'created_at' => time(), 'last_error' => null,
                ]);
                $row = $this->feeRows()->where('payment_intent_id', $paymentId)->lockForUpdate()->first();
            }
            if ($row->attempts >= 24 || time() - $row->created_at >= 72 * 3600) {
                $this->stopPaymentFee($row, $invoice);
                return;
            }
            $fee = null;
            try {
                if ($balance) $fee = $this->paymentFeeAmount($balance, $invoice->currency_code);
            } catch (\InvalidArgumentException $e) {
                $error = 'Fee conversion unavailable';
            }
            $values = [
                'charge_id' => $chargeId, 'balance_transaction_id' => $balanceId,
                'attempts' => $row->attempts + 1, 'last_error' => $fee === null ? $error : null,
            ];
            if ($fee !== null) {
                ExtensionHelper::addPaymentFee($paymentId, $fee);
                $values['state'] = 'completed';
            }
            $this->feeRows()->where('payment_intent_id', $paymentId)->update($values);
            if ($fee === null && $values['attempts'] >= 24) {
                $this->stopPaymentFee((object) array_merge((array) $row, $values), $invoice);
            }
        });
        return true;
    }

    private function paymentFeeAmount($balance, string $currency): float
    {
        if (!is_int($balance->fee ?? null) || $balance->fee < 0
            || !is_string($balance->currency ?? null) || !preg_match('/^[a-z]{3}$/i', $balance->currency)) {
            throw new \InvalidArgumentException('Invalid balance transaction fee');
        }
        $fee = $this->fromMinorUnits($balance->fee, $balance->currency);
        if (strtolower($balance->currency) === strtolower($currency)) return $fee;
        $rate = $balance->exchange_rate ?? null;
        if (!is_numeric($rate) || !is_finite((float) $rate) || $rate <= 0) {
            throw new \InvalidArgumentException('Invalid exchange rate');
        }
        return $this->fromMinorUnits($this->toMinorUnits($fee / $rate, $currency), $currency);
    }

    private function stopPaymentFee($row, $invoice): void
    {
        Audit::create([
            'event' => 'ae_stripe_fee_review', 'auditable_type' => $invoice?->getMorphClass() ?? Invoice::class,
            'auditable_id' => $row->invoice_id, 'old_values' => [], 'new_values' => array_merge((array) $row, ['state' => 'review']),
        ]);
        foreach (User::whereNotNull('role_id')->get() as $staff) {
            Notification::create([
                'user_id' => $staff->id, 'title' => 'Stripe payment fee needs review',
                'body' => 'Fee unavailable for invoice ' . $row->invoice_id . ', PaymentIntent ' . $row->payment_intent_id
                    . '. Review the invoice Audit Log and the balance transaction in Stripe.',
            ]);
        }
        $this->feeRows()->where('payment_intent_id', $row->payment_intent_id)->update(['state' => 'review', 'last_error' => $row->last_error]);
    }
}
