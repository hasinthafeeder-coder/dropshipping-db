<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Debt allocation history and negative-profit review flags for reseller settlements.
 *
 * Allocations are written only during batch generation (not preview/report viewing)
 * and atomically reduce reseller_debt_obligations.outstanding_amount.
 * OPEN batch rebuild reverses allocations before recreating them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_settlement_debt_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('debt_obligation_id');
            $table->string('debt_obligation_number_snapshot', 40);
            $table->string('obligation_type_snapshot', 30);
            $table->string('return_reason_snapshot', 30)->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_number_snapshot')->nullable();
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('allocated_amount', 15, 2);
            $table->decimal('debt_outstanding_before', 15, 2);
            $table->decimal('debt_outstanding_after', 15, 2);
            $table->unsignedInteger('allocation_sequence');
            $table->timestamps();

            $table->foreign('invoice_id', 'rsda_invoice_fk')
                ->references('id')
                ->on('reseller_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('batch_id', 'rsda_batch_fk')
                ->references('id')
                ->on('reseller_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('debt_obligation_id', 'rsda_debt_fk')
                ->references('id')
                ->on('reseller_debt_obligations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'rsda_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rsda_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['invoice_id', 'allocation_sequence'], 'rsda_invoice_seq_idx');
            $table->index('batch_id', 'rsda_batch_idx');
            $table->index('debt_obligation_id', 'rsda_debt_idx');
        });

        Schema::create('reseller_settlement_review_items', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('earning_obligation_id');
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('order_id');
            $table->string('order_number_snapshot');
            $table->string('obligation_number_snapshot', 40);
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('gross_reseller_profit', 15, 2);
            $table->string('review_reason', 40);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(
                ['batch_id', 'earning_obligation_id'],
                'rsri_batch_earning_unique'
            );

            $table->foreign('batch_id', 'rsri_batch_fk')
                ->references('id')
                ->on('reseller_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('earning_obligation_id', 'rsri_earning_fk')
                ->references('id')
                ->on('reseller_earning_obligations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id', 'rsri_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'rsri_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rsri_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['batch_id', 'status'], 'rsri_batch_status_idx');
            $table->index('review_reason', 'rsri_reason_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_settlement_review_items');
        Schema::dropIfExists('reseller_settlement_debt_allocations');
    }
};
