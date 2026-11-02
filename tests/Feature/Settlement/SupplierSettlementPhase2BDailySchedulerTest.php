<?php

namespace Tests\Feature\Settlement;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementInvoiceLine;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Settlement\SupplierSettlementBatchService;
use Feeder\Core\Services\Settlement\SupplierSettlementDailyBatchProcessor;
use Feeder\Core\Services\Settlement\SupplierSettlementGenerationService;
use Feeder\Core\Services\Settlement\SupplierSettlementLockService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsSupplierSettlementPhase2Schema;
use Tests\TestCase;

class SupplierSettlementPhase2BDailySchedulerTest extends TestCase
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

    public function test_daily_processor_creates_and_generates_batch_for_market_business_date(): void
    {
        $fixture = $this->seedPayableMarketFixture([
            'market_code' => 'lk',
            'currency_code' => 'LKR',
            'tracking' => 'TRK-LK-1',
            'order_number' => 'ORD-LK-1',
            'unit_cost' => '40.00',
            'quantity' => 2,
            'confirmed_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
        ]);

        $summary = $this->app->make(SupplierSettlementDailyBatchProcessor::class)->process(
            User::query()->findOrFail($fixture['adminId']),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
            [(int) $fixture['marketId']],
        );

        $this->assertSame(1, $summary['success_market_count']);
        $this->assertSame(0, $summary['failed_market_count']);
        $this->assertSame('success', $summary['markets'][0]['status']);
        $this->assertSame('2026-10-12', $summary['markets'][0]['settlement_date']);
        $this->assertSame(1, $summary['markets'][0]['invoice_count']);
        $this->assertSame(['LKR' => '80.00'], $summary['markets'][0]['payable_totals_by_currency']);

        $batch = SupplierSettlementBatch::query()->firstOrFail();
        $this->assertSame(SupplierSettlementBatch::KIND_DAILY, $batch->kind);
        $this->assertSame(SupplierSettlementBatch::STATUS_OPEN, $batch->status);
        $this->assertSame(SupplierSettlementBatch::GENERATION_SOURCE_SCHEDULED, $batch->generation_source);
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());
        $this->assertNull($batch->locked_at);
        $this->assertNull($batch->closed_at);
        $this->assertSame(0, DB::table('supplier_settlement_bank_exports')->count());
        $this->assertSame(0, DB::table('supplier_payments')->count());
    }

    public function test_daily_processor_is_idempotent_on_repeat_execution(): void
    {
        $fixture = $this->seedPayableMarketFixture([
            'unit_cost' => '15.00',
            'quantity' => 1,
        ]);
        $processor = $this->app->make(SupplierSettlementDailyBatchProcessor::class);
        $actor = User::query()->findOrFail($fixture['adminId']);
        $at = CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC');

        $first = $processor->process($actor, $at, [(int) $fixture['marketId']]);
        $second = $processor->process($actor, $at, [(int) $fixture['marketId']]);

        $this->assertSame($first['markets'][0]['batch_id'], $second['markets'][0]['batch_id']);
        $this->assertSame(1, SupplierSettlementBatch::query()->count());
        $this->assertSame(1, SupplierSettlementInvoice::query()->count());
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());
        $this->assertSame($first['markets'][0]['payable_total'], $second['markets'][0]['payable_total']);
    }

    public function test_daily_processor_handles_multiple_markets_and_separate_currencies(): void
    {
        $lk = $this->seedPayableMarketFixture([
            'market_code' => 'lk',
            'currency_code' => 'LKR',
            'tracking' => 'TRK-LK-MULTI',
            'order_number' => 'ORD-LK-MULTI',
            'unit_cost' => '10.00',
            'quantity' => 3,
        ]);
        $my = $this->seedPayableMarketFixture([
            'market_code' => 'my',
            'currency_code' => 'MYR',
            'tracking' => 'TRK-MY-MULTI',
            'order_number' => 'ORD-MY-MULTI',
            'unit_cost' => '7.50',
            'quantity' => 2,
            'confirmed_at' => CarbonImmutable::parse('2026-10-12 02:00:00', 'UTC'),
        ]);

        $summary = $this->app->make(SupplierSettlementDailyBatchProcessor::class)->process(
            User::query()->findOrFail($lk['adminId']),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
            [(int) $lk['marketId'], (int) $my['marketId']],
        );

        $this->assertSame(2, $summary['success_market_count']);
        $this->assertSame(0, $summary['failed_market_count']);
        $this->assertSame(2, SupplierSettlementBatch::query()->count());

        $byCode = collect($summary['markets'])->keyBy('market_code');
        $this->assertSame(['LKR' => '30.00'], $byCode['lk']['payable_totals_by_currency']);
        $this->assertSame(['MYR' => '15.00'], $byCode['my']['payable_totals_by_currency']);
    }

    public function test_daily_processor_skips_closed_batch_and_continues_other_markets(): void
    {
        $ok = $this->seedPayableMarketFixture([
            'market_code' => 'lk',
            'currency_code' => 'LKR',
            'tracking' => 'TRK-OK',
            'order_number' => 'ORD-OK',
            'unit_cost' => '20.00',
            'quantity' => 1,
        ]);

        $blockedMarketId = DB::table('markets')->insertGetId([
            'code' => 'my',
            'currency_id' => $ok['currencyId'],
            'market_number' => 2,
            'is_active' => true,
        ]);
        $blockedBatchId = DB::table('supplier_settlement_batches')->insertGetId([
            'uuid' => 'BATBLOCK01',
            'batch_number' => 'SSB-MY-BLOCKED',
            'market_id' => $blockedMarketId,
            'market_code_snapshot' => 'MY',
            'timezone_snapshot' => 'Asia/Kuala_Lumpur',
            'settlement_date' => '2026-10-12',
            'kind' => SupplierSettlementBatch::KIND_DAILY,
            'sequence' => 1,
            'status' => SupplierSettlementBatch::STATUS_CLOSED,
            'generation_source' => SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $ok['adminId'],
            'generated_at' => now(),
            'closed_by' => $ok['adminId'],
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->app->make(SupplierSettlementDailyBatchProcessor::class)->process(
            User::query()->findOrFail($ok['adminId']),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
            [(int) $ok['marketId'], (int) $blockedMarketId],
        );

        $this->assertSame(1, $summary['success_market_count']);
        $this->assertSame(1, $summary['skipped_market_count']);
        $this->assertSame(0, $summary['failed_market_count']);

        $byCode = collect($summary['markets'])->keyBy('market_code');
        $this->assertSame('success', $byCode['lk']['status']);
        $this->assertSame('skipped', $byCode['my']['status']);
        $this->assertSame((int) $blockedBatchId, (int) $byCode['my']['batch_id']);
        $this->assertSame(SupplierSettlementBatch::STATUS_OPEN, SupplierSettlementBatch::query()
            ->where('market_id', $ok['marketId'])
            ->value('status'));
    }

    public function test_daily_processor_reports_failure_for_one_market_without_aborting_others(): void
    {
        $ok = $this->seedPayableMarketFixture([
            'market_code' => 'lk',
            'currency_code' => 'LKR',
            'tracking' => 'TRK-OK-FAIL',
            'order_number' => 'ORD-OK-FAIL',
            'unit_cost' => '20.00',
            'quantity' => 1,
        ]);

        $brokenMarketId = DB::table('markets')->insertGetId([
            'code' => 'my',
            'currency_id' => $ok['currencyId'],
            'market_number' => 2,
            'is_active' => true,
        ]);

        // Voided DAILY still occupies market/date/kind/sequence uniqueness, so recreate fails.
        DB::table('supplier_settlement_batches')->insert([
            'uuid' => 'BATVOID001',
            'batch_number' => 'SSB-MY-VOID',
            'market_id' => $brokenMarketId,
            'market_code_snapshot' => 'MY',
            'timezone_snapshot' => 'Asia/Kuala_Lumpur',
            'settlement_date' => '2026-10-12',
            'kind' => SupplierSettlementBatch::KIND_DAILY,
            'sequence' => 1,
            'status' => SupplierSettlementBatch::STATUS_OPEN,
            'generation_source' => SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $ok['adminId'],
            'generated_at' => now(),
            'voided_by' => $ok['adminId'],
            'voided_at' => now(),
            'void_reason' => 'test void',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->app->make(SupplierSettlementDailyBatchProcessor::class)->process(
            User::query()->findOrFail($ok['adminId']),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
            [(int) $ok['marketId'], (int) $brokenMarketId],
        );

        $this->assertSame(1, $summary['success_market_count']);
        $this->assertSame(1, $summary['failed_market_count']);
        $byCode = collect($summary['markets'])->keyBy('market_code');
        $this->assertSame('success', $byCode['lk']['status']);
        $this->assertSame('failed', $byCode['my']['status']);
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()
            ->where('order_item_id', $ok['orderItemId'])
            ->count());
    }

    public function test_artisan_command_is_idempotent_and_fails_when_a_market_errors(): void
    {
        $fixture = $this->seedPayableMarketFixture([
            'unit_cost' => '11.00',
            'quantity' => 1,
        ]);

        config(['feeder.settlement.scheduled_actor_user_id' => $fixture['adminId']]);

        $exitFirst = Artisan::call('settlement:process-daily-supplier-batches', [
            '--at' => '2026-10-12T10:00:00Z',
            '--market' => [(string) $fixture['marketId']],
        ]);
        $exitSecond = Artisan::call('settlement:process-daily-supplier-batches', [
            '--at' => '2026-10-12T10:00:00Z',
            '--market' => [(string) $fixture['marketId']],
        ]);

        $this->assertSame(0, $exitFirst);
        $this->assertSame(0, $exitSecond);
        $this->assertSame(1, SupplierSettlementBatch::query()->count());
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());

        // Force a hard failure path: void an OPEN batch mid-flight via processor market loop.
        $otherMarketId = DB::table('markets')->insertGetId([
            'code' => 'my',
            'currency_id' => $fixture['currencyId'],
            'market_number' => 2,
            'is_active' => true,
        ]);
        DB::table('supplier_settlement_batches')->insert([
            'uuid' => 'BATVOID002',
            'batch_number' => 'SSB-MY-VOID-2',
            'market_id' => $otherMarketId,
            'market_code_snapshot' => 'MY',
            'timezone_snapshot' => 'Asia/Kuala_Lumpur',
            'settlement_date' => '2026-10-12',
            'kind' => SupplierSettlementBatch::KIND_DAILY,
            'sequence' => 1,
            'status' => SupplierSettlementBatch::STATUS_OPEN,
            'generation_source' => SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $fixture['adminId'],
            'generated_at' => now(),
            'voided_by' => $fixture['adminId'],
            'voided_at' => now(),
            'void_reason' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitFailed = Artisan::call('settlement:process-daily-supplier-batches', [
            '--actor' => (string) $fixture['adminId'],
            '--at' => '2026-10-12T10:00:00Z',
            '--market' => [(string) $fixture['marketId'], (string) $otherMarketId],
        ]);

        $this->assertSame(1, $exitFailed);
        $this->assertStringContainsString('FAILED', Artisan::output());
    }

    public function test_daily_run_does_not_lock_export_or_pay_and_preserves_catch_up(): void
    {
        $fixture = $this->seedPayableMarketFixture([
            'unit_cost' => '9.00',
            'quantity' => 1,
        ]);
        $actor = User::query()->findOrFail($fixture['adminId']);
        $market = Market::query()->findOrFail($fixture['marketId']);

        $this->app->make(SupplierSettlementDailyBatchProcessor::class)->process(
            $actor,
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
            [(int) $fixture['marketId']],
        );

        $catchUp = $this->app->make(SupplierSettlementBatchService::class)->createCatchUpBatch(
            $market,
            $actor,
            CarbonImmutable::parse('2026-10-12', 'Asia/Colombo'),
        );
        $this->assertSame(SupplierSettlementBatch::KIND_CATCH_UP, $catchUp->kind);
        $this->assertSame(1, (int) $catchUp->sequence);

        $daily = SupplierSettlementBatch::query()
            ->where('kind', SupplierSettlementBatch::KIND_DAILY)
            ->firstOrFail();
        $this->assertSame(SupplierSettlementBatch::STATUS_OPEN, $daily->status);
        $this->assertNull($daily->locked_at);
        $this->assertSame(0, DB::table('supplier_settlement_bank_exports')->count());
        $this->assertSame(0, DB::table('supplier_payments')->count());

        // Existing lock path still works after scheduled generation.
        $locked = $this->app->make(SupplierSettlementLockService::class)->lock($daily->fresh(), $actor);
        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $locked->status);
    }

    public function test_existing_generation_regressions_still_pass_through_services(): void
    {
        $fixture = $this->seedPayableMarketFixture([
            'unit_cost' => '50.00',
            'quantity' => 1,
            'variant_cost' => '125.00',
        ]);

        DB::table('product_variants')->where('id', $fixture['variantId'])->update(['cost' => '9999.00']);

        $batch = $this->app->make(SupplierSettlementBatchService::class)->createOrGetDailyBatch(
            Market::query()->findOrFail($fixture['marketId']),
            User::query()->findOrFail($fixture['adminId']),
            CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
        );
        $result = $this->app->make(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame('50.00', $result['payable_total']);
        $this->assertSame('50.00', (string) SupplierSettlementInvoiceLine::query()->value('unit_cost'));
    }

    public function test_scheduled_run_discovers_outstanding_business_dates_without_a_selected_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 08:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'));

        try {
            $fixture = $this->seedPayableMarketFixture([
                'unit_cost' => '40.00',
                'quantity' => 2,
                'variant_cost' => '999.00',
                'confirmed_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
            ]);
            config(['feeder.settlement.scheduled_actor_user_id' => $fixture['adminId']]);

            $exitCode = Artisan::call('settlement:process-daily-supplier-batches', [
                '--market' => [(string) $fixture['marketId']],
            ]);

            $this->assertSame(0, $exitCode);
            $batch = SupplierSettlementBatch::query()->firstOrFail();
            $this->assertSame('2026-10-12', $batch->settlement_date?->toDateString());
            $this->assertSame(SupplierSettlementBatch::KIND_DAILY, $batch->kind);
            $this->assertSame(SupplierSettlementBatch::GENERATION_SOURCE_SCHEDULED, $batch->generation_source);
            $this->assertSame('40.00', (string) SupplierSettlementInvoiceLine::query()->value('unit_cost'));
            $this->assertSame('80.00', (string) SupplierSettlementInvoiceLine::query()->value('line_payable'));
            $this->assertSame(1, SupplierSettlementBatch::query()->count());

            $secondExit = Artisan::call('settlement:process-daily-supplier-batches', [
                '--market' => [(string) $fixture['marketId']],
            ]);

            $this->assertSame(0, $secondExit);
            $this->assertSame(1, SupplierSettlementBatch::query()->count());
            $this->assertSame(1, SupplierSettlementInvoiceLine::query()->count());
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_locked_daily_batch_keeps_new_items_on_a_catch_up_batch_without_duplicates(): void
    {
        $fixture = $this->seedPayableMarketFixture([
            'unit_cost' => '20.00',
            'quantity' => 1,
            'tracking' => 'TRK-LOCKED-1',
            'order_number' => 'ORD-LOCKED-1',
        ]);
        $actor = User::query()->findOrFail($fixture['adminId']);
        $at = CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC');
        $processor = $this->app->make(SupplierSettlementDailyBatchProcessor::class);

        $processor->process($actor, $at, [(int) $fixture['marketId']]);
        $daily = SupplierSettlementBatch::query()->where('kind', SupplierSettlementBatch::KIND_DAILY)->firstOrFail();
        $this->app->make(SupplierSettlementLockService::class)->lock($daily->fresh(), $actor);

        $productId = (int) DB::table('order_items')->where('id', $fixture['orderItemId'])->value('product_id');
        $secondOrderId = DB::table('orders')->insertGetId([
            'supplier_id' => $fixture['userId'],
            'market_id' => $fixture['marketId'],
            'currency_id' => $fixture['currencyId'],
            'order_number' => 'ORD-LOCKED-2',
            'status' => OrderStatus::DELIVERED->value,
            'currency_code_snapshot' => 'LKR',
            'delivered_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
            'returned_at' => null,
        ]);
        $secondItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $secondOrderId,
            'product_id' => $productId,
            'product_variant_id' => $fixture['variantId'],
            'product_name_snapshot' => 'Product',
            'variant_name_snapshot' => 'Default',
            'quantity' => 1,
            'unit_cost_snapshot' => '8.00',
        ]);
        $courierServiceId = (int) DB::table('shipments')->where('order_id', $fixture['orderId'])->value('courier_service_id');
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $secondOrderId,
            'supplier_id' => $fixture['userId'],
            'courier_service_id' => $courierServiceId,
            'tracking_number' => 'TRK-LOCKED-2',
        ]);
        $importId = DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $courierServiceId,
            'status' => CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC'),
        ]);
        DB::table('courier_receipt_import_rows')->insert([
            'courier_receipt_import_id' => $importId,
            'normalized_tracking_number' => 'TRK-LOCKED-2',
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED->value,
            'matched_order_id' => $secondOrderId,
            'matched_shipment_id' => $shipmentId,
            'matched_supplier_id' => $fixture['userId'],
        ]);

        DB::table('product_variants')->where('id', $fixture['variantId'])->update(['cost' => '500.00']);

        $processor->process($actor, $at, [(int) $fixture['marketId']]);

        $daily->refresh();
        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $daily->status);
        $this->assertSame(1, SupplierSettlementInvoiceLine::query()
            ->where('order_item_id', $fixture['orderItemId'])
            ->count());

        $catchUp = SupplierSettlementBatch::query()
            ->where('kind', SupplierSettlementBatch::KIND_CATCH_UP)
            ->firstOrFail();
        $this->assertSame('2026-10-12', $catchUp->settlement_date?->toDateString());
        $catchUpLine = SupplierSettlementInvoiceLine::query()->where('order_item_id', $secondItemId)->firstOrFail();
        $this->assertSame('8.00', (string) $catchUpLine->unit_cost);
        $this->assertSame('8.00', (string) $catchUpLine->line_payable);
        $this->assertSame(2, SupplierSettlementInvoiceLine::query()->count());

        $processor->process($actor, $at, [(int) $fixture['marketId']]);

        $this->assertSame(2, SupplierSettlementBatch::query()->count());
        $this->assertSame(2, SupplierSettlementInvoiceLine::query()->count());
        $this->assertSame(SupplierSettlementBatch::STATUS_LOCKED, $daily->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|string>
     */
    private function seedPayableMarketFixture(array $overrides = []): array
    {
        $currencyCode = (string) ($overrides['currency_code'] ?? 'LKR');
        $marketCode = (string) ($overrides['market_code'] ?? 'lk');

        $currencyId = DB::table('currencies')->where('code', $currencyCode)->value('id');
        if ($currencyId === null) {
            $currencyId = DB::table('currencies')->insertGetId(['code' => $currencyCode]);
        }

        $marketId = DB::table('markets')->insertGetId([
            'code' => $marketCode,
            'currency_id' => $currencyId,
            'market_number' => $marketCode === 'my' ? 2 : 1,
            'is_active' => true,
        ]);
        $companyId = DB::table('companies')->insertGetId(['name' => 'Supplier '.$marketCode]);
        $userId = DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = DB::table('users')->insertGetId(['company_id' => null]);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Product '.$marketCode,
            'market_id' => $marketId,
        ]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => 'Default',
            'cost' => $overrides['variant_cost'] ?? '100.00',
        ]);

        $confirmedAt = $overrides['confirmed_at']
            ?? CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC');
        $tracking = (string) ($overrides['tracking'] ?? 'TRK-'.Str::upper(Str::random(6)));

        $orderId = DB::table('orders')->insertGetId([
            'supplier_id' => $userId,
            'market_id' => $marketId,
            'currency_id' => $currencyId,
            'order_number' => $overrides['order_number'] ?? 'ORD-'.Str::upper(Str::random(5)),
            'status' => OrderStatus::DELIVERED->value,
            'currency_code_snapshot' => $currencyCode,
            'delivered_at' => $confirmedAt,
            'returned_at' => null,
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Product',
            'variant_name_snapshot' => 'Default',
            'quantity' => $overrides['quantity'] ?? 1,
            'unit_cost_snapshot' => $overrides['unit_cost'] ?? '50.00',
        ]);
        $courierServiceId = DB::table('courier_services')->insertGetId([]);
        $shipmentId = DB::table('shipments')->insertGetId([
            'order_id' => $orderId,
            'supplier_id' => $userId,
            'courier_service_id' => $courierServiceId,
            'tracking_number' => $tracking,
        ]);
        $importId = DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $courierServiceId,
            'status' => CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => $confirmedAt,
        ]);
        DB::table('courier_receipt_import_rows')->insert([
            'courier_receipt_import_id' => $importId,
            'normalized_tracking_number' => $tracking,
            'match_result' => CourierReceiptImportRowMatchResult::MATCHED->value,
            'matched_order_id' => $orderId,
            'matched_shipment_id' => $shipmentId,
            'matched_supplier_id' => $userId,
        ]);

        return [
            'currencyId' => $currencyId,
            'marketId' => $marketId,
            'companyId' => $companyId,
            'userId' => $userId,
            'adminId' => $adminId,
            'variantId' => $variantId,
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
        ];
    }
}
