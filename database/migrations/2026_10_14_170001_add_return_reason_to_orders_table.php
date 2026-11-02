<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative return reason captured at supplier return scan.
 *
 * Nullable for legacy RETURNED rows that pre-date reason persistence.
 * Canonical values: DUPLICATE, OUT_OF_OFFICE, OTHER.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'return_reason')) {
                $table->string('return_reason', 30)
                    ->nullable()
                    ->after('return_is_damaged');

                $table->index('return_reason', 'orders_return_reason_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'return_reason')) {
                $table->dropIndex('orders_return_reason_idx');
                $table->dropColumn('return_reason');
            }
        });
    }
};
