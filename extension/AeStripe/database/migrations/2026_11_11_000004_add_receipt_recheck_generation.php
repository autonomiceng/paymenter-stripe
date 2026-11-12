<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ae_stripe_receipt_returns', 'recheck_generation')) return;
        try {
            Schema::table('ae_stripe_receipt_returns', fn (Blueprint $table) => $table->unsignedBigInteger('recheck_generation')->default(0));
        } catch (\Throwable $error) {
            if (!Schema::hasColumn('ae_stripe_receipt_returns', 'recheck_generation')) throw $error;
        }
    }

    public function down(): void
    {
        Schema::table('ae_stripe_receipt_returns', fn (Blueprint $table) => $table->dropColumn('recheck_generation'));
    }
};
