<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reconciliation_drifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('billing_payments')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_payment_id');
            $table->string('billing_status', 32);
            $table->string('provider_status', 32)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('detected_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 100)->nullable();
            $table->timestamps();

            $table->index(['provider', 'detected_at']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reconciliation_drifts');
    }
};
