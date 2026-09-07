<?php

namespace Tests\Feature\Authorization;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CallCenterAgentPermissionFoundationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $agentManagementSlugs = [
        'call_center.agents.view',
        'call_center.agents.create',
        'call_center.agents.update',
        'call_center.agents.activate',
        'call_center.agents.deactivate',
        'call_center.agents.commission.update',
        'call_center.agents.permissions.update',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'dropshipping',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => 'admin',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::beginTransaction();

        $this->assertNotNull(
            Portal::where('code', 'RESELLER')->value('id'),
            'RESELLER portal must exist for authorization foundation tests.'
        );

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_call_center_agent_management_permissions_exist_on_reseller_portal(): void
    {
        $resellerPortalId = Portal::where('code', 'RESELLER')->value('id');

        foreach ($this->agentManagementSlugs as $slug) {
            $permission = Permission::query()
                ->where('slug', $slug)
                ->where('portal_id', $resellerPortalId)
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($permission, "Missing RESELLER permission: {$slug}");
            $this->assertSame('Call Center', $permission->module);
            $this->assertSame('Agents', $permission->group);
        }

        $this->assertSame(
            7,
            Permission::query()
                ->where('portal_id', $resellerPortalId)
                ->whereIn('slug', $this->agentManagementSlugs)
                ->whereNull('deleted_at')
                ->count()
        );

        foreach (['ADMIN', 'SUPPLIER'] as $portalCode) {
            $portalId = Portal::where('code', $portalCode)->value('id');

            $this->assertSame(
                0,
                Permission::query()
                    ->where('portal_id', $portalId)
                    ->whereIn('slug', $this->agentManagementSlugs)
                    ->whereNull('deleted_at')
                    ->count(),
                "{$portalCode} must not receive call center agent management permissions."
            );
        }
    }

    public function test_reseller_owner_has_all_call_center_agent_management_permissions(): void
    {
        $owner = $this->resellerRole('owner');
        $ownerSlugs = $owner->permissions()->pluck('slug')->all();

        foreach ($this->agentManagementSlugs as $slug) {
            $this->assertContains($slug, $ownerSlugs);
        }
    }

    public function test_call_center_agent_role_exists_without_management_permissions(): void
    {
        $agentRole = $this->resellerRole('call-center-agent');

        $this->assertSame('Call Center Agent', $agentRole->name);
        $this->assertTrue((bool) $agentRole->is_system);
        $this->assertNull($agentRole->company_id);

        $agentSlugs = $agentRole->permissions()->pluck('slug')->all();

        foreach ($this->agentManagementSlugs as $slug) {
            $this->assertNotContains($slug, $agentSlugs);
        }
    }

    public function test_manager_and_staff_do_not_receive_call_center_agent_management_permissions(): void
    {
        foreach (['manager', 'staff'] as $roleSlug) {
            $role = $this->resellerRole($roleSlug);
            $slugs = $role->permissions()->pluck('slug')->all();

            foreach ($this->agentManagementSlugs as $slug) {
                $this->assertNotContains(
                    $slug,
                    $slugs,
                    "RESELLER {$roleSlug} must not receive {$slug}."
                );
            }
        }
    }

    public function test_permission_role_and_assignment_seeders_are_idempotent(): void
    {
        $resellerPortalId = Portal::where('code', 'RESELLER')->value('id');

        $permissionCountBefore = Permission::query()
            ->where('portal_id', $resellerPortalId)
            ->whereIn('slug', $this->agentManagementSlugs)
            ->whereNull('deleted_at')
            ->count();

        $roleCountBefore = Role::query()
            ->where('portal_id', $resellerPortalId)
            ->where('slug', 'call-center-agent')
            ->whereNull('deleted_at')
            ->count();

        $owner = $this->resellerRole('owner');
        $assignmentCountBefore = DB::table('role_permissions')
            ->where('role_id', $owner->id)
            ->whereIn('permission_id', function ($query) use ($resellerPortalId): void {
                $query->select('id')
                    ->from('permissions')
                    ->where('portal_id', $resellerPortalId)
                    ->whereIn('slug', $this->agentManagementSlugs)
                    ->whereNull('deleted_at');
            })
            ->count();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            $permissionCountBefore,
            Permission::query()
                ->where('portal_id', $resellerPortalId)
                ->whereIn('slug', $this->agentManagementSlugs)
                ->whereNull('deleted_at')
                ->count()
        );

        $this->assertSame(
            $roleCountBefore,
            Role::query()
                ->where('portal_id', $resellerPortalId)
                ->where('slug', 'call-center-agent')
                ->whereNull('deleted_at')
                ->count()
        );

        $this->assertSame(
            $assignmentCountBefore,
            DB::table('role_permissions')
                ->where('role_id', $owner->id)
                ->whereIn('permission_id', function ($query) use ($resellerPortalId): void {
                    $query->select('id')
                        ->from('permissions')
                        ->where('portal_id', $resellerPortalId)
                        ->whereIn('slug', $this->agentManagementSlugs)
                        ->whereNull('deleted_at');
                })
                ->count()
        );

        $this->assertSame(7, $permissionCountBefore);
        $this->assertSame(1, $roleCountBefore);
        $this->assertSame(7, $assignmentCountBefore);
    }

    public function test_migration_upsert_is_idempotent_and_assigns_owner_only(): void
    {
        $migration = require database_path('migrations/2026_09_05_000001_add_call_center_agent_permissions_and_role.php');

        $migration->up();
        $migration->up();

        $resellerPortalId = Portal::where('code', 'RESELLER')->value('id');

        $this->assertSame(
            7,
            Permission::query()
                ->where('portal_id', $resellerPortalId)
                ->whereIn('slug', $this->agentManagementSlugs)
                ->whereNull('deleted_at')
                ->count()
        );

        $this->assertSame(
            1,
            Role::query()
                ->where('portal_id', $resellerPortalId)
                ->where('slug', 'call-center-agent')
                ->whereNull('deleted_at')
                ->count()
        );

        $owner = $this->resellerRole('owner');
        $agentRole = $this->resellerRole('call-center-agent');
        $manager = $this->resellerRole('manager');
        $staff = $this->resellerRole('staff');

        $permissionIds = Permission::query()
            ->where('portal_id', $resellerPortalId)
            ->whereIn('slug', $this->agentManagementSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        $this->assertSame(
            7,
            DB::table('role_permissions')
                ->where('role_id', $owner->id)
                ->whereIn('permission_id', $permissionIds)
                ->count()
        );

        foreach ([$agentRole, $manager, $staff] as $role) {
            $this->assertSame(
                0,
                DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->whereIn('permission_id', $permissionIds)
                    ->count()
            );
        }
    }

    private function resellerRole(string $slug): Role
    {
        $role = Role::query()
            ->where('slug', $slug)
            ->whereHas('portal', fn ($query) => $query->where('code', 'RESELLER'))
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($role, "Missing RESELLER role: {$slug}");

        return $role;
    }
}
