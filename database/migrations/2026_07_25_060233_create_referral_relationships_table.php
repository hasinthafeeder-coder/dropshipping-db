<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_relationships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('child_user_id')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamps();

            $table->unique('child_user_id');
            $table->index('parent_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_relationships');
    }
};
