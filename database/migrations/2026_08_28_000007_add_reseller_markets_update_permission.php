<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'ADMIN')->value('id');

        if (! $portalId) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'resellers.markets.update')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $permissionId) {
            $maxSort = DB::table('permissions')
                ->where('portal_id', $portalId)
                ->max('sort_order');

            $permissionId = DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Users',
                'group' => 'Resellers',
                'name' => 'Update Reseller Markets',
                'slug' => 'resellers.markets.update',
                'description' => null,
                'sort_order' => ((int) $maxSort) + 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resellerViewPermissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'resellers.view')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $resellerViewPermissionId) {
            return;
        }

        $roleIds = DB::table('role_permissions')
            ->where('permission_id', $resellerViewPermissionId)
            ->pluck('role_id');

        $now = now();

        foreach ($roleIds as $roleId) {
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
        $permissionId = DB::table('permissions')
            ->where('slug', 'resellers.markets.update')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
