<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(billing_table('invoice_items', 'billing_invoice_items'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained(billing_table('invoices', 'billing_invoices'))->cascadeOnDelete();
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
        Schema::dropIfExists(billing_table('invoice_items', 'billing_invoice_items'));
    }
};
