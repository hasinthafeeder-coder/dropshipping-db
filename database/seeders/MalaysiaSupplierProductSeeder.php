<?php

namespace Database\Seeders;

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
use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;
use Feeder\Core\Models\UserProfile;
use Feeder\Core\Services\ProductService;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MalaysiaSupplierProductSeeder extends Seeder
{
    /**
     * @var list<array{company: string, first: string, last: string, product: string, selling: float, cost: float}>
     */
    private const SUPPLIERS = [
        ['company' => 'Batu Bintang Trading', 'first' => 'Ahmad', 'last' => 'Rahman', 'product' => 'Wireless Bluetooth Earbuds', 'cost' => 18.50, 'selling' => 39.90],
        ['company' => 'Selangor Home Essentials', 'first' => 'Siti', 'last' => 'Aminah', 'product' => 'Non-Stick Frying Pan 28cm', 'cost' => 22.00, 'selling' => 49.90],
        ['company' => 'Penang Gadget Hub', 'first' => 'Lim', 'last' => 'Wei Jie', 'product' => 'USB-C Fast Charging Cable 2m', 'cost' => 6.50, 'selling' => 15.90],
        ['company' => 'Johor Fashion Wholesale', 'first' => 'Nurul', 'last' => 'Huda', 'product' => 'Cotton Crew Neck T-Shirt', 'cost' => 12.00, 'selling' => 29.90],
        ['company' => 'Sabah Outdoor Gear', 'first' => 'Daniel', 'last' => 'Moguring', 'product' => 'Foldable Camping Chair', 'cost' => 35.00, 'selling' => 79.90],
        ['company' => 'KL Office Supplies', 'first' => 'Raj', 'last' => 'Kumar', 'product' => 'Ergonomic Office Mouse', 'cost' => 14.50, 'selling' => 32.50],
        ['company' => 'Melaka Kitchen Mart', 'first' => 'Farah', 'last' => 'Izzati', 'product' => 'Stainless Steel Knife Set', 'cost' => 28.00, 'selling' => 59.90],
        ['company' => 'Perak Beauty Distributors', 'first' => 'Chong', 'last' => 'Mei Ling', 'product' => 'Vitamin C Face Serum 30ml', 'cost' => 16.00, 'selling' => 34.90],
        ['company' => 'Terengganu Sports Co', 'first' => 'Hafiz', 'last' => 'Rosli', 'product' => 'Yoga Mat with Carry Strap', 'cost' => 19.00, 'selling' => 42.00],
        ['company' => 'Negeri Sembilan Traders', 'first' => 'Priya', 'last' => 'Devi', 'product' => 'LED Desk Lamp with USB Port', 'cost' => 24.00, 'selling' => 54.90],
    ];

    public function run(): void
    {
        $this->ensurePrerequisites();

        $portal = Portal::query()
            ->where('code', PortalCode::SUPPLIER->value)
            ->firstOrFail();

        $ownerRoleId = Role::query()
            ->where('slug', 'owner')
            ->whereHas('portal', fn ($query) => $query->where('code', PortalCode::SUPPLIER->value))
            ->value('id');

        if ($ownerRoleId === null) {
            $this->call(RoleSeeder::class);

            $ownerRoleId = Role::query()
                ->where('slug', 'owner')
                ->whereHas('portal', fn ($query) => $query->where('code', PortalCode::SUPPLIER->value))
                ->value('id');
        }

        $malaysiaMarket = Market::query()->where('code', 'my')->firstOrFail();
        $category = ProductCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        if ($category === null) {
            $this->call(ProductCategorySeeder::class);
            $category = ProductCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->firstOrFail();
        }

        $productService = app(ProductService::class);
        $defaultCommission = '15.00';

        foreach (self::SUPPLIERS as $index => $supplierData) {
            $sequence = $index + 1;
            $phone = sprintf('0123456%03d', $sequence);
            $email = sprintf('my-supplier%02d@feeder.local', $sequence);
            $mykad = sprintf('900101%06d', $sequence);

            $company = Company::query()->firstOrCreate(
                ['email' => $email],
                [
                    'uuid' => UuidService::generate(),
                    'portal_id' => $portal->id,
                    'name' => $supplierData['company'],
                    'email' => $email,
                    'phone' => $phone,
                    'registration_number' => 'MY-REG-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'tax_number' => 'MY-TAX-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
                    'status' => CompanyStatus::ACTIVE->value,
                    'operation_market_id' => $malaysiaMarket->id,
                    'approved_at' => now(),
                ]
            );

            $company->forceFill([
                'name' => $supplierData['company'],
                'operation_market_id' => $malaysiaMarket->id,
                'status' => CompanyStatus::ACTIVE->value,
            ])->save();

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'uuid' => UuidService::generate(),
                    'company_id' => $company->id,
                    'role_id' => $ownerRoleId,
                    'phone' => $phone,
                    'password' => Hash::make('password'),
                    'user_type' => UserType::OWNER->value,
                    'status' => UserStatus::ACTIVE->value,
                    'phone_verified_at' => now(),
                ]
            );

            if ($ownerRoleId !== null && $user->role_id === null) {
                $user->forceFill(['role_id' => $ownerRoleId])->save();
            }

            $company->forceFill(['owner_user_id' => $user->id])->save();
            $user->forceFill(['company_id' => $company->id])->save();

            $profile = UserProfile::query()->firstOrNew(['user_id' => $user->id]);

            if (! $profile->exists) {
                $profile->uuid = UuidService::generate();
            }

            $profile->fill([
                'first_name' => $supplierData['first'],
                'last_name' => $supplierData['last'],
                'nic' => $mykad,
            ])->save();

            if (Product::query()->where('supplier_id', $user->id)->exists()) {
                continue;
            }

            $productService->createProduct(
                [
                    'supplier_id' => $user->id,
                    'category_id' => $category->id,
                    'name' => $supplierData['product'],
                    'status' => ProductStatus::ACTIVE,
                    'system_visible' => true,
                    'web_visible' => true,
                    'price_locked' => false,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ],
                [
                    ['language_code' => 'en', 'description' => $supplierData['product'].' — Malaysia market listing.'],
                    ['language_code' => 'ms', 'description' => null],
                    ['language_code' => 'ta', 'description' => null],
                ],
                [
                    [
                        'name' => 'Standard',
                        'barcode' => sprintf('MY-%04d-STD', $sequence),
                        'cost' => $supplierData['cost'],
                        'selling_price' => $supplierData['selling'],
                        'suggested_price' => round($supplierData['selling'] * 1.15, 2),
                        'weight' => 0.350,
                        'company_commission' => $defaultCommission,
                        'sort_order' => 0,
                        'is_active' => true,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ],
                ],
            );
        }
    }

    private function ensurePrerequisites(): void
    {
        $this->call([
            CountrySeeder::class,
            CurrencySeeder::class,
            MarketSeeder::class,
            MarketDefaultCompanyCommissionSeeder::class,
        ]);

        $supplierPortalExists = Portal::query()
            ->where('code', PortalCode::SUPPLIER->value)
            ->exists();

        if (! $supplierPortalExists) {
            $this->call([
                PortalSeeder::class,
                RoleSeeder::class,
                PermissionSeeder::class,
                RolePermissionSeeder::class,
            ]);
        }
    }
}
