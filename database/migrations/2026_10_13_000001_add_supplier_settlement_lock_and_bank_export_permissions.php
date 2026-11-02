<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin supplier settlement permissions (read + lock + bank-export prepare).
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $permissions = [
        ['View Supplier Settlements', 'settlements.suppliers.view'],
        ['Lock Supplier Settlement Batches', 'settlements.suppliers.lock'],
        ['Prepare Supplier Settlement Bank Exports', 'settlements.suppliers.bank_exports.prepare'],
    ];

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'ADMIN')->value('id');

        if (! $portalId) {
            return;
        }

        $now = now();

        $maxSort = (int) DB::table('permissions')
            ->where('portal_id', $portalId)
            ->max('sort_order');

        $permissionIdsBySlug = [];

        foreach ($this->permissions as [$name, $slug]) {
            $existingId = DB::table('permissions')
                ->where('portal_id', $portalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->value('id');

            if ($existingId) {
                $permissionIdsBySlug[$slug] = (int) $existingId;

                continue;
            }

            $maxSort += 10;

            $permissionIdsBySlug[$slug] = (int) DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Settlements',
                'group' => 'Supplier Settlement',
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'sort_order' => $maxSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleIds = DB::table('roles')
            ->where('portal_id', $portalId)
            ->whereIn('slug', ['super-admin', 'manager'])
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIdsBySlug as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Backwards compatibility: roles that previously reached settlements via orders.view.
        $viewPermissionId = $permissionIdsBySlug['settlements.suppliers.view'] ?? null;
        $ordersViewPermissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'orders.view')
            ->whereNull('deleted_at')
            ->value('id');

        if ($viewPermissionId && $ordersViewPermissionId) {
            $ordersViewRoleIds = DB::table('role_permissions')
                ->where('permission_id', $ordersViewPermissionId)
                ->pluck('role_id');

            foreach ($ordersViewRoleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $viewPermissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $portalId = DB::table('portals')->where('code', 'ADMIN')->value('id');

        if (! $portalId) {
            return;
        }

        $slugs = array_column($this->permissions, 1);

        $permissionIds = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->whereIn('slug', $slugs)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
