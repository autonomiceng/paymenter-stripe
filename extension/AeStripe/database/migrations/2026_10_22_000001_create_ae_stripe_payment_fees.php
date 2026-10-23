<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::create('ae_stripe_payment_fees', function (Blueprint $table) {
                $table->string('payment_intent_id')->primary();
                $table->unsignedBigInteger('invoice_id');
                $table->string('charge_id')->nullable();
                $table->string('balance_transaction_id')->nullable();
                $table->string('state')->default('pending')->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->unsignedBigInteger('created_at');
                $table->string('last_error')->nullable();
            });
        } catch (\Throwable $error) {
            if (!Schema::hasTable('ae_stripe_payment_fees')) throw $error;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ae_stripe_payment_fees');
    }
};
