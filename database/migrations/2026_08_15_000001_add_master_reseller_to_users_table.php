<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_master_reseller')) {
                $table->boolean('is_master_reseller')->default(false)->after('user_type');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('is_master_reseller');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_master_reseller']);
            if (Schema::hasColumn('users', 'is_master_reseller')) {
                $table->dropColumn('is_master_reseller');
            }
        });
    }
};
