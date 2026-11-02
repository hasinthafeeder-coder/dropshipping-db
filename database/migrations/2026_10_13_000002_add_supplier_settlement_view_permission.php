<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dedicated Admin supplier settlement read permission for environments that
 * already applied 2026_10_13_000001 before settlements.suppliers.view existed.
 *
 * Idempotent: safe when 000001 already created and assigned this permission.
 */
return new class extends Migration
{
    private string $slug = 'settlements.suppliers.view';

    private string $name = 'View Supplier Settlements';

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'ADMIN')->value('id');

        if (! $portalId) {
            return;
        }

        $now = now();

        $existingId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', $this->slug)
            ->whereNull('deleted_at')
            ->value('id');

        if ($existingId) {
            $permissionId = (int) $existingId;
        } else {
            $maxSort = (int) DB::table('permissions')
                ->where('portal_id', $portalId)
                ->max('sort_order');

            $permissionId = (int) DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Settlements',
                'group' => 'Supplier Settlement',
                'name' => $this->name,
                'slug' => $this->slug,
                'description' => null,
                'sort_order' => $maxSort + 10,
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
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Backwards compatibility: roles that previously reached settlements via orders.view.
        $ordersViewPermissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'orders.view')
            ->whereNull('deleted_at')
            ->value('id');

        if ($ordersViewPermissionId) {
            $ordersViewRoleIds = DB::table('role_permissions')
                ->where('permission_id', $ordersViewPermissionId)
                ->pluck('role_id');

            foreach ($ordersViewRoleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
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

        $permissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', $this->slug)
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
