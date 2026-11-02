<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive daily-summary columns for confirmed settlement payments (net of
 * reversals), outstanding snapshots, and introducer bonus reserve/paid.
 *
 * Does not create a new summary table. Does not backfill historical rows.
 * Company profit formula is unchanged and does not use these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financial_daily_summaries')) {
            return;
        }

        Schema::table('financial_daily_summaries', function (Blueprint $table): void {
            if (! Schema::hasColumn('financial_daily_summaries', 'supplier_payments_confirmed')) {
                $table->decimal('supplier_payments_confirmed', 15, 2)->nullable()->after('reseller_settlement_row_count');
                $table->unsignedInteger('supplier_payments_confirmed_row_count')->default(0)->after('supplier_payments_confirmed');
                $table->unsignedInteger('supplier_payments_confirmed_omitted_count')->default(0)->after('supplier_payments_confirmed_row_count');
            }

            if (! Schema::hasColumn('financial_daily_summaries', 'reseller_payments_confirmed')) {
                $table->decimal('reseller_payments_confirmed', 15, 2)->nullable()->after('supplier_payments_confirmed_omitted_count');
                $table->unsignedInteger('reseller_payments_confirmed_row_count')->default(0)->after('reseller_payments_confirmed');
                $table->unsignedInteger('reseller_payments_confirmed_omitted_count')->default(0)->after('reseller_payments_confirmed_row_count');
            }

            if (! Schema::hasColumn('financial_daily_summaries', 'supplier_outstanding_balance')) {
                $table->decimal('supplier_outstanding_balance', 15, 2)->nullable()->after('reseller_payments_confirmed_omitted_count');
            }

            if (! Schema::hasColumn('financial_daily_summaries', 'reseller_outstanding_balance')) {
                $table->decimal('reseller_outstanding_balance', 15, 2)->nullable()->after('supplier_outstanding_balance');
            }

            if (! Schema::hasColumn('financial_daily_summaries', 'introducer_bonus_reserved')) {
                $table->decimal('introducer_bonus_reserved', 15, 2)->nullable()->after('reseller_outstanding_balance');
                $table->unsignedInteger('introducer_bonus_reserved_row_count')->default(0)->after('introducer_bonus_reserved');
                $table->unsignedInteger('introducer_bonus_reserved_omitted_count')->default(0)->after('introducer_bonus_reserved_row_count');
            }

            if (! Schema::hasColumn('financial_daily_summaries', 'introducer_bonus_paid')) {
                $table->decimal('introducer_bonus_paid', 15, 2)->nullable()->after('introducer_bonus_reserved_omitted_count');
                $table->unsignedInteger('introducer_bonus_paid_row_count')->default(0)->after('introducer_bonus_paid');
                $table->unsignedInteger('introducer_bonus_paid_omitted_count')->default(0)->after('introducer_bonus_paid_row_count');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('financial_daily_summaries')) {
            return;
        }

        Schema::table('financial_daily_summaries', function (Blueprint $table): void {
            $columns = [
                'supplier_payments_confirmed',
                'supplier_payments_confirmed_row_count',
                'supplier_payments_confirmed_omitted_count',
                'reseller_payments_confirmed',
                'reseller_payments_confirmed_row_count',
                'reseller_payments_confirmed_omitted_count',
                'supplier_outstanding_balance',
                'reseller_outstanding_balance',
                'introducer_bonus_reserved',
                'introducer_bonus_reserved_row_count',
                'introducer_bonus_reserved_omitted_count',
                'introducer_bonus_paid',
                'introducer_bonus_paid_row_count',
                'introducer_bonus_paid_omitted_count',
            ];

            $existing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => Schema::hasColumn('financial_daily_summaries', $column),
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
