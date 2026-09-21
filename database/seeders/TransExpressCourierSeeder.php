<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsCourierMarketPricing;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierService;
use Illuminate\Database\Seeder;

/**
 * Production-safe seed for TransExpress.
 *
 * Does not create supplier accounts or credentials — those are Admin-managed.
 * Seeds CourierMarketPricing for active markets so connected supplier accounts
 * are discoverable by OrderCourierLookupService.
 * Safe to re-run: updateOrCreate / firstOrCreate.
 */
class TransExpressCourierSeeder extends Seeder
{
    use SeedsCourierMarketPricing;

    public function run(): void
    {
        $courier = Courier::query()->updateOrCreate(
            ['code' => 'TRANSEXPRESS'],
            [
                'name' => 'TransExpress',
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
                'external_service_id' => 'transexpress-standard',
                'is_active' => true,
            ]
        );

        $this->seedMarketPricingForActiveMarkets($courier, [
            'first_kg_fee' => 650,
            'additional_kg_fee' => 150,
        ]);

        $this->command?->info('TransExpress courier ready (code=TRANSEXPRESS).');
    }
}
