<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait BuildsSupplierSettlementSchema
{
    private const ISOLATED_TEST_DATABASE = 'dropshipping_settlement_schema_test';

    protected function configureIsolatedSettlementTestDatabase(): void
    {
        if (extension_loaded('pdo_sqlite')) {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
            ]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');
        } else {
            config([
                'database.default' => 'mysql',
                'database.connections.mysql.url' => null,
                'database.connections.mysql.host' => env('DB_HOST', '127.0.0.1'),
                'database.connections.mysql.port' => env('DB_PORT', '3306'),
                'database.connections.mysql.database' => self::ISOLATED_TEST_DATABASE,
                'database.connections.mysql.username' => env('DB_USERNAME', 'root'),
                'database.connections.mysql.password' => env('DB_PASSWORD', 'admin'),
            ]);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        $this->assertUsingIsolatedSettlementDatabase();
    }

    protected function assertUsingIsolatedSettlementDatabase(): void
    {
        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        $this->assertNotSame(
            'dropshipping',
            $database,
            'Supplier settlement schema tests must not target the shared dropshipping database.'
        );

        if ($default === 'sqlite') {
            $this->assertSame(':memory:', $database);

            return;
        }

        $this->assertSame(
            self::ISOLATED_TEST_DATABASE,
            $database,
            'Supplier settlement schema tests must use the dedicated isolated test database.'
        );
    }

    protected function buildSupplierSettlementSchema(): void
    {
        $this->dropSettlementAndStubTables();
        $this->createParentStubTables();
        $this->runSettlementMigrations();
    }

    protected function dropSettlementAndStubTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ($this->settlementAndStubTableNames() as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    protected function truncateSettlementTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ($this->settlementAndStubTableNames() as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @return list<string>
     */
    protected function settlementAndStubTableNames(): array
    {
        return [
            'supplier_payments',
            'supplier_settlement_bank_export_exceptions',
            'supplier_settlement_bank_export_lines',
            'supplier_settlement_bank_exports',
            'supplier_settlement_returned_references',
            'supplier_settlement_invoice_lines',
            'supplier_settlement_invoices',
            'supplier_settlement_batches',
            'supplier_settlement_account_designations',
            'courier_receipt_import_rows',
            'courier_receipt_imports',
            'courier_services',
            'shipments',
            'order_items',
            'orders',
            'product_variants',
            'products',
            'company_bank_accounts',
            'settings',
            'markets',
            'currencies',
            'companies',
            'users',
        ];
    }

    protected function createParentStubTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->nullable();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->nullable();
        });

        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->nullable();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key');
            $table->unsignedBigInteger('market_id')->nullable();
            $table->string('group')->default('system');
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['key', 'market_id']);
        });

        Schema::create('company_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('account_number', 50);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->string('order_number')->nullable();
            $table->string('status', 30)->nullable();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_variant_id')->nullable();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
        });

        Schema::create('courier_services', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('courier_receipt_imports', function (Blueprint $table) {
            $table->id();
            $table->string('status', 30)->nullable();
        });

        Schema::create('courier_receipt_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('courier_receipt_import_id')->nullable();
        });
    }

    protected function runSettlementMigrations(): void
    {
        $files = [
            '2026_10_12_120000_create_supplier_settlement_account_designations_table.php',
            '2026_10_12_120001_create_supplier_settlement_batches_table.php',
            '2026_10_12_120002_create_supplier_settlement_invoices_and_lines_tables.php',
            '2026_10_12_120003_create_supplier_settlement_returned_references_table.php',
            '2026_10_12_120004_create_supplier_settlement_bank_exports_tables.php',
            '2026_10_12_120005_create_supplier_payments_table.php',
            '2026_10_12_120006_add_effective_payment_fk_to_supplier_settlement_invoices_table.php',
            '2026_11_02_120000_add_transfer_fee_snapshots_to_supplier_settlement_invoices_table.php',
        ];

        foreach ($files as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }
    }

    protected function seedMinimalSettlementParents(): array
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Supplier Co']);
        $platformCompanyId = DB::table('companies')->insertGetId(['name' => 'Platform Co']);
        $currencyId = DB::table('currencies')->insertGetId(['code' => 'LKR']);
        $marketId = DB::table('markets')->insertGetId(['code' => 'LK']);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => $platformCompanyId]);
        $originAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $platformCompanyId,
            'account_number' => '000123456789012',
        ]);
        $beneficiaryAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $companyId,
            'account_number' => '000987654321098',
        ]);
        $productId = DB::table('products')->insertGetId([]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'cost' => 100.00,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'supplier_id' => $userId,
            'currency_id' => $currencyId,
            'order_number' => 'LK-TEST-1',
            'status' => 'DELIVERED',
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_variant_id' => $variantId,
        ]);
        $orderItemId2 = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_variant_id' => $variantId,
        ]);
        $shipmentId = DB::table('shipments')->insertGetId(['order_id' => $orderId]);
        $courierServiceId = DB::table('courier_services')->insertGetId([]);
        $importId = DB::table('courier_receipt_imports')->insertGetId(['status' => 'CONFIRMED']);
        $importRowId = DB::table('courier_receipt_import_rows')->insertGetId([
            'courier_receipt_import_id' => $importId,
        ]);

        $batchId = DB::table('supplier_settlement_batches')->insertGetId([
            'uuid' => 'BAT0000001',
            'batch_number' => 'SSB-TEST-1',
            'market_id' => $marketId,
            'market_code_snapshot' => 'LK',
            'timezone_snapshot' => 'Asia/Colombo',
            'settlement_date' => '2026-10-12',
            'kind' => 'DAILY',
            'sequence' => 1,
            'status' => 'LOCKED',
            'generation_source' => 'MANUAL',
            'generated_by' => $adminId,
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('supplier_settlement_invoices')->insertGetId([
            'uuid' => 'INV0000001',
            'invoice_number' => 'SSI-TEST-1',
            'batch_id' => $batchId,
            'supplier_company_id' => $companyId,
            'supplier_company_name_snapshot' => 'Supplier Co',
            'currency_id' => $currencyId,
            'currency_code_snapshot' => 'LKR',
            'payable_total' => 100.00,
            'transfer_fee_amount' => 0,
            'transfer_fee_currency_code_snapshot' => null,
            'transfer_fee_status' => 'transfer_fee_not_configured',
            'net_payable' => 100.00,
            'payment_status' => 'UNPAID',
            'instruction_status' => 'ACTIVE',
            'effective_payment_id' => null,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact(
            'companyId',
            'platformCompanyId',
            'currencyId',
            'marketId',
            'userId',
            'adminId',
            'originAccountId',
            'beneficiaryAccountId',
            'productId',
            'variantId',
            'orderId',
            'orderItemId',
            'orderItemId2',
            'shipmentId',
            'courierServiceId',
            'importId',
            'importRowId',
            'batchId',
            'invoiceId',
        );
    }
}
