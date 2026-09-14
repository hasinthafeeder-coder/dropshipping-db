<?php

namespace Database\Seeders;

use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Development/testing seeder: demo couriers, services, cities, market pricing,
 * and supplier courier accounts for the Sri Lanka market.
 *
 * Compatible with OrderCourierLookupService + CourierFeeCalculator:
 * eligibility requires active SupplierCourierAccount + active CourierMarketPricing.
 * Fees use first_kg_fee / additional_kg_fee (market-level, not per-city).
 *
 * Safe to re-run: updateOrCreate / firstOrCreate on natural keys.
 *
 * Run (local/dev only):
 *   php artisan db:seed --class=DevelopmentCourierSeeder
 */
class DevelopmentCourierSeeder extends Seeder
{
    private const MARKET_CODE = 'lk';

    private const ALLOWED_ENVIRONMENTS = ['local', 'development', 'testing'];

    /**
     * @var list<array{
     *     code: string,
     *     name: string,
     *     services: list<array{code: string, name: string, external_service_id: string}>,
     *     first_kg_fee: float,
     *     additional_kg_fee: float
     * }>
     */
    private const COURIERS = [
        [
            'code' => 'DEMO_A',
            'name' => 'Demo Courier A',
            'services' => [
                [
                    'code' => 'STANDARD',
                    'name' => 'Standard Delivery',
                    'external_service_id' => 'demo-a-standard',
                ],
            ],
            'first_kg_fee' => 300.00,
            'additional_kg_fee' => 50.00,
        ],
        [
            'code' => 'DEMO_B',
            'name' => 'Demo Courier B',
            'services' => [
                [
                    'code' => 'EXPRESS',
                    'name' => 'Express Delivery',
                    'external_service_id' => 'demo-b-express',
                ],
            ],
            'first_kg_fee' => 350.00,
            'additional_kg_fee' => 75.00,
        ],
        [
            'code' => 'DEMO_C',
            'name' => 'Demo Courier C',
            'services' => [
                [
                    'code' => 'SAME_DAY',
                    'name' => 'Same Day Delivery',
                    'external_service_id' => 'demo-c-same-day',
                ],
            ],
            'first_kg_fee' => 450.00,
            'additional_kg_fee' => 100.00,
        ],
    ];

    /**
     * Sri Lankan cities used by Create Order district/city lookups.
     * Cities are courier-scoped; district_name drives the district dropdown.
     *
     * @var list<array{district_name: string, city_name: string, external_city_code: string, external_district_code: string}>
     */
    private const CITIES = [
        [
            'district_name' => 'Colombo',
            'city_name' => 'Colombo',
            'external_city_code' => 'CMB',
            'external_district_code' => 'COL',
        ],
        [
            'district_name' => 'Colombo',
            'city_name' => 'Kaduwela',
            'external_city_code' => 'KDW',
            'external_district_code' => 'COL',
        ],
        [
            'district_name' => 'Colombo',
            'city_name' => 'Dehiwala',
            'external_city_code' => 'DHW',
            'external_district_code' => 'COL',
        ],
        [
            'district_name' => 'Colombo',
            'city_name' => 'Maharagama',
            'external_city_code' => 'MHG',
            'external_district_code' => 'COL',
        ],
        [
            'district_name' => 'Colombo',
            'city_name' => 'Nugegoda',
            'external_city_code' => 'NUG',
            'external_district_code' => 'COL',
        ],
        [
            'district_name' => 'Colombo',
            'city_name' => 'Moratuwa',
            'external_city_code' => 'MRT',
            'external_district_code' => 'COL',
        ],
        [
            'district_name' => 'Gampaha',
            'city_name' => 'Negombo',
            'external_city_code' => 'NEG',
            'external_district_code' => 'GAM',
        ],
        [
            'district_name' => 'Gampaha',
            'city_name' => 'Gampaha',
            'external_city_code' => 'GAM',
            'external_district_code' => 'GAM',
        ],
        [
            'district_name' => 'Kandy',
            'city_name' => 'Kandy',
            'external_city_code' => 'KDY',
            'external_district_code' => 'KAN',
        ],
        [
            'district_name' => 'Galle',
            'city_name' => 'Galle',
            'external_city_code' => 'GLE',
            'external_district_code' => 'GAL',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->command?->warn(
                'DevelopmentCourierSeeder skipped: only runs in local/development/testing environments.'
            );

            return;
        }

        $market = Market::query()
            ->with('currency')
            ->where('code', self::MARKET_CODE)
            ->where('is_active', true)
            ->first();

        if ($market === null) {
            throw new RuntimeException(
                'DevelopmentCourierSeeder requires an active Sri Lanka market (code=lk).'
            );
        }

        if ($market->currency_id === null) {
            throw new RuntimeException(
                'DevelopmentCourierSeeder requires market lk to have a currency_id.'
            );
        }

        $suppliers = $this->resolveSriLankaSuppliers((int) $market->id);

        $courierCount = 0;
        $serviceCount = 0;
        $cityCount = 0;
        $pricingCount = 0;
        $accountCount = 0;

        foreach (self::COURIERS as $definition) {
            $courier = Courier::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'is_active' => true,
                ]
            );
            $courierCount++;

