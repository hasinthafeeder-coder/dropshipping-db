<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // CountrySeeder::class,
            // CurrencySeeder::class,
            // MarketSeeder::class,
            // MarketDefaultCompanyCommissionSeeder::class,
            // PortalSeeder::class,
            // RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            // SupplierAccountSeeder::class,
            // SriLankaMarketBackfillSeeder::class,
            // ProductCategorySeeder::class,
        ]);
    }
}
