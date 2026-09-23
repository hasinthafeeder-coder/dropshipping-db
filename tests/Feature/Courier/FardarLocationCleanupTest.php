<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ShipmentStatus;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderAddress;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\Shipment;
use Feeder\Core\Models\ShipmentEvent;
use Feeder\Core\Services\Courier\Fardar\FardarLocationCleanupService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class FardarLocationCleanupTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private Courier $fardar;

    private Courier $royal;

    private Courier $transexpress;

    private CourierState $fardarState;

    private CourierCity $fardarCity;

    private CourierState $royalState;

    private CourierCity $royalCity;

    private CourierState $txState;

    private CourierCity $txCity;

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
            'feeder.fardar_cleanup_force_fail_after_cities' => false,
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::beginTransaction();

        $this->seedMarketLookups();
        $this->installIsolatedCouriers();
    }

    protected function tearDown(): void
    {
        config(['feeder.fardar_cleanup_force_fail_after_cities' => false]);

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $this->seedFardarDependentBusinessGraph();
        $before = $this->snapshotCounts();

        $this->artisan('courier:fardar-cleanup')
            ->expectsOutputToContain('FARDAR TEST DATA CLEANUP — DRY RUN')
            ->expectsOutputToContain('No records have been deleted.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshotCounts());
    }

    public function test_execute_requires_explicit_confirmation(): void
    {
        $before = $this->snapshotCounts();

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsConfirmation('Continue?', 'no')
            ->expectsOutputToContain('Aborted. No records have been deleted.')
            ->assertFailed();

        $this->assertSame($before, $this->snapshotCounts());
    }

    public function test_execute_deletes_fardar_locations_and_dependent_test_data(): void
    {
        $graph = $this->seedFardarDependentBusinessGraph();

        $royalStatesBefore = CourierState::query()->where('courier_id', $this->royal->id)->count();
        $royalCitiesBefore = CourierCity::query()->where('courier_id', $this->royal->id)->count();
        $txStatesBefore = CourierState::query()->where('courier_id', $this->transexpress->id)->count();
        $txCitiesBefore = CourierCity::query()->where('courier_id', $this->transexpress->id)->count();

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->expectsOutputToContain('FARDAR TEST DATA CLEANUP — COMPLETED')
            ->expectsOutputToContain('Fardar courier: EXISTS')
            ->expectsOutputToContain('Royal/Curfox: unchanged')
            ->expectsOutputToContain('TransExpress: unchanged')
            ->assertSuccessful();

        $this->assertSame(0, CourierState::query()->where('courier_id', $this->fardar->id)->count());
        $this->assertSame(0, CourierCity::query()->where('courier_id', $this->fardar->id)->count());
        $this->assertDatabaseHas('couriers', [
            'id' => $this->fardar->id,
            'code' => 'FARDAR',
        ]);

        $this->assertDatabaseMissing('orders', ['id' => $graph['order']->id]);
        $this->assertDatabaseMissing('shipments', ['id' => $graph['shipment']->id]);
        $this->assertDatabaseMissing('order_items', ['id' => $graph['order_item']->id]);
        $this->assertDatabaseMissing('order_addresses', ['id' => $graph['order_address']->id]);
        $this->assertDatabaseMissing('order_status_histories', ['id' => $graph['status_history']->id]);
        $this->assertDatabaseMissing('shipment_events', ['id' => $graph['shipment_event']->id]);

        $this->assertSame(0, (int) DB::table('orders')->where('draft_courier_city_id', $this->fardarCity->id)->count());
        $this->assertSame(0, (int) DB::table('shipments')->where('courier_city_id', $this->fardarCity->id)->count());

        $this->assertSame($royalStatesBefore, CourierState::query()->where('courier_id', $this->royal->id)->count());
        $this->assertSame($royalCitiesBefore, CourierCity::query()->where('courier_id', $this->royal->id)->count());
        $this->assertSame($txStatesBefore, CourierState::query()->where('courier_id', $this->transexpress->id)->count());
        $this->assertSame($txCitiesBefore, CourierCity::query()->where('courier_id', $this->transexpress->id)->count());
        $this->assertDatabaseHas('courier_cities', [
            'id' => $this->royalCity->id,
            'courier_id' => $this->royal->id,
            'external_id' => 'cleanup-royal-city-1',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'id' => $this->txCity->id,
            'courier_id' => $this->transexpress->id,
            'external_id' => 'cleanup-tx-city-1',
        ]);
    }

    public function test_other_courier_records_remain_unchanged(): void
    {
        $other = Courier::query()->create([
            'code' => 'OTHER'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Other Courier',
            'is_active' => true,
        ]);
        $state = CourierState::query()->create([
            'courier_id' => $other->id,
            'external_id' => 'cleanup-other-state-1',
            'name' => 'Other State',
            'is_active' => true,
        ]);
        CourierCity::query()->create([
            'courier_id' => $other->id,
            'courier_state_id' => $state->id,
            'external_id' => 'cleanup-other-city-1',
            'name' => 'Other City',
            'city_name' => 'Other City',
            'district_name' => 'Other State',
            'external_city_code' => 'cleanup-other-city-1',
            'external_district_code' => 'cleanup-other-state-1',
            'is_active' => true,
        ]);

        $beforeStates = CourierState::query()->where('courier_id', $other->id)->count();
        $beforeCities = CourierCity::query()->where('courier_id', $other->id)->count();

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertSuccessful();

        $this->assertSame($beforeStates, CourierState::query()->where('courier_id', $other->id)->count());
        $this->assertSame($beforeCities, CourierCity::query()->where('courier_id', $other->id)->count());
    }

    public function test_foreign_key_dependency_order_deletes_cities_before_states(): void
    {
        $this->assertSame(1, CourierCity::query()->where('courier_id', $this->fardar->id)->count());
        $this->assertSame(1, CourierState::query()->where('courier_id', $this->fardar->id)->count());

        app(FardarLocationCleanupService::class)->execute();

        $this->assertSame(0, CourierCity::query()->where('courier_id', $this->fardar->id)->count());
        $this->assertSame(0, CourierState::query()->where('courier_id', $this->fardar->id)->count());
    }

    public function test_transaction_rolls_back_when_deletion_fails(): void
    {
        $graph = $this->seedFardarDependentBusinessGraph();
        config(['feeder.fardar_cleanup_force_fail_after_cities' => true]);

        $statesBefore = CourierState::query()->where('courier_id', $this->fardar->id)->count();
        $citiesBefore = CourierCity::query()->where('courier_id', $this->fardar->id)->count();

        try {
            app(FardarLocationCleanupService::class)->execute();
            $this->fail('Expected cleanup to fail and roll back.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }

        $this->assertSame($statesBefore, CourierState::query()->where('courier_id', $this->fardar->id)->count());
        $this->assertSame($citiesBefore, CourierCity::query()->where('courier_id', $this->fardar->id)->count());
        $this->assertDatabaseHas('courier_cities', ['id' => $this->fardarCity->id]);
        $this->assertDatabaseHas('courier_states', ['id' => $this->fardarState->id]);
        $this->assertDatabaseHas('orders', ['id' => $graph['order']->id]);
        $this->assertDatabaseHas('shipments', ['id' => $graph['shipment']->id]);
        $this->assertDatabaseHas('order_items', ['id' => $graph['order_item']->id]);
        $this->assertDatabaseHas('shipment_events', ['id' => $graph['shipment_event']->id]);
    }

    public function test_cleanup_refuses_if_fardar_is_missing(): void
    {
        $this->fardar->forceFill(['code' => 'FARDAR_RENAMED_'.Str::upper(Str::random(4))])->save();

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsOutputToContain('cannot be identified')
            ->assertFailed();

        $this->assertDatabaseHas('courier_cities', ['id' => $this->fardarCity->id]);
    }

    public function test_cleanup_refuses_if_fardar_is_not_unique(): void
    {
        $service = new class extends FardarLocationCleanupService
        {
            protected function findFardarCandidates(): Collection
            {
                return collect([
                    (new Courier)->forceFill(['id' => 1, 'code' => 'FARDAR', 'name' => 'A']),
                    (new Courier)->forceFill(['id' => 2, 'code' => 'FARDAR', 'name' => 'B']),
                ]);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be uniquely identified');
        $service->resolveFardarCourier();
    }

    public function test_cleanup_refuses_to_delete_shared_records(): void
    {
        $this->fardarCity->forceFill(['courier_state_id' => $this->royalState->id])->save();

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsOutputToContain('shared location records')
            ->assertFailed();

        $this->assertDatabaseHas('courier_cities', ['id' => $this->fardarCity->id]);
        $this->assertDatabaseHas('courier_states', ['id' => $this->fardarState->id]);
    }

    public function test_unexpected_non_fardar_shipment_dependency_refuses_cleanup(): void
    {
        $order = $this->makeOrderReferencingFardarCity();

        // Unexpected: Royal shipment pointing at a Fardar city.
        $badShipment = Shipment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'courier_id' => $this->royal->id,
            'courier_service_id' => $this->ensureService($this->royal)->id,
            'courier_city_id' => $this->fardarCity->id,
            'tracking_number' => 'TRK-UNEXPECTED-1',
            'weight_snapshot' => 1,
            'courier_fee_snapshot' => 100,
            'currency_id' => $order->currency_id,
            'status' => ShipmentStatus::BOOKED,
            'booked_at' => now(),
        ]);

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsOutputToContain('unexpected dependencies')
            ->assertFailed();

        $this->assertDatabaseHas('courier_cities', ['id' => $this->fardarCity->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('shipments', ['id' => $badShipment->id]);
    }

    public function test_dry_run_reports_dependent_test_data_without_deleting(): void
    {
        $this->seedFardarDependentBusinessGraph();
        $before = $this->snapshotCounts();

        $this->artisan('courier:fardar-cleanup')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Dependent test data:')
            ->expectsOutputToContain('Orders: 1')
            ->expectsOutputToContain('Shipments: 1')
            ->expectsOutputToContain('No records have been deleted.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshotCounts());
    }

    public function test_fardar_courier_row_and_services_remain(): void
    {
        $service = $this->ensureService($this->fardar);

        $this->artisan('courier:fardar-cleanup', ['--execute' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('couriers', [
            'id' => $this->fardar->id,
            'code' => 'FARDAR',
        ]);
        $this->assertDatabaseHas('courier_services', [
            'id' => $service->id,
            'courier_id' => $this->fardar->id,
        ]);
    }

    /**
     * Isolate from production Fardar rows by renaming the live courier inside the test transaction.
     */
    private function installIsolatedCouriers(): void
    {
        $existingFardar = Courier::query()
            ->whereRaw('UPPER(TRIM(code)) = ?', ['FARDAR'])
            ->first();

        if ($existingFardar !== null) {
            $existingFardar->forceFill([
                'code' => 'FARDAR_HOLD_'.Str::upper(Str::random(6)),
            ])->save();
        }

        $this->royal = Courier::query()->updateOrCreate(
            ['code' => 'ROYAL'],
            ['name' => 'Royal Express', 'is_active' => true]
        );
        $this->transexpress = Courier::query()->updateOrCreate(
            ['code' => 'TRANSEXPRESS'],
            ['name' => 'TransExpress', 'is_active' => true]
        );
        $this->fardar = Courier::query()->create([
            'code' => 'FARDAR',
            'name' => 'Fardar Express Domestic',
            'is_active' => true,
        ]);

        $this->fardarState = CourierState::query()->create([
            'courier_id' => $this->fardar->id,
            'external_id' => 'cleanup-fardar-state-1',
            'name' => 'Cleanup Fardar District',
            'is_active' => true,
        ]);
        $this->fardarCity = CourierCity::query()->create([
            'courier_id' => $this->fardar->id,
            'courier_state_id' => $this->fardarState->id,
            'external_id' => 'cleanup-fardar-city-1',
            'name' => 'Cleanup Fardar City',
            'city_name' => 'Cleanup Fardar City',
            'district_name' => 'Cleanup Fardar District',
            'external_city_code' => 'cleanup-fardar-city-1',
            'external_district_code' => 'cleanup-fardar-state-1',
            'is_active' => true,
        ]);

        $this->royalState = CourierState::query()->updateOrCreate(
            [
                'courier_id' => $this->royal->id,
                'external_id' => 'cleanup-royal-state-1',
            ],
            [
                'name' => 'Cleanup Royal State',
                'is_active' => true,
            ]
        );
        $this->royalCity = CourierCity::query()->updateOrCreate(
            [
                'courier_id' => $this->royal->id,
                'external_id' => 'cleanup-royal-city-1',
            ],
            [
                'courier_state_id' => $this->royalState->id,
                'name' => 'Cleanup Royal City',
                'city_name' => 'Cleanup Royal City',
                'district_name' => 'Cleanup Royal State',
                'external_city_code' => 'cleanup-royal-city-1',
                'external_district_code' => 'cleanup-royal-state-1',
                'is_active' => true,
            ]
        );

        $this->txState = CourierState::query()->updateOrCreate(
            [
                'courier_id' => $this->transexpress->id,
                'external_id' => 'cleanup-tx-state-1',
            ],
            [
                'name' => 'Cleanup TX State',
                'is_active' => true,
            ]
        );
        $this->txCity = CourierCity::query()->updateOrCreate(
            [
                'courier_id' => $this->transexpress->id,
                'external_id' => 'cleanup-tx-city-1',
            ],
            [
                'courier_state_id' => $this->txState->id,
                'name' => 'Cleanup TX City',
                'city_name' => 'Cleanup TX City',
                'district_name' => 'Cleanup TX State',
                'external_city_code' => 'cleanup-tx-city-1',
                'external_district_code' => 'cleanup-tx-state-1',
                'is_active' => true,
            ]
        );
    }

    private function ensureService(Courier $courier): CourierService
    {
        return CourierService::query()->firstOrCreate(
            [
                'courier_id' => $courier->id,
                'code' => 'STANDARD',
            ],
            [
                'name' => 'Standard Delivery',
                'external_service_id' => strtolower($courier->code).'-standard',
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array{
     *     order: Order,
     *     shipment: Shipment,
     *     order_item: OrderItem,
     *     order_address: OrderAddress,
     *     status_history: OrderStatusHistory,
     *     shipment_event: ShipmentEvent
     * }
     */
    private function seedFardarDependentBusinessGraph(): array
    {
        $order = $this->makeOrderReferencingFardarCity();
        $catalog = $this->makeSupplierProductVariant($order->supplier);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $catalog['product']->id,
            'product_variant_id' => $catalog['variant']->id,
            'product_name_snapshot' => $catalog['product']->name,
            'variant_name_snapshot' => $catalog['variant']->name,
            'barcode_snapshot' => $catalog['variant']->barcode,
            'quantity' => 1,
            'unit_selling_price' => 100,
            'unit_cost_snapshot' => 50,
            'unit_company_commission_snapshot' => 10,
            'unit_weight_snapshot' => 1,
            'line_selling_total' => 100,
            'line_weight_total' => 1,
        ]);

        $address = OrderAddress::query()->create([
            'order_id' => $order->id,
            'recipient_name' => 'Cleanup Customer',
            'line1' => 'Cleanup Street 1',
            'full_address_text' => 'Cleanup Street 1',
            'city_name' => 'Cleanup Fardar City',
            'country_id' => $this->countryByIso('LK')->id,
        ]);

        $history = OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::PENDING,
            'changed_by' => $order->created_by,
            'changed_by_company_id' => $order->reseller_company_id,
            'reason' => 'cleanup-seed',
            'created_at' => now(),
        ]);

        $shipment = $this->makeShipmentReferencingFardarCity($order);

        $event = ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'external_status' => 'BOOKED',
            'normalized_status' => ShipmentStatus::BOOKED->value,
            'description' => 'Cleanup seed event',
            'event_at' => now(),
            'raw_response' => ['source' => 'cleanup-test'],
            'created_at' => now(),
        ]);

        return [
            'order' => $order,
            'shipment' => $shipment,
            'order_item' => $item,
            'order_address' => $address,
            'status_history' => $history,
            'shipment_event' => $event,
        ];
    }

    private function makeOrderReferencingFardarCity(): Order
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $market = $this->marketByCode('lk');

        $customer = Customer::query()->create([
            'uuid' => UuidService::generate(),
            'display_name' => 'Cleanup Customer',
            'primary_country_id' => $this->countryByIso('LK')->id,
            'is_banned' => false,
        ]);

        return Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-CLN-'.random_int(100000, 999999),
            'source' => OrderSource::MANUAL,
            'status' => OrderStatus::PENDING,
            'market_id' => $market->id,
            'currency_id' => $market->currency_id,
            'market_code_snapshot' => $market->code,
            'currency_code_snapshot' => 'LKR',
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => 'Cleanup Customer',
            'primary_phone_snapshot' => '070'.random_int(1000000, 9999999),
            'primary_phone_country_id' => $this->countryByIso('LK')->id,
            'items_subtotal' => 100,
            'discount_amount' => 0,
            'courier_fee_amount' => 0,
            'customer_payable_amount' => 100,
            'total_weight' => 1,
            'draft_courier_id' => $this->fardar->id,
            'draft_courier_city_id' => $this->fardarCity->id,
            'created_by' => $reseller->id,
        ]);
    }

    private function makeShipmentReferencingFardarCity(Order $order): Shipment
    {
        return Shipment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'courier_id' => $this->fardar->id,
            'courier_service_id' => $this->ensureService($this->fardar)->id,
            'courier_city_id' => $this->fardarCity->id,
            'tracking_number' => 'TRK-CLEANUP-1',
            'weight_snapshot' => 1,
            'courier_fee_snapshot' => 100,
            'currency_id' => $order->currency_id,
            'status' => ShipmentStatus::BOOKED,
            'booked_at' => now(),
        ]);
    }

    /**
     * @return array{
     *     fardar_states: int,
     *     fardar_cities: int,
     *     royal_states: int,
     *     royal_cities: int,
     *     tx_states: int,
     *     tx_cities: int,
     *     fardar_orders: int,
     *     fardar_shipments: int
     * }
     */
    private function snapshotCounts(): array
    {
        $cityIds = CourierCity::query()->where('courier_id', $this->fardar->id)->pluck('id');

        return [
            'fardar_states' => CourierState::query()->where('courier_id', $this->fardar->id)->count(),
            'fardar_cities' => CourierCity::query()->where('courier_id', $this->fardar->id)->count(),
            'royal_states' => CourierState::query()->where('courier_id', $this->royal->id)->count(),
            'royal_cities' => CourierCity::query()->where('courier_id', $this->royal->id)->count(),
            'tx_states' => CourierState::query()->where('courier_id', $this->transexpress->id)->count(),
            'tx_cities' => CourierCity::query()->where('courier_id', $this->transexpress->id)->count(),
            'fardar_orders' => $cityIds->isEmpty()
                ? 0
                : (int) DB::table('orders')->whereIn('draft_courier_city_id', $cityIds)->count(),
            'fardar_shipments' => $cityIds->isEmpty()
                ? 0
                : (int) DB::table('shipments')->whereIn('courier_city_id', $cityIds)->count(),
        ];
    }
}
