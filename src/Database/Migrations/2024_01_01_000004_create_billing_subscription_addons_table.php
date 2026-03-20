<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.subscription_addons', 'billing_subscription_addons'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained(config('billing.tables.subscriptions', 'billing_subscriptions'))->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained(config('billing.tables.features', 'billing_features'));
            $table->string('status')->default('active');
            $table->integer('price_override')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('provider_addon_id')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
            $table->unique(['subscription_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.subscription_addons', 'billing_subscription_addons'));
    }
};
