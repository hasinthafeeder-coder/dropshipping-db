<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'RESELLER')->value('id');

        if (! $portalId) {
            return;
        }

        $existingId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'products.view')
            ->whereNull('deleted_at')
            ->value('id');

        $now = now();

        if ($existingId) {
            $permissionId = $existingId;
        } else {
            $maxSort = (int) DB::table('permissions')
                ->where('portal_id', $portalId)
                ->max('sort_order');

            $permissionId = DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Products',
                'group' => 'Products',
                'name' => 'View Products',
                'slug' => 'products.view',
                'description' => null,
                'sort_order' => $maxSort + 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $ownerRoleId = DB::table('roles')
            ->where('slug', 'owner')
            ->where('portal_id', $portalId)
            ->value('id');

        if (! $ownerRoleId) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $ownerRoleId,
            'permission_id' => $permissionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('slug', 'products.view')
            ->whereIn('portal_id', function ($query): void {
                $query->select('id')
                    ->from('portals')
                    ->where('code', 'RESELLER');
            })
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
