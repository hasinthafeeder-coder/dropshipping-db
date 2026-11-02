<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit platform-origin / supplier-beneficiary bank account selection per currency.
 *
 * Engine-specific "one PLATFORM_ORIGIN per currency" unique is deferred until
 * production VERSION() is recorded; enforce that rule in services for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_settlement_account_designations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->unique();
            $table->string('role', 30);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('company_bank_account_id');
            $table->unsignedBigInteger('designated_by');
            $table->timestamp('designated_at');
            $table->timestamps();

            $table->unique(
                ['company_id', 'currency_id', 'role'],
                'ssad_company_currency_role_unique'
            );

            $table->foreign('company_id', 'ssad_company_fk')
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('currency_id', 'ssad_currency_fk')
                ->references('id')
                ->on('currencies')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('company_bank_account_id', 'ssad_bank_account_fk')
                ->references('id')
                ->on('company_bank_accounts')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('designated_by', 'ssad_designated_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index('currency_id', 'ssad_currency_idx');
            $table->index('role', 'ssad_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_settlement_account_designations');
    }
};
