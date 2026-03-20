<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.invoice_items', 'billing_invoice_items'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained(config('billing.tables.invoices', 'billing_invoices'))->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price')->default(0);
            $table->integer('total')->default(0);
            $table->string('feature_slug')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.invoice_items', 'billing_invoice_items'));
    }
};
