<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Gateways\AeStripe\SettlementStore;

if (!class_exists(SettlementStore::class)) require_once __DIR__ . '/../../SettlementStore.php';

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ae_stripe_settlements')) {
            Schema::create('ae_stripe_settlements', function (Blueprint $table) {
                $table->string('payment_intent_id')->primary();
                $table->unsignedBigInteger('invoice_id');
                $table->string('state', 16);
            });
        }
        SettlementStore::backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('ae_stripe_settlements');
    }
};
