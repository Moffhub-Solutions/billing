<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('billing.tables.usage_records', 'billing_usage_records'), function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->morphs('billable');
            $table->string('feature_slug')->index();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('overage_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id', 'feature_slug']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('billing.tables.usage_records', 'billing_usage_records'));
    }
};
