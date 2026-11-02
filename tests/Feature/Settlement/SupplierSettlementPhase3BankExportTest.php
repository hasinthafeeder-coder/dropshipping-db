<?php

namespace Tests\Feature\Settlement;

use Carbon\CarbonImmutable;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementAccountDesignation;
use Feeder\Core\Models\SupplierSettlementBankExport;
use Feeder\Core\Models\SupplierSettlementBankExportException;
use Feeder\Core\Models\SupplierSettlementBankExportLine;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Settlement\SupplierSettlementBankExportService;
use Feeder\Core\Services\Settlement\SupplierSettlementBankExportWorkbookBuilder;
use Feeder\Core\Services\Settlement\SupplierSettlementBatchService;
use Feeder\Core\Services\Settlement\SupplierSettlementGenerationService;
use Feeder\Core\Services\Settlement\SupplierSettlementLockService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsSupplierSettlementPhase3Schema;
use Tests\Support\ReadsSimpleXlsxWorkbook;
use Tests\TestCase;

class SupplierSettlementPhase3BankExportTest extends TestCase
{
    use BuildsSupplierSettlementPhase3Schema;
    use ReadsSimpleXlsxWorkbook;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureIsolatedSettlementPhase3Database();
        config([
            'feeder.markets.timezones' => [
                'lk' => 'Asia/Colombo',
                'my' => 'Asia/Kuala_Lumpur',
            ],
        ]);

        Storage::fake('local');

        if (! self::$schemaReady) {
            $this->buildSupplierSettlementPhase3Schema();
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

    public function test_successful_export_has_exact_ten_workbook_columns_in_order(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $export = $this->prepareExport($fixture);

        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);
        $this->assertSame(1, (int) $export->ready_version);
        $this->assertNotNull($export->file_uuid);
        $this->assertNotNull($export->content_sha256);

        $path = Storage::disk('local')->path(
            'supplier-settlement-bank-exports/'.$export->batch_id.'/'.$export->file_uuid.'.xlsx'
        );
        $matrix = $this->readSimpleXlsxSheetMatrix($path);

        $this->assertSame(
            $this->app->make(SupplierSettlementBankExportWorkbookBuilder::class)->headers(),
            $matrix[0],
        );
        $this->assertCount(2, $matrix);
        $this->assertSame('', $matrix[1][0]);
        $this->assertSame('', $matrix[1][1]);
        $this->assertSame('12/10/2026', $matrix[1][2]);
        $this->assertSame('7010', $matrix[1][3]);
        $this->assertSame('001', $matrix[1][4]);
        $this->assertSame('000987654321098', $matrix[1][5]);
        $this->assertSame('Supplier Beneficiary', $matrix[1][6]);
        $this->assertSame('100.00', $matrix[1][7]);
        $this->assertSame($fixture['batchNumber'], $matrix[1][8]);
        $this->assertSame($fixture['invoiceNumber'], $matrix[1][9]);
    }

    public function test_platform_origin_is_not_selected_and_registered_beneficiary_account_is_used(): void
    {
        $fixture = $this->seedLockedExportFixture();

        // Platform accounts must never be selected as origin for manual settlement.
        DB::table('company_bank_accounts')->insert([
            'company_id' => $fixture['platformCompanyId'],
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Other Origin',
            'bank_name' => 'Bank',
            'branch_name' => 'Branch',
            'bank_code' => '9999',
            'branch_code' => '999',
            'account_number' => '111111111111111',
        ]);

        $export = $this->prepareExport($fixture);
        $line = $export->exportLines->firstOrFail();

        $this->assertNull($line->origin_company_bank_account_id);
        $this->assertNull($line->origin_account_number);
        $this->assertNull($line->origin_account_name);
        $this->assertSame((int) $fixture['beneficiaryAccountId'], (int) $line->beneficiary_company_bank_account_id);
        $this->assertSame('000987654321098', $line->beneficiary_account_number);
    }

