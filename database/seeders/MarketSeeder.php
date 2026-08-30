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
                'is_active' => true,
            ],
            [
                'code' => 'my',
                'name' => 'Malaysia',
                'country_iso_code' => 'MY',
                'currency_iso_code' => 'MYR',
                'is_active' => true,
            ],
            [
                'code' => 'th',
                'name' => 'Thailand',
                'country_iso_code' => 'TH',
                'currency_iso_code' => 'THB',
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

            Market::query()->firstOrCreate(
                ['code' => $market['code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $market['name'],
                    'country_id' => $countryId,
                    'currency_id' => $currencyId,
                    'is_active' => $market['is_active'],
                ]
            );
        }
    }
}
