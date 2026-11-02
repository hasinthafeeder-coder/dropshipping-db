<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable positive reseller earning obligations for Plus Report foundations.
 *
 * One earning per eligible delivered order. Distinct from reseller_debt_obligations.
 * Financial basis columns freeze the calculator inputs used at recording time so
 * later Admin setting changes cannot rewrite historical earnings.
 *
 * Uniqueness:
 * - order_id: at most one positive earning obligation per order
 * - source_idempotency_key: stable business identity (earning:order:{id})
 *
 * Future settlement payment recording must atomically reduce debt outstanding and
 * mark how much of this earning was applied; report viewing never mutates either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_earning_obligations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('obligation_number', 40)->unique();
            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('reseller_company_id')->nullable();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('currency_code_snapshot', 3);
            $table->date('business_date');
            $table->decimal('cod_amount_snapshot', 15, 2);
            $table->decimal('service_charge_snapshot', 15, 2);
            $table->decimal('courier_charge_snapshot', 15, 2);
            $table->decimal('company_commission_snapshot', 15, 2);
            $table->decimal('gross_reseller_profit', 15, 2);
            $table->unsignedBigInteger('courier_receipt_import_id')->nullable();
            $table->unsignedBigInteger('courier_receipt_import_row_id')->nullable();
            $table->string('source_type', 40);
            $table->string('source_idempotency_key', 80)->unique();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('reseller_id', 'reo_reseller_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_company_id', 'reo_reseller_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_id', 'reo_order_fk')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('market_id', 'reo_market_fk')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'reo_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_receipt_import_id', 'reo_import_fk')
                ->references('id')
                ->on('courier_receipt_imports')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('courier_receipt_import_row_id', 'reo_import_row_fk')
                ->references('id')
                ->on('courier_receipt_import_rows')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('recorded_by', 'reo_recorded_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index(['reseller_id', 'business_date'], 'reo_reseller_business_idx');
            $table->index(['market_id', 'currency_id'], 'reo_market_currency_idx');
            $table->index('business_date', 'reo_business_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_earning_obligations');
    }
};