    public function test_multiple_registered_supplier_bank_accounts_are_rejected_not_first_available(): void
    {
        $fixture = $this->seedLockedExportFixture();

        DB::table('company_bank_accounts')->insert([
            'company_id' => $fixture['companyId'],
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Other Beneficiary',
            'bank_name' => 'Bank',
            'branch_name' => 'Branch',
            'bank_code' => '8888',
            'branch_code' => '888',
            'account_number' => '222222222222222',
        ]);

        $export = $this->prepareExport($fixture);

        $this->assertSame(SupplierSettlementBankExport::STATUS_FAILED, $export->status);
        $this->assertSame(0, $export->exportLines->count());
        $this->assertSame(
            SupplierSettlementBankExportException::REASON_AMBIGUOUS_BENEFICIARY_ACCOUNT,
            $export->exceptions->first()->reason_code,
        );
    }

    public function test_leading_zeros_preserved_in_bank_and_account_identifiers(): void
    {
        $fixture = $this->seedLockedExportFixture([
            'origin_account_number' => '000123456789012',
            'beneficiary_account_number' => '000987654321098',
            'beneficiary_bank_code' => '0701',
            'beneficiary_branch_code' => '001',
        ]);

        $export = $this->prepareExport($fixture);
        $line = $export->exportLines->firstOrFail();

        $this->assertNull($line->origin_account_number);
        $this->assertSame('000987654321098', $line->beneficiary_account_number);
        $this->assertSame('0701', $line->beneficiary_bank_code);
        $this->assertSame('001', $line->beneficiary_branch_code);

        $path = Storage::disk('local')->path(
            'supplier-settlement-bank-exports/'.$export->batch_id.'/'.$export->file_uuid.'.xlsx'
        );
        $matrix = $this->readSimpleXlsxSheetMatrix($path);
        $this->assertSame('', $matrix[1][0]);
        $this->assertSame('', $matrix[1][1]);
        $this->assertSame('0701', $matrix[1][3]);
        $this->assertSame('001', $matrix[1][4]);
        $this->assertSame('000987654321098', $matrix[1][5]);
    }

    public function test_export_is_one_batch_and_one_currency_only(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $otherCurrencyId = DB::table('currencies')->insertGetId(['code' => 'MYR']);

        $this->expectException(SupplierSettlementException::class);
        $this->expectExceptionMessage('Bank exports must use the batch market currency.');

        $this->app->make(SupplierSettlementBankExportService::class)->prepare(
            SupplierSettlementBatch::query()->findOrFail($fixture['batchId']),
            $otherCurrencyId,
            User::query()->findOrFail($fixture['adminId']),
        );
    }

    public function test_open_batch_is_rejected_even_when_invoices_are_active(): void
    {
        $fixture = $this->seedLockedExportFixture();
        DB::table('supplier_settlement_batches')
            ->where('id', $fixture['batchId'])
            ->update(['status' => SupplierSettlementBatch::STATUS_OPEN, 'locked_at' => null, 'locked_by' => null]);

        $this->assertSame(
            SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            DB::table('supplier_settlement_invoices')->where('id', $fixture['invoiceId'])->value('instruction_status'),
        );

        try {
            $this->prepareExport($fixture);
            $this->fail('Expected OPEN batch export to be rejected.');
        } catch (SupplierSettlementException $e) {
            $this->assertSame('SETTLEMENT_BATCH_NOT_LOCKED', $e->errorCode);
        }

        $this->assertSame(0, SupplierSettlementBankExport::query()->count());
    }

