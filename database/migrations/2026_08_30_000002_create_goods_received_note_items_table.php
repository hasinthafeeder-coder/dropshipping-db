<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_received_note_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->decimal('unit_cost', 15, 2);
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('grn_id')
                ->references('id')
                ->on('goods_received_notes')
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

            $table->unique(['grn_id', 'product_variant_id']);
            $table->index('grn_id');
            $table->index('product_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_received_note_items');
    }
};
