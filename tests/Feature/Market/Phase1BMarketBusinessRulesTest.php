<?php

namespace Tests\Feature\Market;

use Database\Seeders\CountrySeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\MarketSeeder;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Models\ResellerMarketAccess;
use Feeder\Core\Models\User;
use Feeder\Core\Services\MarketService;
use Feeder\Core\Services\ProductService;
use Feeder\Core\Services\ResellerMarketAccessService;
use Feeder\Core\Services\ResellerSupplierAssignmentService;
use Feeder\Core\Services\SupplierOperationMarketService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpMarketData;
use Tests\TestCase;

class Phase1BMarketBusinessRulesTest extends TestCase
{
    use SetsUpMarketData;

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

        $this->seedMarketLookups();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_supplier_registration_resolves_active_operation_market_from_country(): void
    {
        $service = app(SupplierOperationMarketService::class);
        $company = $this->makeCompany(PortalCode::SUPPLIER);
        $sriLanka = $this->countryByIso('LK');

        $service->assignOnRegistration($company, $sriLanka->uuid);
        $company->save();

        $this->assertSame(
            $this->marketByCode('lk')->id,
            $company->fresh()->operation_market_id
        );
    }

    public function test_inactive_thailand_country_cannot_resolve_operation_market(): void
    {
        $service = app(MarketService::class);
        $thailand = $this->countryByIso('TH');

        $this->expectException(ValidationException::class);

        $service->resolveActiveMarketForCountry($thailand);
    }

    public function test_supplier_operation_market_cannot_be_changed_after_creation(): void
    {
        $company = $this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('lk')->id,
        ]);

        $this->expectException(ValidationException::class);

        $company->update([
            'operation_market_id' => $this->marketByCode('my')->id,
        ]);
    }

    public function test_new_product_receives_supplier_operation_market(): void
    {
        $supplier = $this->makeSupplierUser($this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('my')->id,
        ]));
        $category = $this->makeCategory();

        $product = app(ProductService::class)->createProduct([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => 'Market Product',
            'status' => ProductStatus::DRAFT,
        ]);

        $this->assertSame($this->marketByCode('my')->id, $product->market_id);
    }

    public function test_product_update_ignores_submitted_market_id(): void
    {
        $supplier = $this->makeSupplierUser($this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('lk')->id,
        ]));
        $category = $this->makeCategory();
        $productService = app(ProductService::class);

        $product = $productService->createProduct([
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'name' => 'Locked Market Product',
            'status' => ProductStatus::DRAFT,
        ]);

        $updated = $productService->updateProduct($product, [
            'name' => 'Locked Market Product Updated',
            'market_id' => $this->marketByCode('my')->id,
        ]);

        $this->assertSame($this->marketByCode('lk')->id, $updated->market_id);
    }

    public function test_reseller_market_access_service_grant_and_duplicate_rules(): void
    {
        $service = app(ResellerMarketAccessService::class);
        $resellerCompany = $this->makeCompany(PortalCode::RESELLER);
        $sriLanka = $this->marketByCode('lk');

        $service->grantMarketAccess($resellerCompany, $sriLanka);
        $service->grantMarketAccess($resellerCompany, $sriLanka);

        $this->assertSame(1, ResellerMarketAccess::query()->where('company_id', $resellerCompany->id)->count());
        $this->assertTrue($service->hasMarketAccess($resellerCompany, $sriLanka));
    }

    public function test_non_reseller_company_cannot_receive_market_access(): void
    {
        $service = app(ResellerMarketAccessService::class);
        $supplierCompany = $this->makeCompany(PortalCode::SUPPLIER);

        $this->expectException(ValidationException::class);
        $service->grantMarketAccess($supplierCompany, $this->marketByCode('lk'));
    }

    public function test_inactive_market_cannot_be_granted(): void
    {
        $service = app(ResellerMarketAccessService::class);
        $resellerCompany = $this->makeCompany(PortalCode::RESELLER);

        $this->expectException(ValidationException::class);
        $service->grantMarketAccess($resellerCompany, $this->marketByCode('th'));
    }

    public function test_last_allowed_market_cannot_be_removed(): void
    {
        $service = app(ResellerMarketAccessService::class);
        $resellerCompany = $this->makeCompany(PortalCode::RESELLER);
        $sriLanka = $this->marketByCode('lk');

        $service->syncMarketAccess($resellerCompany, [$sriLanka->id]);

        $this->expectException(ValidationException::class);
        $service->revokeMarketAccess($resellerCompany, $sriLanka);
    }

    public function test_supplier_assignment_requires_matching_market_access(): void
    {
        $assignmentService = app(ResellerSupplierAssignmentService::class);
        $reseller = $this->makeResellerUser($this->makeCompany(PortalCode::RESELLER));
        $this->configureResellerCompany($reseller->company, ['lk']);

        $matchingSupplier = $this->makeSupplierUser($this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('lk')->id,
        ]));
        $foreignSupplier = $this->makeSupplierUser($this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('my')->id,
        ]));

        $assignmentService->assign($reseller, $matchingSupplier->uuid);

        $this->expectException(ValidationException::class);
        $assignmentService->assign($reseller, $foreignSupplier->uuid);
    }

    public function test_bulk_market_access_grant_and_revoke_return_summary(): void
    {
        $service = app(ResellerMarketAccessService::class);
        $market = $this->marketByCode('my');

        $firstCompany = $this->makeCompany(PortalCode::RESELLER);
        $secondCompany = $this->makeCompany(PortalCode::RESELLER);
        $supplierCompany = $this->makeCompany(PortalCode::SUPPLIER);

        $this->configureResellerCompany($firstCompany, ['lk']);
        $this->configureResellerCompany($secondCompany, ['lk', 'my']);

        $grantResult = $service->bulkGrantMarketAccess(
            [$firstCompany->id, $secondCompany->id, $supplierCompany->id],
            $market->id
        );

        $this->assertSame(3, $grantResult->selected);
        $this->assertSame(1, $grantResult->changed);
        $this->assertSame(2, $grantResult->skipped);

        $revokeResult = $service->bulkRevokeMarketAccess([$firstCompany->id], $market->id);

        $this->assertSame(1, $revokeResult->changed);

        $lastMarketResult = $service->bulkRevokeMarketAccess([$firstCompany->id], $this->marketByCode('lk')->id);

        $this->assertSame(0, $lastMarketResult->changed);
        $this->assertSame(1, $lastMarketResult->skipped);
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

    private function makeResellerUser(Company $company): User
    {
        $user = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $company->id,
            'email' => 'reseller-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '078'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::OWNER->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);

        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user;
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
