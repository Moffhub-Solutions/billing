<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(billing_table('payments', 'billing_payments'), function (Blueprint $table): void {
            // Tranches of a split payment share one group ulid. Single
            // (unsplit) payments leave these null.
            $table->ulid('payment_group')->nullable()->after('provider_reference')->index();
            $table->unsignedSmallInteger('group_sequence')->nullable()->after('payment_group'); // 1-based position
            $table->unsignedSmallInteger('group_size')->nullable()->after('group_sequence');     // total tranches
        });
    }

    public function down(): void
    {
        Schema::table(billing_table('payments', 'billing_payments'), function (Blueprint $table): void {
            $table->dropIndex([billing_table('payments', 'billing_payments').'_payment_group_index']);
            $table->dropColumn(['payment_group', 'group_sequence', 'group_size']);
        });
    }
};
