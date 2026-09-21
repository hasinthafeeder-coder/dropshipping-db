<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsCourierMarketPricing;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierService;
use Illuminate\Database\Seeder;

/**
 * Production-safe seed for Courier 1 (Royal Express / Curfox).
 *
 * Does not create supplier accounts or credentials — those are Admin-managed.
 * Seeds CourierMarketPricing for active markets so connected supplier accounts
 * are discoverable by OrderCourierLookupService (account alone is not enough).
 * Safe to re-run: updateOrCreate / firstOrCreate.
 */
class RoyalExpressCourierSeeder extends Seeder
{
    use SeedsCourierMarketPricing;

    public function run(): void
    {
        $courier = Courier::query()->updateOrCreate(
            ['code' => 'ROYAL'],
            [
                'name' => 'Royal Express',
                'is_active' => true,
            ]
        );

        CourierService::query()->updateOrCreate(
            [
                'courier_id' => $courier->id,
                'code' => 'STANDARD',
            ],
            [
                'name' => 'Standard Delivery',
                'external_service_id' => 'royal-standard',
                'is_active' => true,
            ]
        );

        $this->seedMarketPricingForActiveMarkets($courier, [
            'first_kg_fee' => 700,
            'additional_kg_fee' => 200,
        ]);

        $this->command?->info('Royal Express courier ready (code=ROYAL).');
    }
}
