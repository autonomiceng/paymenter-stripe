<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('ae_stripe_receipt_returns', ['invoice_id'])) return;
        try {
            Schema::table('ae_stripe_receipt_returns', fn (Blueprint $table) => $table->index('invoice_id'));
        } catch (\Throwable $error) {
            if (!Schema::hasIndex('ae_stripe_receipt_returns', ['invoice_id'])) throw $error;
        }
    }

    public function down(): void
    {
        Schema::table('ae_stripe_receipt_returns', fn (Blueprint $table) => $table->dropIndex(['invoice_id']));
    }
};
