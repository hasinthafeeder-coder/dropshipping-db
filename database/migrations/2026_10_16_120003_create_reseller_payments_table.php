<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only reseller payment / reversal ledger.
 *
 * Supports partial payments against LOCKED invoices. Overpayment is rejected.
 * Original PAYMENT rows are never updated for business state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_payments', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('payment_number', 40)->unique();
            $table->string('entry_type', 20);
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('reseller_company_id')->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('amount', 15, 2);
            $table->string('payment_reference', 100)->nullable();
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->unsignedBigInteger('reverses_payment_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->unique('reverses_payment_id', 'rp_reverses_payment_unique');

            $table->foreign('invoice_id', 'rp_invoice_fk')
                ->references('id')
                ->on('reseller_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id', 'rp_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_company_id', 'rp_reseller_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rp_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('confirmed_by', 'rp_confirmed_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reverses_payment_id', 'rp_reverses_payment_fk')
                ->references('id')
                ->on('reseller_payments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['reseller_id', 'confirmed_at'], 'rp_reseller_confirmed_idx');
            $table->index('entry_type', 'rp_entry_type_idx');
            $table->index('invoice_id', 'rp_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_payments');
    }
};
