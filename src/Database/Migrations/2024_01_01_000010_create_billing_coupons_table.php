<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.coupons', 'billing_coupons'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->string('name');
            $table->string('discount_type'); // percent | fixed
            $table->unsignedInteger('discount_value'); // percent (0-100) or cents
            $table->string('currency', 3)->default('KES');
            $table->string('duration'); // once | repeating | forever
            $table->unsignedInteger('duration_in_months')->nullable(); // for repeating
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->timestamp('redeem_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('applies_to_plans')->nullable(); // null = all plans
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'redeem_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.coupons', 'billing_coupons'));
    }
};
