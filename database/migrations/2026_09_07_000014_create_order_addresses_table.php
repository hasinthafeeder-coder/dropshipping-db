<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('recipient_name');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city_name');
            $table->string('district_name')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->unsignedBigInteger('country_id');
            $table->text('full_address_text')->nullable();
            $table->timestamps();

            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('country_id')
                ->references('id')
                ->on('countries')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
