<?php

namespace Database\Seeders;

use Feeder\Core\Services\Settlement\ResellerNonDeliveryFineSettingsService;
use Illuminate\Database\Seeder;

/**
 * Seeds approved LKR non-delivery fine totals only.
 * MYR and other markets remain unconfigured until Admin sets them explicitly.
 */
class NonDeliveryFineDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        app(ResellerNonDeliveryFineSettingsService::class)->seedApprovedDefaults();
    }
}
