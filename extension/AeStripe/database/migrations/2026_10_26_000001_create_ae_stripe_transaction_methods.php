<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ae_stripe_transaction_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_transaction_id')->primary();
            $table->foreign('invoice_transaction_id')->references('id')->on('invoice_transactions')->cascadeOnDelete();
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ae_stripe_transaction_methods');
    }
};
