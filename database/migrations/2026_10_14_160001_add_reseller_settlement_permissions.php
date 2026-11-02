<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dedicated Admin reseller settlement permission family.
 *
 * Independent from settlements.suppliers.* and orders.view.
 * Idempotent for environments that already seeded these permissions.
 *
 * Manager role receives view only (least privilege). Mutation permissions
 * are assigned to super-admin; managers get them only via explicit grant.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $permissions = [
        ['View Reseller Settlements', 'settlements.resellers.view'],
        ['Generate Reseller Settlement Batches', 'settlements.resellers.generate'],
        ['Lock Reseller Settlement Batches', 'settlements.resellers.lock'],
        ['View Reseller Settlement Payments', 'settlements.resellers.payments.view'],
        ['Record Reseller Settlement Payments', 'settlements.resellers.payments.record'],
    ];

    /**
     * Permission slugs granted to the manager role by this migration.
     *
     * @var list<string>
     */
    private array $managerSlugs = [
        'settlements.resellers.view',
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
                'group' => 'Reseller Settlement',
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
