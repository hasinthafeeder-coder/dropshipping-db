<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted admin financial daily summaries and payment cycles.
 *
 * Cards and the daily report read these rows instead of rescanning remittances,
 * earnings, debts, and settlement invoices on every request. Totals are updated
 * at courier confirmation, settlement generation, payment, void, and reversal.
 * This migration does not backfill historical financial records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financial_payment_cycles')) {
            Schema::create('financial_payment_cycles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('market_id');
                $table->char('currency_code', 3);
                $table->string('status', 20);
                // 1 while OPEN, null when CLOSED — MySQL allows many NULLs in a unique key,
                // so at most one open cycle exists per market + currency.
                $table->unsignedTinyInteger('open_slot')->nullable();
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('supplier_batch_id')->nullable();
                $table->unsignedBigInteger('reseller_batch_id')->nullable();
                $table->timestamp('supplier_generated_at')->nullable();
                $table->timestamp('reseller_generated_at')->nullable();
                $table->timestamp('supplier_fully_paid_at')->nullable();
                $table->timestamp('reseller_fully_paid_at')->nullable();
                $table->date('settlement_date')->nullable();
                $table->timestamps();

                $table->unique(
                    ['market_id', 'currency_code', 'open_slot'],
                    'fpc_market_currency_open_slot_unique'
                );
                $table->index(['market_id', 'currency_code', 'status'], 'fpc_market_currency_status_idx');
                $table->index('closed_at', 'fpc_closed_at_idx');

                $table->foreign('market_id', 'fpc_market_fk')
                    ->references('id')->on('markets')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('financial_daily_summaries')) {
            Schema::create('financial_daily_summaries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payment_cycle_id');
                $table->unsignedBigInteger('market_id');
                $table->char('currency_code', 3);
                $table->date('business_date');
                $table->decimal('total_income', 15, 2)->nullable();
                $table->unsignedInteger('total_income_row_count')->default(0);
                $table->unsignedInteger('total_income_omitted_count')->default(0);
                $table->decimal('courier_charges', 15, 2)->nullable();
                $table->unsignedInteger('courier_charges_row_count')->default(0);
                $table->unsignedInteger('courier_charges_omitted_count')->default(0);
                $table->decimal('service_charge', 15, 2)->nullable();
                $table->unsignedInteger('service_charge_row_count')->default(0);
                $table->unsignedInteger('service_charge_omitted_count')->default(0);
                $table->decimal('fine_charges', 15, 2)->nullable();
                $table->unsignedInteger('fine_charges_row_count')->default(0);
                $table->unsignedInteger('fine_charges_omitted_count')->default(0);
                $table->decimal('return_charges', 15, 2)->nullable();
                $table->unsignedInteger('return_charges_row_count')->default(0);
                $table->unsignedInteger('return_charges_omitted_count')->default(0);
                $table->decimal('supplier_settlement_total', 15, 2)->nullable();
                $table->unsignedInteger('supplier_settlement_row_count')->default(0);
                $table->decimal('reseller_settlement_total', 15, 2)->nullable();
                $table->unsignedInteger('reseller_settlement_row_count')->default(0);
                $table->decimal('company_profit', 15, 2)->nullable();
                $table->string('company_profit_status', 30)->default('incomplete');
                $table->boolean('supplier_batch_generated')->default(false);
                $table->boolean('reseller_batch_generated')->default(false);
                $table->boolean('supplier_fully_paid')->default(false);
                $table->boolean('reseller_fully_paid')->default(false);
                $table->timestamps();

                $table->unique(
                    ['payment_cycle_id', 'business_date'],
                    'fds_cycle_date_unique'
                );
                $table->unique(
                    ['market_id', 'currency_code', 'business_date', 'payment_cycle_id'],
                    'fds_market_currency_date_cycle_unique'
                );
                $table->index(['currency_code', 'business_date'], 'fds_currency_date_idx');
                $table->index(['payment_cycle_id', 'currency_code'], 'fds_cycle_currency_idx');

                $table->foreign('payment_cycle_id', 'fds_cycle_fk')
                    ->references('id')->on('financial_payment_cycles')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('market_id', 'fds_market_fk')
                    ->references('id')->on('markets')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }

        if (! Schema::hasTable('financial_summary_contributions')) {
            Schema::create('financial_summary_contributions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('daily_summary_id');
                $table->unsignedBigInteger('payment_cycle_id');
                $table->string('metric', 40);
                $table->string('source_type', 60);
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->decimal('amount', 15, 2)->nullable();
                $table->boolean('amount_present')->default(false);
                $table->json('meta')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['source_type', 'source_id', 'metric'],
                    'fsc_source_metric_unique'
                );
                $table->index(['daily_summary_id', 'metric', 'voided_at'], 'fsc_summary_metric_void_idx');
                $table->index(['payment_cycle_id', 'metric', 'voided_at'], 'fsc_cycle_metric_void_idx');
                $table->index('order_id', 'fsc_order_idx');

                $table->foreign('daily_summary_id', 'fsc_summary_fk')
                    ->references('id')->on('financial_daily_summaries')
                    ->restrictOnDelete()->cascadeOnUpdate();
                $table->foreign('payment_cycle_id', 'fsc_cycle_fk')
                    ->references('id')->on('financial_payment_cycles')
                    ->restrictOnDelete()->cascadeOnUpdate();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_summary_contributions');
        Schema::dropIfExists('financial_daily_summaries');
        Schema::dropIfExists('financial_payment_cycles');
    }
};
