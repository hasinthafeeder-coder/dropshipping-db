<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot');
            $table->string('barcode_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_selling_price', 15, 2);
            $table->decimal('unit_cost_snapshot', 15, 2);
            $table->decimal('unit_company_commission_snapshot', 15, 2)->default(0);
            $table->decimal('unit_weight_snapshot', 12, 3)->default(0);
            $table->decimal('line_selling_total', 15, 2);
            $table->decimal('line_weight_total', 12, 3)->default(0);
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['order_id', 'product_variant_id']);
            $table->index('product_id');
            $table->index('product_variant_id');
            $table->index(['product_variant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
