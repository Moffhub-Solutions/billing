<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.invoices', 'billing_invoices'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable');
            $table->foreignId('subscription_id')->nullable()->constrained(config('billing.tables.subscriptions', 'billing_subscriptions'))->nullOnDelete();
            $table->string('number')->unique();
            $table->string('status')->default('draft')->index();
            $table->integer('subtotal')->default(0);
            $table->integer('tax_amount')->default(0);
            $table->integer('total')->default(0);
            $table->string('currency', 3)->default('KES');
            $table->float('tax_rate')->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.invoices', 'billing_invoices'));
    }
};
