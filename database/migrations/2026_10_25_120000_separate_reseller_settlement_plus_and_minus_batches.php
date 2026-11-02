<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller settlement batches were one DAILY row per market and date, and
 * invoice generation netted debt into that same batch.
 *
 * report_type lets a market/date keep separate PLUS and MINUS identities.
 * Existing rows stay COMBINED so historical netted invoices remain readable.
 *
 * Deduction lines record Minus Report membership only. They do not reduce
 * reseller_debt_obligations.outstanding_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_settlement_batches', function (Blueprint $table) {
            $table->string('report_type', 20)->default('COMBINED')->after('kind');
        });

        Schema::table('reseller_settlement_batches', function (Blueprint $table) {
            $table->dropUnique('rsb_market_date_kind_seq_unique');
            $table->unique(
                ['market_id', 'settlement_date', 'kind', 'sequence', 'report_type'],
                'rsb_market_date_kind_seq_type_unique'
            );
            $table->index('report_type', 'rsb_report_type_idx');
        });

        Schema::create('reseller_settlement_deduction_lines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('debt_obligation_id')->unique();
            $table->string('debt_obligation_number_snapshot', 40);
            $table->string('obligation_type_snapshot', 30);
            $table->string('return_reason_snapshot', 30)->nullable();
            $table->string('fine_condition_snapshot', 30)->nullable();
            $table->unsignedBigInteger('reseller_id');
            $table->string('reseller_name_snapshot', 200);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_number_snapshot')->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('original_amount_snapshot', 15, 2);
            $table->decimal('outstanding_amount_snapshot', 15, 2);
            $table->date('effective_date_snapshot');
            $table->timestamps();

            $table->foreign('batch_id', 'rsdl_batch_fk')
                ->references('id')
                ->on('reseller_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('debt_obligation_id', 'rsdl_debt_fk')
                ->references('id')
                ->on('reseller_debt_obligations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id', 'rsdl_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'rsdl_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rsdl_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index('batch_id', 'rsdl_batch_idx');
            $table->index('effective_date_snapshot', 'rsdl_effective_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_settlement_deduction_lines');

        Schema::table('reseller_settlement_batches', function (Blueprint $table) {
            $table->dropUnique('rsb_market_date_kind_seq_type_unique');
            $table->dropIndex('rsb_report_type_idx');
            $table->dropColumn('report_type');
            $table->unique(
                ['market_id', 'settlement_date', 'kind', 'sequence'],
                'rsb_market_date_kind_seq_unique'
            );
        });
    }
};
