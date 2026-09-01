<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $permissions = [
        ['View GRNs', 'grns.view'],
        ['Create GRNs', 'grns.create'],
        ['Update GRNs', 'grns.update'],
        ['Delete GRNs', 'grns.delete'],
    ];

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'SUPPLIER')->value('id');

        if (! $portalId) {
            return;
        }

        $maxSort = (int) DB::table('permissions')
            ->where('portal_id', $portalId)
            ->max('sort_order');

        $productViewPermissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'products.view')
            ->whereNull('deleted_at')
            ->value('id');

        $roleIds = $productViewPermissionId
            ? DB::table('role_permissions')
                ->where('permission_id', $productViewPermissionId)
                ->pluck('role_id')
            : collect();

        $now = now();

        foreach ($this->permissions as [$name, $slug]) {
            $existingId = DB::table('permissions')
                ->where('portal_id', $portalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->value('id');

            if ($existingId) {
                $permissionId = $existingId;
            } else {
                $maxSort += 10;

                $permissionId = DB::table('permissions')->insertGetId([
                    'uuid' => strtoupper(Str::random(10)),
                    'portal_id' => $portalId,
                    'module' => 'Inventory',
                    'group' => 'GRNs',
                    'name' => $name,
                    'slug' => $slug,
                    'description' => null,
                    'sort_order' => $maxSort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($roleIds as $roleId) {
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
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', array_column($this->permissions, 1))
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
