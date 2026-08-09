<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Super Admin
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions(
            'ADMIN',
            'super-admin',
            Permission::whereHas('portal', fn($q) => $q->where('code', 'ADMIN'))
                ->pluck('id')
                ->toArray()
        );

        /*
        |--------------------------------------------------------------------------
        | Admin Manager
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions('ADMIN', 'manager', [
            'dashboard.view',

            'resellers.view',
            'resellers.approve',
            'resellers.reject',
            'resellers.suspend',

            'suppliers.view',
            'suppliers.approve',
            'suppliers.reject',
            'suppliers.suspend',

            'companies.view',
            'companies.approve',
            'companies.reject',
            'companies.suspend',

            'roles.view',

            'permissions.view',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reseller Owner
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions('RESELLER', 'owner', [
            'dashboard.view',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Supplier Owner
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions('SUPPLIER', 'owner', [
            'dashboard.view',
        ]);
    }

    protected function syncPermissions(
        string $portalCode,
        string $roleSlug,
        array $permissions
    ): void {

        $role = Role::where('slug', $roleSlug)
            ->whereHas('portal', fn($q) => $q->where('code', $portalCode))
            ->first();

        if (!$role) {
            return;
        }

        if (is_numeric(reset($permissions))) {

            $role->permissions()->sync($permissions);

            return;
        }

        $permissionIds = Permission::whereIn('slug', $permissions)
            ->whereHas('portal', fn($q) => $q->where('code', $portalCode))
            ->pluck('id')
            ->toArray();

        $role->permissions()->sync($permissionIds);
    }
}
