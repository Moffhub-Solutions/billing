<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.payment_tokens', 'billing_payment_tokens'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable');
            $table->string('provider'); // mpesa, paystack, flutterwave, pesapal
            $table->string('token_type'); // card, mpesa, bank_account, mobile_money
            $table->string('token'); // provider-specific token (authorization_code, pm_xxx, etc.)
            $table->string('last_four', 4)->nullable(); // last 4 digits of card/phone
            $table->string('card_brand')->nullable(); // visa, mastercard, etc.
            $table->string('card_exp_month', 2)->nullable();
            $table->string('card_exp_year', 4)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('phone')->nullable(); // for M-Pesa/mobile money
            $table->string('email')->nullable(); // required by Paystack for recurring
            $table->boolean('is_default')->default(false);
            $table->boolean('is_reusable')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'provider']);
            $table->index(['billable_type', 'billable_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.payment_tokens', 'billing_payment_tokens'));
    }
};