    public function test_locked_batch_export_succeeds_for_eligible_invoices(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $export = $this->prepareExport($fixture);

        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);
        $this->assertSame(1, $export->exportLines->count());
        $this->assertSame((int) $fixture['invoiceId'], (int) $export->exportLines->first()->invoice_id);
    }

    public function test_missing_platform_origin_designation_does_not_block_ready_export(): void
    {
        $fixture = $this->seedLockedExportFixture();
        DB::table('supplier_settlement_account_designations')
            ->where('role', SupplierSettlementAccountDesignation::ROLE_PLATFORM_ORIGIN)
            ->delete();

        $export = $this->prepareExport($fixture);
        $line = $export->exportLines->firstOrFail();

        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);
        $this->assertSame(1, (int) $export->ready_version);
        $this->assertNull($line->origin_company_bank_account_id);
        $this->assertSame((int) $fixture['beneficiaryAccountId'], (int) $line->beneficiary_company_bank_account_id);
    }

    public function test_ambiguous_platform_origin_designation_is_ignored(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $otherPlatformId = DB::table('companies')->insertGetId(['name' => 'Other Platform']);
        $otherOriginId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $otherPlatformId,
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Other Platform Origin',
            'bank_name' => 'Bank',
            'branch_name' => 'Branch',
            'bank_code' => '7010',
            'branch_code' => '002',
            'account_number' => '000111111111111',
        ]);
        DB::table('supplier_settlement_account_designations')->insert([
            'uuid' => 'DES0000099',
            'role' => SupplierSettlementAccountDesignation::ROLE_PLATFORM_ORIGIN,
            'company_id' => $otherPlatformId,
            'currency_id' => $fixture['currencyId'],
            'company_bank_account_id' => $otherOriginId,
            'designated_by' => $fixture['adminId'],
            'designated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $this->prepareExport($fixture);
        $line = $export->exportLines->firstOrFail();

        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);
        $this->assertNull($line->origin_company_bank_account_id);
        $this->assertSame((int) $fixture['beneficiaryAccountId'], (int) $line->beneficiary_company_bank_account_id);
    }

    public function test_export_uses_registered_supplier_bank_account_without_beneficiary_designation(): void
    {
        $fixture = $this->seedLockedExportFixture();
        DB::table('supplier_settlement_account_designations')
            ->where('role', SupplierSettlementAccountDesignation::ROLE_SUPPLIER_BENEFICIARY)
            ->delete();

        $export = $this->prepareExport($fixture);
        $line = $export->exportLines->firstOrFail();

        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);
        $this->assertSame((int) $fixture['beneficiaryAccountId'], (int) $line->beneficiary_company_bank_account_id);
        $this->assertSame('000987654321098', $line->beneficiary_account_number);
        $this->assertSame('7010', $line->beneficiary_bank_code);
        $this->assertSame('001', $line->beneficiary_branch_code);
        $this->assertSame('Supplier Beneficiary', $line->beneficiary_account_name);
    }

    public function test_missing_registered_supplier_bank_account_produces_exception_and_no_payable_line(): void
    {
        $fixture = $this->seedLockedExportFixture();
        DB::table('supplier_settlement_account_designations')
            ->where('role', SupplierSettlementAccountDesignation::ROLE_SUPPLIER_BENEFICIARY)
            ->delete();
        DB::table('company_bank_accounts')
            ->where('id', $fixture['beneficiaryAccountId'])
            ->delete();

        $export = $this->prepareExport($fixture);

        $this->assertSame(SupplierSettlementBankExport::STATUS_FAILED, $export->status);
        $this->assertNull($export->ready_version);
        $this->assertSame('NO_ELIGIBLE_INVOICES', $export->failure_reason);
        $this->assertSame(0, $export->exportLines->count());
        $this->assertSame(1, $export->exceptions->count());
        $this->assertSame(
            SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_ACCOUNT,
            $export->exceptions->first()->reason_code,
        );
    }

    public function test_different_suppliers_receive_their_own_registered_beneficiary_details(): void
    {
        $fixture = $this->seedLockedExportFixture();

        $secondCompanyId = DB::table('companies')->insertGetId(['name' => 'Supplier Two']);
        $secondBeneficiaryAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $secondCompanyId,
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Supplier Two Account',
            'bank_name' => 'Commercial Bank',
            'branch_name' => 'Kandy',
            'bank_code' => '0701',
            'branch_code' => '002',
            'account_number' => '000555555555555',
        ]);
        $secondInvoiceNumber = 'SSI-'.$fixture['batchNumber'].'-'.$secondCompanyId.'-LKR';
        $secondInvoiceId = DB::table('supplier_settlement_invoices')->insertGetId([
            'uuid' => 'INV'.Str::upper(Str::random(7)),
            'invoice_number' => $secondInvoiceNumber,
            'batch_id' => $fixture['batchId'],
            'supplier_company_id' => $secondCompanyId,
            'supplier_company_name_snapshot' => 'Supplier Two',
            'currency_id' => $fixture['currencyId'],
            'currency_code_snapshot' => 'LKR',
            'payable_total' => '50.00',
            'transfer_fee_amount' => 0,
            'transfer_fee_currency_code_snapshot' => null,
            'transfer_fee_status' => 'transfer_fee_not_configured',
            'net_payable' => '50.00',
            'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID,
            'instruction_status' => SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            'effective_payment_id' => null,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $this->prepareExport($fixture);
        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $export->status);
        $this->assertSame(2, $export->exportLines->count());

        $byCompany = $export->exportLines->keyBy(static fn ($line) => (int) $line->supplier_company_id);

        $firstLine = $byCompany->get((int) $fixture['companyId']);
        $this->assertNotNull($firstLine);
        $this->assertSame((int) $fixture['beneficiaryAccountId'], (int) $firstLine->beneficiary_company_bank_account_id);
        $this->assertSame('000987654321098', $firstLine->beneficiary_account_number);
        $this->assertSame('7010', $firstLine->beneficiary_bank_code);
        $this->assertSame((int) $fixture['invoiceId'], (int) $firstLine->invoice_id);

        $secondLine = $byCompany->get($secondCompanyId);
        $this->assertNotNull($secondLine);
        $this->assertSame($secondBeneficiaryAccountId, (int) $secondLine->beneficiary_company_bank_account_id);
        $this->assertSame('000555555555555', $secondLine->beneficiary_account_number);
        $this->assertSame('0701', $secondLine->beneficiary_bank_code);
        $this->assertSame('002', $secondLine->beneficiary_branch_code);
        $this->assertSame('Supplier Two Account', $secondLine->beneficiary_account_name);
        $this->assertSame($secondInvoiceId, (int) $secondLine->invoice_id);
        $this->assertSame($secondCompanyId, (int) $secondLine->supplier_company_id);
    }

    public function test_invalid_invoice_produces_exception_and_cannot_become_export_line(): void
    {
        $fixture = $this->seedLockedExportFixture();
        DB::table('company_bank_accounts')
            ->where('id', $fixture['beneficiaryAccountId'])
            ->update(['bank_code' => null]);

        $export = $this->prepareExport($fixture);

        $this->assertSame(SupplierSettlementBankExport::STATUS_FAILED, $export->status);
        $this->assertSame(0, SupplierSettlementBankExportLine::query()->count());
        $this->assertSame(
            SupplierSettlementBankExportException::REASON_MISSING_BENEFICIARY_BANK_CODE,
            $export->exceptions->first()->reason_code,
        );
    }

    public function test_duplicate_requests_do_not_create_duplicate_active_ready_exports(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $service = $this->app->make(SupplierSettlementBankExportService::class);
        $batch = SupplierSettlementBatch::query()->findOrFail($fixture['batchId']);
        $actor = User::query()->findOrFail($fixture['adminId']);

        $first = $service->prepare($batch, (int) $fixture['currencyId'], $actor);
        $second = $service->prepare($batch->fresh(), (int) $fixture['currencyId'], $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierSettlementBankExport::query()->where('status', 'READY')->count());
        $this->assertSame(1, SupplierSettlementBankExportLine::query()->count());
    }

    public function test_failed_attempts_do_not_consume_ready_version_and_next_ready_uses_next_version(): void
    {
        $fixture = $this->seedLockedExportFixture();
        DB::table('company_bank_accounts')
            ->where('id', $fixture['beneficiaryAccountId'])
            ->update(['bank_code' => '']);

        $failed = $this->prepareExport($fixture);
        $this->assertNull($failed->ready_version);
        $this->assertSame(1, (int) $failed->attempt_number);
        $this->assertSame('NO_ELIGIBLE_INVOICES', $failed->failure_reason);

        DB::table('company_bank_accounts')
            ->where('id', $fixture['beneficiaryAccountId'])
            ->update(['bank_code' => '7010']);

        $ready = $this->prepareExport($fixture);
        $this->assertSame(SupplierSettlementBankExport::STATUS_READY, $ready->status);
        $this->assertSame(1, (int) $ready->ready_version);
        $this->assertSame(2, (int) $ready->attempt_number);

        $this->app->make(SupplierSettlementBankExportService::class)->supersede(
            $ready,
            User::query()->findOrFail($fixture['adminId']),
            'Regenerate after bank template change',
        );

        $ready2 = $this->prepareExport($fixture);
        $this->assertSame(2, (int) $ready2->ready_version);
        $this->assertSame(3, (int) $ready2->attempt_number);
        $this->assertSame(
            1,
            SupplierSettlementBankExport::query()
                ->where('status', SupplierSettlementBankExport::STATUS_READY)
                ->whereNull('superseded_at')
                ->count()
        );
        $this->assertSame(1, SupplierSettlementBankExport::query()->where('status', SupplierSettlementBankExport::STATUS_SUPERSEDED)->count());
        $this->assertSame(1, SupplierSettlementBankExport::query()->where('status', SupplierSettlementBankExport::STATUS_FAILED)->count());
        $this->assertSame(3, SupplierSettlementBankExport::query()->count());
    }

    public function test_existing_export_history_remains_intact_after_supersede_and_reexport(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $first = $this->prepareExport($fixture);
        $firstLineUuid = $first->exportLines->first()->uuid;
        $firstFileUuid = $first->file_uuid;

        $this->app->make(SupplierSettlementBankExportService::class)->supersede(
            $first,
            User::query()->findOrFail($fixture['adminId']),
            'Bank rejected file',
            SupplierSettlementBankExport::DISPOSITION_BANK_REJECTED,
        );

        $second = $this->prepareExport($fixture);

        $this->assertDatabaseHas('supplier_settlement_bank_exports', [
            'id' => $first->id,
            'status' => SupplierSettlementBankExport::STATUS_SUPERSEDED,
            'file_uuid' => $firstFileUuid,
            'ready_version' => 1,
        ]);
        $this->assertDatabaseHas('supplier_settlement_bank_export_lines', [
            'uuid' => $firstLineUuid,
            'bank_export_id' => $first->id,
        ]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, (int) $second->ready_version);
        $this->assertSame(2, SupplierSettlementBankExportLine::query()->count());
    }

    public function test_invoice_amounts_and_export_totals_agree_exactly(): void
    {
        $fixture = $this->seedLockedExportFixture(['payable_total' => '251.50']);
        $export = $this->prepareExport($fixture);
        $line = $export->exportLines->firstOrFail();
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);

        $money = $this->app->make(MoneyNormalizer::class);
        $this->assertTrue($money->equals((string) $invoice->payable_total, (string) $line->amount));
        $this->assertTrue($money->equals('251.50', (string) $line->amount));
    }

    public function test_no_supplier_payments_created_and_invoice_not_marked_paid(): void
    {
        $fixture = $this->seedLockedExportFixture();
        $this->prepareExport($fixture);

        $this->assertSame(0, DB::table('supplier_payments')->count());
        $invoice = SupplierSettlementInvoice::query()->findOrFail($fixture['invoiceId']);
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID, $invoice->payment_status);
        $this->assertSame(SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE, $invoice->instruction_status);
        $this->assertNull($invoice->effective_payment_id);
    }

    public function test_transaction_rollback_leaves_no_partially_committed_ready_export(): void
    {
        $fixture = $this->seedLockedExportFixture();

        try {
            DB::transaction(function () use ($fixture) {
                $this->prepareExport($fixture);
                throw new \RuntimeException('force rollback after prepare');
            });
            $this->fail('Expected rollback exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('force rollback after prepare', $e->getMessage());
        }

        $this->assertSame(0, SupplierSettlementBankExport::query()->count());
        $this->assertSame(0, SupplierSettlementBankExportLine::query()->count());
        $this->assertSame(0, SupplierSettlementBankExportException::query()->count());
    }

    public function test_phase1_and_phase2_settlement_behavior_still_works(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('supplier_settlement_bank_exports'),
        );

        $currencyId = DB::table('currencies')->insertGetId(['code' => 'LKR']);
        $marketId = DB::table('markets')->insertGetId([
            'code' => 'lk',
            'currency_id' => $currencyId,
            'market_number' => 1,
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Phase2 Supplier']);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => null]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Widget',
            'market_id' => $marketId,
        ]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => 'Default',
            'cost' => '40.00',
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'supplier_id' => $userId,
            'market_id' => $marketId,
            'currency_id' => $currencyId,
            'order_number' => 'ORD-P2-1',
            'status' => OrderStatus::DELIVERED->value,
            'currency_code_snapshot' => 'LKR',
            'delivered_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
            'returned_at' => null,
        ]);
        DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Widget',
            'variant_name_snapshot' => 'Default',
            'quantity' => 2,
            'unit_cost_snapshot' => '40.00',
        ]);
        $courierServiceId = DB::table('courier_services')->insertGetId([]);
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $orderId,
            'supplier_id' => $userId,
            'courier_service_id' => $courierServiceId,
            'tracking_number' => 'TRK-P2-1',
        ]);
        $importId = DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $courierServiceId,
            'status' => CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
        ]);
        DB::table('courier_receipt_import_rows')->insert([
            'courier_receipt_import_id' => $importId,
            'normalized_tracking_number' => 'TRK-P2-1',
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED->value,
            'matched_order_id' => $orderId,
            'matched_shipment_id' => $shipmentId,
            'matched_supplier_id' => $userId,
        ]);

        $batch = $this->app->make(SupplierSettlementBatchService::class)->createOrGetDailyBatch(
            Market::query()->findOrFail($marketId),
            User::query()->findOrFail($adminId),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
        );
        $generated = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);
        $this->assertSame(1, $generated['invoice_count']);
        $this->assertSame('80.00', $generated['payable_total']);

        $locked = $this->app->make(SupplierSettlementLockService::class)
            ->lock($batch->fresh(), User::query()->findOrFail($adminId));
        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $locked->status);
        $this->assertSame(1, SupplierSettlementInvoice::query()->where('batch_id', $locked->id)->count());
        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|string>
     */
    private function seedLockedExportFixture(array $overrides = []): array
    {
        $currencyId = DB::table('currencies')->insertGetId(['code' => 'LKR']);
        $marketId = DB::table('markets')->insertGetId([
            'code' => 'lk',
            'currency_id' => $currencyId,
            'market_number' => 1,
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Supplier Co']);
        $platformCompanyId = DB::table('companies')->insertGetId(['name' => 'Platform Co']);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => $platformCompanyId]);

        $originAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $platformCompanyId,
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Platform Origin',
            'bank_name' => 'Commercial Bank',
            'branch_name' => 'Head Office',
            'bank_code' => '7056',
            'branch_code' => '001',
            'account_number' => $overrides['origin_account_number'] ?? '000123456789012',
        ]);
        $beneficiaryAccountId = DB::table('company_bank_accounts')->insertGetId([
            'company_id' => $companyId,
            'uuid' => (string) Str::uuid(),
            'account_name' => 'Supplier Beneficiary',
            'bank_name' => 'Commercial Bank',
            'branch_name' => 'Colombo',
            'bank_code' => $overrides['beneficiary_bank_code'] ?? '7010',
            'branch_code' => $overrides['beneficiary_branch_code'] ?? '001',
            'account_number' => $overrides['beneficiary_account_number'] ?? '000987654321098',
        ]);

        DB::table('supplier_settlement_account_designations')->insert([
            [
                'uuid' => 'DES0000001',
                'role' => SupplierSettlementAccountDesignation::ROLE_PLATFORM_ORIGIN,
                'company_id' => $platformCompanyId,
                'currency_id' => $currencyId,
                'company_bank_account_id' => $originAccountId,
                'designated_by' => $adminId,
                'designated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => 'DES0000002',
                'role' => SupplierSettlementAccountDesignation::ROLE_SUPPLIER_BENEFICIARY,
                'company_id' => $companyId,
                'currency_id' => $currencyId,
                'company_bank_account_id' => $beneficiaryAccountId,
                'designated_by' => $adminId,
                'designated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $batchNumber = 'SSB-LK-20261012-DAILY-1';
        $invoiceNumber = 'SSI-'.$batchNumber.'-'.$companyId.'-LKR';
        $payableTotal = $overrides['payable_total'] ?? '100.00';

        $batchId = DB::table('supplier_settlement_batches')->insertGetId([
            'uuid' => 'BAT'.Str::upper(Str::random(7)),
            'batch_number' => $batchNumber,
            'market_id' => $marketId,
            'market_code_snapshot' => 'LK',
            'timezone_snapshot' => 'Asia/Colombo',
            'settlement_date' => '2026-10-12',
            'kind' => SupplierSettlementBatch::KIND_DAILY,
            'sequence' => 1,
            'status' => SupplierSettlementBatch::STATUS_LOCKED,
            'generation_source' => SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $adminId,
            'generated_at' => now(),
            'locked_by' => $adminId,
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoiceId = DB::table('supplier_settlement_invoices')->insertGetId([
            'uuid' => 'INV'.Str::upper(Str::random(7)),
            'invoice_number' => $invoiceNumber,
            'batch_id' => $batchId,
            'supplier_company_id' => $companyId,
            'supplier_company_name_snapshot' => 'Supplier Co',
            'currency_id' => $currencyId,
            'currency_code_snapshot' => 'LKR',
            'payable_total' => $payableTotal,
            'transfer_fee_amount' => 0,
            'transfer_fee_currency_code_snapshot' => null,
            'transfer_fee_status' => 'transfer_fee_not_configured',
            'net_payable' => $payableTotal,
            'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID,
            'instruction_status' => SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            'effective_payment_id' => null,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'currencyId' => $currencyId,
            'marketId' => $marketId,
            'companyId' => $companyId,
            'platformCompanyId' => $platformCompanyId,
            'userId' => $userId,
            'adminId' => $adminId,
            'originAccountId' => $originAccountId,
            'beneficiaryAccountId' => $beneficiaryAccountId,
            'batchId' => $batchId,
            'invoiceId' => $invoiceId,
            'batchNumber' => $batchNumber,
            'invoiceNumber' => $invoiceNumber,
        ];
    }

    /**
     * @param  array<string, int|string>  $fixture
     */
    private function prepareExport(array $fixture): SupplierSettlementBankExport
    {
        return $this->app->make(SupplierSettlementBankExportService::class)->prepare(
            SupplierSettlementBatch::query()->findOrFail($fixture['batchId']),
            (int) $fixture['currencyId'],
            User::query()->findOrFail($fixture['adminId']),
        );
    }
}
