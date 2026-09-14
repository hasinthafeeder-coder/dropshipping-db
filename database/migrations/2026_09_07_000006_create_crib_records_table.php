<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crib_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('market_id');
            $table->unsignedBigInteger('country_id');
            $table->string('normalized_phone', 32);
            $table->string('risk_level', 30)->nullable();
            $table->string('risk_code', 50)->nullable();
            $table->text('risk_summary')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('last_import_batch_id')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('country_id')
                ->references('id')
                ->on('countries')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('last_import_batch_id')
                ->references('id')
                ->on('crib_import_batches')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->unique(['market_id', 'normalized_phone']);
            $table->index('normalized_phone');
            $table->index(['normalized_phone', 'is_active']);
            $table->index(['market_id', 'is_active']);
            $table->index('country_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crib_records');
    }
};
