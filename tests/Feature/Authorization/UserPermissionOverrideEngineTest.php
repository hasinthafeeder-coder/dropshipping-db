<?php

namespace Tests\Feature\Authorization;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Feeder\Core\Authorization\Services\UserPermissionService;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserPermissionOverrideEngineTest extends TestCase
{
    private UserPermissionService $userPermissions;

    private Role $ownerRole;

    private Role $agentRole;

    private Permission $productsView;

    private Permission $teamStructureView;

    private Permission $dashboardView;

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
            'cache.default' => 'array',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::beginTransaction();
        Cache::flush();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->userPermissions = app(UserPermissionService::class);

        $this->ownerRole = $this->resellerRole('owner');
        $this->agentRole = $this->resellerRole('call-center-agent');

        $this->productsView = $this->resellerPermission('products.view');
        $this->teamStructureView = $this->resellerPermission('team.structure.view');
        $this->dashboardView = $this->resellerPermission('dashboard.view');

        $this->assertTrue(
            $this->ownerRole->permissions()->where('permissions.id', $this->productsView->id)->exists(),
            'RESELLER owner must include products.view for inheritance tests.'
        );
        $this->assertFalse(
            $this->agentRole->permissions()->where('permissions.id', $this->productsView->id)->exists(),
            'RESELLER call-center-agent must not include products.view for explicit-grant tests.'
        );
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        Cache::flush();

