<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number')->nullable()->unique();
            $table->string('source', 30);
            $table->string('status', 30)->default('PENDING');

            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('currency_id');
            $table->string('market_code_snapshot', 10);
            $table->string('currency_code_snapshot', 3);

            $table->unsignedBigInteger('reseller_id');
            $table->unsignedBigInteger('reseller_company_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('cca_id')->nullable();

            $table->string('customer_name_snapshot');
            $table->string('primary_phone_snapshot', 64);
            $table->string('secondary_phone_snapshot', 64)->nullable();
            $table->unsignedBigInteger('primary_phone_country_id');
            $table->unsignedBigInteger('secondary_phone_country_id')->nullable();

            $table->decimal('items_subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('courier_fee_amount', 15, 2)->default(0);
            $table->decimal('customer_payable_amount', 15, 2)->default(0);
            $table->decimal('total_weight', 12, 3)->default(0);

            $table->decimal('reseller_commission_amount', 15, 2)->default(0);
            $table->decimal('supplier_commission_amount', 15, 2)->default(0);
            $table->decimal('company_commission_amount', 15, 2)->default(0);
            $table->decimal('agent_commission_amount', 15, 2)->default(0);

            $table->boolean('after_hours')->default(false);
            $table->boolean('after_hours_warning_shown')->default(false);
            $table->decimal('after_hours_penalty_amount', 15, 2)->default(0);
            $table->boolean('duplicate_warning_shown')->default(false);
            $table->boolean('duplicate_warning_overridden')->default(false);
            $table->unsignedBigInteger('duplicate_reference_order_id')->nullable();
            $table->decimal('duplicate_order_penalty_amount', 15, 2)->default(0);
            $table->decimal('return_penalty_amount', 15, 2)->default(0);

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reactivated_at')->nullable();
            $table->timestamp('operations_hidden_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('reseller_company_id')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('cca_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('primary_phone_country_id')
                ->references('id')
                ->on('countries')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('secondary_phone_country_id')
                ->references('id')
                ->on('countries')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('duplicate_reference_order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('status');
            $table->index('source');
            $table->index(['customer_id', 'status']);
            $table->index(['reseller_id', 'status']);
            $table->index(['supplier_id', 'status']);
            $table->index(['cca_id', 'status']);
            $table->index('market_id');
            $table->index('cancelled_at');
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
