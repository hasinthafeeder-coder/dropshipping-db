<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display-only returned order references for a settlement batch.
 *
 * Never creates invoices, bank export rows, payments, or liabilities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_settlement_returned_references', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('supplier_company_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->string('order_number_snapshot');
            $table->string('order_status_snapshot', 30);
            $table->unsignedBigInteger('supplier_user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('display_payable', 15, 2)->default(0);
            $table->date('returned_at_business_date');
            $table->string('source', 30);
            $table->unsignedBigInteger('prior_payable_invoice_id')->nullable();
            $table->timestamps();

            $table->unique('order_item_id', 'ssrr_order_item_unique');

            $table->foreign('batch_id', 'ssrr_batch_fk')
                ->references('id')
                ->on('supplier_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_company_id', 'ssrr_supplier_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssrr_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'ssrr_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_item_id', 'ssrr_order_item_fk')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_user_id', 'ssrr_supplier_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_id', 'ssrr_product_fk')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id', 'ssrr_variant_fk')
                ->references('id')
                ->on('product_variants')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('prior_payable_invoice_id', 'ssrr_prior_invoice_fk')
                ->references('id')
                ->on('supplier_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['batch_id', 'supplier_company_id'], 'ssrr_batch_supplier_idx');
            $table->index('returned_at_business_date', 'ssrr_return_date_idx');
            $table->index('source', 'ssrr_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_settlement_returned_references');
    }
};
