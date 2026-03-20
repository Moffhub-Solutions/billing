<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.payments', 'billing_payments'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable');
            $table->foreignId('subscription_id')->nullable()->constrained(config('billing.tables.subscriptions', 'billing_subscriptions'))->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained(config('billing.tables.invoices', 'billing_invoices'))->nullOnDelete();
            $table->integer('amount');
            $table->string('currency', 3)->default('KES');
            $table->string('status')->default('pending')->index();
            $table->string('payment_provider')->nullable();
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('provider_reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.payments', 'billing_payments'));
    }
};
