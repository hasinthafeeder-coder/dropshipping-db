<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reseller portal permission to view the notification inbox and dashboard banners.
 *
 * This migration does not insert notification records.
 */
return new class extends Migration
{
    private string $slug = 'notifications.view';

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'RESELLER')->value('id');

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
                'module' => 'Notifications',
                'group' => 'Inbox',
                'name' => 'View Notifications',
                'slug' => $this->slug,
                'description' => null,
                'sort_order' => $maxSort + 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $ownerRoleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'owner')
            ->whereNull('deleted_at')
            ->value('id');

        if ($ownerRoleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ownerRoleId,
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

        $permissionIds = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', $this->slug)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
