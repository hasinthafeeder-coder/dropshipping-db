<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crib_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('market_id');
            $table->string('source_name');
            $table->string('source_file_hash', 128)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->string('status', 30);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('market_id')
                ->references('id')
                ->on('markets')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('market_id');
            $table->index('status');
            $table->index('imported_at');
            $table->index('source_file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crib_import_batches');
    }
};
