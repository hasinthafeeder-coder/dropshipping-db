<?php

namespace Database\Seeders;

use Feeder\Core\Models\Currency;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'iso_code' => 'LKR',
                'name' => 'Sri Lankan Rupee',
                'symbol' => 'Rs',
                'decimal_places' => 2,
                'is_active' => true,
            ],
            [
                'iso_code' => 'MYR',
                'name' => 'Malaysian Ringgit',
                'symbol' => 'RM',
                'decimal_places' => 2,
                'is_active' => true,
            ],
            [
                'iso_code' => 'THB',
                'name' => 'Thai Baht',
                'symbol' => '฿',
                'decimal_places' => 2,
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->firstOrCreate(
                ['iso_code' => $currency['iso_code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'decimal_places' => $currency['decimal_places'],
                    'is_active' => $currency['is_active'],
                ]
            );
        }
    }
}
