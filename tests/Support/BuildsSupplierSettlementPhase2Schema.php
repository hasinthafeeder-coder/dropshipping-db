<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isolated runtime schema for supplier settlement Phase 2 service tests.
 *
 * Never targets the shared dropshipping database.
 */
trait BuildsSupplierSettlementPhase2Schema
{
    use BuildsSupplierSettlementSchema;

    protected function configureIsolatedSettlementPhase2Database(): void
    {
        // Reuse the Phase 1 isolated database helper (never targets dropshipping).
        $this->configureIsolatedSettlementTestDatabase();
    }

    protected function buildSupplierSettlementPhase2Schema(): void
    {
        $this->dropSettlementAndStubTables();
        $this->createPhase2ParentStubTables();
        $this->runSettlementMigrations();
    }

    protected function createPhase2ParentStubTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->unsignedTinyInteger('market_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key');
            $table->unsignedBigInteger('market_id')->nullable();
            $table->string('group')->default('system');
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['key', 'market_id']);
        });

        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('account_number', 50);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->string('order_number')->nullable();
            $table->string('status', 30)->nullable();
            $table->string('currency_code_snapshot', 3)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->string('variant_name_snapshot')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            // Nullable string affinity so isolated tests can assert null/malformed snapshots.
            $table->string('unit_cost_snapshot', 40)->nullable();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('courier_service_id')->nullable();
            $table->string('tracking_number')->nullable();
        });

        Schema::create('courier_services', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('courier_receipt_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('courier_service_id')->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamp('confirmed_at')->nullable();
        });

        Schema::create('courier_receipt_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('courier_receipt_import_id')->nullable();
            $table->string('normalized_tracking_number')->nullable();
            $table->string('match_result', 40)->nullable();
            $table->unsignedBigInteger('matched_order_id')->nullable();
            $table->unsignedBigInteger('matched_shipment_id')->nullable();
            $table->unsignedBigInteger('matched_supplier_id')->nullable();
        });
    }
}
