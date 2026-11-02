<?php

namespace Database\Seeders;

use Feeder\Core\Services\Settlement\SupplierSettlementTransferFeeSettingsService;
use Illuminate\Database\Seeder;

class SupplierSettlementTransferFeeDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        app(SupplierSettlementTransferFeeSettingsService::class)->seedApprovedDefaults();
    }
}
