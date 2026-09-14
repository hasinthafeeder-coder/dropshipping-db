<?php

namespace Tests\Feature\Order;

use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\ShipmentStatus;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\ShipmentBookingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class ShipmentFoundationTest extends TestCase
{
    use SetsUpOrderFoundationData;

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
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_one_shipment_per_order_with_courier_refs_and_tracking(): void
    {
        $order = $this->createOrder();
        $setup = $this->makeCourierSetup($order);

        $shipment = app(ShipmentBookingService::class)->book(
            $order,
            $setup['courier']->id,
            $setup['service']->id,
            $setup['city']->id,
            $this->successfulAdapter('TRK-1001'),
            $order->reseller_id,
        );

        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame($setup['courier']->id, $shipment->courier_id);
        $this->assertSame($setup['service']->id, $shipment->courier_service_id);
        $this->assertSame($setup['city']->id, $shipment->courier_city_id);
        $this->assertSame('TRK-1001', $shipment->tracking_number);
        $this->assertSame(ShipmentStatus::BOOKED, $shipment->status);
        $this->assertTrue($shipment->isLocked());
        $this->assertNotNull($shipment->weight_snapshot);
        $this->assertNotNull($shipment->courier_fee_snapshot);

        $this->expectException(QueryException::class);

        Shipment::query()->create([
            'order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'courier_id' => $setup['courier']->id,
            'courier_service_id' => $setup['service']->id,
            'courier_city_id' => $setup['city']->id,
            'tracking_number' => 'TRK-DUPLICATE',
            'weight_snapshot' => 1,
            'courier_fee_snapshot' => 100,
            'currency_id' => $order->currency_id,
            'status' => ShipmentStatus::BOOKED,
            'booked_at' => now(),
        ]);
    }

    public function test_failed_booking_does_not_create_shipment_or_lock_order(): void
    {
        $order = $this->createOrder();
        $setup = $this->makeCourierSetup($order);

        $adapter = new class implements CourierBookingAdapter
        {
            public function book(
                Order $order,
                Courier $courier,
                CourierService $service,
                CourierCity $city,
                ?SupplierCourierAccount $account,
                float $weightKg,
                float $courierFee,
            ): array {
                throw ValidationException::withMessages([
                    'booking' => ['Courier API failed.'],
                ]);
            }
        };

        try {
            app(ShipmentBookingService::class)->book(
                $order,
                $setup['courier']->id,
                $setup['service']->id,
                $setup['city']->id,
                $adapter,
                $order->reseller_id,
            );
            $this->fail('Expected booking failure.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
        $this->assertFalse($order->fresh()->hasBookedShipment());
    }

    public function test_order_items_locked_after_successful_booking(): void
    {
        $order = $this->createOrder();
        $setup = $this->makeCourierSetup($order);

        app(ShipmentBookingService::class)->book(
            $order,
            $setup['courier']->id,
            $setup['service']->id,
            $setup['city']->id,
            $this->successfulAdapter('TRK-LOCK'),
            $order->reseller_id,
        );

        $this->expectException(ValidationException::class);
        app(ShipmentBookingService::class)->assertOrderItemsMutable($order->fresh(['shipment']));
    }

    private function createOrder(): Order
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;

        return app(OrderService::class)->create([
            'source' => OrderSource::MANUAL,
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Ship Customer',
                'primary_country_id' => $countryId,
                'primary_phone' => '070'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Ship Customer',
                'line1' => '10 Shipping Lane',
                'city_name' => 'Colombo',
                'country_id' => $countryId,
            ],
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                    'unit_selling_price' => 300,
                ],
            ],
            'courier_fee_amount' => 0,
        ]);
    }

    /**
     * @return array{courier: Courier, service: CourierService, city: CourierCity}
     */
    private function makeCourierSetup(Order $order): array
    {
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
        ]);

        return compact('courier', 'service', 'city');
    }

    private function successfulAdapter(string $trackingNumber): CourierBookingAdapter
    {
        return new class($trackingNumber) implements CourierBookingAdapter
        {
            public function __construct(private readonly string $trackingNumber)
            {
            }

            public function book(
                Order $order,
                Courier $courier,
                CourierService $service,
                CourierCity $city,
                ?SupplierCourierAccount $account,
                float $weightKg,
                float $courierFee,
            ): array {
                return [
                    'tracking_number' => $this->trackingNumber,
                    'external_booking_ref' => 'EXT-'.$this->trackingNumber,
                    'raw_response' => ['ok' => true],
                ];
            }
        };
    }
}
