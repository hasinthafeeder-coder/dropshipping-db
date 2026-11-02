<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive invoice-level snapshots for the reseller settlement bank-transfer fee.
 *
 * Fee is charged once per payee (invoice) per batch. Persisted so later settings
 * changes never rewrite historical invoice totals.
 *
 * transfer_fee_status:
 * - applied: fee was configured for the invoice market/currency and snapshotted
 * - transfer_fee_not_configured: market fee missing (e.g. MYR); no silent LKR/FX
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_settlement_invoices', function (Blueprint $table) {
            $table->decimal('transfer_fee_amount', 15, 2)
                ->default(0)
                ->after('adjustment_total');
            $table->string('transfer_fee_currency_code_snapshot', 3)
                ->nullable()
                ->after('transfer_fee_amount');
            $table->string('transfer_fee_status', 40)
                ->default('transfer_fee_not_configured')
                ->after('transfer_fee_currency_code_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_settlement_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_fee_amount',
                'transfer_fee_currency_code_snapshot',
                'transfer_fee_status',
            ]);
        });
    }
};
