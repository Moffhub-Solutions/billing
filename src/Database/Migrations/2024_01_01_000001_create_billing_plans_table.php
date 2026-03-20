<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.plans', 'billing_plans'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('base_price')->default(0);
            $table->string('currency', 3)->default('KES');
            $table->string('billing_cycle')->default('monthly');
            $table->integer('trial_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('features')->nullable();
            $table->json('limits')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.plans', 'billing_plans'));
    }
};
