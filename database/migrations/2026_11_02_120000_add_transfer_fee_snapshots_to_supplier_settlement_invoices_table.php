<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive invoice-level snapshots for the supplier settlement bank-transfer fee.
 *
 * Fee is charged once per payee (invoice) per batch. Persisted so later settings
 * changes never rewrite historical invoice totals.
 *
 * payable_total remains gross (sum of lines). net_payable is the cash-due amount
 * after the fee. Historical rows backfill net_payable = payable_total with fee 0.
 *
 * transfer_fee_status:
 * - applied: fee was configured for the invoice market/currency and snapshotted
 * - transfer_fee_not_configured: market fee missing (e.g. MYR); no silent LKR/FX
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_settlement_invoices', function (Blueprint $table) {
            $table->decimal('transfer_fee_amount', 15, 2)
                ->default(0)
                ->after('payable_total');
            $table->string('transfer_fee_currency_code_snapshot', 3)
                ->nullable()
                ->after('transfer_fee_amount');
            $table->string('transfer_fee_status', 40)
                ->default('transfer_fee_not_configured')
                ->after('transfer_fee_currency_code_snapshot');
            $table->decimal('net_payable', 15, 2)
                ->default(0)
                ->after('transfer_fee_status');
        });

        // Preserve historical cash amounts: unpaid/paid records keep prior totals.
        DB::table('supplier_settlement_invoices')->update([
            'transfer_fee_amount' => 0,
            'transfer_fee_currency_code_snapshot' => null,
            'transfer_fee_status' => 'transfer_fee_not_configured',
            'net_payable' => DB::raw('payable_total'),
        ]);
    }

    public function down(): void
    {
        Schema::table('supplier_settlement_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'transfer_fee_amount',
                'transfer_fee_currency_code_snapshot',
                'transfer_fee_status',
                'net_payable',
            ]);
        });
    }
};
