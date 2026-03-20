<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.usage_events', 'billing_usage_events'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable');
            $table->string('feature_slug')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('transaction_id')->nullable()->unique();
            $table->json('properties')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'feature_slug', 'recorded_at'], 'usage_events_billable_feature_recorded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.usage_events', 'billing_usage_events'));
    }
};
