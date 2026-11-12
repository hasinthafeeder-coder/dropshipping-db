<?php

namespace Tests\Feature\Authorization;

use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingPermissionFoundationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $adminSlugs = [
        'meetings.view',
        'meetings.manage',
        'meetings.check_in',
        'meetings.excuses.manage',
        'meetings.reports.view',
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

        $this->assertNotNull(Portal::where('code', 'ADMIN')->value('id'));
        $this->assertNotNull(Portal::where('code', 'RESELLER')->value('id'));

        $migration = require database_path('migrations/2026_11_10_150001_add_reseller_meeting_permissions.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_admin_meeting_permissions_exist_and_are_assigned(): void
    {
        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        foreach ($this->adminSlugs as $slug) {
            $permission = Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($permission, $slug.' missing for ADMIN');

            $role = Role::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', 'super-admin')
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($role);
            $this->assertTrue(
                DB::table('role_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permission->id)
                    ->exists(),
                $slug.' not assigned to ADMIN super-admin'
            );
        }
    }

    public function test_reseller_meeting_view_permission_exists_and_is_assigned(): void
    {
        $resellerPortalId = (int) Portal::where('code', 'RESELLER')->value('id');

        $permission = Permission::query()
            ->where('portal_id', $resellerPortalId)
            ->where('slug', 'meetings.view')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($permission);

        $role = Role::query()
            ->where('portal_id', $resellerPortalId)
            ->where('slug', 'owner')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($role);
        $this->assertTrue(
            DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->exists()
        );
    }
}
