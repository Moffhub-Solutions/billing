<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = billing_table('payments', 'billing_payments');

        Schema::table($table, function (Blueprint $table): void {
            // Replace the plain index with a unique one: webhook settlement and
            // refunds locate a payment by provider_payment_id via ->first(), so
            // two rows sharing an id could settle/refund the wrong tranche.
            // (Nullable, so the many pending rows without an id are unaffected.)
            $table->dropIndex(['provider_payment_id']);
            $table->unique('provider_payment_id');
        });
    }

    public function down(): void
    {
        $table = billing_table('payments', 'billing_payments');

        Schema::table($table, function (Blueprint $table): void {
            $table->dropUnique(['provider_payment_id']);
            $table->index('provider_payment_id');
        });
    }
};
