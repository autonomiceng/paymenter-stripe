<?php

namespace Paymenter\Extensions\Gateways\StripeSaved\Concerns;

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
        app(\Illuminate\Database\Migrations\Migrator::class)->run(base_path('extensions/Gateways/StripeSaved/database/migrations'));
    }

    private function ensurePaymentFeeSchema(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('stripe_saved_payment_fees')) $this->upgraded();
    }

    private function feeRows()
    {
        return DB::table('stripe_saved_payment_fees');
    }

    public function retryPaymentFees(): void
    {
        if (!$this->gatewayEnabled()) return;
        foreach ($this->feeRows()->where('state', 'pending')->orderBy('created_at')->limit(100)->get() as $row) {
            $transaction = InvoiceTransaction::where('transaction_id', $row->payment_intent_id)->first();
            if (!$transaction || !$transaction->invoice || ($transaction->gateway->extension ?? null) !== 'StripeSaved') {
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
                'balance_transaction' => $row->balance_transaction_id,
            ]);
        }
    }

    private function recordChargeFee($charge): void
    {
        $paymentId = $charge->payment_intent ?? null;
        if (!$paymentId) return;
        $transaction = InvoiceTransaction::where('transaction_id', $paymentId)->first();
        // A charge can arrive before settlement. Keep that delivery retryable.
        abort_unless($transaction, 409);
        if (($transaction->gateway->extension ?? null) !== 'StripeSaved') return;
        DB::transaction(function () use ($charge, $paymentId, $transaction) {
            User::where('id', $transaction->invoice->user_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::where('id', $transaction->invoice->id)->lockForUpdate()->firstOrFail();
            $current = InvoiceTransaction::where('transaction_id', $paymentId)->lockForUpdate()->first();
            abort_unless(($current->gateway->extension ?? null) === 'StripeSaved', 409);
            $row = $this->feeRows()->where('payment_intent_id', $paymentId)->lockForUpdate()->first();
            if ($row && $row->state !== 'pending') return;
            if (!$row) {
                $this->feeRows()->insert([
                    'payment_intent_id' => $paymentId, 'invoice_id' => $invoice->id,
                    'charge_id' => $charge->id ?? null,
                    'balance_transaction_id' => $charge->balance_transaction ?? null,
                    'state' => 'pending', 'attempts' => 0, 'created_at' => time(), 'last_error' => null,
                ]);
                $row = $this->feeRows()->where('payment_intent_id', $paymentId)->lockForUpdate()->first();
            }
            if ($row->attempts >= 24 || time() - $row->created_at >= 72 * 3600) {
                $this->stopPaymentFee($row, $invoice);
                return;
            }
            $balanceId = $charge->balance_transaction ?? $row->balance_transaction_id;
            $chargeId = $charge->id ?? $row->charge_id;
            $error = 'Balance transaction unavailable';
            $fee = null;
            try {
                if (!$balanceId && $chargeId) {
                    $latest = $this->request('get', '/charges/' . $chargeId);
                    abort_unless(($latest->payment_intent ?? null) === $paymentId, 409);
                    $balanceId = $latest->balance_transaction ?? null;
                }
                if ($balanceId) {
                    $balance = $this->request('get', '/balance_transactions/' . $balanceId);
                    if (($balance->status ?? 'available') !== 'available') {
                        $error = 'Balance transaction pending';
                    } else {
                        $fee = $this->paymentFeeAmount($balance, $invoice->currency_code);
                    }
                }
            } catch (\Illuminate\Http\Client\RequestException | \Illuminate\Http\Client\ConnectionException $e) {
                $error = 'Balance transaction lookup failed';
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
            'event' => 'stripe_saved_fee_review', 'auditable_type' => $invoice?->getMorphClass() ?? Invoice::class,
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
