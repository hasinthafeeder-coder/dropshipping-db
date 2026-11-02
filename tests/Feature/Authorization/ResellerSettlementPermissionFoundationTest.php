<?php

namespace Tests\Feature\Authorization;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Isolated foundation coverage for Admin reseller settlement permissions.
 *
 * Prefer SQLite :memory:; otherwise use a dedicated MySQL database.
 * Never targets the shared dropshipping database.
 */
class ResellerSettlementPermissionFoundationTest extends TestCase
{
    private const ISOLATED_MYSQL_DATABASE = 'dropshipping_reseller_settlement_permission_test';

    /**
     * @var list<string>
     */
    private array $allResellerSettlementSlugs = [
        'settlements.resellers.view',
        'settlements.resellers.generate',
        'settlements.resellers.lock',
        'settlements.resellers.payments.view',
        'settlements.resellers.payments.record',
    ];

    /**
     * @var list<string>
     */
    private array $managerResellerSettlementSlugs = [
        'settlements.resellers.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureIsolatedDatabase();
        $this->createIsolatedAuthSchema();
        $this->seedMinimalPortalsAndRoles();
    }

    public function test_permission_seeder_creates_reseller_settlement_permissions_on_admin_portal(): void
    {
        $this->seed(PermissionSeeder::class);

        $adminPortalId = Portal::where('code', 'ADMIN')->value('id');

        foreach ($this->allResellerSettlementSlugs as $slug) {
            $permission = Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($permission, "Missing ADMIN permission: {$slug}");
            $this->assertSame('Settlements', $permission->module);
            $this->assertSame('Reseller Settlement', $permission->group);
        }
    }

    public function test_manager_receives_only_reseller_settlement_view_without_orders_view_or_supplier_settlements(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');
        $manager = Role::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'manager')
            ->firstOrFail();

        $assigned = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $manager->id)
            ->where('permissions.portal_id', $adminPortalId)
            ->whereNull('permissions.deleted_at')
            ->pluck('permissions.slug')
            ->all();

        foreach ($this->managerResellerSettlementSlugs as $slug) {
            $this->assertContains($slug, $assigned);
        }

        foreach (array_diff($this->allResellerSettlementSlugs, $this->managerResellerSettlementSlugs) as $slug) {
            $this->assertNotContains($slug, $assigned, "Manager must not receive {$slug} by default");
        }

        $this->assertNotContains('orders.view', $assigned);
        $this->assertContains('settlements.suppliers.view', $assigned);
    }

    public function test_super_admin_receives_all_reseller_settlement_permissions(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');
        $superAdmin = Role::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'super-admin')
            ->firstOrFail();

        $permissionIds = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->whereIn('slug', $this->allResellerSettlementSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        $this->assertSame(count($this->allResellerSettlementSlugs), $permissionIds->count());

        $this->assertSame(
            count($this->allResellerSettlementSlugs),
            DB::table('role_permissions')
                ->where('role_id', $superAdmin->id)
                ->whereIn('permission_id', $permissionIds)
                ->count()
        );
    }

    public function test_supplier_settlement_permissions_do_not_overlap_reseller_settlement_slugs(): void
    {
        $this->seed(PermissionSeeder::class);

        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        $supplierSlugs = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'like', 'settlements.suppliers.%')
            ->whereNull('deleted_at')
            ->pluck('slug')
            ->all();

        $resellerSlugs = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'like', 'settlements.resellers.%')
            ->whereNull('deleted_at')
            ->pluck('slug')
            ->all();

        $this->assertNotEmpty($supplierSlugs);
        $this->assertNotEmpty($resellerSlugs);
        $this->assertSame([], array_values(array_intersect($supplierSlugs, $resellerSlugs)));
    }

    public function test_reseller_settlement_permission_migration_is_idempotent(): void
    {
        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        $migration = require database_path('migrations/2026_10_14_160001_add_reseller_settlement_permissions.php');
        $migration->up();
        $migration->up();

        foreach ($this->allResellerSettlementSlugs as $slug) {
            $this->assertSame(
                1,
                Permission::query()
                    ->where('portal_id', $adminPortalId)
                    ->where('slug', $slug)
                    ->whereNull('deleted_at')
                    ->count(),
                "Expected exactly one permission for {$slug}"
            );
        }

        $viewId = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'settlements.resellers.view')
            ->value('id');

        $managerId = (int) Role::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'manager')
            ->value('id');

        $this->assertSame(
            1,
            DB::table('role_permissions')
                ->where('role_id', $managerId)
                ->where('permission_id', $viewId)
                ->count()
        );

        $generateId = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'settlements.resellers.generate')
            ->value('id');

        $this->assertSame(
            0,
            DB::table('role_permissions')
                ->where('role_id', $managerId)
                ->where('permission_id', $generateId)
                ->count()
        );
    }

    private function configureIsolatedDatabase(): void
    {
        if (extension_loaded('pdo_sqlite')) {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.prefix' => '',
                'database.connections.sqlite.foreign_key_constraints' => true,
            ]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');

            $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

            return;
        }

        $this->ensureIsolatedMysqlDatabaseExists();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.mysql.port' => env('DB_PORT', '3306'),
            'database.connections.mysql.database' => self::ISOLATED_MYSQL_DATABASE,
            'database.connections.mysql.username' => env('DB_USERNAME', 'root'),
            'database.connections.mysql.password' => env('DB_PASSWORD', 'admin'),
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');

        $this->assertSame(
            self::ISOLATED_MYSQL_DATABASE,
            (string) config('database.connections.mysql.database')
        );
        $this->assertNotSame('dropshipping', (string) config('database.connections.mysql.database'));
    }

    private function ensureIsolatedMysqlDatabaseExists(): void
    {
        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (string) env('DB_PORT', '3306');
        $username = (string) env('DB_USERNAME', 'root');
        $password = (string) env('DB_PASSWORD', 'admin');

        $pdo = new PDO(
            "mysql:host={$host};port={$port}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $database = self::ISOLATED_MYSQL_DATABASE;
        $pdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function createIsolatedAuthSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('portals');
        Schema::enableForeignKeyConstraints();

        Schema::create('portals', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->nullable();
            $table->unsignedBigInteger('portal_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 10)->nullable();
            $table->unsignedBigInteger('portal_id');
            $table->string('module')->nullable();
            $table->string('group')->nullable();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
    }

    private function seedMinimalPortalsAndRoles(): void
    {
        foreach ([
            ['ADMIN', 'Admin'],
            ['RESELLER', 'Reseller'],
            ['SUPPLIER', 'Supplier'],
        ] as [$code, $name]) {
            DB::table('portals')->insert([
                'uuid' => strtoupper(substr($code, 0, 10)),
                'code' => $code,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->seed(RoleSeeder::class);
    }
}
