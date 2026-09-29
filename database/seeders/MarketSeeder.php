<?php

namespace Database\Seeders;

use Feeder\Core\Models\Country;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Seeder;

class MarketSeeder extends Seeder
{
    public function run(): void
    {
        $markets = [
            [
                'code' => 'lk',
                'name' => 'Sri Lanka',
                'country_iso_code' => 'LK',
                'currency_iso_code' => 'LKR',
                'market_number' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'my',
                'name' => 'Malaysia',
                'country_iso_code' => 'MY',
                'currency_iso_code' => 'MYR',
                'market_number' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'th',
                'name' => 'Thailand',
                'country_iso_code' => 'TH',
                'currency_iso_code' => 'THB',
                'market_number' => null,
                'is_active' => false,
            ],
        ];

        foreach ($markets as $market) {
            $countryId = Country::query()
                ->where('iso_code', $market['country_iso_code'])
                ->value('id');

            $currencyId = Currency::query()
                ->where('iso_code', $market['currency_iso_code'])
                ->value('id');

            if ($countryId === null || $currencyId === null) {
                continue;
            }

            $row = Market::query()->firstOrCreate(
                ['code' => $market['code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $market['name'],
                    'country_id' => $countryId,
                    'currency_id' => $currencyId,
                    'market_number' => $market['market_number'],
                    'is_active' => $market['is_active'],
                ]
            );

            if ($row->market_number !== $market['market_number']) {
                $row->forceFill(['market_number' => $market['market_number']])->save();
            }
        }
    }
}
