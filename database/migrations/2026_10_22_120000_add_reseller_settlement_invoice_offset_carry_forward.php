<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previous-invoice carry-forward offsets.
 *
 * An offset is an internal settlement of an older invoice's outstanding balance
 * from a later invoice's remaining eligible amount (after debt and transfer fee).
 * It is not a ResellerPayment and must not be presented as a bank transfer.
 *
 * Identity for every invoice:
 *   outstanding_balance = net_payable - amount_paid - offset_applied_total
 *
 * previous_invoice_offset_total is the amount this invoice applied to older
 * invoices. It is already removed from net_payable and is not part of
 * debt_deduction_total, so the same amount is not deducted twice.
 *
 * offset_applied_total is the amount later settlements applied to this invoice.
 * amount_paid remains cash only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_settlement_invoices', function (Blueprint $table) {
            $table->decimal('previous_invoice_offset_total', 15, 2)
                ->default(0)
                ->after('transfer_fee_status');
            $table->decimal('offset_applied_total', 15, 2)
                ->default(0)
                ->after('amount_paid');
        });

        Schema::create('reseller_settlement_invoice_offsets', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('source_invoice_id');
            $table->unsignedBigInteger('source_batch_id');
            $table->unsignedBigInteger('target_invoice_id');
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('allocated_amount', 15, 2);
            $table->decimal('target_outstanding_before', 15, 2);
            $table->decimal('target_outstanding_after', 15, 2);
            $table->decimal('target_offset_applied_before', 15, 2);
            $table->decimal('target_offset_applied_after', 15, 2);
            $table->unsignedInteger('allocation_sequence');
            $table->timestamps();

            $table->unique(
                ['source_invoice_id', 'target_invoice_id'],
                'rsio_source_target_unique'
            );
            $table->unique(
                ['source_batch_id', 'target_invoice_id'],
                'rsio_batch_target_unique'
            );

            $table->foreign('source_invoice_id', 'rsio_source_invoice_fk')
                ->references('id')
                ->on('reseller_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('source_batch_id', 'rsio_source_batch_fk')
                ->references('id')
                ->on('reseller_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('target_invoice_id', 'rsio_target_invoice_fk')
                ->references('id')
                ->on('reseller_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id', 'rsio_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('market_id', 'rsio_market_fk')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'rsio_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['reseller_id', 'market_id', 'currency_id'], 'rsio_reseller_market_currency_idx');
            $table->index('target_invoice_id', 'rsio_target_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_settlement_invoice_offsets');

        Schema::table('reseller_settlement_invoices', function (Blueprint $table) {
            $table->dropColumn(['previous_invoice_offset_total', 'offset_applied_total']);
        });
    }
};
