<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Services\UuidService;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $portals = Portal::pluck('id', 'code');

        $permissions = [

            'ADMIN' => [

                [
                    'module' => 'Dashboard',
                    'group' => 'Dashboard',
                    'permissions' => [
                        ['View Dashboard', 'dashboard.view'],
                    ],
                ],

                [
                    'module' => 'Users',
                    'group' => 'Resellers',
                    'permissions' => [
                        ['View Resellers', 'resellers.view'],
                        ['Approve Resellers', 'resellers.approve'],
                        ['Reject Resellers', 'resellers.reject'],
                        ['Suspend Resellers', 'resellers.suspend'],
                    ],
                ],

                [
                    'module' => 'Users',
                    'group' => 'Suppliers',
                    'permissions' => [
                        ['View Suppliers', 'suppliers.view'],
                        ['Approve Suppliers', 'suppliers.approve'],
                        ['Reject Suppliers', 'suppliers.reject'],
                        ['Suspend Suppliers', 'suppliers.suspend'],
                    ],
                ],

                [
                    'module' => 'Companies',
                    'group' => 'Company Management',
                    'permissions' => [
                        ['View Companies', 'companies.view'],
                        ['Approve Companies', 'companies.approve'],
                        ['Reject Companies', 'companies.reject'],
                        ['Suspend Companies', 'companies.suspend'],
                    ],
                ],

                [
                    'module' => 'Roles',
                    'group' => 'Role Management',
                    'permissions' => [
                        ['View Roles', 'roles.view'],
                        ['Create Roles', 'roles.create'],
                        ['Update Roles', 'roles.update'],
                        ['Delete Roles', 'roles.delete'],
                    ],
                ],

                [
                    'module' => 'Permissions',
                    'group' => 'Permission Management',
                    'permissions' => [
                        ['View Permissions', 'permissions.view'],
                        ['Update Permissions', 'permissions.update'],
                    ],
                ],

            ],

            'RESELLER' => [

                [
                    'module' => 'Dashboard',
                    'group' => 'Dashboard',
                    'permissions' => [
                        ['View Dashboard', 'dashboard.view'],
                    ],
                ],

            ],

            'SUPPLIER' => [

                [
                    'module' => 'Dashboard',
                    'group' => 'Dashboard',
                    'permissions' => [
                        ['View Dashboard', 'dashboard.view'],
                    ],
                ],

            ],

        ];

        foreach ($permissions as $portalCode => $modules) {

            $sortOrder = 10;

            foreach ($modules as $module) {

                foreach ($module['permissions'] as [$name, $slug]) {

                    Permission::updateOrCreate(

                        [
                            'portal_id' => $portals[$portalCode],
                            'slug' => $slug,
                        ],

                        [
                            'uuid' => UuidService::generate(),
                            'module' => $module['module'],
                            'group' => $module['group'],
                            'name' => $name,
                            'description' => null,
                            'sort_order' => $sortOrder,
                        ]

                    );

                    $sortOrder += 10;
                }
            }
        }
    }
}
