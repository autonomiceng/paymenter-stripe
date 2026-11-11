<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ae_stripe_locks')) return;
        Schema::create('ae_stripe_locks', function (Blueprint $table) {
            $table->string('scope', 80)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ae_stripe_locks');
    }
};
