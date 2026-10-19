<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(billing_table('standing_order_executions', 'billing_standing_order_executions'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('standing_order_id')
                ->constrained(billing_table('standing_orders', 'billing_standing_orders'), 'id', 'bsoe_order_fk')
                ->cascadeOnDelete();
            $table->string('provider_transaction_id')->nullable()->unique('bsoe_txn_unique');
            $table->unsignedBigInteger('amount'); // cents
            $table->string('status', 20); // completed | failed
            $table->string('result_code', 32)->nullable();
            $table->string('result_description', 512)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('payment_id')->nullable()
                ->constrained(billing_table('payments', 'billing_payments'), 'id', 'bsoe_payment_fk')
                ->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['standing_order_id', 'status'], 'bsoe_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(billing_table('standing_order_executions', 'billing_standing_order_executions'));
    }
};
