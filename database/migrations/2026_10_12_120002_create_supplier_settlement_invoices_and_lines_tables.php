<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payable invoices and immutable payable lines.
 *
 * effective_payment_id is stored here but the FK to supplier_payments is added
 * in a later migration after that table exists (circular reference).
 *
 * Returned references are a separate display-only table in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_settlement_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('invoice_number', 40)->unique();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('supplier_company_id');
            $table->string('supplier_company_name_snapshot', 200);
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->decimal('payable_total', 15, 2);
            $table->string('payment_status', 20);
            $table->string('instruction_status', 20);
            $table->unsignedBigInteger('effective_payment_id')->nullable();
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['batch_id', 'supplier_company_id', 'currency_id'],
                'ssi_batch_supplier_currency_unique'
            );
            $table->unique('effective_payment_id', 'ssi_effective_payment_unique');

            $table->foreign('batch_id', 'ssi_batch_fk')
                ->references('id')
                ->on('supplier_settlement_batches')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_company_id', 'ssi_supplier_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssi_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('voided_by', 'ssi_voided_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['supplier_company_id', 'issued_at'], 'ssi_supplier_issued_idx');
            $table->index('payment_status', 'ssi_payment_status_idx');
            $table->index('instruction_status', 'ssi_instruction_status_idx');
            $table->index('currency_id', 'ssi_currency_idx');
        });

        Schema::create('supplier_settlement_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->unsignedBigInteger('invoice_id');
            $table->date('settlement_date');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->string('order_number_snapshot');
            $table->string('order_status_snapshot', 30);
            $table->unsignedBigInteger('supplier_user_id');
            $table->unsignedBigInteger('shipment_id');
            $table->string('tracking_number_snapshot');
            $table->unsignedBigInteger('courier_service_id');
            $table->unsignedBigInteger('courier_receipt_import_id');
            $table->unsignedBigInteger('courier_receipt_import_row_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_payable', 15, 2);
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->string('line_payment_status', 20);
            $table->timestamps();

            $table->unique('order_item_id', 'ssil_order_item_unique');

            $table->foreign('invoice_id', 'ssil_invoice_fk')
                ->references('id')
                ->on('supplier_settlement_invoices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'ssil_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_item_id', 'ssil_order_item_fk')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_user_id', 'ssil_supplier_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('shipment_id', 'ssil_shipment_fk')
                ->references('id')
                ->on('shipments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_service_id', 'ssil_courier_service_fk')
                ->references('id')
                ->on('courier_services')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_receipt_import_id', 'ssil_crr_import_fk')
                ->references('id')
                ->on('courier_receipt_imports')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_receipt_import_row_id', 'ssil_crr_row_fk')
                ->references('id')
                ->on('courier_receipt_import_rows')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_id', 'ssil_product_fk')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id', 'ssil_variant_fk')
                ->references('id')
                ->on('product_variants')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssil_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['invoice_id', 'line_payment_status'], 'ssil_invoice_status_idx');
            $table->index('settlement_date', 'ssil_settlement_date_idx');
            $table->index('courier_receipt_import_id', 'ssil_crr_import_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_settlement_invoice_lines');
        Schema::dropIfExists('supplier_settlement_invoices');
    }
};
