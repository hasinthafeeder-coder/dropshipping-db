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
 * Isolated foundation coverage for Admin supplier settlement permissions.
 *
 * Prefer SQLite :memory:; otherwise use a dedicated MySQL database.
 * Never targets the shared dropshipping database.
 */
class SupplierSettlementPermissionFoundationTest extends TestCase
{
    private const ISOLATED_MYSQL_DATABASE = 'dropshipping_settlement_permission_test';

    /**
     * @var list<string>
     */
    private array $settlementSlugs = [
        'settlements.suppliers.view',
        'settlements.suppliers.generate',
        'settlements.suppliers.lock',
        'settlements.suppliers.bank_exports.prepare',
        'settlements.suppliers.payments.record',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureIsolatedDatabase();
        $this->createIsolatedAuthSchema();
        $this->seedMinimalPortalsAndRoles();
    }

    public function test_permission_seeder_creates_settlement_permissions_on_admin_portal(): void
    {
        $this->seed(PermissionSeeder::class);

        $adminPortalId = Portal::where('code', 'ADMIN')->value('id');

        foreach ($this->settlementSlugs as $slug) {
            $permission = Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $slug)
                ->whereNull('deleted_at')
                ->first();

            $this->assertNotNull($permission, "Missing ADMIN permission: {$slug}");
            $this->assertSame('Settlements', $permission->module);
            $this->assertSame('Supplier Settlement', $permission->group);
        }
    }

    public function test_manager_receives_settlement_view_and_mutation_permissions_without_orders_view(): void
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

        foreach ($this->settlementSlugs as $slug) {
            $this->assertContains($slug, $assigned);
        }

        $this->assertNotContains('orders.view', $assigned);
    }

    public function test_super_admin_receives_all_admin_settlement_permissions(): void
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
            ->whereIn('slug', $this->settlementSlugs)
            ->whereNull('deleted_at')
            ->pluck('id');

        $this->assertSame(count($this->settlementSlugs), $permissionIds->count());

        $this->assertSame(
            count($this->settlementSlugs),
            DB::table('role_permissions')
                ->where('role_id', $superAdmin->id)
                ->whereIn('permission_id', $permissionIds)
                ->count()
        );
    }

    public function test_permission_and_role_seeders_are_idempotent_for_settlement_permissions(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        $permissionCountBefore = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->whereIn('slug', $this->settlementSlugs)
            ->whereNull('deleted_at')
            ->count();

        $manager = Role::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'manager')
            ->firstOrFail();

        $assignmentCountBefore = DB::table('role_permissions')
            ->where('role_id', $manager->id)
            ->whereIn('permission_id', function ($query) use ($adminPortalId): void {
                $query->select('id')
                    ->from('permissions')
                    ->where('portal_id', $adminPortalId)
                    ->whereIn('slug', $this->settlementSlugs)
                    ->whereNull('deleted_at');
            })
            ->count();

        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(
            $permissionCountBefore,
            Permission::query()
                ->where('portal_id', $adminPortalId)
                ->whereIn('slug', $this->settlementSlugs)
                ->whereNull('deleted_at')
                ->count()
        );

        $this->assertSame(
            $assignmentCountBefore,
            DB::table('role_permissions')
                ->where('role_id', $manager->id)
                ->whereIn('permission_id', function ($query) use ($adminPortalId): void {
                    $query->select('id')
                        ->from('permissions')
                        ->where('portal_id', $adminPortalId)
                        ->whereIn('slug', $this->settlementSlugs)
                        ->whereNull('deleted_at');
                })
                ->count()
        );

        $this->assertSame(count($this->settlementSlugs), $permissionCountBefore);
        $this->assertSame(count($this->settlementSlugs), $assignmentCountBefore);
    }

    public function test_payment_record_permission_migration_is_idempotent_and_assigns_manager_and_super_admin(): void
    {
        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        $migration = require database_path('migrations/2026_10_13_000003_add_supplier_settlement_payment_record_permission.php');
        $migration->up();
        $migration->up();

        $permission = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'settlements.suppliers.payments.record')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($permission);
        $this->assertSame('Record Supplier Settlement Payments', $permission->name);
        $this->assertSame(
            1,
            Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', 'settlements.suppliers.payments.record')
                ->whereNull('deleted_at')
                ->count()
        );

        foreach (['super-admin', 'manager'] as $roleSlug) {
            $roleId = (int) Role::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $roleSlug)
                ->value('id');

            $this->assertSame(
                1,
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permission->id)
                    ->count()
            );
        }
    }

    public function test_view_permission_migration_is_idempotent_and_assigns_manager_and_super_admin(): void
    {
        $adminPortalId = (int) Portal::where('code', 'ADMIN')->value('id');

        // Simulate pre-view environment: lock + prepare already present and assigned.
        $lockId = (int) DB::table('permissions')->insertGetId([
            'uuid' => 'LOCKPERM01',
            'portal_id' => $adminPortalId,
            'module' => 'Settlements',
            'group' => 'Supplier Settlement',
            'name' => 'Lock Supplier Settlement Batches',
            'slug' => 'settlements.suppliers.lock',
            'description' => null,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $exportId = (int) DB::table('permissions')->insertGetId([
            'uuid' => 'EXPORTPR01',
            'portal_id' => $adminPortalId,
            'module' => 'Settlements',
            'group' => 'Supplier Settlement',
            'name' => 'Prepare Supplier Settlement Bank Exports',
            'slug' => 'settlements.suppliers.bank_exports.prepare',
            'description' => null,
            'sort_order' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['super-admin', 'manager'] as $roleSlug) {
            $roleId = (int) Role::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $roleSlug)
                ->value('id');

            foreach ([$lockId, $exportId] as $permissionId) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $migration = require database_path('migrations/2026_10_13_000002_add_supplier_settlement_view_permission.php');
        $migration->up();
        $migration->up();

        $viewPermission = Permission::query()
            ->where('portal_id', $adminPortalId)
            ->where('slug', 'settlements.suppliers.view')
            ->whereNull('deleted_at')
            ->first();

        $this->assertNotNull($viewPermission);
        $this->assertSame(
            1,
            Permission::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', 'settlements.suppliers.view')
                ->whereNull('deleted_at')
                ->count()
        );

        foreach (['super-admin', 'manager'] as $roleSlug) {
            $roleId = (int) Role::query()
                ->where('portal_id', $adminPortalId)
                ->where('slug', $roleSlug)
                ->value('id');

            $this->assertSame(
                1,
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $viewPermission->id)
                    ->count()
            );
        }
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
