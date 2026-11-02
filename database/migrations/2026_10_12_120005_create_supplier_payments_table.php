<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only supplier payment / reversal ledger.
 *
 * Original PAYMENT rows are never updated for business state.
 * UNIQUE(reverses_payment_id) ensures a payment cannot be reversed twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('payment_number', 40)->unique();
            $table->string('entry_type', 20);
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('bank_export_line_id')->nullable();
            $table->unsignedBigInteger('supplier_company_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('amount', 15, 2);
            $table->string('bank_reference', 100)->nullable();
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->unsignedBigInteger('reverses_payment_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->unique('reverses_payment_id', 'sp_reverses_payment_unique');

            $table->foreign('invoice_id', 'sp_invoice_fk')
                ->references('id')
                ->on('supplier_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('bank_export_line_id', 'sp_export_line_fk')
                ->references('id')
                ->on('supplier_settlement_bank_export_lines')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_company_id', 'sp_supplier_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'sp_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('confirmed_by', 'sp_confirmed_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reverses_payment_id', 'sp_reverses_payment_fk')
                ->references('id')
                ->on('supplier_payments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['supplier_company_id', 'confirmed_at'], 'sp_supplier_confirmed_idx');
            $table->index('bank_export_line_id', 'sp_export_line_idx');
            $table->index('entry_type', 'sp_entry_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
