<?php

namespace Tests\Support;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Company;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\ResellerSupplierAssignment;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Order\CallCenterAgentEligibilityService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait SetsUpOrderFoundationData
{
    use SetsUpMarketData;

    protected function makePortal(PortalCode $code): Portal
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
    protected function makeCompany(PortalCode $portalCode, array $attributes = []): Company
    {
        $portal = $this->makePortal($portalCode);

        return Company::query()->create(array_merge([
            'uuid' => UuidService::generate(),
            'portal_id' => $portal->id,
            'name' => $portalCode->value.' Company '.Str::upper(Str::random(4)),
            'email' => Str::lower($portalCode->value).'-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '077'.random_int(1000000, 9999999),
            'status' => CompanyStatus::ACTIVE->value,
        ], $attributes));
    }

    protected function makeSupplierUser(?Company $company = null): User
    {
        $company ??= $this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('lk')->id,
        ]);

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

    protected function makeResellerUser(?Company $company = null, array $marketCodes = ['lk']): User
    {
        $company ??= $this->makeCompany(PortalCode::RESELLER, [
            'home_country_id' => $this->countryByIso('LK')->id,
        ]);

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
        $this->configureResellerCompany($company, $marketCodes);

        return $user->fresh(['company']);
    }

    protected function assignSupplierToReseller(User $reseller, User $supplier, ?User $assignedBy = null): void
    {
        ResellerSupplierAssignment::query()->firstOrCreate([
            'reseller_id' => $reseller->id,
            'supplier_id' => $supplier->id,
        ], [
            'uuid' => (string) Str::uuid(),
            'assigned_by' => $assignedBy?->id ?? $reseller->id,
        ]);
    }

    protected function makeCcaUser(Company $resellerCompany, ?Role $role = null): User
    {
        $role ??= $this->callCenterAgentRole();

        return User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $resellerCompany->id,
            'role_id' => $role->id,
            'email' => 'cca-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '076'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::EMPLOYEE->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);
    }

    protected function callCenterAgentRole(): Role
    {
        $portal = $this->makePortal(PortalCode::RESELLER);

        return Role::query()->firstOrCreate(
            [
                'portal_id' => $portal->id,
                'slug' => CallCenterAgentEligibilityService::ROLE_SLUG,
            ],
            [
                'uuid' => UuidService::generate(),
                'company_id' => null,
                'name' => 'Call Center Agent',
                'description' => 'Standard employee role for reseller call center agents.',
                'is_system' => true,
            ]
        );
    }

    protected function makeCategory(): ProductCategory
    {
        return ProductCategory::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'General',
            'slug' => 'general-'.Str::lower(Str::random(6)),
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{product: Product, variant: ProductVariant}
     */
    protected function makeSupplierProductVariant(User $supplier, array $variantOverrides = [], ?string $marketCode = 'lk'): array
    {
        $category = $this->makeCategory();

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'supplier_id' => $supplier->id,
            'category_id' => $category->id,
            'market_id' => $this->marketByCode($marketCode)->id,
            'name' => 'Product '.Str::upper(Str::random(5)),
            'slug' => 'product-'.Str::lower(Str::random(8)),
            'status' => ProductStatus::ACTIVE->value,
            'system_visible' => true,
            'web_visible' => true,
            'created_by' => $supplier->id,
            'updated_by' => $supplier->id,
        ]);

        $variant = ProductVariant::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'name' => 'Default',
            'barcode' => 'BC'.Str::upper(Str::random(10)),
            'cost' => 100.00,
            'selling_price' => 250.00,
            'weight' => 0.500,
            'company_commission' => 150.00,
            'is_active' => true,
            'created_by' => $supplier->id,
            'updated_by' => $supplier->id,
        ], $variantOverrides));

        return compact('product', 'variant');
    }
}