        parent::tearDown();
    }

    public function test_grant_creates_allowed_true_override(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->userPermissions->grant($user, $this->productsView->id);

        $this->assertOverrideState($user, $this->productsView->id, true);
        $this->assertSame(1, $this->overrideCount($user, $this->productsView->id));
    }

    public function test_deny_creates_allowed_false_override(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);

        $this->assertOverrideState($user, $this->productsView->id, false);
        $this->assertSame(1, $this->overrideCount($user, $this->productsView->id));
    }

    public function test_revoke_removes_override(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->userPermissions->revoke($user, $this->productsView->id);

        $this->assertSame(0, $this->overrideCount($user, $this->productsView->id));
    }

    public function test_inherited_role_permission_works_without_override(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->assertTrue($user->hasPermission('products.view'));
        $this->assertSame(0, $this->overrideCount($user, $this->productsView->id));
    }

    public function test_explicit_grant_works_when_role_lacks_permission(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->assertFalse($user->hasPermission('products.view'));

        $this->userPermissions->grant($user, $this->productsView->id);

        $this->assertTrue($user->fresh()->hasPermission('products.view'));
        $this->assertOverrideState($user, $this->productsView->id, true);
    }

    public function test_explicit_deny_overrides_role_grant(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->assertTrue($user->hasPermission('products.view'));

        $this->userPermissions->deny($user, $this->productsView->id);

        $this->assertFalse($user->fresh()->hasPermission('products.view'));
        $this->assertOverrideState($user, $this->productsView->id, false);
    }

    public function test_revoke_restores_role_inheritance_after_deny(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->assertFalse($user->fresh()->hasPermission('products.view'));

        $this->userPermissions->revoke($user, $this->productsView->id);

        $this->assertTrue($user->fresh()->hasPermission('products.view'));
        $this->assertSame(0, $this->overrideCount($user, $this->productsView->id));
    }

    public function test_grant_to_deny_transition_updates_same_pivot_row(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->userPermissions->grant($user, $this->productsView->id);
        $this->assertOverrideState($user, $this->productsView->id, true);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->assertOverrideState($user, $this->productsView->id, false);
        $this->assertSame(1, $this->overrideCount($user, $this->productsView->id));
        $this->assertFalse($user->fresh()->hasPermission('products.view'));
    }

    public function test_deny_to_grant_transition_updates_same_pivot_row(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->assertFalse($user->fresh()->hasPermission('products.view'));

        $this->userPermissions->grant($user, $this->productsView->id);
        $this->assertOverrideState($user, $this->productsView->id, true);
        $this->assertSame(1, $this->overrideCount($user, $this->productsView->id));
        $this->assertTrue($user->fresh()->hasPermission('products.view'));
    }

    public function test_grant_then_revoke_restores_inheritance(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->grant($user, $this->productsView->id);
        $this->userPermissions->revoke($user, $this->productsView->id);

        $this->assertSame(0, $this->overrideCount($user, $this->productsView->id));
        $this->assertTrue($user->fresh()->hasPermission('products.view'));
    }

    public function test_deny_then_revoke_restores_inheritance(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->userPermissions->revoke($user, $this->productsView->id);

        $this->assertSame(0, $this->overrideCount($user, $this->productsView->id));
        $this->assertTrue($user->fresh()->hasPermission('products.view'));
    }

    public function test_user_a_grant_does_not_affect_user_b(): void
    {
        $userA = $this->createUser($this->agentRole, 'agent-a@override.test');
        $userB = $this->createUser($this->agentRole, 'agent-b@override.test');

        $this->assertFalse($userA->hasPermission('products.view'));
        $this->assertFalse($userB->hasPermission('products.view'));

        $this->userPermissions->grant($userA, $this->productsView->id);

        $this->assertTrue($userA->fresh()->hasPermission('products.view'));
        $this->assertFalse($userB->fresh()->hasPermission('products.view'));
        $this->assertSame(0, $this->overrideCount($userB, $this->productsView->id));
    }

    public function test_user_a_deny_does_not_affect_user_b(): void
    {
        $userA = $this->createUser($this->ownerRole, 'owner-a@override.test');
        $userB = $this->createUser($this->ownerRole, 'owner-b@override.test');

        $this->assertTrue($userA->hasPermission('products.view'));
        $this->assertTrue($userB->hasPermission('products.view'));

        $this->userPermissions->deny($userA, $this->productsView->id);

        $this->assertFalse($userA->fresh()->hasPermission('products.view'));
        $this->assertTrue($userB->fresh()->hasPermission('products.view'));
        $this->assertTrue(
            $this->ownerRole->permissions()->where('permissions.id', $this->productsView->id)->exists()
        );
    }

    public function test_user_override_does_not_modify_role_permissions(): void
    {
        $user = $this->createUser($this->ownerRole);
        $rolePermissionCountBefore = $this->ownerRole->permissions()->count();

        $this->userPermissions->grant($user, $this->teamStructureView->id);
        $this->userPermissions->deny($user, $this->productsView->id);

        $this->assertSame($rolePermissionCountBefore, $this->ownerRole->permissions()->count());
        $this->assertTrue(
            $this->ownerRole->permissions()->where('permissions.id', $this->productsView->id)->exists()
        );
        $this->assertFalse(
            $this->agentRole->permissions()->where('permissions.id', $this->productsView->id)->exists()
        );
    }

    public function test_grant_immediately_changes_effective_permission_cache(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->assertFalse($user->hasPermission('products.view'));

        $this->userPermissions->grant($user, $this->productsView->id);

        $this->assertTrue($user->hasPermission('products.view'));
    }

    public function test_deny_immediately_changes_effective_permission_cache(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->assertTrue($user->hasPermission('products.view'));

        $this->userPermissions->deny($user, $this->productsView->id);

        $this->assertFalse($user->hasPermission('products.view'));
    }

    public function test_revoke_immediately_restores_inherited_permission_cache(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->assertFalse($user->hasPermission('products.view'));

        $this->userPermissions->revoke($user, $this->productsView->id);

        $this->assertTrue($user->hasPermission('products.view'));
    }

    public function test_sync_allowed_invalidates_cache_and_preserves_denies(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->userPermissions->deny($user, $this->dashboardView->id);
        $this->assertFalse($user->hasPermission('dashboard.view'));

        $this->userPermissions->syncAllowed($user, [
            $this->productsView->id,
            $this->teamStructureView->id,
        ]);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasPermission('products.view'));
        $this->assertTrue($fresh->hasPermission('team.structure.view'));
        $this->assertOverrideState($user, $this->dashboardView->id, false);
        $this->assertFalse($fresh->hasPermission('dashboard.view'));
    }

    public function test_sync_removes_allowed_overrides_not_in_set(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->userPermissions->grant($user, $this->productsView->id);
        $this->userPermissions->grant($user, $this->teamStructureView->id);

        $this->userPermissions->sync($user, [$this->productsView->id]);

        $this->assertOverrideState($user, $this->productsView->id, true);
        $this->assertSame(0, $this->overrideCount($user, $this->teamStructureView->id));
        $this->assertTrue($user->fresh()->hasPermission('products.view'));
        $this->assertFalse($user->fresh()->hasPermission('team.structure.view'));
    }

    public function test_sync_overrides_supports_allow_and_deny_map(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->syncOverrides($user, [
            $this->productsView->id => false,
            $this->teamStructureView->id => true,
        ]);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasPermission('products.view'));
        $this->assertTrue($fresh->hasPermission('team.structure.view'));
        $this->assertOverrideState($user, $this->productsView->id, false);
        $this->assertOverrideState($user, $this->teamStructureView->id, true);
    }

    public function test_repeated_grant_does_not_create_duplicate_pivot_rows(): void
    {
        $user = $this->createUser($this->agentRole);

        $this->userPermissions->grant($user, $this->productsView->id);
        $this->userPermissions->grant($user, $this->productsView->id);
        $this->userPermissions->grant($user, $this->productsView->id);

        $this->assertSame(1, $this->overrideCount($user, $this->productsView->id));
        $this->assertOverrideState($user, $this->productsView->id, true);
    }

    public function test_repeated_deny_does_not_create_duplicate_pivot_rows(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->userPermissions->deny($user, $this->productsView->id);
        $this->userPermissions->deny($user, $this->productsView->id);
        $this->userPermissions->deny($user, $this->productsView->id);

        $this->assertSame(1, $this->overrideCount($user, $this->productsView->id));
        $this->assertOverrideState($user, $this->productsView->id, false);
    }

    public function test_only_selected_permission_is_affected(): void
    {
        $user = $this->createUser($this->ownerRole);

        $this->assertTrue($user->hasPermission('products.view'));
        $this->assertTrue($user->hasPermission('dashboard.view'));

        $this->userPermissions->deny($user, $this->productsView->id);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasPermission('products.view'));
        $this->assertTrue($fresh->hasPermission('dashboard.view'));
        $this->assertSame(0, $this->overrideCount($user, $this->dashboardView->id));
    }

    public function test_call_center_agent_role_supports_user_level_operational_overrides(): void
    {
        $user = $this->createUser($this->agentRole, 'agent-ops@override.test', UserType::EMPLOYEE);

        $this->assertFalse($user->hasPermission('products.view'));
        $this->assertFalse($user->hasPermission('team.structure.view'));

        $this->userPermissions->grant($user, $this->productsView->id);
        $this->userPermissions->deny($user, $this->teamStructureView->id);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasPermission('products.view'));
        $this->assertFalse($fresh->hasPermission('team.structure.view'));

        foreach ([
            'call_center.agents.view',
            'call_center.agents.create',
            'call_center.agents.update',
            'call_center.agents.activate',
            'call_center.agents.deactivate',
            'call_center.agents.commission.update',
            'call_center.agents.permissions.update',
        ] as $managementSlug) {
            $this->assertFalse(
                $fresh->hasPermission($managementSlug),
                "Agent must not receive management permission {$managementSlug}."
            );
            $this->assertFalse(
                $this->agentRole->permissions()->where('permissions.slug', $managementSlug)->exists()
            );
        }
    }

    private function createUser(
        Role $role,
        ?string $email = null,
        UserType $userType = UserType::EMPLOYEE
    ): User {
        $email ??= 'user-'.Str::uuid().'@override.test';

        $user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => null,
            'role_id' => $role->id,
            'email' => $email,
            'phone' => null,
            'password' => Hash::make('password'),
            'user_type' => $userType->value,
            'status' => UserStatus::ACTIVE->value,
            'is_master_reseller' => false,
        ]);

        return $user->fresh(['role']);
    }

    private function assertOverrideState(User $user, int $permissionId, bool $allowed): void
    {
        $row = DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission_id', $permissionId)
            ->first();

        $this->assertNotNull($row, 'Expected user_permissions override row.');
        $this->assertSame($allowed, (bool) $row->allowed);
    }

    private function overrideCount(User $user, int $permissionId): int
    {
        return (int) DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission_id', $permissionId)
            ->count();
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

    private function resellerPermission(string $slug): Permission
    {
        $permission = Permission::query()
            ->where('slug', $slug)
            ->whereHas('portal', fn ($query) => $query->where('code', 'RESELLER'))
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($permission, "Missing RESELLER permission: {$slug}");

        return $permission;
    }
}
