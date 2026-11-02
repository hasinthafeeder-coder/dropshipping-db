<?php

namespace Database\Seeders;

use Feeder\Core\Services\Settlement\ResellerSettlementTransferFeeSettingsService;
use Illuminate\Database\Seeder;

class ResellerSettlementTransferFeeDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        app(ResellerSettlementTransferFeeSettingsService::class)->seedApprovedDefaults();
    }
}
