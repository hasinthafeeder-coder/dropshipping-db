<?php

namespace Tests\Feature\Market;

use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\MarketSeeder;
use Database\Support\SriLankaMarketBackfill;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Country;
use Feeder\Core\Models\Currency;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Models\ResellerMarketAccess;
use Feeder\Core\Models\User;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketArchitectureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'dropshipping',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => 'admin',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::beginTransaction();

        $this->seed(CountrySeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(MarketSeeder::class);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_countries_are_seeded_correctly(): void
    {
        $this->assertDatabaseCount('countries', 3);
        $this->assertDatabaseHas('countries', [
            'iso_code' => 'LK',
            'name' => 'Sri Lanka',
            'phone_country_code' => '+94',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('countries', [
            'iso_code' => 'MY',
            'name' => 'Malaysia',
            'phone_country_code' => '+60',
        ]);
        $this->assertDatabaseHas('countries', [
            'iso_code' => 'TH',
            'name' => 'Thailand',
            'phone_country_code' => '+66',
        ]);
    }

    public function test_currencies_are_seeded_correctly(): void
    {
        $this->assertDatabaseCount('currencies', 3);
        $this->assertDatabaseHas('currencies', [
            'iso_code' => 'LKR',
            'name' => 'Sri Lankan Rupee',
            'symbol' => 'Rs',
            'decimal_places' => 2,
        ]);
        $this->assertDatabaseHas('currencies', [
            'iso_code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'symbol' => 'RM',
            'decimal_places' => 2,
        ]);
        $this->assertDatabaseHas('currencies', [
            'iso_code' => 'THB',
            'name' => 'Thai Baht',
            'symbol' => '฿',
            'decimal_places' => 2,
        ]);
    }

    public function test_markets_are_seeded_correctly(): void
    {
        $this->assertDatabaseCount('markets', 3);

        $sriLankaMarket = Market::query()->where('code', 'lk')->firstOrFail();
        $malaysiaMarket = Market::query()->where('code', 'my')->firstOrFail();
        $thailandMarket = Market::query()->where('code', 'th')->firstOrFail();

        $this->assertSame('Sri Lanka', $sriLankaMarket->name);
        $this->assertTrue($sriLankaMarket->is_active);
        $this->assertTrue($malaysiaMarket->is_active);
        $this->assertFalse($thailandMarket->is_active);

        $this->assertSame('LK', $sriLankaMarket->country->iso_code);
        $this->assertSame('LKR', $sriLankaMarket->currency->iso_code);
        $this->assertSame('MY', $malaysiaMarket->country->iso_code);
        $this->assertSame('MYR', $malaysiaMarket->currency->iso_code);
        $this->assertSame('TH', $thailandMarket->country->iso_code);
        $this->assertSame('THB', $thailandMarket->currency->iso_code);
    }

    public function test_market_belongs_to_country_and_currency(): void
    {
        $market = Market::query()->where('code', 'lk')->firstOrFail();

        $this->assertInstanceOf(Country::class, $market->country);
        $this->assertInstanceOf(Currency::class, $market->currency);
        $this->assertSame('LK', $market->country->iso_code);
        $this->assertSame('LKR', $market->currency->iso_code);
    }

    public function test_product_belongs_to_market(): void
    {
        $market = Market::query()->where('code', 'lk')->firstOrFail();
        $supplier = $this->makeSupplier('Supplier Co');
        $category = $this->makeCategory();

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'market_id' => $market->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'status' => ProductStatus::DRAFT,
        ]);

        $product->refresh();

        $this->assertInstanceOf(Market::class, $product->market);
        $this->assertSame($market->id, $product->market->id);
    }

    public function test_company_resolves_operation_market_and_home_country(): void
    {
        $market = Market::query()->where('code', 'lk')->firstOrFail();
        $country = Country::query()->where('iso_code', 'LK')->firstOrFail();

        $supplierCompany = $this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $market->id,
        ]);

        $resellerCompany = $this->makeCompany(PortalCode::RESELLER, [
            'home_country_id' => $country->id,
        ]);

        $supplierCompany->refresh();
        $resellerCompany->refresh();

        $this->assertSame($market->id, $supplierCompany->operationMarket->id);
        $this->assertSame($country->id, $resellerCompany->homeCountry->id);
    }

    public function test_company_resolves_allowed_markets(): void
    {
        $market = Market::query()->where('code', 'lk')->firstOrFail();
        $resellerCompany = $this->makeCompany(PortalCode::RESELLER);

        ResellerMarketAccess::query()->create([
            'company_id' => $resellerCompany->id,
            'market_id' => $market->id,
        ]);

        $resellerCompany->refresh();

        $this->assertCount(1, $resellerCompany->allowedMarkets);
        $this->assertSame('lk', $resellerCompany->allowedMarkets->first()->code);
    }

    public function test_backfill_assigns_sri_lanka_data_to_existing_records(): void
    {
        $sriLankaMarket = Market::query()->where('code', 'lk')->firstOrFail();
        $sriLankaCountry = Country::query()->where('iso_code', 'LK')->firstOrFail();

        $supplierCompany = $this->makeCompany(PortalCode::SUPPLIER);
        $resellerCompany = $this->makeCompany(PortalCode::RESELLER);
        $supplier = $this->makeSupplierUser($supplierCompany);
        $category = $this->makeCategory();

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => 'Legacy Product',
            'slug' => 'legacy-product',
            'status' => ProductStatus::ACTIVE,
        ]);

        SriLankaMarketBackfill::run();

        $supplierCompany->refresh();
        $resellerCompany->refresh();
        $product->refresh();

        $this->assertSame($sriLankaMarket->id, $supplierCompany->operation_market_id);
        $this->assertSame($sriLankaCountry->id, $resellerCompany->home_country_id);
        $this->assertSame($sriLankaMarket->id, $product->market_id);

        $this->assertDatabaseHas('reseller_market_access', [
            'company_id' => $resellerCompany->id,
            'market_id' => $sriLankaMarket->id,
        ]);
    }

    public function test_backfill_is_idempotent(): void
    {
        $resellerCompany = $this->makeCompany(PortalCode::RESELLER);

        SriLankaMarketBackfill::run();
        SriLankaMarketBackfill::run();

        $this->assertSame(
            1,
            ResellerMarketAccess::query()
                ->where('company_id', $resellerCompany->id)
                ->count()
        );
    }

    public function test_seeders_are_idempotent_when_rerun(): void
    {
        $this->seed(CountrySeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(MarketSeeder::class);

        $this->assertDatabaseCount('countries', 3);
        $this->assertDatabaseCount('currencies', 3);
        $this->assertDatabaseCount('markets', 3);
    }

    public function test_product_can_still_be_created_without_market_id(): void
    {
        $supplier = $this->makeSupplier('Regression Supplier');
        $category = $this->makeCategory();

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => 'Regression Product',
            'slug' => 'regression-product',
            'status' => ProductStatus::DRAFT,
        ]);

        $this->assertNull($product->market_id);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Regression Product',
        ]);
    }

    private function makePortal(PortalCode $code): Portal
    {
        return Portal::query()->firstOrCreate(
            ['code' => $code->value],
            [
                'uuid' => UuidService::generate(),
                'name' => $code->value.' Portal',
                'subdomain' => Str::lower($code->value).'-'.Str::lower(Str::random(4)),
                'description' => $code->value.' Portal',
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCompany(PortalCode $portalCode, array $attributes = []): Company
    {
        $portal = $this->makePortal($portalCode);

        return Company::query()->create(array_merge([
            'uuid' => UuidService::generate(),
            'portal_id' => $portal->id,
            'name' => $portalCode->value.' Company',
            'email' => Str::lower($portalCode->value).'-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '077'.random_int(1000000, 9999999),
            'status' => CompanyStatus::ACTIVE->value,
        ], $attributes));
    }

    private function makeSupplierUser(Company $company): User
    {
        $user = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $company->id,
            'email' => 'supplier-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '077'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::OWNER->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);

        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user;
    }

    private function makeSupplier(string $companyName): User
    {
        $company = $this->makeCompany(PortalCode::SUPPLIER, [
            'name' => $companyName,
        ]);

        return $this->makeSupplierUser($company);
    }

    private function makeCategory(): ProductCategory
    {
        return ProductCategory::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'General',
            'slug' => 'general-'.Str::lower(Str::random(6)),
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }
}
