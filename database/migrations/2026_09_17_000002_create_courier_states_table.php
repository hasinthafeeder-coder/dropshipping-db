<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_states', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('courier_id');
            $table->string('external_id');
            $table->string('external_ref_no')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('courier_id')
                ->references('id')
                ->on('couriers')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['courier_id', 'external_id']);
            $table->index(['courier_id', 'name']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_states');
    }
};
