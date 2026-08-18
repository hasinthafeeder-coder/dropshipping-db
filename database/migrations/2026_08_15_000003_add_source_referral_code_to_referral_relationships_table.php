<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_relationships', function (Blueprint $table) {
            if (! Schema::hasColumn('referral_relationships', 'source_referral_code_id')) {
                $table->foreignId('source_referral_code_id')->nullable()->after('child_user_id')->constrained('referral_codes')->cascadeOnUpdate()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('referral_relationships', function (Blueprint $table) {
            if (Schema::hasColumn('referral_relationships', 'source_referral_code_id')) {
                $table->dropConstrainedForeignId('source_referral_code_id');
            }
        });
    }
};
