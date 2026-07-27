<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('portal_id')->constrained('portals')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('module', 100);
            $table->string('name', 100);
            $table->string('slug', 150);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->softDeletes();

            $table->unique(['portal_id', 'slug']);

            $table->index('portal_id');
            $table->index('module');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
