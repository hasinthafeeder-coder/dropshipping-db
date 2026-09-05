<?php

namespace Database\Seeders;

use Feeder\Core\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FinancialSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'reseller_service_charge' => [
                'value' => '75.00',
                'group' => 'financial',
                'description' => 'Default reseller service charge charged per order.',
            ],
            'introducer_bonus' => [
                'value' => '50.00',
                'group' => 'financial',
                'description' => 'Default introducer bonus paid per eligible sale.',
            ],
        ];

        foreach ($defaults as $key => $payload) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'uuid' => (string) Str::uuid(),
                    'key' => $key,
                    'group' => $payload['group'],
                    'value' => $payload['value'],
                    'description' => $payload['description'],
                ]
            );
        }
    }
}
