<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcode_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('market_id')->primary();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcode_sequences');
    }
};
