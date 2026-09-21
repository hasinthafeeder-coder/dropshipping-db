<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsCourierMarketPricing;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierService;
use Illuminate\Database\Seeder;

/**
 * Production-safe seed for Fardar Express Domestic.
 *
 * Does not create supplier accounts or credentials — those are Admin-managed.
 * Location master data is imported via courier:import-locations (CSV).
 * Seeds CourierMarketPricing for active markets so connected supplier accounts
 * are discoverable by OrderCourierLookupService.
 * Safe to re-run: updateOrCreate / firstOrCreate.
 */
class FardarExpressCourierSeeder extends Seeder
{
    use SeedsCourierMarketPricing;

    public function run(): void
    {
        $courier = Courier::query()->updateOrCreate(
            ['code' => 'FARDAR'],
            [
                'name' => 'Fardar Express Domestic',
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
                'external_service_id' => 'fardar-standard',
                'is_active' => true,
            ]
        );

        $this->seedMarketPricingForActiveMarkets($courier, [
            'first_kg_fee' => 600,
            'additional_kg_fee' => 100,
        ]);

        $this->command?->info('Fardar Express Domestic courier ready (code=FARDAR).');
    }
}
