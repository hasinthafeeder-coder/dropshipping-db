<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fulfillment_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_fulfillment_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedInteger('packed_quantity')->default(0);
            $table->timestamps();

            $table->foreign('order_fulfillment_id')
                ->references('id')
                ->on('order_fulfillments')
                ->cascadeOnDelete()
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

            $table->unique('order_item_id');
            $table->index('order_fulfillment_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_items');
    }
};
