<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropForeign('company_bank_accounts_company_id_foreign');
            $table->dropUnique('company_bank_accounts_company_id_unique');
            $table->index('company_id');
        });

        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropForeign('company_bank_accounts_company_id_foreign');
            $table->dropIndex('company_bank_accounts_company_id_index');
            $table->unique('company_id');
        });

        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }
};