            foreach ($definition['services'] as $serviceDefinition) {
                CourierService::query()->updateOrCreate(
                    [
                        'courier_id' => $courier->id,
                        'code' => $serviceDefinition['code'],
                    ],
                    [
                        'name' => $serviceDefinition['name'],
                        'external_service_id' => $serviceDefinition['external_service_id'],
                        'is_active' => true,
                    ]
                );
                $serviceCount++;
            }

            foreach (self::CITIES as $cityDefinition) {
                CourierCity::query()->updateOrCreate(
                    [
                        'courier_id' => $courier->id,
                        'external_city_code' => $cityDefinition['external_city_code'],
                    ],
                    [
                        'district_name' => $cityDefinition['district_name'],
                        'city_name' => $cityDefinition['city_name'],
                        'external_district_code' => $cityDefinition['external_district_code'],
                        'is_active' => true,
                    ]
                );
                $cityCount++;
            }

            CourierMarketPricing::query()->updateOrCreate(
                [
                    'courier_id' => $courier->id,
                    'market_id' => $market->id,
                ],
                [
                    'currency_id' => $market->currency_id,
                    'first_kg_fee' => $definition['first_kg_fee'],
                    'additional_kg_fee' => $definition['additional_kg_fee'],
                    'is_active' => true,
                ]
            );
            $pricingCount++;

            foreach ($suppliers as $supplier) {
                SupplierCourierAccount::query()->updateOrCreate(
                    [
                        'supplier_id' => $supplier->id,
                        'courier_id' => $courier->id,
                    ],
                    [
                        'account_label' => $definition['name'].' Dev Account',
                        'credentials_encrypted' => Crypt::encryptString(json_encode([
                            'api_key' => 'dev-'.$definition['code'].'-supplier-'.$supplier->id,
                            'source' => 'DevelopmentCourierSeeder',
                        ], JSON_THROW_ON_ERROR)),
                        'meta_json' => [
                            'seeded_by' => 'DevelopmentCourierSeeder',
                            'environment' => app()->environment(),
                        ],
                        'is_active' => true,
                    ]
                );
                $accountCount++;
            }
        }

        $this->command?->info('DevelopmentCourierSeeder completed.');
        $this->command?->table(
            ['Metric', 'Count'],
            [
                ['Couriers', (string) $courierCount],
                ['Services', (string) $serviceCount],
                ['Cities', (string) $cityCount],
                ['Market pricings', (string) $pricingCount],
                ['Supplier accounts', (string) $accountCount],
                ['LK suppliers linked', (string) $suppliers->count()],
            ]
        );

        $this->command?->info(
            'Example (≤1 kg): Demo Courier A → first_kg_fee '.$this->formatFee(300.0)
            .' LKR (Colombo city selectable; fee is market-level, not city-level).'
        );
    }

    /**
     * Active supplier owners whose company operates in the Sri Lanka market.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveSriLankaSuppliers(int $marketId)
    {
        return User::query()
            ->where('status', UserStatus::ACTIVE->value)
            ->whereHas('company', function ($query) use ($marketId): void {
                $query->where('operation_market_id', $marketId)
                    ->whereHas('portal', fn ($portal) => $portal->where('code', PortalCode::SUPPLIER->value));
            })
            ->orderBy('id')
            ->get();
    }

    private function formatFee(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
