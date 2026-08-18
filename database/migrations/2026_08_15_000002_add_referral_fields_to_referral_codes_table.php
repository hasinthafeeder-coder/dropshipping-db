<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('referral_codes', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }

            if (! Schema::hasColumn('referral_codes', 'activated_by_user_id')) {
                $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            }

            if (! Schema::hasColumn('referral_codes', 'activated_at')) {
                $table->timestamp('activated_at')->nullable();
            }

            if (! Schema::hasColumn('referral_codes', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }

            if (! Schema::hasColumn('referral_codes', 'last_changed_by_user_id')) {
                $table->foreignId('last_changed_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('referral_codes', function (Blueprint $table) {
            if (Schema::hasColumn('referral_codes', 'last_changed_by_user_id')) {
                $table->dropConstrainedForeignId('last_changed_by_user_id');
            }

            if (Schema::hasColumn('referral_codes', 'deactivated_at')) {
                $table->dropColumn('deactivated_at');
            }

            if (Schema::hasColumn('referral_codes', 'activated_at')) {
                $table->dropColumn('activated_at');
            }

            if (Schema::hasColumn('referral_codes', 'activated_by_user_id')) {
                $table->dropConstrainedForeignId('activated_by_user_id');
            }
        });
    }
};
