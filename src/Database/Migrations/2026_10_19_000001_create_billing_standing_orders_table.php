<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(billing_table('standing_orders', 'billing_standing_orders'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable', 'bso_billable_idx');
            $table->foreignId('subscription_id')->nullable()
                ->constrained(billing_table('subscriptions', 'billing_subscriptions'), 'id', 'bso_subscription_fk')
                ->nullOnDelete();

            $table->string('provider');
            $table->string('provider_reference')->nullable(); // provider standing order id / response ref
            $table->string('account_reference', 32)->nullable(); // reference shown on the customer's statement
            $table->string('name');

            $table->unsignedBigInteger('amount'); // cents
            $table->string('currency', 3);
            $table->string('frequency', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('pending');

            // MSISDN is stored encrypted (EncryptedString cast); the hash allows
            // lookups without decrypting and last4 is for display.
            $table->text('customer_phone')->nullable();
            $table->string('customer_phone_hash', 64)->nullable();
            $table->string('customer_phone_last4', 4)->nullable();

            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 64)->nullable();
            $table->string('consent_user_agent', 512)->nullable();

            $table->timestamp('last_executed_at')->nullable();
            $table->timestamp('next_expected_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('provider_reference', 'bso_provider_ref_idx');
            $table->index('account_reference', 'bso_account_ref_idx');
            $table->index(['status', 'next_expected_at'], 'bso_status_next_idx');
            $table->index('customer_phone_hash', 'bso_phone_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(billing_table('standing_orders', 'billing_standing_orders'));
    }
};
