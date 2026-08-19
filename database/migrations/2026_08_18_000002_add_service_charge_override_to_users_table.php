<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'reseller_service_charge_override')) {
                $table->decimal('reseller_service_charge_override', 10, 2)->nullable()->after('user_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'reseller_service_charge_override')) {
                $table->dropColumn('reseller_service_charge_override');
            }
        });
    }
};
