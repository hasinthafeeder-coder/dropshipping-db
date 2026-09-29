<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedInteger('quantity');
            $table->string('status', 30)->default('ACTIVE');
            $table->unsignedBigInteger('reserved_by')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('consumed_at')->nullable();
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

            $table->foreign('reserved_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('released_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            // One reservation ledger row per order item (status tracks lifecycle).
            $table->unique('order_item_id');
            $table->index(['product_variant_id', 'status']);
            $table->index(['order_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
