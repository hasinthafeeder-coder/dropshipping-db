<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 4 meeting attendance permissions.
 *
 * Admin manages meetings, check-in, excuses, and reports.
 * Reseller views their own meeting QR / attendance status only.
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private array $permissions = [
        ['ADMIN', 'Meetings', 'Attendance', 'View Meetings', 'meetings.view'],
        ['ADMIN', 'Meetings', 'Attendance', 'Manage Meetings', 'meetings.manage'],
        ['ADMIN', 'Meetings', 'Attendance', 'Check In Attendance', 'meetings.check_in'],
        ['ADMIN', 'Meetings', 'Attendance', 'Manage Meeting Excuses', 'meetings.excuses.manage'],
        ['ADMIN', 'Meetings', 'Attendance', 'View Meeting Reports', 'meetings.reports.view'],
        ['RESELLER', 'Meetings', 'Attendance', 'View Meetings', 'meetings.view'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $roleSlugsByPortal = [
        'ADMIN' => ['super-admin', 'manager'],
        'RESELLER' => ['owner'],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->permissions as [$portalCode, $module, $group, $name, $slug]) {
            $portalId = DB::table('portals')->where('code', $portalCode)->value('id');

            if (! $portalId) {
                continue;
            }

            $existingId = DB::table('permissions')
                ->where('portal_id', $portalId)
                ->where('slug', $slug)
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
                    'module' => $module,
                    'group' => $group,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => null,
                    'sort_order' => $maxSort + 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($this->roleSlugsByPortal[$portalCode] ?? [] as $roleSlug) {
                $roleId = DB::table('roles')
                    ->where('portal_id', $portalId)
                    ->where('slug', $roleSlug)
                    ->whereNull('deleted_at')
                    ->value('id');

                if (! $roleId) {
                    continue;
                }

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
        foreach (['ADMIN', 'RESELLER'] as $portalCode) {
            $portalId = DB::table('portals')->where('code', $portalCode)->value('id');

            if (! $portalId) {
                continue;
            }

            $slugs = [];
            foreach ($this->permissions as [$code, , , , $slug]) {
                if ($code === $portalCode) {
                    $slugs[] = $slug;
                }
            }

            $permissionIds = DB::table('permissions')
                ->where('portal_id', $portalId)
                ->whereIn('slug', $slugs)
                ->pluck('id');

            if ($permissionIds->isEmpty()) {
                continue;
            }

            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
