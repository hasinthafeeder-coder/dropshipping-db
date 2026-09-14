<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\OrderAssignmentState;
use Feeder\Core\Enums\OrderCcaAssignmentOrigin;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderCcaAssignment;
use Feeder\Core\Services\Order\OrderCcaAssignmentService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class OrderPoolAssignmentTest extends TestCase
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

    public function test_existing_order_defaults_to_unassigned_when_cca_null(): void
    {
        $order = $this->createSampleOrder();

        $this->assertNull($order->cca_id);
        $this->assertFalse((bool) $order->available_in_pool);
        $this->assertSame(OrderAssignmentState::UNASSIGNED, $order->assignmentState());
    }

    public function test_direct_assignment_sets_assigned_state_and_origin_direct(): void
    {
        $order = $this->createSampleOrder();
        $cca = $this->makeCcaUser($order->resellerCompany);
        $service = app(OrderCcaAssignmentService::class);

        $service->assign($order, (int) $cca->id, (int) $order->reseller_id);

        $order = $order->fresh(['ccaAssignments']);
        $this->assertSame(OrderAssignmentState::ASSIGNED, $order->assignmentState());
        $this->assertFalse((bool) $order->available_in_pool);
        $this->assertSame((int) $cca->id, (int) $order->cca_id);
        $this->assertSame(
            OrderCcaAssignmentOrigin::DIRECT,
            $order->ccaAssignments->last()->origin
        );
    }

    public function test_unassign_returns_to_unassigned(): void
    {
        $order = $this->createSampleOrder();
        $cca = $this->makeCcaUser($order->resellerCompany);
        $service = app(OrderCcaAssignmentService::class);

        $service->assign($order, (int) $cca->id, (int) $order->reseller_id);
        $service->unassign($order->fresh(), (int) $order->reseller_id);

        $order = $order->fresh(['ccaAssignments']);
        $this->assertNull($order->cca_id);
        $this->assertFalse((bool) $order->available_in_pool);
        $this->assertSame(OrderAssignmentState::UNASSIGNED, $order->assignmentState());
        $this->assertNotNull($order->ccaAssignments->last()->unassigned_at);
    }

    public function test_move_to_pool_sets_pool_state(): void
    {
        $order = $this->createSampleOrder();
        $service = app(OrderCcaAssignmentService::class);

        $service->moveToPool($order, (int) $order->reseller_id);

        $order = $order->fresh();
        $this->assertNull($order->cca_id);
        $this->assertTrue((bool) $order->available_in_pool);
        $this->assertSame(OrderAssignmentState::POOL, $order->assignmentState());
    }

    public function test_cca_claims_pool_order_with_pool_claim_origin(): void
    {
        $order = $this->createSampleOrder();
        $cca = $this->makeCcaUser($order->resellerCompany);
        $service = app(OrderCcaAssignmentService::class);

        $service->moveToPool($order, (int) $order->reseller_id);
        $service->claimFromPool($order->fresh(), $cca, (int) $order->reseller_company_id);

        $order = $order->fresh(['ccaAssignments']);
        $this->assertSame((int) $cca->id, (int) $order->cca_id);
        $this->assertFalse((bool) $order->available_in_pool);
        $this->assertSame(
            OrderCcaAssignmentOrigin::POOL_CLAIM,
            $order->ccaAssignments->last()->origin
        );
    }

    public function test_cca_cannot_claim_second_pool_order_before_status_change(): void
    {
        $ctx = $this->makeOrderContext();
        $first = $this->createSampleOrder($ctx);
        $second = $this->createSampleOrder($ctx);
        $cca = $this->makeCcaUser($ctx['reseller']->company);
        $service = app(OrderCcaAssignmentService::class);

        $service->moveToPool($first, (int) $ctx['reseller']->id);
        $service->moveToPool($second, (int) $ctx['reseller']->id);
        $service->claimFromPool($first->fresh(), $cca, (int) $ctx['reseller']->company_id);

        $this->expectException(ValidationException::class);
        $service->claimFromPool($second->fresh(), $cca, (int) $ctx['reseller']->company_id);
    }

    public function test_cca_can_claim_another_pool_order_after_status_change(): void
    {
        $ctx = $this->makeOrderContext();
        $first = $this->createSampleOrder($ctx);
        $second = $this->createSampleOrder($ctx);
        $cca = $this->makeCcaUser($ctx['reseller']->company);
        $service = app(OrderCcaAssignmentService::class);
        $statusService = app(OrderStatusService::class);

        $service->moveToPool($first, (int) $ctx['reseller']->id);
        $service->moveToPool($second, (int) $ctx['reseller']->id);
        $service->claimFromPool($first->fresh(), $cca, (int) $ctx['reseller']->company_id);

        $statusService->transition(
            $first->fresh(),
            OrderStatus::HOLD,
            (int) $cca->id,
            (int) $ctx['reseller']->company_id,
            'Working claim',
            (int) $ctx['reseller']->company_id,
        );

        $service->claimFromPool($second->fresh(), $cca, (int) $ctx['reseller']->company_id);

        $this->assertSame((int) $cca->id, (int) $second->fresh()->cca_id);
    }

    public function test_second_cca_cannot_claim_same_pool_order(): void
    {
        $ctx = $this->makeOrderContext();
        $order = $this->createSampleOrder($ctx);
        $ccaOne = $this->makeCcaUser($ctx['reseller']->company);
        $ccaTwo = $this->makeCcaUser($ctx['reseller']->company);
        $service = app(OrderCcaAssignmentService::class);

        $service->moveToPool($order, (int) $ctx['reseller']->id);
        $service->claimFromPool($order->fresh(), $ccaOne, (int) $ctx['reseller']->company_id);

        $this->expectException(ValidationException::class);
        $service->claimFromPool($order->fresh(), $ccaTwo, (int) $ctx['reseller']->company_id);
    }

    public function test_non_cca_cannot_self_claim_pool_order(): void
    {
        $order = $this->createSampleOrder();
        $service = app(OrderCcaAssignmentService::class);
        $service->moveToPool($order, (int) $order->reseller_id);

        $this->expectException(ValidationException::class);
        $service->claimFromPool(
            $order->fresh(),
            $order->reseller,
            (int) $order->reseller_company_id,
        );
    }

    public function test_cross_company_assignment_is_rejected(): void
    {
        $order = $this->createSampleOrder();
        $otherCca = $this->makeCcaUser($this->makeResellerUser()->company);
        $service = app(OrderCcaAssignmentService::class);

        $this->expectException(ValidationException::class);
        $service->assign(
            $order,
            (int) $otherCca->id,
            (int) $order->reseller_id,
            null,
            (int) $order->reseller_company_id,
        );
    }

    public function test_pool_state_cleared_when_assigned(): void
    {
        $order = $this->createSampleOrder();
        $cca = $this->makeCcaUser($order->resellerCompany);
        $service = app(OrderCcaAssignmentService::class);

        $service->moveToPool($order, (int) $order->reseller_id);
        $this->assertTrue((bool) $order->fresh()->available_in_pool);

        $service->assign($order->fresh(), (int) $cca->id, (int) $order->reseller_id);
        $this->assertFalse((bool) $order->fresh()->available_in_pool);
    }

    public function test_existing_assignment_history_defaults_to_direct_origin(): void
    {
        $order = $this->createSampleOrder();
        $cca = $this->makeCcaUser($order->resellerCompany);

        $assignment = OrderCcaAssignment::query()->create([
            'order_id' => $order->id,
            'cca_id' => $cca->id,
            'assigned_by' => $order->reseller_id,
            'assigned_at' => now(),
        ]);

        $this->assertSame(OrderCcaAssignmentOrigin::DIRECT, $assignment->fresh()->origin);
    }

    public function test_assignment_state_filters_and_bulk_operations(): void
    {
        $ctx = $this->makeOrderContext();
        $assigned = $this->createSampleOrder($ctx);
        $unassigned = $this->createSampleOrder($ctx);
        $pool = $this->createSampleOrder($ctx);
        $cca = $this->makeCcaUser($ctx['reseller']->company);
        $service = app(OrderCcaAssignmentService::class);

        $service->assign($assigned, (int) $cca->id, (int) $ctx['reseller']->id);
        $service->moveToPool($pool, (int) $ctx['reseller']->id);

        $companyId = (int) $ctx['reseller']->company_id;

        $this->assertSame(1, Order::query()->where('reseller_company_id', $companyId)->assignmentState(OrderAssignmentState::ASSIGNED)->count());
        $this->assertSame(1, Order::query()->where('reseller_company_id', $companyId)->assignmentState(OrderAssignmentState::UNASSIGNED)->count());
        $this->assertSame(1, Order::query()->where('reseller_company_id', $companyId)->assignmentState(OrderAssignmentState::POOL)->count());

        $service->bulkAssign(
            [(int) $unassigned->id, (int) $pool->id],
            (int) $cca->id,
            (int) $ctx['reseller']->id,
            $companyId,
        );

        $this->assertSame(3, Order::query()->where('reseller_company_id', $companyId)->assignmentState(OrderAssignmentState::ASSIGNED)->count());

        $service->bulkMoveToPool(
            [(int) $assigned->id, (int) $unassigned->id],
            (int) $ctx['reseller']->id,
            $companyId,
        );

        $this->assertSame(2, Order::query()->where('reseller_company_id', $companyId)->assignmentState(OrderAssignmentState::POOL)->count());
    }

    public function test_manual_create_origin_when_assigning_with_manual_create(): void
    {
        $order = $this->createSampleOrder();
        $cca = $this->makeCcaUser($order->resellerCompany);
        $service = app(OrderCcaAssignmentService::class);

        $service->assign(
            $order,
            (int) $cca->id,
            (int) $cca->id,
            null,
            (int) $order->reseller_company_id,
            OrderCcaAssignmentOrigin::MANUAL_CREATE,
        );

        $this->assertSame(
            OrderCcaAssignmentOrigin::MANUAL_CREATE,
            $order->fresh(['ccaAssignments'])->ccaAssignments->last()->origin
        );
    }

    /**
     * @return array{reseller: \Feeder\Core\Models\User, supplier: \Feeder\Core\Models\User, variant: \Feeder\Core\Models\ProductVariant}
     */
    private function makeOrderContext(): array
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        return compact('reseller', 'supplier', 'variant');
    }

    /**
     * @param  array{reseller?: \Feeder\Core\Models\User, supplier?: \Feeder\Core\Models\User, variant?: \Feeder\Core\Models\ProductVariant}|array<string, mixed>  $contextOrOverrides
     */
    private function createSampleOrder(array $contextOrOverrides = []): Order
    {
        $contextKeys = ['reseller', 'supplier', 'variant'];
        $isContext = isset($contextOrOverrides['reseller'], $contextOrOverrides['supplier'], $contextOrOverrides['variant']);

        if ($isContext) {
            $ctx = $contextOrOverrides;
            $overrides = [];
        } else {
            $ctx = $this->makeOrderContext();
            $overrides = $contextOrOverrides;
        }

        return app(OrderService::class)->create(array_merge([
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $ctx['reseller']->id,
            'reseller_company_id' => $ctx['reseller']->company_id,
            'supplier_id' => $ctx['supplier']->id,
            'created_by' => $ctx['reseller']->id,
            'customer' => [
                'display_name' => 'Pool Customer',
                'primary_country_id' => $this->countryByIso('LK')->id,
                'primary_phone' => '077'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $this->countryByIso('LK')->id,
            ],
            'address' => [
                'recipient_name' => 'Pool Customer',
                'line1' => 'Line 1',
                'city_name' => 'Colombo',
                'country_id' => $this->countryByIso('LK')->id,
            ],
            'items' => [
                [
                    'product_variant_id' => $ctx['variant']->id,
                    'quantity' => 1,
                    'unit_selling_price' => (float) $ctx['variant']->selling_price,
                ],
            ],
        ], $overrides));
    }
}
