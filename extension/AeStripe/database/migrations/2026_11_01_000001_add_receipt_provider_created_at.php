<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ae_stripe_receipt_returns', 'provider_created_at')) return;
        try {
            Schema::table('ae_stripe_receipt_returns', fn (Blueprint $table) => $table->unsignedBigInteger('provider_created_at')->nullable());
        } catch (\Throwable $error) {
            if (!Schema::hasColumn('ae_stripe_receipt_returns', 'provider_created_at')) throw $error;
        }
    }

    public function down(): void
    {
        Schema::table('ae_stripe_receipt_returns', fn (Blueprint $table) => $table->dropColumn('provider_created_at'));
    }
};
