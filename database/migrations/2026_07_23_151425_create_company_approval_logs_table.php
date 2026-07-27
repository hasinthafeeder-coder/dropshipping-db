<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_approval_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('action', 30);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('company_id');
            $table->index('approved_by');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_approval_logs');
    }
};
