<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('nic', 12)->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('profile_photo', 255)->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('first_name');
            $table->index('last_name');
            $table->index('nic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
