<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Services\Order\OrderCcaAssignmentService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class OrderFoundationTest extends TestCase
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

    public function test_order_defaults_to_pending_with_initial_status_history(): void
    {
        $order = $this->createSampleOrder();

        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertSame(OrderSource::MANUAL, $order->source);
        $this->assertNotNull($order->order_number);

        $history = OrderStatusHistory::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $history);
        $this->assertNull($history->first()->from_status);
        $this->assertSame(OrderStatus::PENDING, $history->first()->to_status);
    }

    public function test_order_number_is_unique(): void
    {
        $first = $this->createSampleOrder();
        $second = $this->createSampleOrder();

        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_one_order_cannot_contain_variants_from_different_suppliers(): void
    {
        $reseller = $this->makeResellerUser();
        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplierA);
        $this->assignSupplierToReseller($reseller, $supplierB);
        $variantA = $this->makeSupplierProductVariant($supplierA)['variant'];
        $variantB = $this->makeSupplierProductVariant($supplierB)['variant'];
        $countryId = $this->countryByIso('LK')->id;

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplierA->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Mixed Supplier',
                'primary_country_id' => $countryId,
                'primary_phone' => '0715555555',
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Mixed Supplier',
                'line1' => '123 Main St',
                'city_name' => 'Colombo',
                'country_id' => $countryId,
            ],
            'items' => [
                [
                    'product_variant_id' => $variantA->id,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
                [
                    'product_variant_id' => $variantB->id,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
            ],
        ]);
    }

    public function test_duplicate_variant_lines_are_prevented(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Dup Lines',
                'primary_country_id' => $countryId,
                'primary_phone' => '0716666666',
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Dup Lines',
                'line1' => '123 Main St',
                'city_name' => 'Colombo',
                'country_id' => $countryId,
            ],
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                    'unit_selling_price' => 250,
                ],
            ],
        ]);
    }

    public function test_customer_identity_and_financial_snapshots_exist(): void
    {
        $order = $this->createSampleOrder([
            'discount_amount' => 50,
            'courier_fee_amount' => 600,
        ]);

        $this->assertNotNull($order->customer_id);
        $this->assertNotNull($order->customer_name_snapshot);
        $this->assertNotNull($order->primary_phone_snapshot);
        $this->assertSame('250.00', $order->items_subtotal);
        $this->assertSame('50.00', $order->discount_amount);
        $this->assertSame('600.00', $order->courier_fee_amount);
        $this->assertSame('800.00', $order->customer_payable_amount);
        $this->assertSame('0.00', $order->reseller_commission_amount);
        $this->assertSame('0.00', $order->agent_commission_amount);
    }

    public function test_arbitrary_status_transitions_append_history(): void
    {
        $order = $this->createSampleOrder();
        $statusService = app(OrderStatusService::class);

        $statusService->transition($order, OrderStatus::CONFIRMED, $order->reseller_id, $order->reseller_company_id, 'Jump');
        $statusService->transition($order->fresh(), OrderStatus::HOLD, $order->reseller_id, $order->reseller_company_id, 'Hold');
        $statusService->transition($order->fresh(), OrderStatus::FIRST_ATTEMPT, $order->reseller_id, $order->reseller_company_id);

        $histories = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $histories);
        $this->assertSame(OrderStatus::FIRST_ATTEMPT, $order->fresh()->status);

        $histories->first()->update(['reason' => 'mutated']);
        $this->assertSame('Order created', $histories->first()->fresh()->reason);
    }

    public function test_cca_current_and_assignment_history(): void
    {
        $order = $this->createSampleOrder();
        $ccaOne = $this->makeCcaUser($order->resellerCompany);
        $ccaTwo = $this->makeCcaUser($order->resellerCompany);
        $service = app(OrderCcaAssignmentService::class);

        $service->assign($order, $ccaOne->id, $order->reseller_id, 'First CCA');
        $service->assign($order->fresh(), $ccaTwo->id, $order->reseller_id, 'Second CCA');

        $order = $order->fresh(['ccaAssignments']);

        $this->assertSame($ccaTwo->id, $order->cca_id);
        $this->assertCount(2, $order->ccaAssignments);
        $this->assertNotNull($order->ccaAssignments->first()->unassigned_at);
        $this->assertNull($order->ccaAssignments->last()->unassigned_at);
    }

    public function test_duplicate_variant_unique_constraint_at_database(): void
    {
        $order = $this->createSampleOrder();
        $item = $order->items->first();

        $this->expectException(QueryException::class);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'product_name_snapshot' => 'X',
            'variant_name_snapshot' => 'Y',
            'barcode_snapshot' => 'Z',
            'quantity' => 1,
            'unit_selling_price' => 10,
            'unit_cost_snapshot' => 5,
            'unit_company_commission_snapshot' => 1,
            'unit_weight_snapshot' => 0.1,
            'line_selling_total' => 10,
            'line_weight_total' => 0.1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSampleOrder(array $overrides = []): Order
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;
        $phone = '070'.random_int(1000000, 9999999);

        return app(OrderService::class)->create(array_merge([
            'source' => OrderSource::MANUAL,
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Order Customer',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Order Customer',
                'line1' => '45 Galle Road',
                'city_name' => 'Colombo',
                'district_name' => 'Colombo',
                'country_id' => $countryId,
                'full_address_text' => '45 Galle Road, Colombo',
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
