<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Supplier Phase 2A order fulfillment permissions.
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $permissions = [
        ['View Orders', 'orders.view'],
        ['Send Orders to Print', 'orders.send-to-print'],
        ['Print Orders', 'orders.print'],
        ['Send Orders to Packing', 'orders.send-to-packing'],
        ['Pack Orders', 'orders.pack'],
        ['Cancel Orders', 'orders.cancel'],
    ];

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'SUPPLIER')->value('id');

        if (! $portalId) {
            return;
        }

        $now = now();

        $maxSort = (int) DB::table('permissions')
            ->where('portal_id', $portalId)
            ->max('sort_order');

        $permissionIds = [];

        foreach ($this->permissions as [$name, $slug]) {
            $existingId = DB::table('permissions')
                ->where('portal_id', $portalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->value('id');

            if ($existingId) {
                $permissionIds[] = (int) $existingId;

                continue;
            }

            $maxSort += 10;

            $permissionIds[] = (int) DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Orders',
                'group' => 'Orders',
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'sort_order' => $maxSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $roleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'owner')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $roleId) {
            return;
        }

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
        $portalId = DB::table('portals')->where('code', 'SUPPLIER')->value('id');

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
