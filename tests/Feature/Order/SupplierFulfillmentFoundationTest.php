<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\OrderType;
use Feeder\Core\Enums\StockReservationStatus;
use Feeder\Core\Enums\SupplierFulfillmentEventType;
use Feeder\Core\Enums\SupplierFulfillmentStatus;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderFulfillment;
use Feeder\Core\Models\OrderFulfillmentEvent;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\StockReservation;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\GoodsReceivedNoteService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Feeder\Core\Services\Order\SupplierFulfillmentQueryService;
use Feeder\Core\Services\Order\SupplierFulfillmentService;
use Feeder\Core\Services\Order\SupplierInvoiceService;
use Feeder\Core\Services\StockService;
use Feeder\Core\Enums\ShipmentStatus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class SupplierFulfillmentFoundationTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private OrderService $orderService;

    private OrderStatusService $statusService;

    private SupplierFulfillmentService $fulfillmentService;

    private SupplierFulfillmentQueryService $queryService;

    private StockService $stockService;

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

        $this->orderService = app(OrderService::class);
        $this->statusService = app(OrderStatusService::class);
        $this->fulfillmentService = app(SupplierFulfillmentService::class);
        $this->queryService = app(SupplierFulfillmentQueryService::class);
        $this->stockService = app(StockService::class);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_confirmed_new_order_is_fresh_eligible(): void
    {
        $context = $this->makeConfirmedOrderContext();

        $this->assertTrue(
            $this->queryService->isFreshEligible($context['order'], $context['supplier']->id)
        );

        $freshIds = $this->queryService->freshOrders($context['supplier']->id)->pluck('id');
        $this->assertTrue($freshIds->contains($context['order']->id));
    }

    public function test_exchange_order_is_not_fresh_eligible(): void
    {
        $context = $this->makeConfirmedOrderContext(orderType: OrderType::EXCHANGE);

        $this->assertFalse(
            $this->queryService->isFreshEligible($context['order'], $context['supplier']->id)
        );

        $this->expectException(ValidationException::class);
        $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);
    }

    public function test_supplier_cannot_operate_on_another_suppliers_order(): void
    {
        $context = $this->makeConfirmedOrderContext();
        $otherSupplier = $this->makeSupplierUser();

        $this->expectException(ValidationException::class);
        $this->fulfillmentService->sendToPrint($context['order'], $otherSupplier->id);
    }

    public function test_fresh_does_not_reserve_stock(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 10, orderQuantity: 2);

        $before = $this->stockService->availableQuantityForVariant($context['variant']->id);

        $this->fulfillmentService->ensureFresh($context['order'], $context['supplier']->id);

        $after = $this->stockService->availableQuantityForVariant($context['variant']->id);

        $this->assertSame($before, $after);
        $this->assertSame(0, $this->stockService->reservedQuantityForVariant($context['variant']->id));
        $this->assertDatabaseMissing('stock_reservations', ['order_id' => $context['order']->id]);
    }

    public function test_send_to_print_reserves_required_quantities(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 10, orderQuantity: 3);

        $fulfillment = $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);

        $this->assertSame(SupplierFulfillmentStatus::PRINT, $fulfillment->status);
        $this->assertSame(OrderStatus::PRINT, $context['order']->fresh()->status);
        $this->assertSame(3, $this->stockService->reservedQuantityForVariant($context['variant']->id));
        $this->assertSame(7, $this->stockService->availableQuantityForVariant($context['variant']->id));

        $reservation = StockReservation::query()->where('order_id', $context['order']->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame(StockReservationStatus::ACTIVE, $reservation->status);
        $this->assertSame(3, $reservation->quantity);

        $events = OrderFulfillmentEvent::query()->where('order_id', $context['order']->id)->pluck('event_type');
        $this->assertTrue($events->contains(SupplierFulfillmentEventType::STOCK_RESERVED));
        $this->assertTrue($events->contains(SupplierFulfillmentEventType::MOVED_TO_PRINT));
    }

    public function test_send_to_print_fails_atomically_when_stock_insufficient(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 1, orderQuantity: 5);

        try {
            $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertDatabaseMissing('stock_reservations', ['order_id' => $context['order']->id]);
        $this->assertNull(OrderFulfillment::query()->where('order_id', $context['order']->id)->first());
        $this->assertSame(1, $this->stockService->availableQuantityForVariant($context['variant']->id));
    }

    public function test_duplicate_send_to_print_cannot_reserve_twice(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 10, orderQuantity: 2);

        $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);

        try {
            $this->fulfillmentService->sendToPrint($context['order']->fresh(), $context['supplier']->id);
            $this->fail('Expected ValidationException on duplicate send to print');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fulfillment', $e->errors());
        }

        $this->assertSame(1, StockReservation::query()->where('order_id', $context['order']->id)->count());
        $this->assertSame(2, $this->stockService->reservedQuantityForVariant($context['variant']->id));
    }

    public function test_reserved_stock_reduces_available_stock(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 20, orderQuantity: 8);

        $this->assertSame(20, $this->stockService->availableQuantityForVariant($context['variant']->id));

        $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);

        $breakdown = $this->stockService->stockBreakdownForVariant($context['variant']->id);
        $this->assertSame(20, $breakdown['received']);
        $this->assertSame(0, $breakdown['damaged']);
        $this->assertSame(8, $breakdown['reserved']);
        $this->assertSame(0, $breakdown['consumed']);
        $this->assertSame(12, $breakdown['available']);
    }

    public function test_package_cannot_complete_with_missing_quantities(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 2);
        $this->moveToPackaging($context);

        $this->expectException(ValidationException::class);
        $this->fulfillmentService->completePackaging($context['order']->fresh(), $context['supplier']->id);
    }

    public function test_package_completion_consumes_reserved_stock_and_dispatches(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 2);
        $this->moveToPackaging($context);

        $itemId = $context['order']->items->first()->id;
        $this->fulfillmentService->setPackedQuantity(
            $context['order']->fresh(),
            $itemId,
            2,
            $context['supplier']->id,
        );

        $fulfillment = $this->fulfillmentService->completePackaging(
            $context['order']->fresh(),
            $context['supplier']->id,
        );

        $this->assertSame(SupplierFulfillmentStatus::DISPATCHED, $fulfillment->status);
        $this->assertSame(OrderStatus::DISPATCHED, $context['order']->fresh()->status);
        $this->assertSame(0, $this->stockService->reservedQuantityForVariant($context['variant']->id));
        $this->assertSame(2, $this->stockService->consumedQuantityForVariant($context['variant']->id));
        $this->assertSame(3, $this->stockService->availableQuantityForVariant($context['variant']->id));

        $reservation = StockReservation::query()->where('order_id', $context['order']->id)->first();
        $this->assertSame(StockReservationStatus::CONSUMED, $reservation->status);
        $this->assertDatabaseHas('stock_consumptions', [
            'order_id' => $context['order']->id,
            'quantity' => 2,
        ]);

        $events = OrderFulfillmentEvent::query()->where('order_id', $context['order']->id)->pluck('event_type');
        $this->assertTrue($events->contains(SupplierFulfillmentEventType::PACKAGE_COMPLETED));
        $this->assertTrue($events->contains(SupplierFulfillmentEventType::STOCK_CONSUMED));
        $this->assertTrue($events->contains(SupplierFulfillmentEventType::DISPATCHED));
    }

    public function test_dispatched_cannot_return_to_packaging(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 1);
        $this->moveToPackaging($context);
        $this->fulfillmentService->setPackedQuantity(
            $context['order']->fresh(),
            $context['order']->items->first()->id,
            1,
            $context['supplier']->id,
        );
        $this->fulfillmentService->completePackaging($context['order']->fresh(), $context['supplier']->id);

        $this->expectException(ValidationException::class);
        $this->fulfillmentService->sendToPackaging($context['order']->fresh(), $context['supplier']->id);
    }

    public function test_packaging_can_be_cancelled_before_dispatch(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 1);
        $this->moveToPackaging($context);

        $this->assertTrue(
            $this->fulfillmentService->canCancel($context['order']->fresh(['fulfillment']), $context['supplier']->id)
        );

        $this->fulfillmentService->cancel($context['order']->fresh(), $context['supplier']->id, reason: 'Cancel packing');

        $this->assertSame(OrderStatus::CANCELLED, $context['order']->fresh()->status);
        $this->assertNotNull($context['order']->fresh(['fulfillment'])->fulfillment?->cancelled_at);
    }

    public function test_dispatched_cannot_be_cancelled(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 1);
        $this->moveToPackaging($context);
        $this->fulfillmentService->setPackedQuantity(
            $context['order']->fresh(),
            $context['order']->items->first()->id,
            1,
            $context['supplier']->id,
        );
        $this->fulfillmentService->completePackaging($context['order']->fresh(), $context['supplier']->id);

        $this->assertSame(OrderStatus::DISPATCHED, $context['order']->fresh()->status);
        $this->assertFalse(
            $this->fulfillmentService->canCancel($context['order']->fresh(['fulfillment']), $context['supplier']->id)
        );

        $this->expectException(ValidationException::class);
        $this->fulfillmentService->cancel($context['order']->fresh(), $context['supplier']->id);
    }

    public function test_unprinted_order_cannot_move_to_packaging(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 1);
        $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);
        $this->ensurePrintableShipment($context['order']->fresh());

        $this->expectException(ValidationException::class);
        $this->fulfillmentService->sendToPackaging($context['order']->fresh(), $context['supplier']->id);
    }

    public function test_out_of_stock_cancellation_releases_reservations_when_present(): void
    {
        // Print cancellation (has reservations) — also covers release path used by OOS cancel.
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 2);
        $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);

        $this->assertSame(2, $this->stockService->reservedQuantityForVariant($context['variant']->id));

        $this->fulfillmentService->cancel($context['order']->fresh(), $context['supplier']->id, reason: 'Supplier cancel');

        $reservation = StockReservation::query()->where('order_id', $context['order']->id)->first();
        $this->assertSame(StockReservationStatus::RELEASED, $reservation->status);
        $this->assertSame(0, $this->stockService->reservedQuantityForVariant($context['variant']->id));
        $this->assertSame(5, $this->stockService->availableQuantityForVariant($context['variant']->id));
        $this->assertSame(OrderStatus::CANCELLED, $context['order']->fresh()->status);

        // Dedicated OOS cancel path (no reservation expected).
        $oosContext = $this->makeConfirmedOrderContext(stockQuantity: 0, orderQuantity: 1);
        $this->fulfillmentService->markOutOfStock($oosContext['order'], $oosContext['supplier']->id);
        $this->fulfillmentService->cancel($oosContext['order']->fresh(), $oosContext['supplier']->id);

        $events = OrderFulfillmentEvent::query()
            ->where('order_id', $oosContext['order']->id)
            ->pluck('event_type');
        $this->assertTrue($events->contains(SupplierFulfillmentEventType::CANCELLED_OUT_OF_STOCK));
        $this->assertSame(OrderStatus::CANCELLED, $oosContext['order']->fresh()->status);
    }

    public function test_invoice_number_remains_stable_across_operations(): void
    {
        $context = $this->makeConfirmedOrderContext(stockQuantity: 5, orderQuantity: 1);
        $fulfillment = $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);

        $first = $fulfillment->invoice_number;
        $this->assertNotNull($first);
        $this->assertSame($context['order']->order_number, $first);
        $this->assertMatchesRegularExpression('/^\d{11}$/', $first);

        $again = app(SupplierInvoiceService::class)->ensureInvoiceNumber($fulfillment->fresh());
        $this->assertSame($first, $again);
        $this->assertSame($first, $fulfillment->fresh()->invoice_number);
    }

    public function test_concurrent_reservation_attempts_cannot_over_reserve_stock(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $this->seedStock($supplier, $variant->product_id, $variant->id, 5);

        $orderA = $this->createAndConfirmOrder($reseller, $supplier, $variant->id, 4);
        $orderB = $this->createAndConfirmOrder($reseller, $supplier, $variant->id, 4);

        $this->fulfillmentService->sendToPrint($orderA, $supplier->id);

        try {
            $this->fulfillmentService->sendToPrint($orderB, $supplier->id);
            $this->fail('Second reservation should fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame(4, $this->stockService->reservedQuantityForVariant($variant->id));
        $this->assertSame(1, $this->stockService->availableQuantityForVariant($variant->id));
        $this->assertSame(
            1,
            StockReservation::query()->active()->where('product_variant_id', $variant->id)->count()
        );
        $this->assertNull(OrderFulfillment::query()->where('order_id', $orderB->id)->first());
    }

    /**
     * @return array{
     *     order: Order,
     *     supplier: User,
     *     reseller: User,
     *     variant: \Feeder\Core\Models\ProductVariant
     * }
     */
    private function makeConfirmedOrderContext(
        int $stockQuantity = 10,
        int $orderQuantity = 1,
        OrderType $orderType = OrderType::NEW,
    ): array {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        if ($stockQuantity > 0) {
            $this->seedStock($supplier, $variant->product_id, $variant->id, $stockQuantity);
        }

        $order = $this->createAndConfirmOrder(
            $reseller,
            $supplier,
            $variant->id,
            $orderQuantity,
            $orderType,
        );

        return compact('order', 'supplier', 'reseller', 'variant');
    }

    private function createAndConfirmOrder(
        User $reseller,
        User $supplier,
        int $variantId,
        int $quantity,
        OrderType $orderType = OrderType::NEW,
    ): Order {
        $countryId = $this->countryByIso('LK')->id;
        $phone = '070'.random_int(1000000, 9999999);

        $order = $this->orderService->create([
            'source' => OrderSource::MANUAL,
            'order_type' => $orderType,
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Fulfillment Customer',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Fulfillment Customer',
                'line1' => '12 Test Street',
                'city_name' => 'Colombo',
                'district_name' => 'Colombo',
                'country_id' => $countryId,
                'full_address_text' => '12 Test Street, Colombo',
            ],
            'items' => [
                [
                    'product_variant_id' => $variantId,
                    'quantity' => $quantity,
                    'unit_selling_price' => 250,
                ],
            ],
            'discount_amount' => 0,
            'courier_fee_amount' => 0,
        ]);

        return $this->statusService->transition(
            $order,
            OrderStatus::CONFIRMED,
            $reseller->id,
        )->fresh(['items', 'fulfillment']);
    }

    private function seedStock(User $supplier, int $productId, int $variantId, int $received, int $damaged = 0): void
    {
        app(GoodsReceivedNoteService::class)->createGrn(
            $supplier->id,
            [
                'received_date' => now()->toDateString(),
                'created_by' => $supplier->id,
                'updated_by' => $supplier->id,
            ],
            [[
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'received_quantity' => $received,
                'damaged_quantity' => $damaged,
                'unit_cost' => '100.00',
            ]]
        );
    }

    /**
     * @param  array{order: Order, supplier: User}  $context
     */
    private function moveToPackaging(array $context): void
    {
        $this->fulfillmentService->sendToPrint($context['order'], $context['supplier']->id);
        $this->assertSame(OrderStatus::PRINT, $context['order']->fresh()->status);

        $this->ensurePrintableShipment($context['order']);
        $this->fulfillmentService->markPrinted($context['order']->fresh(['shipment', 'fulfillment']), $context['supplier']->id);
        $this->assertSame(OrderStatus::PRINTED, $context['order']->fresh()->status);

        $this->fulfillmentService->sendToPackaging($context['order']->fresh(), $context['supplier']->id);

        $this->assertSame(OrderStatus::PACKING, $context['order']->fresh()->status);
        $this->assertSame(
            SupplierFulfillmentStatus::PACKAGING,
            $context['order']->fresh(['fulfillment'])->fulfillment->status
        );
    }

    private function ensurePrintableShipment(Order $order): void
    {
        if ($order->shipment !== null) {
            return;
        }

        $courier = Courier::query()->create([
            'code' => 'DOM'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Domestic Courier',
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

        Shipment::query()->create([
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
}
