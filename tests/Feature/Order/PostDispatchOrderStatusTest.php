<?php

namespace Tests\Feature\Order;

use Carbon\Carbon;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ShipmentStatus;
use Feeder\Core\Enums\StockReservationStatus;
use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\StockReservation;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class PostDispatchOrderStatusTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private OrderStatusService $statusService;

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

        $this->seedMarketLookups();
        $this->statusService = app(OrderStatusService::class);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_dispatched_to_delivered_records_history_and_delivered_at(): void
    {
        $order = $this->makeDispatchedOrder();

        $result = $this->statusService->transition(
            $order,
            OrderStatus::DELIVERED,
            $order->reseller_id,
            $order->reseller_company_id,
            'Courier delivered',
            $order->reseller_company_id,
        );

        $this->assertSame(OrderStatus::DELIVERED, $result->status);
        $this->assertNotNull($result->delivered_at);
        $this->assertNull($result->returned_at);
        $this->assertNull($result->cancelled_at);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::DISPATCHED->value,
            'to_status' => OrderStatus::DELIVERED->value,
            'reason' => 'Courier delivered',
        ]);
    }

    public function test_dispatched_to_returned_records_history_and_returned_at(): void
    {
        $order = $this->makeDispatchedOrder();

        $result = $this->statusService->transition(
            $order,
            OrderStatus::RETURNED,
            $order->reseller_id,
            $order->reseller_company_id,
            'Courier returned',
            $order->reseller_company_id,
        );

        $this->assertSame(OrderStatus::RETURNED, $result->status);
        $this->assertNotNull($result->returned_at);
        $this->assertNull($result->delivered_at);
        $this->assertNull($result->cancelled_at);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::DISPATCHED->value,
            'to_status' => OrderStatus::RETURNED->value,
            'reason' => 'Courier returned',
        ]);
    }

    public function test_invalid_transitions_out_of_dispatched_are_rejected_by_service(): void
    {
        $order = $this->makeDispatchedOrder();

        $this->expectException(ValidationException::class);
        $this->statusService->transition(
            $order,
            OrderStatus::PACKING,
            $order->reseller_id,
            $order->reseller_company_id,
            null,
            $order->reseller_company_id,
        );
    }

    public function test_delivered_cannot_become_returned_and_returned_cannot_become_delivered(): void
    {
        $delivered = $this->makeDispatchedOrder();
        $this->statusService->transition(
            $delivered,
            OrderStatus::DELIVERED,
            $delivered->reseller_id,
            $delivered->reseller_company_id,
            null,
            $delivered->reseller_company_id,
        );

        try {
            $this->statusService->transition(
                $delivered->fresh(),
                OrderStatus::RETURNED,
                $delivered->reseller_id,
                $delivered->reseller_company_id,
                null,
                $delivered->reseller_company_id,
            );
            $this->fail('DELIVERED → RETURNED should be rejected');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(OrderStatus::DELIVERED, $delivered->fresh()->status);
        $this->assertNotNull($delivered->fresh()->delivered_at);
        $this->assertNull($delivered->fresh()->returned_at);

        $returned = $this->makeDispatchedOrder();
        $this->statusService->transition(
            $returned,
            OrderStatus::RETURNED,
            $returned->reseller_id,
            $returned->reseller_company_id,
            null,
            $returned->reseller_company_id,
        );

        $this->expectException(ValidationException::class);
        $this->statusService->transition(
            $returned->fresh(),
            OrderStatus::DELIVERED,
            $returned->reseller_id,
            $returned->reseller_company_id,
            null,
            $returned->reseller_company_id,
        );
    }

    public function test_repeated_and_rejected_transitions_do_not_overwrite_timestamps_or_duplicate_history(): void
    {
        $order = $this->makeDispatchedOrder();
        $fixedAt = Carbon::parse('2026-10-01 10:00:00');
        Carbon::setTestNow($fixedAt);

        $this->statusService->transition(
            $order,
            OrderStatus::DELIVERED,
            $order->reseller_id,
            $order->reseller_company_id,
            'First delivery',
            $order->reseller_company_id,
        );

        $delivered = $order->fresh();
        $originalDeliveredAt = $delivered->delivered_at?->toDateTimeString();
        $historyCount = OrderStatusHistory::query()->where('order_id', $order->id)->count();

        Carbon::setTestNow($fixedAt->copy()->addHours(5));

        $same = $this->statusService->transition(
            $delivered,
            OrderStatus::DELIVERED,
            $order->reseller_id,
            $order->reseller_company_id,
            'Repeat noop',
            $order->reseller_company_id,
        );

        $this->assertSame($originalDeliveredAt, $same->delivered_at?->toDateTimeString());
        $this->assertSame(
            $historyCount,
            OrderStatusHistory::query()->where('order_id', $order->id)->count()
        );

        try {
            $this->statusService->transition(
                $same,
                OrderStatus::RETURNED,
                $order->reseller_id,
                $order->reseller_company_id,
                'Rejected overwrite attempt',
                $order->reseller_company_id,
            );
            $this->fail('Expected rejected transition');
        } catch (ValidationException) {
            // expected
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::DELIVERED, $fresh->status);
        $this->assertSame($originalDeliveredAt, $fresh->delivered_at?->toDateTimeString());
        $this->assertNull($fresh->returned_at);
        $this->assertSame(
            $historyCount,
            OrderStatusHistory::query()->where('order_id', $order->id)->count()
        );

        Carbon::setTestNow();
    }

    public function test_cancellation_remains_prohibited_after_dispatch_and_outcomes(): void
    {
        $dispatched = $this->makeDispatchedOrder();
        $this->assertFalse($this->statusService->canCancel($dispatched));

        try {
            $this->statusService->transition(
                $dispatched,
                OrderStatus::CANCELLED,
                $dispatched->reseller_id,
                $dispatched->reseller_company_id,
                null,
                $dispatched->reseller_company_id,
            );
            $this->fail('DISPATCHED cancellation should fail');
        } catch (ValidationException) {
            // expected
        }

        $delivered = $this->makeDispatchedOrder();
        $this->statusService->transition(
            $delivered,
            OrderStatus::DELIVERED,
            $delivered->reseller_id,
            $delivered->reseller_company_id,
            null,
            $delivered->reseller_company_id,
        );
        $this->assertFalse($this->statusService->canCancel($delivered->fresh()));

        $this->expectException(ValidationException::class);
        $this->statusService->transition(
            $delivered->fresh(),
            OrderStatus::CANCELLED,
            $delivered->reseller_id,
            $delivered->reseller_company_id,
            null,
            $delivered->reseller_company_id,
        );
    }

    public function test_post_dispatch_outcome_does_not_alter_fulfillment_or_stock_reservations(): void
    {
        $order = $this->makeDispatchedOrder(withFulfillmentArtifacts: true);

        $reservationBefore = StockReservation::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($reservationBefore);
        $this->assertSame(StockReservationStatus::CONSUMED, $reservationBefore->status);
        $reservationUpdatedAt = $reservationBefore->updated_at?->toDateTimeString();

        $fulfillment = $order->fresh(['fulfillment'])->fulfillment;
        $this->assertNotNull($fulfillment);
        $this->assertSame(SupplierFulfillmentStatus::DISPATCHED, $fulfillment->status);
        $this->assertNull($fulfillment->cancelled_at);

        $this->statusService->transition(
            $order,
            OrderStatus::RETURNED,
            $order->reseller_id,
            $order->reseller_company_id,
            'Return intake',
            $order->reseller_company_id,
        );

        $fresh = $order->fresh(['fulfillment']);
        $this->assertSame(OrderStatus::RETURNED, $fresh->status);
        $this->assertNotNull($fresh->returned_at);
        $this->assertSame(SupplierFulfillmentStatus::DISPATCHED, $fresh->fulfillment?->status);
        $this->assertNull($fresh->fulfillment?->cancelled_at);

        $reservationAfter = StockReservation::query()->where('order_id', $order->id)->first();
        $this->assertSame(StockReservationStatus::CONSUMED, $reservationAfter?->status);
        $this->assertSame($reservationUpdatedAt, $reservationAfter?->updated_at?->toDateTimeString());
    }

    public function test_shipment_status_remains_independent_from_order_outcome(): void
    {
        $order = $this->makeDispatchedOrder();
        $shipment = $this->attachBookedShipment($order);

        $this->assertSame(ShipmentStatus::BOOKED, $shipment->status);

        $this->statusService->transition(
            $order,
            OrderStatus::DELIVERED,
            $order->reseller_id,
            $order->reseller_company_id,
            null,
            $order->reseller_company_id,
        );

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame(ShipmentStatus::BOOKED, $shipment->fresh()->status);
    }

    public function test_pre_dispatch_cancellation_and_reactivation_remain_unchanged(): void
    {
        $order = $this->createReadyOrder();

        $this->statusService->transition(
            $order,
            OrderStatus::CANCELLED,
            $order->reseller_id,
            $order->reseller_company_id,
            'Cancel for later',
            $order->reseller_company_id,
        );

        $cancelled = $order->fresh();
        $this->assertSame(OrderStatus::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertTrue($this->statusService->canReactivate($cancelled));

        $this->statusService->transition(
            $cancelled,
            OrderStatus::HOLD,
            $order->reseller_id,
            $order->reseller_company_id,
            'Reactivate',
            $order->reseller_company_id,
        );

        $reactivated = $order->fresh();
        $this->assertSame(OrderStatus::HOLD, $reactivated->status);
        $this->assertNotNull($reactivated->reactivated_at);
        $this->assertNull($reactivated->operations_hidden_at);
        $this->assertNull($reactivated->delivered_at);
        $this->assertNull($reactivated->returned_at);
    }

    /**
     * @return Order
     */
    private function makeDispatchedOrder(bool $withFulfillmentArtifacts = false): Order
    {
        $order = $this->createReadyOrder();
        $order->forceFill([
            'status' => OrderStatus::DISPATCHED,
            'confirmed_at' => now()->subDay(),
            'discount_locked_at' => now()->subDay(),
        ])->save();

        if ($withFulfillmentArtifacts) {
            $order->fulfillment()->create([
                'status' => SupplierFulfillmentStatus::DISPATCHED,
                'created_by' => $order->supplier_id,
                'updated_by' => $order->supplier_id,
            ]);

            $item = $order->items()->first();
            StockReservation::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'status' => StockReservationStatus::CONSUMED,
                'reserved_at' => now()->subHours(3),
                'consumed_at' => now()->subMinutes(30),
                'reserved_by' => $order->supplier_id,
            ]);
        }

        return $order->fresh();
    }

    private function attachBookedShipment(Order $order): Shipment
    {
        $courier = Courier::query()->create([
            'code' => 'TST'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Test Courier',
            'is_active' => true,
        ]);

        $service = CourierService::query()->create([
            'courier_id' => $courier->id,
            'code' => 'COD',
            'name' => 'Cash on Delivery',
            'external_service_id' => 'ext-cod',
            'is_active' => true,
        ]);

        $city = CourierCity::query()->create([
            'courier_id' => $courier->id,
            'district_name' => 'Colombo',
            'city_name' => 'Colombo 03',
            'external_city_code' => 'CMB03',
            'external_district_code' => 'COL',
            'is_active' => true,
        ]);

        CourierMarketPricing::query()->create([
            'courier_id' => $courier->id,
            'market_id' => $order->market_id,
            'currency_id' => $order->currency_id,
            'first_kg_fee' => 600.00,
            'additional_kg_fee' => 100.00,
            'is_active' => true,
        ]);

        SupplierCourierAccount::query()->create([
            'supplier_id' => $order->supplier_id,
            'courier_id' => $courier->id,
            'account_label' => 'Primary',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'secret'], JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'is_default' => true,
        ]);

        return Shipment::query()->create([
            'order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'courier_id' => $courier->id,
            'courier_service_id' => $service->id,
            'courier_city_id' => $city->id,
            'tracking_number' => 'TRK-'.strtoupper(substr(uniqid(), -6)),
            'weight_snapshot' => 1,
            'courier_fee_snapshot' => 350,
            'currency_id' => $order->currency_id,
            'status' => ShipmentStatus::BOOKED,
            'booked_at' => now(),
            'booked_by' => $order->reseller_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReadyOrder(array $overrides = []): Order
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;

        return app(OrderService::class)->create(array_merge([
            'source' => OrderSource::MANUAL,
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Post Dispatch Customer',
                'primary_country_id' => $countryId,
                'primary_phone' => '070'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Post Dispatch Customer',
                'line1' => '1 Test Road',
                'city_name' => 'Colombo',
                'country_id' => $countryId,
            ],
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
            ],
            'discount_amount' => 0,
            'courier_fee_amount' => 0,
        ], $overrides));
    }
}
