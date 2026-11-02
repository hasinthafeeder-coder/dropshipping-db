<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsCourierMarketPricing;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Country;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\ResellerMarketAccess;
use Feeder\Core\Models\ResellerSupplierAssignment;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\GoodsReceivedNoteService;
use Feeder\Core\Services\Order\AfterHoursDeterminationService;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimum local fixtures for settlement:generate-dispatched-test-orders.
 *
 * Hard-refuses the shared dropshipping database. Safe to re-run (firstOrCreate).
 */
class SettlementGenTestFixtureSeeder extends Seeder
{
    use SeedsCourierMarketPricing;

    public const TARGET_DATABASE = 'dropshipping_settlement_gen_test';

    public const RESELLER_PHONE = '0799000001';

    public const SUPPLIER_PHONE = '0770000005';

    private const STOCK_UNITS = 1500;

    public function run(): void
    {
        $this->assertDedicatedDatabase();

        $this->seedLookups();
        $market = Market::query()->where('code', 'lk')->firstOrFail();
        $this->ensureAfterHoursPenalty($market);

        $portals = $this->seedPortals();
        $supplier = $this->seedSupplier($portals[PortalCode::SUPPLIER->value], $market);
        $reseller = $this->seedReseller($portals[PortalCode::RESELLER->value], $market);

        ResellerSupplierAssignment::query()->firstOrCreate(
            [
                'reseller_id' => $reseller->id,
                'supplier_id' => $supplier->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'assigned_by' => $reseller->id,
            ]
        );

        $this->seedCouriersAndAccounts($supplier, $market);
        $this->seedProductAndStock($supplier, $market);

        $this->command?->info('SettlementGenTestFixtureSeeder complete on '.self::TARGET_DATABASE);
        $this->command?->info('Reseller phone: '.self::RESELLER_PHONE.' (user #'.$reseller->id.')');
        $this->command?->info('Supplier phone: '.self::SUPPLIER_PHONE.' (user #'.$supplier->id.')');
    }

    private function assertDedicatedDatabase(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $host = strtolower((string) config("database.connections.{$connection}.host"));

        if (strcasecmp($database, 'dropshipping') === 0) {
            throw new RuntimeException(
                'SettlementGenTestFixtureSeeder refuses the shared dropshipping database.'
            );
        }

        if (strcasecmp($database, self::TARGET_DATABASE) !== 0) {
            throw new RuntimeException(
                'SettlementGenTestFixtureSeeder only runs on '.self::TARGET_DATABASE
                .' (current: '.$database.').'
            );
        }

        $allowedHosts = ['127.0.0.1', 'localhost', 'mysql', 'mariadb', 'db', '::1'];
        if (! in_array($host, $allowedHosts, true) && ! str_ends_with($host, '.local')) {
            throw new RuntimeException(
                'SettlementGenTestFixtureSeeder refuses non-local host: '.$host
            );
        }
    }

    private function seedLookups(): void
    {
        foreach ([
            ['iso_code' => 'LKR', 'name' => 'Sri Lankan Rupee', 'symbol' => 'Rs'],
        ] as $currency) {
            Currency::query()->firstOrCreate(
                ['iso_code' => $currency['iso_code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'decimal_places' => 2,
                    'is_active' => true,
                ]
            );
        }

        foreach ([
            ['iso_code' => 'LK', 'name' => 'Sri Lanka', 'phone_country_code' => '+94'],
        ] as $country) {
            Country::query()->firstOrCreate(
                ['iso_code' => $country['iso_code']],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $country['name'],
                    'phone_country_code' => $country['phone_country_code'],
                    'is_active' => true,
                ]
            );
        }

        $countryId = (int) Country::query()->where('iso_code', 'LK')->value('id');
        $currencyId = (int) Currency::query()->where('iso_code', 'LKR')->value('id');

        Market::query()->firstOrCreate(
            ['code' => 'lk'],
            [
                'uuid' => UuidService::generate(),
                'name' => 'Sri Lanka',
                'country_id' => $countryId,
                'currency_id' => $currencyId,
                'market_number' => 1,
                'is_active' => true,
            ]
        );
    }

    private function ensureAfterHoursPenalty(Market $market): void
    {
        $service = app(AfterHoursDeterminationService::class);
        if (! $service->hasPenaltyAmount($market)) {
            $service->setPenaltyAmount($market, AfterHoursDeterminationService::MARKET_DEFAULTS['lk']);
        }
    }

