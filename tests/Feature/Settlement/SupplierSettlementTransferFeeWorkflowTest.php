<?php

namespace Tests\Feature\Settlement;

use Carbon\CarbonImmutable;
use Feeder\Core\Enums\CourierReceiptImportRowMatchResult;
use Feeder\Core\Enums\CourierReceiptImportStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\SupplierPayment;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Settlement\SupplierSettlementBatchService;
use Feeder\Core\Services\Settlement\SupplierSettlementGenerationService;
use Feeder\Core\Services\Settlement\SupplierSettlementLockService;
use Feeder\Core\Services\Settlement\SupplierSettlementPaymentService;
use Feeder\Core\Services\Settlement\SupplierSettlementTransferFeeSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsSupplierSettlementPhase2Schema;
use Tests\TestCase;

/**
 * Supplier settlement transfer-fee behaviour on the isolated Phase 2 schema.
 */
class SupplierSettlementTransferFeeWorkflowTest extends TestCase
{
    use BuildsSupplierSettlementPhase2Schema;

    private static bool $schemaReady = false;

    private SupplierSettlementTransferFeeSettingsService $transferFees;

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

        $this->transferFees = app(SupplierSettlementTransferFeeSettingsService::class);
    }

    public static function tearDownAfterClass(): void
    {
        self::$schemaReady = false;

        parent::tearDownAfterClass();
    }

    public function test_default_lkr_transfer_fee_of_30_is_applied_once_per_supplier_invoice(): void
    {
        $fixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '100.00',
            'quantity' => 2,
        ]);
        $market = Market::query()->findOrFail($fixture['marketId']);
        $this->transferFees->seedApprovedDefaults();
        $this->assertTrue($this->transferFees->hasFee($market));

        $batch = $this->createDailyBatch($fixture);
        $result = app(SupplierSettlementGenerationService::class)->generate($batch);

        $this->assertSame(1, $result['invoice_count']);
        $this->assertSame('200.00', $result['payable_total']);

        $invoice = SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame('200.00', number_format((float) $invoice->payable_total, 2, '.', ''));
        $this->assertSame('30.00', number_format((float) $invoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('LKR', $invoice->transfer_fee_currency_code_snapshot);
        $this->assertSame(SupplierSettlementInvoice::TRANSFER_FEE_STATUS_APPLIED, $invoice->transfer_fee_status);
        $this->assertSame('170.00', number_format((float) $invoice->net_payable, 2, '.', ''));
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID, $invoice->payment_status);
        $this->assertNull($invoice->effective_payment_id);
        $this->assertSame(0, SupplierPayment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_myr_fee_missing_does_not_silently_charge_lkr_or_convert(): void
    {
        $fixture = $this->seedPayableFixture([
            'currency_code' => 'MYR',
            'market_code' => 'my',
            'order_item_unit_cost_snapshot' => '100.00',
            'quantity' => 1,
        ]);
        $market = Market::query()->findOrFail($fixture['marketId']);
        $this->transferFees->seedApprovedDefaults();
        $this->assertFalse($this->transferFees->hasFee($market));

        $batch = $this->createDailyBatch($fixture);
        app(SupplierSettlementGenerationService::class)->generate($batch);

        $invoice = SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame('MYR', $invoice->currency_code_snapshot);
        $this->assertSame(
            SupplierSettlementInvoice::TRANSFER_FEE_STATUS_NOT_CONFIGURED,
            $invoice->transfer_fee_status
        );
        $this->assertSame('0.00', number_format((float) $invoice->transfer_fee_amount, 2, '.', ''));
        $this->assertNull($invoice->transfer_fee_currency_code_snapshot);
        $this->assertSame('100.00', number_format((float) $invoice->payable_total, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $invoice->net_payable, 2, '.', ''));
    }

    public function test_rebuild_does_not_double_deduct_transfer_fee(): void
    {
        $fixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '200.00',
            'quantity' => 1,
        ]);
        $this->transferFees->seedApprovedDefaults();

        $batch = $this->createDailyBatch($fixture);
        $generation = app(SupplierSettlementGenerationService::class);
        $generation->generate($batch);
        $generation->generate($batch->fresh());

        $this->assertSame(1, SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->count());
        $invoice = SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame('30.00', number_format((float) $invoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('170.00', number_format((float) $invoice->net_payable, 2, '.', ''));
        $this->assertSame('200.00', number_format((float) $invoice->payable_total, 2, '.', ''));
    }

    public function test_configured_fee_change_applies_to_new_invoices_only(): void
    {
        $fixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '100.00',
            'quantity' => 1,
        ]);
        $market = Market::query()->findOrFail($fixture['marketId']);
        $this->transferFees->seedApprovedDefaults();

        $batch = $this->createDailyBatch($fixture);
        app(SupplierSettlementGenerationService::class)->generate($batch);
        app(SupplierSettlementLockService::class)->lock(
            $batch->fresh(),
            User::query()->findOrFail($fixture['adminId']),
        );

        $lockedInvoice = SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame('30.00', number_format((float) $lockedInvoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('70.00', number_format((float) $lockedInvoice->net_payable, 2, '.', ''));

        $this->transferFees->setFee($market, '50.00');
        $lockedInvoice->refresh();
        $this->assertSame('30.00', number_format((float) $lockedInvoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('70.00', number_format((float) $lockedInvoice->net_payable, 2, '.', ''));

        $nextFixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '100.00',
            'quantity' => 1,
            'reuse_market' => [
                'currencyId' => $fixture['currencyId'],
                'marketId' => $fixture['marketId'],
            ],
            'tracking' => 'TRK-NEXT-'.Str::upper(Str::random(4)),
            'order_number' => 'ORD-NEXT-'.Str::upper(Str::random(4)),
            'confirmed_at' => CarbonImmutable::parse('2026-10-13 04:30:00', 'UTC'),
            'delivered_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        ]);
        $nextBatch = $this->createDailyBatch($nextFixture, '2026-10-13');
        $nextResult = app(SupplierSettlementGenerationService::class)->generate($nextBatch);
        $this->assertSame(1, $nextResult['invoice_count']);

        $newInvoice = SupplierSettlementInvoice::query()->where('batch_id', $nextBatch->id)->firstOrFail();
        $this->assertSame('50.00', number_format((float) $newInvoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('50.00', number_format((float) $newInvoice->net_payable, 2, '.', ''));
        $lockedInvoice->refresh();
        $this->assertSame('30.00', number_format((float) $lockedInvoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('70.00', number_format((float) $lockedInvoice->net_payable, 2, '.', ''));
    }

    public function test_zero_net_after_fee_stays_unpaid_without_payment_record(): void
    {
        $fixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '20.00',
            'quantity' => 1,
        ]);
        $this->transferFees->seedApprovedDefaults();

        $batch = $this->createDailyBatch($fixture);
        app(SupplierSettlementGenerationService::class)->generate($batch);

        $invoice = SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame('20.00', number_format((float) $invoice->payable_total, 2, '.', ''));
        $this->assertSame('30.00', number_format((float) $invoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $invoice->net_payable, 2, '.', ''));
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_UNPAID, $invoice->payment_status);
        $this->assertNull($invoice->effective_payment_id);
        $this->assertSame(0, SupplierPayment::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_payment_recording_uses_fee_adjusted_net_and_does_not_deduct_fee_again(): void
    {
        $fixture = $this->seedPayableFixture([
            'order_item_unit_cost_snapshot' => '130.00',
            'quantity' => 1,
        ]);
        $this->transferFees->seedApprovedDefaults();

        $batch = $this->createDailyBatch($fixture);
        app(SupplierSettlementGenerationService::class)->generate($batch);
        app(SupplierSettlementLockService::class)->lock(
            $batch->fresh(),
            User::query()->findOrFail($fixture['adminId']),
        );

        $invoice = SupplierSettlementInvoice::query()->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame('100.00', number_format((float) $invoice->net_payable, 2, '.', ''));
        $this->assertSame('30.00', number_format((float) $invoice->transfer_fee_amount, 2, '.', ''));

        $payment = app(SupplierSettlementPaymentService::class)->record(
            $invoice,
            User::query()->findOrFail($fixture['adminId']),
            '100.00',
            (int) $fixture['currencyId'],
            'supplier-fee-pay-100',
            true,
            'REF-SUP-FEE-100',
        );

        $invoice->refresh();
        $this->assertSame(SupplierSettlementInvoice::PAYMENT_STATUS_PAID, $invoice->payment_status);
        $this->assertSame('30.00', number_format((float) $invoice->transfer_fee_amount, 2, '.', ''));
        $this->assertSame('130.00', number_format((float) $invoice->payable_total, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $invoice->net_payable, 2, '.', ''));
        $this->assertSame((int) $payment->id, (int) $invoice->effective_payment_id);
        $this->assertSame(1, SupplierPayment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame('100.00', number_format((float) $payment->amount, 2, '.', ''));
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function createDailyBatch(array $fixture, string $settlementDate = '2026-10-12'): SupplierSettlementBatch
    {
        return app(SupplierSettlementBatchService::class)->createOrGetDailyBatch(
            Market::query()->findOrFail($fixture['marketId']),
            User::query()->findOrFail($fixture['adminId']),
            CarbonImmutable::parse($settlementDate.' 12:00:00', 'Asia/Colombo'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, int|string>
     */
    private function seedPayableFixture(array $overrides = []): array
    {
        if (isset($overrides['reuse_market']) && is_array($overrides['reuse_market'])) {
            $currencyId = (int) $overrides['reuse_market']['currencyId'];
            $marketId = (int) $overrides['reuse_market']['marketId'];
        } else {
            $currencyId = (int) DB::table('currencies')->insertGetId([
                'code' => $overrides['currency_code'] ?? 'LKR',
            ]);
            $marketId = (int) DB::table('markets')->insertGetId([
                'code' => $overrides['market_code'] ?? 'lk',
                'currency_id' => $currencyId,
                'market_number' => ($overrides['market_code'] ?? 'lk') === 'my' ? 2 : 1,
                'is_active' => true,
            ]);
        }

        $companyId = (int) DB::table('companies')->insertGetId(['name' => 'Supplier Fee Co']);
        $userId = (int) DB::table('users')->insertGetId(['company_id' => $companyId]);
        $adminId = (int) DB::table('users')->insertGetId(['company_id' => null]);

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Fee Product',
            'market_id' => $marketId,
        ]);
        $variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'name' => 'Default',
            'cost' => $overrides['variant_cost'] ?? '9.99',
        ]);

        $tracking = (string) ($overrides['tracking'] ?? 'TRK-'.Str::upper(Str::random(6)));
        $confirmedAt = $overrides['confirmed_at']
            ?? CarbonImmutable::parse('2026-10-12 04:30:00', 'UTC');
        $deliveredAt = $overrides['delivered_at']
            ?? CarbonImmutable::parse('2026-10-11 08:00:00', 'UTC');

        $orderId = (int) DB::table('orders')->insertGetId([
            'supplier_id' => $userId,
            'market_id' => $marketId,
            'currency_id' => $currencyId,
            'order_number' => $overrides['order_number'] ?? 'ORD-'.Str::upper(Str::random(5)),
            'status' => OrderStatus::DELIVERED->value,
            'currency_code_snapshot' => $overrides['currency_code'] ?? 'LKR',
            'delivered_at' => $deliveredAt,
            'returned_at' => null,
        ]);

        $orderItemId = (int) DB::table('order_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name_snapshot' => 'Fee Product',
            'variant_name_snapshot' => 'Default',
            'quantity' => $overrides['quantity'] ?? 1,
            'unit_cost_snapshot' => $overrides['order_item_unit_cost_snapshot'] ?? '50.00',
        ]);

        $courierServiceId = (int) DB::table('courier_services')->insertGetId([]);
        $shipmentId = (int) DB::table('shipments')->insertGetId([
            'order_id' => $orderId,
            'supplier_id' => $userId,
            'courier_service_id' => $courierServiceId,
            'tracking_number' => $tracking,
        ]);

        $importId = (int) DB::table('courier_receipt_imports')->insertGetId([
            'courier_service_id' => $courierServiceId,
            'status' => CourierReceiptImportStatus::CONFIRMED->value,
            'confirmed_at' => $confirmedAt,
        ]);
        $importRowId = (int) DB::table('courier_receipt_import_rows')->insertGetId([
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
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
            'importId' => $importId,
            'importRowId' => $importRowId,
        ];
    }
}
