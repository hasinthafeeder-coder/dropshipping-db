<?php

namespace Database\Seeders;

use Feeder\Core\Models\Country;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            [
                'iso_code' => 'LK',
                'name' => 'Sri Lanka',
                'phone_country_code' => '+94',
                'is_active' => true,
            ],
            [
                'iso_code' => 'MY',
                'name' => 'Malaysia',
                'phone_country_code' => '+60',
                'is_active' => true,
            ],
            [
                'iso_code' => 'TH',
                'name' => 'Thailand',
                'phone_country_code' => '+66',
                'is_active' => true,
            ],
        ];

        foreach ($countries as $country) {
            Country::query()->firstOrCreate(
                ['iso_code' => $country['iso_code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $country['name'],
                    'phone_country_code' => $country['phone_country_code'],
                    'is_active' => $country['is_active'],
                ]
            );
        }
    }
}