    /**
     * @return array<string, Portal>
     */
    private function seedPortals(): array
    {
        $result = [];

        foreach ([
            PortalCode::ADMIN,
            PortalCode::SUPPLIER,
            PortalCode::RESELLER,
        ] as $code) {
            $result[$code->value] = Portal::query()->firstOrCreate(
                ['code' => $code->value],
                [
                    'uuid' => UuidService::generate(),
                    'name' => $code->value.' Portal',
                    'subdomain' => strtolower($code->value),
                    'description' => $code->value.' Portal',
                    'is_active' => true,
                ]
            );
        }

        return $result;
    }

    private function seedSupplier(Portal $portal, Market $market): User
    {
        $company = Company::query()->firstOrCreate(
            ['email' => 'settlegen-supplier@feeder.local'],
            [
                'uuid' => UuidService::generate(),
                'portal_id' => $portal->id,
                'name' => 'SETTLETEST Supplier Co',
                'phone' => self::SUPPLIER_PHONE,
                'registration_number' => 'SETTLETEST-SUP',
                'status' => CompanyStatus::ACTIVE->value,
                'operation_market_id' => $market->id,
            ]
        );
        $company->forceFill([
            'status' => CompanyStatus::ACTIVE->value,
            'operation_market_id' => $market->id,
            'portal_id' => $portal->id,
        ])->save();

        $user = User::query()->firstOrCreate(
            ['phone' => self::SUPPLIER_PHONE],
            [
                'uuid' => UuidService::generate(),
                'company_id' => $company->id,
                'email' => 'settlegen-supplier-owner@feeder.local',
                'password' => Hash::make('password'),
                'user_type' => UserType::OWNER->value,
                'status' => UserStatus::ACTIVE->value,
                'phone_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'company_id' => $company->id,
            'status' => UserStatus::ACTIVE->value,
        ])->save();
        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user->fresh('company');
    }

