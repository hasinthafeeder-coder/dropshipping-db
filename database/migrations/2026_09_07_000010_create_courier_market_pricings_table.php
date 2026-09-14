<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_market_pricings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('courier_id');
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('currency_id');
            $table->decimal('first_kg_fee', 15, 2);
            $table->decimal('additional_kg_fee', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('courier_id')
                ->references('id')
                ->on('couriers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

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

            $table->unique(['courier_id', 'market_id']);
            $table->index('market_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_market_pricings');
    }
};
