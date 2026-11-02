<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Company expense ledger permissions.
 *
 * View, create, and void are separate. There is no update or delete permission.
 * Managers receive view only. This migration does not insert expense records.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $permissions = [
        ['Finance', 'Expenses', 'View Expenses', 'expenses.view'],
        ['Finance', 'Expenses', 'Create Expenses', 'expenses.create'],
        ['Finance', 'Expenses', 'Void Expenses', 'expenses.void'],
    ];

    /**
     * @var list<string>
     */
    private array $managerSlugs = [
        'expenses.view',
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

        foreach ($this->permissions as [$module, $group, $name, $slug]) {
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
                'module' => $module,
                'group' => $group,
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'sort_order' => $maxSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $superAdminRoleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'super-admin')
            ->whereNull('deleted_at')
            ->value('id');

        if ($superAdminRoleId) {
            foreach ($permissionIdsBySlug as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $superAdminRoleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $managerRoleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'manager')
            ->whereNull('deleted_at')
            ->value('id');

        if ($managerRoleId) {
            foreach ($this->managerSlugs as $slug) {
                $permissionId = $permissionIdsBySlug[$slug] ?? null;

                if ($permissionId === null) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $managerRoleId,
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

        $slugs = array_column($this->permissions, 3);

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
