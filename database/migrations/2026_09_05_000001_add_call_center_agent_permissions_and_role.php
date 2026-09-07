<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Call Center Agent management permissions (RESELLER portal).
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $permissions = [
        ['View Call Center Agents', 'call_center.agents.view'],
        ['Create Call Center Agents', 'call_center.agents.create'],
        ['Update Call Center Agents', 'call_center.agents.update'],
        ['Activate Call Center Agents', 'call_center.agents.activate'],
        ['Deactivate Call Center Agents', 'call_center.agents.deactivate'],
        ['Update Call Center Agent Commission', 'call_center.agents.commission.update'],
        ['Update Call Center Agent Permissions', 'call_center.agents.permissions.update'],
    ];

    public function up(): void
    {
        $portalId = DB::table('portals')->where('code', 'RESELLER')->value('id');

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
                $permissionIds[] = $existingId;

                continue;
            }

            $maxSort += 10;

            $permissionIds[] = DB::table('permissions')->insertGetId([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'module' => 'Call Center',
                'group' => 'Agents',
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'sort_order' => $maxSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $agentRoleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'call-center-agent')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $agentRoleId) {
            DB::table('roles')->insert([
                'uuid' => strtoupper(Str::random(10)),
                'portal_id' => $portalId,
                'company_id' => null,
                'name' => 'Call Center Agent',
                'slug' => 'call-center-agent',
                'description' => 'Standard employee role for reseller call center agents.',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $ownerRoleId = DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'owner')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $ownerRoleId) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
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

        $slugs = array_column($this->permissions, 1);

        $permissionIds = DB::table('permissions')
            ->where('portal_id', $portalId)
            ->whereIn('slug', $slugs)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        DB::table('roles')
            ->where('portal_id', $portalId)
            ->where('slug', 'call-center-agent')
            ->delete();
    }
};
