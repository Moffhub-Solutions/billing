<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(billing_table('payment_proofs', 'billing_payment_proofs'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable');

            $table->foreignId('invoice_id')->nullable()
                ->constrained(billing_table('invoices', 'billing_invoices'))->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()
                ->constrained(billing_table('subscriptions', 'billing_subscriptions'))->nullOnDelete();

            // The channel is free text on purpose: a proof can be for a channel
            // the library does not integrate (another bank, wallet, cash deposit).
            $table->string('channel');
            $table->string('reference')->nullable(); // e.g. an M-Pesa code, bank slip no.
            $table->string('payer_name')->nullable();
            $table->string('payer_detail')->nullable(); // phone / account that paid
            $table->unsignedBigInteger('amount'); // declared amount, in cents
            $table->string('currency', 3);
            $table->string('proof_url')->nullable(); // uploaded statement / screenshot

            $table->string('status')->default('pending')->index();
            $table->text('notes')->nullable();

            // Maker / checker actors (ids are app-defined, so kept as strings).
            $table->string('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('review_notes')->nullable();

            // The Payment created when the proof is verified.
            $table->foreignId('payment_id')->nullable()
                ->constrained(billing_table('payments', 'billing_payments'))->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'status']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(billing_table('payment_proofs', 'billing_payment_proofs'));
    }
};
