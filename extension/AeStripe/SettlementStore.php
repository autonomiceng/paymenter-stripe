<?php

namespace Paymenter\Extensions\Gateways\AeStripe;

use Illuminate\Support\Facades\{DB, Schema};

final class SettlementStore
{
    public static function claim(string $paymentId, int $invoiceId): bool
    {
        return DB::table('ae_stripe_settlements')->insertOrIgnore([
            'payment_intent_id' => $paymentId, 'invoice_id' => $invoiceId, 'state' => 'succeeded',
        ]) === 1;
    }

    public static function backfill(): int
    {
        if (!Schema::hasTable('invoice_transactions')) return 0;
        $gateways = DB::table('extensions')->where('extension', 'AeStripe')->pluck('id')->all();
        $count = 0;
        DB::table('invoice_transactions')->whereIn('gateway_id', $gateways)->where('status', 'succeeded')
            ->select(['id', 'invoice_id', 'transaction_id'])->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    if (is_string($row->transaction_id) && preg_match('/^pi_[a-zA-Z0-9]+$/D', $row->transaction_id)) {
                        $count += (int) self::claim($row->transaction_id, (int) $row->invoice_id);
                    }
                }
            });
        return $count;
    }
}
