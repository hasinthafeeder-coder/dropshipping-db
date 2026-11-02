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
            'resellers.financial.update',
            'resellers.markets.update',
            'resellers.suppliers.assign',
            'referrals.activate',
            'referrals.deactivate',

            'suppliers.view',
            'suppliers.approve',
            'suppliers.reject',
            'suppliers.suspend',
            'suppliers.courier_accounts.view',
            'suppliers.courier_accounts.create',
            'suppliers.courier_accounts.update',
            'suppliers.courier_accounts.activate',
            'suppliers.courier_accounts.test',

            'companies.view',
            'companies.create',
            'companies.update',
            'companies.approve',
            'companies.reject',
            'companies.suspend',

            'roles.view',

            'permissions.view',
            'team.structure.view',
            'product_categories.view',
            'product_categories.create',
            'product_categories.update',
            'product_categories.delete',
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'products.review',
            'products.approve',
            'products.reject',
            'stock.view',
            'settings.view',
            'settings.financial.update',
            'settlements.suppliers.view',
            'settlements.suppliers.generate',
            'settlements.suppliers.lock',
            'settlements.suppliers.bank_exports.prepare',
            'settlements.suppliers.payments.record',

            // Reseller settlement: view only for managers (least privilege).
            // Generate / lock / payment permissions stay super-admin unless explicitly granted.
            'settlements.resellers.view',

            // Introducer bonus: view only. Generation, payment, and the target
            // exception stay with super-admin unless explicitly granted.
            'settlements.introducer_bonus.view',

            // Company expenses: view only. Create and void stay with super-admin
            // unless explicitly granted.
            'expenses.view',

            // Read-only financial overview. No settlement or payment actions.
            'finance.overview.view',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reseller Owner
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions('RESELLER', 'owner', [
            'dashboard.view',
            'team.structure.view',
            'products.view',
            'call_center.agents.view',
            'call_center.agents.create',
            'call_center.agents.update',
            'call_center.agents.activate',
            'call_center.agents.deactivate',
            'call_center.agents.commission.update',
            'call_center.agents.permissions.update',
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.status.update',
            'orders.cca.assign',
            'orders.comments.create',
            'orders.discount.update',
            'orders.shipment.book',
            'customers.view',
            'customers.bans.view',
            'customers.bans.create',
            'customers.bans.lift',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reseller Call Center Agent
        |
        | Operational order access only. Agents may create orders (auto-assigned
        | to self), view company call-center / ongoing / completed lists, and
        | update status / comments on orders assigned to them.
        | Assignment, discount, and shipment booking stay owner-only.
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions('RESELLER', 'call-center-agent', [
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.status.update',
            'orders.comments.create',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Supplier Owner
        |--------------------------------------------------------------------------
        */
        $this->syncPermissions('SUPPLIER', 'owner', [
            'dashboard.view',
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
            'grns.view',
            'grns.create',
            'grns.update',
            'grns.delete',
            'stock.view',
            'orders.view',
            'orders.send-to-print',
            'orders.print',
            'orders.send-to-packing',
            'orders.pack',
            'orders.return',
            'orders.cancel',
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
            app(\Feeder\Core\Authorization\Services\RolePermissionService::class)
                ->sync($role, $permissions);

            return;
        }

        $permissionIds = Permission::whereIn('slug', $permissions)
            ->whereHas('portal', fn($q) => $q->where('code', $portalCode))
            ->pluck('id')
            ->toArray();

        app(\Feeder\Core\Authorization\Services\RolePermissionService::class)
            ->sync($role, $permissionIds);
    }
}
