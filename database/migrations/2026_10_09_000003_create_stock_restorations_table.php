<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable reversal of stock_consumptions for returned orders.
 *
 * Availability becomes: RECEIVED - DAMAGED - RESERVED - CONSUMED + RESTORED.
 * Historical consumption rows are never deleted or rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_restorations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('stock_consumption_id');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->timestamp('restored_at');
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('order_item_id')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('stock_consumption_id')
                ->references('id')
                ->on('stock_consumptions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('restored_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // One restoration per consumption — enforces idempotency under concurrency.
            $table->unique('stock_consumption_id');
            $table->unique('order_item_id');
            $table->index(['product_variant_id', 'restored_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_restorations');
    }
};
