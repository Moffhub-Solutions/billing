<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(billing_table('unmatched_payments', 'billing_unmatched_payments'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();

            // The provider's transaction id (an M-Pesa receipt). Unique, because
            // the provider redelivers a confirmation until it is answered.
            $table->string('provider_payment_id')->unique();
            $table->string('provider', 32)->default('mpesa');
            $table->unsignedBigInteger('amount'); // in cents
            $table->string('currency', 3);

            // What the payer typed as the account number, and who paid.
            $table->string('account_reference')->nullable();
            $table->string('payer_phone')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('status')->default('open')->index();
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            // The Payment created when it is allocated to an invoice.
            $table->foreignId('payment_id')->nullable()
                ->constrained(billing_table('payments', 'billing_payments'))->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(billing_table('unmatched_payments', 'billing_unmatched_payments'));
    }
};
