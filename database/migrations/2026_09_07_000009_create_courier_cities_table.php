<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_cities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('courier_id');
            $table->string('district_name');
            $table->string('city_name');
            $table->string('external_city_code', 100);
            $table->string('external_district_code', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('courier_id')
                ->references('id')
                ->on('couriers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['courier_id', 'external_city_code']);
            $table->index(['courier_id', 'city_name']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_cities');
    }
};
