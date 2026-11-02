<?php

namespace Tests\Feature\Settlement;

use Carbon\CarbonImmutable;
use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Exceptions\SupplierSettlementException;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementInvoiceLine;
use Feeder\Core\Models\SupplierSettlementReturnedReference;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Settlement\SupplierSettlementBatchService;
use Feeder\Core\Services\Settlement\SupplierSettlementGenerationService;
use Feeder\Core\Services\Settlement\SupplierSettlementLockService;
use Feeder\Core\Support\MoneyNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsSupplierSettlementPhase2Schema;
use Tests\TestCase;

class SupplierSettlementPhase2GenerationTest extends TestCase
{
    use BuildsSupplierSettlementPhase2Schema;

    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureIsolatedSettlementPhase2Database();
        config([
            'feeder.markets.timezones' => [
                'lk' => 'Asia/Colombo',
                'my' => 'Asia/Kuala_Lumpur',
            ],
        ]);

        if (! self::$schemaReady) {
            $this->buildSupplierSettlementPhase2Schema();
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

    public function test_confirmed_matched_receipt_produces_payable_lines_using_order_item_unit_cost_snapshot(): void
    {
        $fixture = $this->seedPayableFixture([
            'variant_cost' => '125.50',
            'quantity' => 2,
            'order_item_unit_cost_snapshot' => '999.99',
        ]);

        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(1, $result['invoice_count']);
        $this->assertSame(1, $result['payable_line_count']);
        $this->assertSame('1999.98', $result['payable_total']);

        $line = SupplierSettlementInvoiceLine::query()->firstOrFail();
        $this->assertSame('999.99', (string) $line->unit_cost);
        $this->assertSame('1999.98', (string) $line->line_payable);
        $this->assertSame(2, (int) $line->quantity);
        $this->assertSame((int) $fixture['importId'], (int) $line->courier_receipt_import_id);
        $this->assertSame((int) $fixture['importRowId'], (int) $line->courier_receipt_import_row_id);
        $this->assertSame((int) $fixture['companyId'], (int) $line->invoice->supplier_company_id);
        $this->assertSame('Supplier Co', $line->invoice->supplier_company_name_snapshot);
        $this->assertTrue($this->app->make(MoneyNormalizer::class)->equals(
            (string) $line->invoice->payable_total,
            (string) $line->line_payable,
        ));
    }

    public function test_multiple_variants_sum_to_supplier_invoice_total_from_snapshots(): void
    {
        $fixture = $this->seedPayableFixture([
            'variant_cost' => '10.00',
            'quantity' => 2,
            'order_item_unit_cost_snapshot' => '100.00',
        ]);

        $secondVariantId = DB::table('product_variants')->insertGetId([
            'product_id' => $fixture['productId'],
            'name' => 'White',
            'cost' => '999.00',
        ]);
        $secondItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $fixture['orderId'],
            'product_id' => $fixture['productId'],
            'product_variant_id' => $secondVariantId,
            'product_name_snapshot' => 'Wireless Earbuds',
            'variant_name_snapshot' => 'White',
            'quantity' => 3,
            'unit_cost_snapshot' => '40.50',
        ]);

        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        // 100.00×2 + 40.50×3 = 200.00 + 121.50 = 321.50
        $this->assertSame(1, $result['invoice_count']);
        $this->assertSame(2, $result['payable_line_count']);
        $this->assertSame('321.50', $result['payable_total']);

        $invoice = SupplierSettlementInvoice::query()->firstOrFail();
        $this->assertSame('321.50', (string) $invoice->payable_total);

