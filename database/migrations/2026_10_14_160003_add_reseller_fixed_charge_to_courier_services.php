<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed fixed charge per courier service for reseller settlements.
 *
 * Shared by the currently supported Sri Lanka market. Nullable until Admin
 * configures an amount — do not seed a guessed default.
 *
 * Independent from CourierMarketPricing weight-based customer shipping fees
 * and from courier remittance Excel delivery charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_services', function (Blueprint $table) {
            if (! Schema::hasColumn('courier_services', 'reseller_fixed_charge')) {
                $table->decimal('reseller_fixed_charge', 15, 2)
                    ->nullable()
                    ->after('external_service_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courier_services', function (Blueprint $table) {
            if (Schema::hasColumn('courier_services', 'reseller_fixed_charge')) {
                $table->dropColumn('reseller_fixed_charge');
            }
        });
    }
};
