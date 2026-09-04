<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private array $permissions = [
        ['ADMIN', 'View Stock', 'stock.view'],
        ['SUPPLIER', 'View Stock', 'stock.view'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as [$portalCode, $name, $slug]) {
            $this->seedPermission($portalCode, $name, $slug);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('slug', 'stock.view')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function seedPermission(string $portalCode, string $name, string $slug): void
    {
        $portalId = DB::table('portals')->where('code', $portalCode)->value('id');

        if (! $portalId) {
            return;
        }

        $maxSort = (int) DB::table('permissions')
            ->where('portal_id', $portalId)
            ->max('sort_order');

        $referencePermissionId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', 'products.view')
            ->whereNull('deleted_at')
            ->value('id');

        $roleIds = $referencePermissionId
            ? DB::table('role_permissions')
                ->where('permission_id', $referencePermissionId)
                ->pluck('role_id')
            : collect();

        $existingId = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->value('id');

        $now = now();

        if ($existingId) {
            $permissionId = $existingId;
        } else {
            $permissionId = DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Inventory',
                'group' => 'Stock',
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'sort_order' => $maxSort + 10,
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
};
