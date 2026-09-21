<?php

namespace Database\Seeders\Concerns;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\Market;

/**
 * Couriers are eligible for reseller order selection only when
 * OrderCourierLookupService finds both an active supplier account
 * and active CourierMarketPricing for the order market.
 *
 * Seeders that register a courier must therefore provision market pricing
 * for active markets; otherwise connected accounts never appear in the dropdown.
 */
trait SeedsCourierMarketPricing
{
    /**
     * @param  array{first_kg_fee: float|int|string, additional_kg_fee: float|int|string}  $fees
     */
    protected function seedMarketPricingForActiveMarkets(Courier $courier, array $fees): void
    {
        Market::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->each(function (Market $market) use ($courier, $fees): void {
                CourierMarketPricing::query()->firstOrCreate(
                    [
                        'courier_id' => $courier->id,
                        'market_id' => $market->id,
                    ],
                    [
                        'currency_id' => $market->currency_id,
                        'first_kg_fee' => $fees['first_kg_fee'],
                        'additional_kg_fee' => $fees['additional_kg_fee'],
                        'is_active' => true,
                    ]
                );
            });
    }
}
