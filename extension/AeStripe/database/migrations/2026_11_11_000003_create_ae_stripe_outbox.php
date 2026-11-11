<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ae_stripe_outbox')) return;
        Schema::create('ae_stripe_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 120);
            $table->string('source', 255);
            $table->unsignedBigInteger('recipient_id');
            $table->json('payload');
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('next_at');
            $table->unsignedBigInteger('sent_at')->nullable();
            $table->string('last_error')->nullable();
            $table->unique(['kind', 'source', 'recipient_id']);
            $table->index(['state', 'next_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('ae_stripe_outbox'); }
};
