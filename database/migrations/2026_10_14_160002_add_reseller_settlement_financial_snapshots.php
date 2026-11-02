<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-time snapshots for reseller settlement financial inputs.
 *
 * - reseller_service_charge_snapshot: Admin financial setting (or reseller override)
 *   resolved at order create. Null on legacy orders (do not invent a value).
 * - reseller_courier_charge_snapshot: configured fixed charge for the courier
 *   service assigned to the order. Separate from weight-based courier_fee_amount
 *   and from courier remittance Excel delivery charges. Null on legacy orders.
 *
 * Both columns are nullable so existing historical rows are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'reseller_service_charge_snapshot')) {
                $table->decimal('reseller_service_charge_snapshot', 15, 2)
                    ->nullable()
                    ->after('courier_fee_amount');
            }

            if (! Schema::hasColumn('orders', 'reseller_courier_charge_snapshot')) {
                $table->decimal('reseller_courier_charge_snapshot', 15, 2)
                    ->nullable()
                    ->after('reseller_service_charge_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'reseller_courier_charge_snapshot')) {
                $table->dropColumn('reseller_courier_charge_snapshot');
            }

            if (Schema::hasColumn('orders', 'reseller_service_charge_snapshot')) {
                $table->dropColumn('reseller_service_charge_snapshot');
            }
        });
    }
};
