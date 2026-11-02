<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseller settlement invoices and earning lines.
 *
 * Grouping: one invoice per (batch, reseller, currency).
 * Lines uniquely claim a reseller_earning_obligation globally so the same
 * earning cannot appear on multiple invoices.
 *
 * Financial breakdown:
 * - gross_earnings_total: sum of positive earning lines
 * - debt_deduction_total: allocated return-fine / debt reductions
 * - adjustment_total: reserved; always 0.00 until an approved adjustment type exists
 * - net_payable / amount_paid / outstanding_balance
 *
 * effective_payment_id FK is added after reseller_payments exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_settlement_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('invoice_number', 40)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('reseller_company_id')->nullable();
            $table->string('reseller_name_snapshot', 200);
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('gross_earnings_total', 15, 2);
            $table->decimal('debt_deduction_total', 15, 2);
            $table->decimal('adjustment_total', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('outstanding_balance', 15, 2);
            $table->string('payment_status', 20);
            $table->string('instruction_status', 20);
            $table->unsignedBigInteger('effective_payment_id')->nullable();
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['batch_id', 'reseller_id', 'currency_id'],
                'rsi_batch_reseller_currency_unique'
            );

            $table->foreign('batch_id', 'rsi_batch_fk')
                ->references('id')
                ->on('reseller_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id', 'rsi_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_company_id', 'rsi_reseller_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rsi_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'rsi_voided_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['reseller_id', 'issued_at'], 'rsi_reseller_issued_idx');
            $table->index('payment_status', 'rsi_payment_status_idx');
            $table->index('instruction_status', 'rsi_instruction_status_idx');
            $table->index('currency_id', 'rsi_currency_idx');
        });

        Schema::create('reseller_settlement_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('earning_obligation_id');
            $table->date('settlement_date');
            $table->unsignedBigInteger('order_id');
            $table->string('order_number_snapshot');
            $table->string('obligation_number_snapshot', 40);
            $table->date('business_date_snapshot');
            $table->decimal('cod_amount_snapshot', 15, 2);
            $table->decimal('service_charge_snapshot', 15, 2);
            $table->decimal('courier_charge_snapshot', 15, 2);
            $table->decimal('company_commission_snapshot', 15, 2);
            $table->decimal('gross_reseller_profit', 15, 2);
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->string('line_payment_status', 20);
            $table->timestamps();

            $table->unique('earning_obligation_id', 'rsil_earning_obligation_unique');

            $table->foreign('invoice_id', 'rsil_invoice_fk')
                ->references('id')
                ->on('reseller_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('earning_obligation_id', 'rsil_earning_fk')
                ->references('id')
                ->on('reseller_earning_obligations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'rsil_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rsil_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['invoice_id', 'line_payment_status'], 'rsil_invoice_status_idx');
            $table->index('settlement_date', 'rsil_settlement_date_idx');
            $table->index('order_id', 'rsil_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_settlement_invoice_lines');
        Schema::dropIfExists('reseller_settlement_invoices');
    }
};
