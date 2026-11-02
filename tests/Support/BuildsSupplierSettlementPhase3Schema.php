<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isolated runtime schema for supplier settlement Phase 3 bank-export tests.
 *
 * Never targets the shared dropshipping database.
 */
trait BuildsSupplierSettlementPhase3Schema
{
    use BuildsSupplierSettlementPhase2Schema;

    protected function configureIsolatedSettlementPhase3Database(): void
    {
        $this->configureIsolatedSettlementTestDatabase();
    }

    protected function buildSupplierSettlementPhase3Schema(): void
    {
        $this->dropSettlementAndStubTables();
        $this->createPhase3ParentStubTables();
        $this->runSettlementMigrations();
    }

    protected function createPhase3ParentStubTables(): void
    {
        $this->createPhase2ParentStubTables();

        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->string('uuid', 36)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('branch_name', 150)->nullable();
            $table->string('bank_code', 30)->nullable();
            $table->string('branch_code', 30)->nullable();
        });
    }
}