        $lines = SupplierSettlementInvoiceLine::query()->orderBy('id')->get();
        $this->assertSame('100.00', (string) $lines[0]->unit_cost);
        $this->assertSame(2, (int) $lines[0]->quantity);
        $this->assertSame('200.00', (string) $lines[0]->line_payable);
        $this->assertSame('40.50', (string) $lines[1]->unit_cost);
        $this->assertSame(3, (int) $lines[1]->quantity);
        $this->assertSame('121.50', (string) $lines[1]->line_payable);
        $this->assertSame((int) $secondItemId, (int) $lines[1]->order_item_id);
    }

    public function test_historical_unit_cost_snapshot_is_unchanged_after_current_variant_cost_changes(): void
    {
        $fixture = $this->seedPayableFixture([
            'variant_cost' => '75.00',
            'quantity' => 4,
            'order_item_unit_cost_snapshot' => '60.25',
        ]);

        DB::table('product_variants')->where('id', $fixture['variantId'])->update([
            'cost' => '9999.00',
        ]);

        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame('241.00', $result['payable_total']);
        $line = SupplierSettlementInvoiceLine::query()->firstOrFail();
        $this->assertSame('60.25', (string) $line->unit_cost);
        $this->assertSame('241.00', (string) $line->line_payable);
        $this->assertNotSame('9999.00', (string) $line->unit_cost);
    }

    public function test_non_delivered_order_statuses_are_excluded_from_supplier_payables(): void
    {
        $excludedStatuses = [
            OrderStatus::PENDING,
            OrderStatus::CONFIRMED,
            OrderStatus::PRINTED,
            OrderStatus::PACKING,
            OrderStatus::DISPATCHED,
            OrderStatus::CANCELLED,
            OrderStatus::RETURNED,
        ];

        foreach ($excludedStatuses as $index => $status) {
            $this->truncateSettlementTables();
            $fixture = $this->seedPayableFixture([
                'tracking' => 'TRK-STATUS-'.$index,
                'order_number' => 'ORD-STATUS-'.$index,
                'order_status' => $status->value,
                'order_item_unit_cost_snapshot' => '80.00',
                'quantity' => 2,
            ]);

            if ($status === OrderStatus::RETURNED) {
                DB::table('orders')->where('id', $fixture['orderId'])->update([
                    'returned_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
                    'delivered_at' => null,
                ]);
            }

            $batch = $this->createDailyBatch($fixture);
            $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

            $this->assertSame(0, $result['invoice_count'], $status->value.' must not create payables');
            $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
                'order_item_id' => $fixture['orderItemId'],
            ]);

            if ($status === OrderStatus::RETURNED) {
                $this->assertSame(1, $result['returned_reference_count']);
                $this->assertSame(
                    '0.00',
                    (string) SupplierSettlementReturnedReference::query()->value('display_payable'),
                );
            }
        }
    }

    public function test_cancelled_and_returned_orders_have_zero_supplier_product_payable(): void
    {
        $fixture = $this->seedPayableFixture([
            'tracking' => 'TRK-CANCEL',
            'order_number' => 'ORD-CANCEL',
            'order_status' => OrderStatus::CANCELLED->value,
            'order_item_unit_cost_snapshot' => '150.00',
            'quantity' => 5,
        ]);

        $returnedOrderId = DB::table('orders')->insertGetId([
            'supplier_id' => $fixture['userId'],
            'market_id' => $fixture['marketId'],
            'currency_id' => $fixture['currencyId'],
            'order_number' => 'ORD-RETURN',
            'status' => OrderStatus::RETURNED->value,
            'currency_code_snapshot' => 'LKR',
            'delivered_at' => null,
            'returned_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        ]);
        $returnedItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $returnedOrderId,
            'product_id' => $fixture['productId'],
            'product_variant_id' => $fixture['variantId'],
            'product_name_snapshot' => 'Wireless Earbuds',
            'variant_name_snapshot' => 'Black',
            'quantity' => 5,
            'unit_cost_snapshot' => '150.00',
        ]);

        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(0, SupplierSettlementInvoice::query()->count());
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $fixture['orderItemId'],
        ]);
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $returnedItemId,
        ]);
        $this->assertSame(1, $result['returned_reference_count']);
        $this->assertSame('0.00', (string) SupplierSettlementReturnedReference::query()->value('display_payable'));
    }

    public function test_currency_mismatch_keeps_lkr_and_myr_totals_separate(): void
    {
        $lkr = $this->seedPayableFixture([
            'tracking' => 'TRK-LKR',
            'order_number' => 'ORD-LKR',
            'order_item_unit_cost_snapshot' => '25.00',
            'quantity' => 2,
        ]);

        $myrCurrencyId = DB::table('currencies')->where('code', 'MYR')->value('id');
        if ($myrCurrencyId === null) {
            $myrCurrencyId = DB::table('currencies')->insertGetId(['code' => 'MYR']);
        }

        $myrOrderId = DB::table('orders')->insertGetId([
            'supplier_id' => $lkr['userId'],
            'market_id' => $lkr['marketId'],
            'currency_id' => $myrCurrencyId,
            'order_number' => 'ORD-MYR',
            'status' => OrderStatus::DELIVERED->value,
            'currency_code_snapshot' => 'MYR',
            'delivered_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
            'returned_at' => null,
        ]);
        $myrItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $myrOrderId,
            'product_id' => $lkr['productId'],
            'product_variant_id' => $lkr['variantId'],
            'product_name_snapshot' => 'Wireless Earbuds',
            'variant_name_snapshot' => 'Black',
            'quantity' => 3,
            'unit_cost_snapshot' => '90.00',
        ]);
        $myrShipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $myrOrderId,
            'supplier_id' => $lkr['userId'],
            'courier_service_id' => $lkr['courierServiceId'],
            'tracking_number' => 'TRK-MYR',
        ]);
        $myrImportId = DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $lkr['courierServiceId'],
            'status' => CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
        ]);
        DB::table('courier_receipt_import_rows')->insert([
            'courier_receipt_import_id' => $myrImportId,
            'normalized_tracking_number' => 'TRK-MYR',
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED->value,
            'matched_order_id' => $myrOrderId,
            'matched_shipment_id' => $myrShipmentId,
            'matched_supplier_id' => $lkr['userId'],
        ]);

        $batch = $this->createDailyBatch($lkr);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(1, $result['invoice_count']);
        $this->assertSame('50.00', $result['payable_total']);
        $this->assertDatabaseHas('supplier_settlement_invoice_lines', [
            'order_item_id' => $lkr['orderItemId'],
            'currency_code_snapshot' => 'LKR',
            'line_payable' => '50.00',
        ]);
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $myrItemId,
        ]);
        $this->assertSame(0, SupplierSettlementInvoice::query()->where('currency_code_snapshot', 'MYR')->count());
    }

    public function test_duplicate_settlement_of_same_order_item_is_prevented_across_batches(): void
    {
        $fixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '33.00',
            'quantity' => 1,
        ]);
        $batch = $this->createDailyBatch($fixture);
        $service = $this->app->make(SupplierSettlementGenerationService::class);
        $service->generate($batch);
        $this->app->make(SupplierSettlementLockService::class)
            ->lock($batch->fresh(), User::query()->findOrFail($fixture['adminId']));

        $catchUp = $this->app->make(SupplierSettlementBatchService::class)->createCatchUpBatch(
            Market::query()->findOrFail($fixture['marketId']),
            User::query()->findOrFail($fixture['adminId']),
            CarbonImmutable::parse('2026-10-12', 'Asia/Colombo'),
        );
        $result = $service->generate($catchUp);

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(0, $result['payable_line_count']);
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()
            ->where('order_item_id', $fixture['orderItemId'])
            ->count());
    }

    public function test_pending_imports_and_unmatched_rows_are_excluded(): void
    {
        $pending = $this->seedPayableFixture([
            'import_status' => CourierReceiptImportStatus::PREVIEWED->value,
            'confirmed_at' => null,
            'tracking' => 'TRK-PENDING',
            'order_number' => 'ORD-PENDING',
        ]);
        $unmatched = $this->seedPayableFixture([
            'match_result' => CourierReceiptImportRowMatchResult::UNMATCHED->value,
            'tracking' => 'TRK-UNMATCHED',
            'order_number' => 'ORD-UNMATCHED',
        ]);

        $batch = $this->createDailyBatch($pending);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(0, $result['payable_line_count']);
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $pending['orderItemId'],
        ]);
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $unmatched['orderItemId'],
        ]);
    }

    public function test_inconsistent_shipment_tracking_courier_supplier_currency_or_date_is_rejected(): void
    {
        $cases = [
            ['tracking_mismatch' => true],
            ['courier_service_mismatch' => true],
            ['supplier_mismatch' => true],
            ['currency_mismatch' => true],
            ['confirmed_at' => CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC')],
        ];

        foreach ($cases as $index => $overrides) {
            $this->truncateSettlementTables();
            $fixture = $this->seedPayableFixture(array_merge([
                'tracking' => 'TRK-BAD-'.$index,
                'order_number' => 'ORD-BAD-'.$index,
            ], $overrides));

            $batch = $this->createDailyBatch($fixture);
            $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

            $this->assertSame(0, $result['invoice_count'], 'case '.$index);
            $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
                'order_item_id' => $fixture['orderItemId'],
            ]);
        }
    }

    public function test_zero_value_invoices_are_not_created_and_totals_match_lines(): void
    {
        $fixture = $this->seedPayableFixture([
            'variant_cost' => '100.00',
            'order_item_unit_cost_snapshot' => '0.00',
            'quantity' => 3,
        ]);

        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(0, SupplierSettlementInvoice::query()->count());
        $this->assertSame(0, $result['skipped_candidate_count']);
        $this->assertSame([], $result['generation_exceptions']);
    }

    public function test_null_unit_cost_snapshot_is_skipped_with_structured_exception_and_does_not_use_live_cost(): void
    {
        $fixture = $this->seedPayableFixture([
            'variant_cost' => '88.00',
            'order_item_unit_cost_snapshot' => '12.00',
            'quantity' => 2,
        ]);

        DB::table('order_items')->where('id', $fixture['orderItemId'])->update([
            'unit_cost_snapshot' => null,
        ]);

        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(0, $result['payable_line_count']);
        $this->assertSame(1, $result['skipped_candidate_count']);
        $this->assertCount(1, $result['generation_exceptions']);
        $this->assertSame('INVALID_UNIT_COST_SNAPSHOT', $result['generation_exceptions'][0]['reason_code']);
        $this->assertSame((int) $fixture['orderId'], $result['generation_exceptions'][0]['order_id']);
        $this->assertSame((int) $fixture['orderItemId'], $result['generation_exceptions'][0]['order_item_id']);
        $this->assertArrayHasKey('live_variant_cost_ignored', $result['generation_exceptions'][0]['context']);
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $fixture['orderItemId'],
        ]);
    }

    public function test_malformed_unit_cost_snapshot_is_skipped_without_aborting_valid_siblings(): void
    {
        $valid = $this->seedPayableFixture([
            'tracking' => 'TRK-VALID-COST',
            'order_number' => 'ORD-VALID-COST',
            'order_item_unit_cost_snapshot' => '25.50',
            'quantity' => 2,
            'variant_cost' => '999.00',
        ]);

        $badOrderId = DB::table('orders')->insertGetId([
            'supplier_id' => $valid['userId'],
            'market_id' => $valid['marketId'],
            'currency_id' => $valid['currencyId'],
            'order_number' => 'ORD-BAD-COST',
            'status' => OrderStatus::DELIVERED->value,
            'currency_code_snapshot' => 'LKR',
            'delivered_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
            'returned_at' => null,
        ]);
        $badItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $badOrderId,
            'product_id' => $valid['productId'],
            'product_variant_id' => $valid['variantId'],
            'product_name_snapshot' => 'Wireless Earbuds',
            'variant_name_snapshot' => 'Black',
            'quantity' => 1,
            'unit_cost_snapshot' => 'not-a-number',
        ]);
        $badShipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $badOrderId,
            'supplier_id' => $valid['userId'],
            'courier_service_id' => $valid['courierServiceId'],
            'tracking_number' => 'TRK-BAD-COST',
        ]);
        $badImportId = DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $valid['courierServiceId'],
            'status' => CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
        ]);
        DB::table('courier_receipt_import_rows')->insert([
            'courier_receipt_import_id' => $badImportId,
            'normalized_tracking_number' => 'TRK-BAD-COST',
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED->value,
            'matched_order_id' => $badOrderId,
            'matched_shipment_id' => $badShipmentId,
            'matched_supplier_id' => $valid['userId'],
        ]);

        $batch = $this->createDailyBatch($valid);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(1, $result['invoice_count']);
        $this->assertSame(1, $result['payable_line_count']);
        $this->assertSame('51.00', $result['payable_total']);
        $this->assertSame(['LKR' => '51.00'], $result['payable_totals_by_currency']);
        $this->assertSame(1, $result['skipped_candidate_count']);
        $this->assertSame('INVALID_UNIT_COST_SNAPSHOT', $result['generation_exceptions'][0]['reason_code']);
        $this->assertSame((int) $badItemId, $result['generation_exceptions'][0]['order_item_id']);
        $this->assertDatabaseHas('supplier_settlement_invoice_lines', [
            'order_item_id' => $valid['orderItemId'],
            'unit_cost' => '25.50',
            'line_payable' => '51.00',
        ]);
        $this->assertDatabaseMissing('supplier_settlement_invoice_lines', [
            'order_item_id' => $badItemId,
        ]);
    }

    public function test_repeated_generation_is_idempotent(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $service = $this->app->make(SupplierSettlementGenerationService::class);

        $first = $service->generate($batch);
        $second = $service->generate($batch->fresh());

        $this->assertSame(1, $first['invoice_count']);
        $this->assertSame(1, $second['invoice_count']);
        $this->assertSame(1, SupplierSettlementInvoice::query()->count());
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());
        $this->assertSame($first['payable_total'], $second['payable_total']);
    }

    public function test_invoice_and_return_cross_table_exclusivity_for_pre_lock_returns(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());

        DB::table('orders')->where('id', $fixture['orderId'])->update([
            'status' => OrderStatus::RETURNED->value,
            'returned_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
            'delivered_at' => null,
        ]);

        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch->fresh());

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(1, $result['returned_reference_count']);
        $this->assertSame(0, SupplierSettlementInvoiceLine::query()->count());
        $this->assertSame(1, SupplierSettlementReturnedReference::query()->count());
        $this->assertSame(
            SupplierSettlementReturnedReference::SOURCE_PRE_LOCK,
            SupplierSettlementReturnedReference::query()->firstOrFail()->source,
        );
    }

    public function test_later_return_preserves_locked_invoice_and_creates_informational_reference(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);
        $locked = $this->app->make(SupplierSettlementLockService::class)
            ->lock($batch->fresh(), User::query()->findOrFail($fixture['adminId']));

        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $locked->status);
        $historicalInvoiceId = (int) SupplierSettlementInvoice::query()->value('id');

        DB::table('orders')->where('id', $fixture['orderId'])->update([
            'status' => OrderStatus::RETURNED->value,
            'returned_at' => CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'),
        ]);

        $catchUp = $this->app->make(SupplierSettlementBatchService::class)->createCatchUpBatch(
            Market::query()->findOrFail($fixture['marketId']),
            User::query()->findOrFail($fixture['adminId']),
            CarbonImmutable::parse('2026-10-13', 'Asia/Colombo'),
        );

        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($catchUp);

        $this->assertSame(1, SupplierSettlementInvoice::query()->count());
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());
        $this->assertSame(1, $result['returned_reference_count']);

        $reference = SupplierSettlementReturnedReference::query()->firstOrFail();
        $this->assertSame(SupplierSettlementReturnedReference::SOURCE_POST_LOCK_CATCH_UP, $reference->source);
        $this->assertSame($historicalInvoiceId, (int) $reference->prior_payable_invoice_id);
        $this->assertSame('0.00', (string) $reference->display_payable);
        $this->assertSame($historicalInvoiceId, (int) SupplierSettlementInvoice::query()->value('id'));
    }

    public function test_locked_batches_cannot_be_rebuilt_and_repeated_lock_is_stable(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $lockService = $this->app->make(SupplierSettlementLockService::class);
        $actor = User::query()->findOrFail($fixture['adminId']);
        $firstLock = $lockService->lock($batch->fresh(), $actor);
        $secondLock = $lockService->lock($batch->fresh(), $actor);

        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $firstLock->status);
        $this->assertSame((string) $firstLock->locked_at, (string) $secondLock->locked_at);
        $this->assertSame(1, SupplierSettlementInvoice::query()->count());

        $this->expectException(SupplierSettlementException::class);
        $this->expectExceptionMessage('Only OPEN settlement batches can be generated or rebuilt.');
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch->fresh());
    }

    public function test_generation_acquires_market_lock_before_batch_lock(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);

        $lockOrder = $this->captureForUpdateTableOrder(function () use ($batch): void {
            $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);
        });

        $marketIndex = array_search('markets', $lockOrder, true);
        $batchIndex = array_search('supplier_settlement_batches', $lockOrder, true);

        $this->assertNotFalse($marketIndex, 'Expected a markets FOR UPDATE lock during generation.');
        $this->assertNotFalse($batchIndex, 'Expected a supplier_settlement_batches FOR UPDATE lock during generation.');
        $this->assertLessThan(
            $batchIndex,
            $marketIndex,
            'Generation must lock markets before supplier_settlement_batches. Observed: '.implode(' → ', $lockOrder)
        );
        $this->assertSame(1, SupplierSettlementInvoice::query()->count());
        $this->assertSame('50.00', (string) SupplierSettlementInvoiceLine::query()->value('unit_cost'));
    }

    public function test_locking_acquires_market_lock_before_batch_lock_and_stays_idempotent(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);
        $actor = User::query()->findOrFail($fixture['adminId']);

        $lockOrder = $this->captureForUpdateTableOrder(function () use ($batch, $actor): void {
            $this->app->make(SupplierSettlementLockService::class)->lock($batch->fresh(), $actor);
        });

        $marketIndex = array_search('markets', $lockOrder, true);
        $batchIndex = array_search('supplier_settlement_batches', $lockOrder, true);

        $this->assertNotFalse($marketIndex, 'Expected a markets FOR UPDATE lock during locking.');
        $this->assertNotFalse($batchIndex, 'Expected a supplier_settlement_batches FOR UPDATE lock during locking.');
        $this->assertLessThan(
            $batchIndex,
            $marketIndex,
            'Locking must lock markets before supplier_settlement_batches. Observed: '.implode(' → ', $lockOrder)
        );

        $locked = SupplierSettlementBatch::query()->findOrFail($batch->id);
        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $locked->status);

        $again = $this->app->make(SupplierSettlementLockService::class)->lock($batch->fresh(), $actor);
        $this->assertSame((string) $locked->locked_at, (string) $again->locked_at);
        $this->assertSame(1, SupplierSettlementInvoice::query()->count());
    }

    public function test_closed_batch_cannot_be_generated_or_locked(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        DB::table('supplier_settlement_batches')->where('id', $batch->id)->update([
            'status' => SupplierSettlementBatch::STATUS_CLOSED,
            'closed_by' => $fixture['adminId'],
            'closed_at' => now(),
        ]);

        $actor = User::query()->findOrFail($fixture['adminId']);

        try {
            $this->app->make(SupplierSettlementGenerationService::class)->generate($batch->fresh());
            $this->fail('Expected generation to reject CLOSED batches.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_BATCH_NOT_OPEN', $exception->errorCode);
        }

        try {
            $this->app->make(SupplierSettlementLockService::class)->lock($batch->fresh(), $actor);
            $this->fail('Expected locking to reject CLOSED batches.');
        } catch (SupplierSettlementException $exception) {
            $this->assertSame('SETTLEMENT_BATCH_NOT_OPEN', $exception->errorCode);
        }
    }

    /**
     * Capture table names for SQL statements that request row locks (FOR UPDATE).
     *
     * @param  callable(): void  $callback
     * @return list<string>
     */
    private function captureForUpdateTableOrder(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $lockOrder = [];

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            if (! str_contains($sql, 'for update')) {
                continue;
            }

            if (
                str_contains($sql, 'from `markets`')
                || str_contains($sql, 'from "markets"')
                || preg_match('/\bfrom\s+markets\b/', $sql)
            ) {
                $lockOrder[] = 'markets';

                continue;
            }

            if (
                str_contains($sql, 'from `supplier_settlement_batches`')
                || str_contains($sql, 'from "supplier_settlement_batches"')
                || preg_match('/\bfrom\s+supplier_settlement_batches\b/', $sql)
            ) {
                $lockOrder[] = 'supplier_settlement_batches';
            }
        }

        return $lockOrder;
    }

    public function test_daily_batch_create_is_idempotent_and_catch_up_sequences_do_not_collide(): void
    {
        $fixture = $this->seedPayableFixture();
        $market = Market::query()->findOrFail($fixture['marketId']);
        $actor = User::query()->findOrFail($fixture['adminId']);
        $service = $this->app->make(SupplierSettlementBatchService::class);

        $at = CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC');
        $first = $service->createOrGetDailyBatch($market, $actor, $at);
        $second = $service->createOrGetDailyBatch($market, $actor, $at);
        $catchUpA = $service->createCatchUpBatch($market, $actor, CarbonImmutable::parse('2026-10-12'));
        $catchUpB = $service->createCatchUpBatch($market, $actor, CarbonImmutable::parse('2026-10-12'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(SupplierSettlementBatch::KIND_DAILY, $first->kind);
        $this->assertSame(1, (int) $first->sequence);
        $this->assertSame(SupplierSettlementBatch::KIND_CATCH_UP, $catchUpA->kind);
        $this->assertSame(1, (int) $catchUpA->sequence);
        $this->assertSame(2, (int) $catchUpB->sequence);
        $this->assertSame(3, SupplierSettlementBatch::query()->count());
    }

    public function test_returned_only_supplier_does_not_receive_zero_value_invoice(): void
    {
        $fixture = $this->seedReturnedFixture();
        $batch = $this->createDailyBatch($fixture);
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(0, $result['invoice_count']);
        $this->assertSame(1, $result['returned_reference_count']);
        $this->assertSame(0, SupplierSettlementInvoice::query()->count());
        $this->assertSame('0.00', (string) SupplierSettlementReturnedReference::query()->value('display_payable'));
    }

    public function test_lock_rejects_invoice_total_mismatch(): void
    {
        $fixture = $this->seedPayableFixture();
        $batch = $this->createDailyBatch($fixture);
        $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        DB::table('supplier_settlement_invoices')->update(['payable_total' => 1.00]);

        $this->expectException(SupplierSettlementException::class);
        $this->expectExceptionMessage('Invoice payable total does not equal the sum of its lines.');
        $this->app->make(SupplierSettlementLockService::class)
            ->lock($batch->fresh(), User::query()->findOrFail($fixture['adminId']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|string>
     */
    private function seedPayableFixture(array $overrides = []): array
    {
        $currencyId = DB::table('currencies')->insertGetId(['code' => 'LKR']);
        $otherCurrencyId = DB::table('currencies')->insertGetId(['code' => 'MYR']);
        $marketId = DB::table('markets')->insertGetId([
            'code' => 'lk',
            'currency_id' => $currencyId,
            'market_number' => 1,
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Supplier Co']);
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co']);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $otherUserId = DB::table('users')->insertGetId(['company_id' => $otherCompanyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => null]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Wireless Earbuds',
            'market_id' => $marketId,
        ]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => 'Black',
            'cost' => $overrides['variant_cost'] ?? '100.00',
        ]);

        $orderCurrencyId = ! empty($overrides['currency_mismatch']) ? $otherCurrencyId : $currencyId;
        $supplierId = $userId;
        $matchedSupplierId = $userId;
        if (! empty($overrides['supplier_mismatch'])) {
            // Order supplier has no company — company resolution must fail.
            DB::table('users')->where('id', $userId)->update(['company_id' => null]);
            $matchedSupplierId = $otherUserId;
        }
        $tracking = (string) ($overrides['tracking'] ?? 'TRK-'.Str::upper(Str::random(6)));
        $confirmedAt = array_key_exists('confirmed_at', $overrides)
            ? $overrides['confirmed_at']
            : CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC');

        $orderStatus = (string) ($overrides['order_status'] ?? OrderStatus::DELIVERED->value);

        $orderId = DB::table('orders')->insertGetId([
            'supplier_id' => $supplierId,
            'market_id' => $marketId,
            'currency_id' => $orderCurrencyId,
            'order_number' => $overrides['order_number'] ?? 'ORD-'.Str::upper(Str::random(5)),
            'status' => $orderStatus,
            'currency_code_snapshot' => ! empty($overrides['currency_mismatch']) ? 'MYR' : 'LKR',
            'delivered_at' => $orderStatus === OrderStatus::DELIVERED->value
                ? CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC')
                : null,
            'returned_at' => null,
        ]);

        $orderItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Wireless Earbuds',
            'variant_name_snapshot' => 'Black',
            'quantity' => $overrides['quantity'] ?? 1,
            'unit_cost_snapshot' => array_key_exists('order_item_unit_cost_snapshot', $overrides)
                ? $overrides['order_item_unit_cost_snapshot']
                : '50.00',
        ]);

        $courierServiceId = DB::table('courier_services')->insertGetId([]);
        $importCourierServiceId = ! empty($overrides['courier_service_mismatch'])
            ? DB::table('courier_services')->insertGetId([])
            : $courierServiceId;

        $shipmentTracking = ! empty($overrides['tracking_mismatch']) ? $tracking.'-X' : $tracking;
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $orderId,
            'supplier_id' => $supplierId,
            'courier_service_id' => $courierServiceId,
            'tracking_number' => $shipmentTracking,
        ]);

        $importId = DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $importCourierServiceId,
            'status' => $overrides['import_status'] ?? CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => $confirmedAt,
        ]);

        $importRowId = DB::table('courier_receipt_import_rows')->insertGetId([
            'courier_receipt_import_id' => $importId,
            'normalized_tracking_number' => $tracking,
            'match_result' => $overrides['match_result'] ?? CourierReceiptImportRowMatchResult::MATCHED->value,
            'matched_order_id' => $orderId,
            'matched_shipment_id' => $shipmentId,
            'matched_supplier_id' => $matchedSupplierId,
        ]);

        return [
            'currencyId' => $currencyId,
            'marketId' => $marketId,
            'companyId' => $companyId,
            'userId' => $userId,
            'adminId' => $adminId,
            'productId' => $productId,
            'variantId' => $variantId,
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
            'shipmentId' => $shipmentId,
            'courierServiceId' => $courierServiceId,
            'importId' => $importId,
            'importRowId' => $importRowId,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function seedReturnedFixture(): array
    {
        $currencyId = DB::table('currencies')->insertGetId(['code' => 'LKR']);
        $marketId = DB::table('markets')->insertGetId([
            'code' => 'lk',
            'currency_id' => $currencyId,
            'market_number' => 1,
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Return Supplier']);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => null]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Return Product',
            'market_id' => $marketId,
        ]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => 'Default',
            'cost' => '80.00',
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'supplier_id' => $userId,
            'market_id' => $marketId,
            'currency_id' => $currencyId,
            'order_number' => 'ORD-RET-1',
            'status' => OrderStatus::RETURNED->value,
            'currency_code_snapshot' => 'LKR',
            'delivered_at' => null,
            'returned_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Return Product',
            'variant_name_snapshot' => 'Default',
            'quantity' => 1,
            'unit_cost_snapshot' => '80.00',
        ]);

        return [
            'currencyId' => $currencyId,
            'marketId' => $marketId,
            'companyId' => $companyId,
            'userId' => $userId,
            'adminId' => $adminId,
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
        ];
    }

    /**
     * @param  array<string, int|string>  $fixture
     */
    private function createDailyBatch(array $fixture): SupplierSettlementBatch
    {
        return $this->app->make(SupplierSettlementBatchService::class)->createOrGetDailyBatch(
            Market::query()->findOrFail($fixture['marketId']),
            User::query()->findOrFail($fixture['adminId']),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
        );
    }
}
