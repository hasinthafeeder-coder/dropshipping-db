<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Default operational order permissions for the RESELLER call-center-agent role.
 *
 * Agents may view company orders, create orders (auto-assigned to self),
 * and update / comment / status-change orders assigned to them.
 * Assignment, discount, and shipment booking remain owner-only.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $permissionSlugs = [
        'orders.view',
        'orders.create',
        'orders.update',
        'orders.status.update',
        'orders.comments.create',
    ];

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'RESELLER')->value('id');

        if (! $portalId) {
            return;
        }

        $roleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'call-center-agent')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $roleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->whereIn('slug', $this->permissionSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        $now = now();

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $portalId = DB::table('portals')->where('code', 'RESELLER')->value('id');

        if (! $portalId) {
            return;
        }

        $roleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'call-center-agent')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $roleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->whereIn('slug', $this->permissionSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
