<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_services', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('courier_id');
            $table->string('code', 50);
            $table->string('name');
            $table->string('external_service_id', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('courier_id')
                ->references('id')
                ->on('couriers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['courier_id', 'code']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_services');
    }
};
