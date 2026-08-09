<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Feeder\Core\Services\UuidService;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $portals = Portal::pluck('id', 'code');

        $roles = [

            // Admin Portal
            [
                'portal' => 'ADMIN',
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'description' => 'Full system access.',
            ],
            [
                'portal' => 'ADMIN',
                'name' => 'Admin Manager',
                'slug' => 'manager',
                'description' => 'Administrative management.',
            ],

            // Reseller Portal
            [
                'portal' => 'RESELLER',
                'name' => 'Owner',
                'slug' => 'owner',
                'description' => 'Company owner.',
            ],
            [
                'portal' => 'RESELLER',
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Company manager.',
            ],
            [
                'portal' => 'RESELLER',
                'name' => 'Staff',
                'slug' => 'staff',
                'description' => 'Company staff.',
            ],

            // Supplier Portal
            [
                'portal' => 'SUPPLIER',
                'name' => 'Owner',
                'slug' => 'owner',
                'description' => 'Company owner.',
            ],
            [
                'portal' => 'SUPPLIER',
                'name' => 'Manager',
                'slug' => 'manager',
                'description' => 'Company manager.',
            ],
            [
                'portal' => 'SUPPLIER',
                'name' => 'Staff',
                'slug' => 'staff',
                'description' => 'Company staff.',
            ],

        ];

        foreach ($roles as $role) {

            Role::updateOrCreate(
                [
                    'portal_id' => $portals[$role['portal']],
                    'slug' => $role['slug'],
                ],
                [
                    'uuid' => UuidService::generate(),
                    'company_id' => null,
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_system' => true,
                ]
            );
        }
    }
}
