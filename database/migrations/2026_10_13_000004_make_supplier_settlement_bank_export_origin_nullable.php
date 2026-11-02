<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origin/platform debit columns are optional: Feeder does not hold or transfer funds.
 * Fresh installs already create these as nullable; this alters previously applied schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_settlement_bank_export_lines')) {
            return;
        }

        Schema::table('supplier_settlement_bank_export_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('origin_company_bank_account_id')->nullable()->change();
            $table->string('origin_account_number', 15)->nullable()->change();
            $table->string('origin_account_name', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Irreversible without inventing placeholder origin accounts for blank rows.
    }
};
