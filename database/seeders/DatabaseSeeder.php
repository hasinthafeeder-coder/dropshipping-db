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
            // PortalSeeder::class,
            // SuperAdminSeeder::class,
            PermissionSeeder::class,
            // RoleSeeder::class,
            RolePermissionSeeder::class,
            // AssignUserRolesSeeder::class,
        ]);
    }
}
