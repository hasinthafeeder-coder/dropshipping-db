<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropUnique('company_bank_accounts_company_id_unique');

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropIndex('company_bank_accounts_company_id_index');

            $table->unique('company_id');
        });
    }
};
