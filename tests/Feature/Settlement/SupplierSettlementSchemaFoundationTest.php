<?php

namespace Tests\Feature\Settlement;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsSupplierSettlementSchema;
use Tests\TestCase;

class SupplierSettlementSchemaFoundationTest extends TestCase
{
    use BuildsSupplierSettlementSchema;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureIsolatedSettlementTestDatabase();

        if (! self::$schemaReady) {
            $this->buildSupplierSettlementSchema();
            self::$schemaReady = true;
        } else {
            $this->truncateSettlementTables();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$schemaReady = false;

        parent::tearDownAfterClass();
    }

    public function test_all_nine_settlement_tables_exist(): void
    {
        foreach ([
            'supplier_settlement_account_designations',
            'supplier_settlement_batches',
            'supplier_settlement_invoices',
            'supplier_settlement_invoice_lines',
            'supplier_settlement_returned_references',
            'supplier_settlement_bank_exports',
            'supplier_settlement_bank_export_lines',
            'supplier_settlement_bank_export_exceptions',
            'supplier_payments',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }
    }

    public function test_uuid_columns_are_ten_character_strings_and_money_is_decimal(): void
    {
        $ids = $this->seedMinimalSettlementParents();

        DB::table('supplier_settlement_invoice_lines')->insert([
            'uuid' => 'LIN0000001',
            'invoice_id' => $ids['invoiceId'],
            'settlement_date' => '2026-10-12',
            'order_id' => $ids['orderId'],
            'order_item_id' => $ids['orderItemId'],
            'order_number_snapshot' => 'LK-TEST-1',
            'order_status_snapshot' => 'DELIVERED',
            'supplier_user_id' => $ids['userId'],
            'shipment_id' => $ids['shipmentId'],
            'tracking_number_snapshot' => 'TRK1',
            'courier_service_id' => $ids['courierServiceId'],
            'courier_receipt_import_id' => $ids['importId'],
            'courier_receipt_import_row_id' => $ids['importRowId'],
            'product_id' => $ids['productId'],
            'product_variant_id' => $ids['variantId'],
            'product_name_snapshot' => 'Product',
            'variant_name_snapshot' => 'Variant',
            'quantity' => 1,
            'unit_cost' => 100.00,
            'line_payable' => 100.00,
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'line_payment_status' => 'UNPAID',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = DB::table('supplier_settlement_invoices')->where('id', $ids['invoiceId'])->first();
        $line = DB::table('supplier_settlement_invoice_lines')->where('uuid', 'LIN0000001')->first();

        $this->assertSame(10, strlen((string) $invoice->uuid));
        $this->assertSame(10, strlen((string) $line->uuid));
        $this->assertEqualsWithDelta(100.00, (float) $invoice->payable_total, 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $line->unit_cost, 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $line->line_payable, 0.001);
    }

    public function test_duplicate_order_item_payable_lines_are_rejected(): void
    {
        $ids = $this->seedMinimalSettlementParents();

        $payload = [
            'invoice_id' => $ids['invoiceId'],
            'settlement_date' => '2026-10-12',
            'order_id' => $ids['orderId'],
            'order_item_id' => $ids['orderItemId'],
            'order_number_snapshot' => 'LK-TEST-1',
            'order_status_snapshot' => 'DELIVERED',
            'supplier_user_id' => $ids['userId'],
            'shipment_id' => $ids['shipmentId'],
            'tracking_number_snapshot' => 'TRK1',
            'courier_service_id' => $ids['courierServiceId'],
            'courier_receipt_import_id' => $ids['importId'],
            'courier_receipt_import_row_id' => $ids['importRowId'],
            'product_id' => $ids['productId'],
            'product_variant_id' => $ids['variantId'],
            'product_name_snapshot' => 'Product',
            'variant_name_snapshot' => 'Variant',
            'quantity' => 1,
            'unit_cost' => 100.00,
            'line_payable' => 100.00,
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'line_payment_status' => 'UNPAID',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('supplier_settlement_invoice_lines')->insert($payload + ['uuid' => 'LIN0000001']);

        $this->expectException(QueryException::class);
        DB::table('supplier_settlement_invoice_lines')->insert($payload + ['uuid' => 'LIN0000002']);
    }

    public function test_duplicate_returned_references_are_rejected(): void
    {
        $ids = $this->seedMinimalSettlementParents();

        $payload = [
            'batch_id' => $ids['batchId'],
            'supplier_company_id' => $ids['companyId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'order_id' => $ids['orderId'],
            'order_item_id' => $ids['orderItemId2'],
            'order_number_snapshot' => 'LK-TEST-1',
            'order_status_snapshot' => 'RETURNED',
            'supplier_user_id' => $ids['userId'],
            'product_id' => $ids['productId'],
            'product_variant_id' => $ids['variantId'],
            'product_name_snapshot' => 'Product',
            'variant_name_snapshot' => 'Variant',
            'quantity' => 1,
            'unit_cost' => 100.00,
            'display_payable' => 0,
            'returned_at_business_date' => '2026-10-12',
            'source' => 'PRE_LOCK',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('supplier_settlement_returned_references')->insert($payload + ['uuid' => 'RET0000001']);

        $this->expectException(QueryException::class);
        DB::table('supplier_settlement_returned_references')->insert($payload + ['uuid' => 'RET0000002']);
    }

    public function test_multiple_failed_exports_can_coexist_without_ready_version(): void
    {
        $ids = $this->seedMinimalSettlementParents();

        $base = [
            'batch_id' => $ids['batchId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'purpose' => 'SETTLEMENT',
            'ready_version' => null,
            'status' => 'FAILED',
            'value_date' => '2026-10-12',
            'value_date_overridden' => false,
            'failure_reason' => 'MISSING_ORIGIN_DESIGNATION',
            'download_count' => 0,
            'generated_by' => $ids['adminId'],
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('supplier_settlement_bank_exports')->insert($base + [
            'uuid' => 'EXP0000001',
            'attempt_number' => 1,
        ]);
        DB::table('supplier_settlement_bank_exports')->insert($base + [
            'uuid' => 'EXP0000002',
            'attempt_number' => 2,
        ]);

        $this->assertSame(
            2,
            DB::table('supplier_settlement_bank_exports')
                ->where('status', 'FAILED')
                ->whereNull('ready_version')
                ->count()
        );
    }

    public function test_bank_account_numbers_preserve_leading_zeros(): void
    {
        $ids = $this->seedMinimalSettlementParents();

        $exportId = DB::table('supplier_settlement_bank_exports')->insertGetId([
            'uuid' => 'EXP0000003',
            'batch_id' => $ids['batchId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'purpose' => 'SETTLEMENT',
            'attempt_number' => 1,
            'ready_version' => 1,
            'status' => 'READY',
            'value_date' => '2026-10-12',
            'value_date_overridden' => false,
            'file_uuid' => 'FIL0000001',
            'content_sha256' => str_repeat('a', 64),
            'byte_size' => 128,
            'original_filename' => 'settlement.xlsx',
            'download_count' => 0,
            'generated_by' => $ids['adminId'],
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_settlement_bank_export_lines')->insert([
            'uuid' => 'EXL0000001',
            'bank_export_id' => $exportId,
            'invoice_id' => $ids['invoiceId'],
            'supplier_company_id' => $ids['companyId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'origin_company_bank_account_id' => $ids['originAccountId'],
            'beneficiary_company_bank_account_id' => $ids['beneficiaryAccountId'],
            'origin_account_number' => '000123456789012',
            'origin_account_name' => 'Platform Origin',
            'value_date_text' => '12/10/2026',
            'beneficiary_bank_code' => '7010',
            'beneficiary_branch_code' => '001',
            'beneficiary_account_number' => '000987654321098',
            'beneficiary_account_name' => 'Supplier Co',
            'amount' => 100.00,
            'particulars' => 'SSB-TEST-1',
            'reference_text' => 'SSI-TEST-1',
            'row_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $line = DB::table('supplier_settlement_bank_export_lines')->where('uuid', 'EXL0000001')->first();
        $this->assertSame('000123456789012', $line->origin_account_number);
        $this->assertSame('000987654321098', $line->beneficiary_account_number);
    }

    public function test_invoice_payment_circular_foreign_keys_and_multiple_payment_rows(): void
    {
        $ids = $this->seedMinimalSettlementParents();

        $exportId = DB::table('supplier_settlement_bank_exports')->insertGetId([
            'uuid' => 'EXP0000004',
            'batch_id' => $ids['batchId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'purpose' => 'SETTLEMENT',
            'attempt_number' => 1,
            'ready_version' => 1,
            'status' => 'READY',
            'value_date' => '2026-10-12',
            'value_date_overridden' => false,
            'file_uuid' => 'FIL0000002',
            'content_sha256' => str_repeat('b', 64),
            'byte_size' => 256,
            'original_filename' => 'settlement.xlsx',
            'download_count' => 0,
            'generated_by' => $ids['adminId'],
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exportLineId = DB::table('supplier_settlement_bank_export_lines')->insertGetId([
            'uuid' => 'EXL0000002',
            'bank_export_id' => $exportId,
            'invoice_id' => $ids['invoiceId'],
            'supplier_company_id' => $ids['companyId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'origin_company_bank_account_id' => $ids['originAccountId'],
            'beneficiary_company_bank_account_id' => $ids['beneficiaryAccountId'],
            'origin_account_number' => '000123456789012',
            'origin_account_name' => 'Platform Origin',
            'value_date_text' => '12/10/2026',
            'beneficiary_bank_code' => '7010',
            'beneficiary_branch_code' => '001',
            'beneficiary_account_number' => '000987654321098',
            'beneficiary_account_name' => 'Supplier Co',
            'amount' => 100.00,
            'particulars' => 'SSB-TEST-1',
            'reference_text' => 'SSI-TEST-1',
            'row_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = DB::table('supplier_payments')->insertGetId([
            'uuid' => 'PAY0000001',
            'payment_number' => 'SP-TEST-1',
            'entry_type' => 'PAYMENT',
            'invoice_id' => $ids['invoiceId'],
            'bank_export_line_id' => $exportLineId,
            'supplier_company_id' => $ids['companyId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'amount' => 100.00,
            'confirmed_by' => $ids['adminId'],
            'confirmed_at' => now(),
            'reverses_payment_id' => null,
            'idempotency_key' => 'pay-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_settlement_invoices')
            ->where('id', $ids['invoiceId'])
            ->update(['effective_payment_id' => $paymentId, 'payment_status' => 'PAID']);

        $reversalId = DB::table('supplier_payments')->insertGetId([
            'uuid' => 'PAY0000002',
            'payment_number' => 'SP-TEST-1-R',
            'entry_type' => 'REVERSAL',
            'invoice_id' => $ids['invoiceId'],
            'bank_export_line_id' => null,
            'supplier_company_id' => $ids['companyId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'amount' => 100.00,
            'confirmed_by' => $ids['adminId'],
            'confirmed_at' => now(),
            'reverses_payment_id' => $paymentId,
            'reversal_reason' => 'Bank rejected',
            'idempotency_key' => 'rev-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_settlement_invoices')
            ->where('id', $ids['invoiceId'])
            ->update(['effective_payment_id' => null, 'payment_status' => 'UNPAID']);

        $this->assertSame(2, DB::table('supplier_payments')->where('invoice_id', $ids['invoiceId'])->count());
        $this->assertNotNull($reversalId);

        $this->expectException(QueryException::class);
        DB::table('supplier_payments')->insert([
            'uuid' => 'PAY0000003',
            'payment_number' => 'SP-TEST-1-R2',
            'entry_type' => 'REVERSAL',
            'invoice_id' => $ids['invoiceId'],
            'bank_export_line_id' => null,
            'supplier_company_id' => $ids['companyId'],
            'currency_id' => $ids['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'amount' => 100.00,
            'confirmed_by' => $ids['adminId'],
            'confirmed_at' => now(),
            'reverses_payment_id' => $paymentId,
            'reversal_reason' => 'Second reverse',
            'idempotency_key' => 'rev-2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_required_unique_indexes_exist(): void
    {
        $connection = Schema::getConnection();
        $sm = $connection->getSchemaBuilder();

        $lineIndexes = collect($sm->getIndexes('supplier_settlement_invoice_lines'));
        $this->assertTrue(
            $lineIndexes->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && $index['columns'] === ['order_item_id']),
            'Expected unique index on supplier_settlement_invoice_lines.order_item_id'
        );

        $returnIndexes = collect($sm->getIndexes('supplier_settlement_returned_references'));
        $this->assertTrue(
            $returnIndexes->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && $index['columns'] === ['order_item_id']),
            'Expected unique index on supplier_settlement_returned_references.order_item_id'
        );

        $paymentIndexes = collect($sm->getIndexes('supplier_payments'));
        $this->assertTrue(
            $paymentIndexes->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && $index['columns'] === ['reverses_payment_id']),
            'Expected unique index on supplier_payments.reverses_payment_id'
        );

        $exportIndexes = collect($sm->getIndexes('supplier_settlement_bank_exports'));
        $this->assertTrue(
            $exportIndexes->contains(fn (array $index): bool => ($index['unique'] ?? false)
                && $index['columns'] === ['batch_id', 'currency_id', 'ready_version']),
            'Expected unique index on bank export ready_version stream'
        );
    }
}
