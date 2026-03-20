<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.features', 'billing_features'), function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('type')->default('boolean');
            $table->boolean('is_addon')->default(false);
            $table->integer('addon_price')->nullable();
            $table->string('addon_billing_cycle')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_addon']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.features', 'billing_features'));
    }
};
