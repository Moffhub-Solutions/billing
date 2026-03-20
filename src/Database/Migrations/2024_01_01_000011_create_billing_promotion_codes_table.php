<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.promotion_codes', 'billing_promotion_codes'), function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique(); // customer-facing code (e.g., SAVE20)
            $table->foreignId('coupon_id')->constrained(config('billing.tables.coupons', 'billing_coupons'));
            $table->boolean('is_active')->default(true);
            $table->boolean('first_time_transaction')->default(false); // only for new customers
            $table->unsignedInteger('minimum_amount')->nullable(); // minimum purchase in cents
            $table->string('minimum_amount_currency', 3)->nullable();
            $table->unsignedInteger('max_redemptions')->nullable(); // per-code limit
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.promotion_codes', 'billing_promotion_codes'));
    }
};
