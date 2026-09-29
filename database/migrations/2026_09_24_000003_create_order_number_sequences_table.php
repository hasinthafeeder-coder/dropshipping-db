<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('market_id');
            $table->date('business_date');
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['market_id', 'business_date']);

            $table->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
