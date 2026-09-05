<?php

namespace Database\Seeders;

use Feeder\Core\Models\Market;
use Feeder\Core\Services\MarketDefaultCompanyCommissionService;
use Illuminate\Database\Seeder;

class MarketDefaultCompanyCommissionSeeder extends Seeder
{
    /**
     * Market-specific default company commission amounts in each market's native currency.
     * These values are not derived from exchange rates.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = MarketDefaultCompanyCommissionService::MARKET_DEFAULTS;

    public function run(): void
    {
        $service = app(MarketDefaultCompanyCommissionService::class);

        foreach (self::DEFAULTS as $marketCode => $amount) {
            $market = Market::query()->where('code', $marketCode)->first();

            if ($market === null) {
                continue;
            }

            if ($service->hasDefaultCompanyCommission($market)) {
                continue;
            }

            $service->setDefaultCompanyCommission($market, $amount);
        }
    }
}
