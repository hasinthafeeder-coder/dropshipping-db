<?php

namespace Tests\Feature\Authorization;

use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationPermissionFoundationTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $adminSlugs = [
        'notifications.view',
        'notifications.create',
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
            Portal::where('code', 'ADMIN')->value('id'),
            'ADMIN portal must exist for notification permission foundation tests.'
        );
        $this->assertNotNull(
            Portal::where('code', 'SUPPLIER')->value('id'),
            'SUPPLIER portal must exist for notification permission foundation tests.'
        );
        $this->assertNotNull(
            Portal::where('code', 'RESELLER')->value('id'),
            'RESELLER portal must exist for notification permission foundation tests.'
        );

        $foundation = require database_path('migrations/2026_11_10_120001_add_notification_foundation_permissions.php');
        $foundation->up();

        $supplierCreate = require database_path('migrations/2026_11_10_130001_add_supplier_notification_create_permission.php');
        $supplierCreate->up();

        $resellerView = require database_path('migrations/2026_11_10_140000_add_reseller_notification_view_permission.php');
        $resellerView->up();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_admin_notification_permissions_exist_and_are_assigned(): void
    {
        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        foreach ($this->adminSlugs as $slug) {
            $permission = Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($permission, "Missing ADMIN permission: {$slug}");
            $this->assertSame('Notifications', $permission->module);
        }

        foreach (['super-admin', 'manager'] as $roleSlug) {
            $role = Role::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $roleSlug)
                ->whereNull('deleted_at')
                ->firstOrFail();

            $assigned = $role->permissions()->pluck('slug')->all();

            foreach ($this->adminSlugs as $slug) {
                $this->assertContains($slug, $assigned, "{$roleSlug} missing {$slug}");
            }
        }
    }

    public function test_supplier_inbox_view_and_create_permissions_exist_on_owner(): void
    {
        $supplierPortalId = (int) Portal::where('code', 'SUPPLIER')->value('id');

        $view = Permission::query()
            ->where('portal_id', $supplierPortalId)
            ->where('slug', 'notifications.view')
            ->whereNull('deleted_at')
            ->first();

        $create = Permission::query()
            ->where('portal_id', $supplierPortalId)
            ->where('slug', 'notifications.create')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($view);
        $this->assertSame('Notifications', $view->module);
        $this->assertSame('Inbox', $view->group);

        $this->assertNotNull($create);
        $this->assertSame('Notifications', $create->module);
        $this->assertSame('Outbound', $create->group);

        $owner = Role::query()
            ->where('portal_id', $supplierPortalId)
            ->where('slug', 'owner')
            ->whereNull('deleted_at')
            ->firstOrFail();

        $ownerSlugs = $owner->permissions()->pluck('slug')->all();
        $this->assertContains('notifications.view', $ownerSlugs);
        $this->assertContains('notifications.create', $ownerSlugs);

        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');
        $this->assertSame(
            0,
            Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', 'notifications.view')
                ->where('group', 'Inbox')
                ->whereNull('deleted_at')
                ->count()
        );
    }

    public function test_reseller_inbox_view_permission_exists_on_owner(): void
    {
        $resellerPortalId = (int) Portal::where('code', 'RESELLER')->value('id');

        $view = Permission::query()
            ->where('portal_id', $resellerPortalId)
            ->where('slug', 'notifications.view')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($view);
        $this->assertSame('Notifications', $view->module);
        $this->assertSame('Inbox', $view->group);

        $owner = Role::query()
            ->where('portal_id', $resellerPortalId)
            ->where('slug', 'owner')
            ->whereNull('deleted_at')
            ->firstOrFail();

        $this->assertContains('notifications.view', $owner->permissions()->pluck('slug')->all());

        $this->assertSame(
            0,
            Permission::query()
                ->where('portal_id', $resellerPortalId)
                ->where('slug', 'notifications.create')
                ->whereNull('deleted_at')
                ->count()
        );
    }
}
