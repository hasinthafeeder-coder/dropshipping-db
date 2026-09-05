<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('referral_codes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('referral_relationships', function (Blueprint $table) {
            if (! Schema::hasColumn('referral_relationships', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('referral_codes', function (Blueprint $table) {
            if (Schema::hasColumn('referral_codes', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('referral_relationships', function (Blueprint $table) {
            if (Schema::hasColumn('referral_relationships', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
