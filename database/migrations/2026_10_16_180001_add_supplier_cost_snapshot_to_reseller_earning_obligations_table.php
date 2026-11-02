<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the historical supplier-cost total used when recording a reseller earning.
 *
 * Formula basis (Phase 1):
 *   eligible order amount − supplier_cost − courier − service − company commission
 *
 * Column is nullable so existing obligation rows are left untouched. Do not backfill
 * historical supplier cost as zero — null means “not captured at recording time”.
 * New obligations must write the calculated supplier cost (including valid 0.00).
 *
 * Precision matches other monetary snapshots on this table (decimal 15,2).
 * No invoice-line column, foreign keys, or silent rewrite of historical gross.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_earning_obligations', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_earning_obligations', 'supplier_cost_snapshot')) {
                $table->decimal('supplier_cost_snapshot', 15, 2)
                    ->nullable()
                    ->after('cod_amount_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reseller_earning_obligations', function (Blueprint $table) {
            if (Schema::hasColumn('reseller_earning_obligations', 'supplier_cost_snapshot')) {
                $table->dropColumn('supplier_cost_snapshot');
            }
        });
    }
};
