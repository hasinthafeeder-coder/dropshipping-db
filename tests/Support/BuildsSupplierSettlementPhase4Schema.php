<?php

namespace Tests\Support;

/**
 * Isolated runtime schema for supplier settlement Phase 4 payment-ledger tests.
 *
 * Reuses the Phase 3 parent stubs and settlement migrations.
 * Never targets the shared dropshipping database.
 */
trait BuildsSupplierSettlementPhase4Schema
{
    use BuildsSupplierSettlementPhase3Schema;

    protected function configureIsolatedSettlementPhase4Database(): void
    {
        $this->configureIsolatedSettlementTestDatabase();
    }

    protected function buildSupplierSettlementPhase4Schema(): void
    {
        $this->buildSupplierSettlementPhase3Schema();
    }
}
