<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::create('ae_stripe_receipt_returns', function (Blueprint $table) {
                $table->string('payment_intent_id');
                $table->string('allocation_key', 180);
                $table->unique(['payment_intent_id', 'allocation_key'], 'ae_stripe_receipt_allocation');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('invoice_id');
                $table->string('customer')->nullable();
                $table->string('currency', 3);
                $table->unsignedBigInteger('received_minor');
                $table->boolean('closed_invoice')->default(false);
                $table->string('subscription_invoice_id')->nullable();
                $table->unsignedBigInteger('amount_minor');
                $table->string('return_key', 74)->unique();
                $table->string('state', 16);
                $table->boolean('retry')->default(true);
                $table->boolean('recheck')->default(false);
                $table->unsignedBigInteger('updated_at');
                $table->index(['state', 'retry', 'updated_at'], 'ae_stripe_return_due');
                $table->string('refund_id')->nullable()->index();
                $table->unsignedBigInteger('credit_id')->nullable();
                $table->unsignedBigInteger('sent_at')->nullable();
                $table->text('reason')->nullable();
                $table->text('notified')->nullable();
            });
        } catch (\Throwable $error) {
            if (!Schema::hasTable('ae_stripe_receipt_returns')) throw $error;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ae_stripe_receipt_returns');
    }
};
