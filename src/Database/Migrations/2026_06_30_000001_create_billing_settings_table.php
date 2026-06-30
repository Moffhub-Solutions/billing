<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(billing_table('settings', 'billing_settings'), function (Blueprint $table): void {
            $table->id();

            // Dotted billing config key, e.g. "currency" or "subscriptions.grace_period_days".
            $table->string('key');

            // Scope. Global rows use empty strings (NOT null) so the unique index
            // below actually enforces one row per (key, scope): in SQL, NULL != NULL,
            // so nullable scope columns would let duplicate global rows slip through.
            // billable_id is a string to stay agnostic to int vs ULID primary keys.
            $table->string('billable_type')->default('');
            $table->string('billable_id')->default('');

            // The override value, JSON-encoded so scalars, booleans, and arrays all
            // round-trip with their type intact.
            $table->json('value')->nullable();

            $table->timestamps();

            $table->unique(['key', 'billable_type', 'billable_id'], 'billing_settings_scope_unique');
            $table->index(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(billing_table('settings', 'billing_settings'));
    }
};
