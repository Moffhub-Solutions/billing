<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.coupon_redemptions', 'billing_coupon_redemptions'), function (Blueprint $table): void {
            $table->id();
            $table->morphs('billable');
            $table->foreignId('coupon_id')->constrained(config('billing.tables.coupons', 'billing_coupons'));
            $table->foreignId('promotion_code_id')->nullable()->constrained(config('billing.tables.promotion_codes', 'billing_promotion_codes'));
            $table->foreignId('subscription_id')->nullable()->constrained(config('billing.tables.subscriptions', 'billing_subscriptions'))->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained(config('billing.tables.invoices', 'billing_invoices'))->nullOnDelete();
            $table->unsignedInteger('original_amount'); // before discount
            $table->unsignedInteger('discount_amount'); // discount applied
            $table->unsignedInteger('final_amount'); // after discount
            $table->timestamp('redeemed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'coupon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.coupon_redemptions', 'billing_coupon_redemptions'));
    }
};