    private function seedReseller(Portal $portal, Market $market): User
    {
        $company = Company::query()->firstOrCreate(
            ['email' => 'settlegen-reseller@feeder.local'],
            [
                'uuid' => UuidService::generate(),
                'portal_id' => $portal->id,
                'name' => 'SETTLETEST Reseller Co',
                'phone' => self::RESELLER_PHONE,
                'registration_number' => 'SETTLETEST-RES',
                'status' => CompanyStatus::ACTIVE->value,
                'home_country_id' => $market->country_id,
            ]
        );
        $company->forceFill([
            'status' => CompanyStatus::ACTIVE->value,
            'portal_id' => $portal->id,
            'home_country_id' => $market->country_id,
        ])->save();

        ResellerMarketAccess::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'market_id' => $market->id,
            ],
            ['uuid' => (string) Str::uuid()]
        );

        $user = User::query()->firstOrCreate(
            ['phone' => self::RESELLER_PHONE],
            [
                'uuid' => UuidService::generate(),
                'company_id' => $company->id,
                'email' => 'settlegen-reseller-owner@feeder.local',
                'password' => Hash::make('password'),
                'user_type' => UserType::OWNER->value,
                'status' => UserStatus::ACTIVE->value,
                'phone_verified_at' => now(),
            ]
        );
        $user->forceFill([
            'company_id' => $company->id,
            'status' => UserStatus::ACTIVE->value,
        ])->save();
        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user->fresh('company');
    }

    private function seedCouriersAndAccounts(User $supplier, Market $market): void
    {
        $defs = [
            'ROYAL' => [
                'name' => 'Royal Express',
                'external_service_id' => 'royal-standard',
                'first_kg_fee' => 700,
                'additional_kg_fee' => 200,
                'credentials' => [
                    'email' => 'settlegen-royal@feeder.local',
                    'password' => 'local-test-only-not-a-real-secret',
                ],
                'meta' => ['merchant_business_id' => 'SETTLETEST-ROYAL', 'origin' => 'Colombo'],
            ],
            'TRANSEXPRESS' => [
                'name' => 'TransExpress',
                'external_service_id' => 'transexpress-standard',
                'first_kg_fee' => 650,
                'additional_kg_fee' => 150,
                'credentials' => [
                    'email' => 'settlegen-tx@feeder.local',
                    'password' => 'local-test-only-not-a-real-secret',
                ],
                'meta' => [],
            ],
            'FARDAR' => [
                'name' => 'Fardar Express Domestic',
                'external_service_id' => 'fardar-standard',
                'first_kg_fee' => 600,
                'additional_kg_fee' => 100,
                'credentials' => [
                    'client_id' => 'SETTLETEST-FARDAR',
                    'api_key' => 'local-test-only-not-a-real-secret',
                ],
                'meta' => [],
            ],
        ];

        $isFirstCourier = true;

        foreach ($defs as $code => $def) {
            $courier = Courier::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $def['name'], 'is_active' => true]
            );

            CourierService::query()->updateOrCreate(
                [
                    'courier_id' => $courier->id,
                    'code' => 'STANDARD',
                ],
                [
                    'name' => 'Standard Delivery',
                    'external_service_id' => $def['external_service_id'],
                    'is_active' => true,
                ]
            );

            $this->seedMarketPricingForActiveMarkets($courier, [
                'first_kg_fee' => $def['first_kg_fee'],
                'additional_kg_fee' => $def['additional_kg_fee'],
            ]);

            CourierCity::query()->firstOrCreate(
                [
                    'courier_id' => $courier->id,
                    'external_id' => 'settlegen-city-'.$code,
                ],
                [
                    'name' => 'SETTLETEST City '.$code,
                    'city_name' => 'Colombo',
                    'district_name' => 'Colombo',
                    'external_city_code' => 'CMB-'.$code,
                    'external_district_code' => 'COL',
                    'is_active' => true,
                ]
            );

            // Only one active default account is allowed per supplier globally.
            $isDefault = $isFirstCourier;
            $isFirstCourier = false;

            $account = SupplierCourierAccount::query()
                ->where('supplier_id', $supplier->id)
                ->where('courier_id', $courier->id)
                ->where('account_label', 'SETTLETEST '.$code)
                ->first();

            if ($account === null) {
                $account = new SupplierCourierAccount([
                    'uuid' => (string) Str::uuid(),
                    'supplier_id' => $supplier->id,
                    'courier_id' => $courier->id,
                    'account_label' => 'SETTLETEST '.$code,
                    'is_active' => true,
                    'is_default' => $isDefault,
                    'created_by' => $supplier->id,
                    'updated_by' => $supplier->id,
                    'meta_json' => $def['meta'],
                ]);
                $account->setCredentials($def['credentials']);
                $account->save();
            } else {
                $account->forceFill([
                    'is_active' => true,
                    'is_default' => $isDefault,
                    'meta_json' => $def['meta'],
                    'updated_by' => $supplier->id,
                ])->save();
                $account->setCredentials($def['credentials']);
                $account->save();
            }
        }
    }

    private function seedProductAndStock(User $supplier, Market $market): void
    {
        $category = ProductCategory::query()->firstOrCreate(
            ['slug' => 'settlegen-general'],
            [
                'id' => (string) Str::uuid(),
                'name' => 'SETTLETEST General',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        $product = Product::query()->firstOrCreate(
            ['slug' => 'settlegen-product'],
            [
                'uuid' => (string) Str::uuid(),
                'supplier_id' => $supplier->id,
                'category_id' => $category->id,
                'market_id' => $market->id,
                'name' => 'SETTLETEST Product',
                'status' => ProductStatus::ACTIVE->value,
                'system_visible' => true,
                'web_visible' => false,
                'price_locked' => true,
                'created_by' => $supplier->id,
                'updated_by' => $supplier->id,
            ]
        );
        $product->forceFill([
            'supplier_id' => $supplier->id,
            'market_id' => $market->id,
            'status' => ProductStatus::ACTIVE->value,
            'system_visible' => true,
        ])->save();

        $variant = ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->first();
        if ($variant === null) {
            $variant = ProductVariant::query()->create([
                'uuid' => (string) Str::uuid(),
                'product_id' => $product->id,
                'name' => 'Default',
                'barcode' => '',
                'cost' => 500.00,
                'selling_price' => 1500.00,
                'weight' => 0.500,
                'company_commission' => 100.00,
                'is_active' => true,
                'created_by' => $supplier->id,
                'updated_by' => $supplier->id,
            ]);
        } else {
            $variant->forceFill([
                'cost' => 500.00,
                'selling_price' => 1500.00,
                'weight' => 0.500,
                'company_commission' => 100.00,
                'is_active' => true,
            ])->save();
        }

        $available = app(\Feeder\Core\Services\StockService::class)
            ->availableQuantityForVariant((int) $variant->id);

        if ($available >= self::STOCK_UNITS) {
            $this->command?->info('Stock already sufficient: '.$available.' units.');

            return;
        }

        $needed = self::STOCK_UNITS - $available;
        app(GoodsReceivedNoteService::class)->createGrn(
            (int) $supplier->id,
            [
                'received_date' => now()->toDateString(),
                'notes' => 'SETTLETEST local stock for settlement generator',
                'created_by' => $supplier->id,
                'updated_by' => $supplier->id,
            ],
            [[
                'product_id' => (int) $product->id,
                'product_variant_id' => (int) $variant->id,
                'received_quantity' => $needed,
                'damaged_quantity' => 0,
                'unit_cost' => '500.00',
            ]]
        );

        $this->command?->info('Seeded GRN for '.$needed.' units (target available >= '.self::STOCK_UNITS.').');
    }
}
