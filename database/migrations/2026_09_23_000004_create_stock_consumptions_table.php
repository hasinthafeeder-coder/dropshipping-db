<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_consumptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('stock_reservation_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('consumed_by')->nullable();
            $table->timestamp('consumed_at');
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

            $table->foreign('stock_reservation_id')
                ->references('id')
                ->on('stock_reservations')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('consumed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique('order_item_id');
            $table->index(['product_variant_id', 'consumed_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_consumptions');
    }
};
