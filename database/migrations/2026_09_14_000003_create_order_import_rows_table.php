<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('raw_name')->nullable();
            $table->text('raw_address')->nullable();
            $table->string('raw_tp_1', 64)->nullable();
            $table->string('raw_tp_2', 64)->nullable();
            $table->string('raw_price', 64)->nullable();
            $table->string('raw_qty', 64)->nullable();
            $table->string('raw_item_code', 64)->nullable();
            $table->string('raw_delivery', 64)->nullable();
            $table->string('normalized_tp_1', 64)->nullable();
            $table->string('normalized_tp_2', 64)->nullable();
            $table->string('status', 30);
            $table->text('validation_errors')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('market_id')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('unit_selling_price', 15, 2)->nullable();
            $table->decimal('courier_fee_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('created_order_id')->nullable();
            $table->timestamps();

            $table->foreign('order_import_batch_id')
                ->references('id')
                ->on('order_import_batches')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('supplier_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_order_id')
                ->references('id')
                ->on('orders')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['order_import_batch_id', 'row_number']);
            $table->index(['order_import_batch_id', 'status']);
            $table->index('created_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_import_rows');
    }
};
