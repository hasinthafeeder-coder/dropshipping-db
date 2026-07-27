<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PortalSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('portals')->insert(
            [
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'ADMIN',
                    'name' => 'Administration',
                    'subdomain' => 'admin',
                    'description' => 'Admin portal',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'SUPPLIER',
                    'name' => 'Supplier Portal',
                    'subdomain' => 'supplier',
                    'description' => 'Supplier portal',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'code' => 'RESELLER',
                    'name' => 'Reseller Portal',
                    'subdomain' => 'reseller',
                    'description' => 'Reseller portal',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]
        );
    }
}
