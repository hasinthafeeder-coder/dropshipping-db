<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive Order Pool / CCA assignment-state support.
 *
 * orders.available_in_pool defaults to false so existing rows remain Unassigned
 * when cca_id is null (not automatically classified as pool).
 *
 * order_cca_assignments.origin defaults to direct for existing history rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('available_in_pool')
                ->default(false)
                ->after('cca_id');

            $table->index(
                ['reseller_company_id', 'available_in_pool', 'cca_id'],
                'orders_company_pool_cca_index'
            );
        });

        Schema::table('order_cca_assignments', function (Blueprint $table) {
            $table->string('origin', 30)
                ->default('direct')
                ->after('assigned_by');

            $table->index(
                ['cca_id', 'origin', 'unassigned_at'],
                'order_cca_assignments_cca_origin_open_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_cca_assignments', function (Blueprint $table) {
            $table->dropIndex('order_cca_assignments_cca_origin_open_index');
            $table->dropColumn('origin');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_company_pool_cca_index');
            $table->dropColumn('available_in_pool');
        });
    }
};
