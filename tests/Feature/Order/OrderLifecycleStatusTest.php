<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Services\Order\OrderStatusService;
use Tests\TestCase;

class OrderLifecycleStatusTest extends TestCase
{
    public function test_canonical_enum_contains_expected_lifecycle_cases(): void
    {
        $values = array_map(static fn (OrderStatus $status) => $status->value, OrderStatus::cases());

        $this->assertSame([
            'PENDING',
            '1ST_ATTEMPT',
            '2ND_ATTEMPT',
            '3RD_ATTEMPT',
            'HOLD',
            'CONFIRMED',
            'PRINT',
            'PRINTED',
            'PACKING',
            'DISPATCHED',
            'CANCELLED',
        ], $values);
    }

    public function test_supplier_forward_transitions_are_sequential(): void
    {
        $this->assertTrue(OrderStatus::canTransitionTo(OrderStatus::CONFIRMED, OrderStatus::PRINT));
        $this->assertTrue(OrderStatus::canTransitionTo(OrderStatus::PRINT, OrderStatus::PRINTED));
        $this->assertTrue(OrderStatus::canTransitionTo(OrderStatus::PRINTED, OrderStatus::PACKING));
        $this->assertTrue(OrderStatus::canTransitionTo(OrderStatus::PACKING, OrderStatus::DISPATCHED));

        $this->assertFalse(OrderStatus::canTransitionTo(OrderStatus::CONFIRMED, OrderStatus::PRINTED));
        $this->assertFalse(OrderStatus::canTransitionTo(OrderStatus::PRINT, OrderStatus::PACKING));
        $this->assertFalse(OrderStatus::canTransitionTo(OrderStatus::PRINTED, OrderStatus::DISPATCHED));
    }

    public function test_cancellation_allowed_before_dispatch_and_blocked_after(): void
    {
        $statusService = app(OrderStatusService::class);

        foreach ([
            OrderStatus::PENDING,
            OrderStatus::FIRST_ATTEMPT,
            OrderStatus::SECOND_ATTEMPT,
            OrderStatus::THIRD_ATTEMPT,
            OrderStatus::HOLD,
            OrderStatus::CONFIRMED,
            OrderStatus::PRINT,
            OrderStatus::PRINTED,
            OrderStatus::PACKING,
        ] as $status) {
            $this->assertTrue($status->canCancel(), $status->value.' should be cancellable');
            $this->assertTrue(OrderStatus::canTransitionTo($status, OrderStatus::CANCELLED));
            $this->assertTrue($statusService->canCancel($status));
            $this->assertTrue($statusService->isBeforeDispatch($status));
        }

        $this->assertFalse(OrderStatus::DISPATCHED->canCancel());
        $this->assertFalse(OrderStatus::canTransitionTo(OrderStatus::DISPATCHED, OrderStatus::CANCELLED));
        $this->assertFalse($statusService->canCancel(OrderStatus::DISPATCHED));
        $this->assertFalse($statusService->isBeforeDispatch(OrderStatus::DISPATCHED));

        $this->assertFalse(OrderStatus::CANCELLED->canCancel());
    }

    public function test_call_center_statuses_exclude_supplier_fulfillment_statuses(): void
    {
        $values = OrderStatus::callCenterStatusValues();

        $this->assertContains(OrderStatus::CONFIRMED->value, $values);
        $this->assertNotContains(OrderStatus::PRINT->value, $values);
        $this->assertNotContains(OrderStatus::PRINTED->value, $values);
        $this->assertNotContains(OrderStatus::PACKING->value, $values);
        $this->assertNotContains(OrderStatus::DISPATCHED->value, $values);
        $this->assertContains(OrderStatus::CANCELLED->value, OrderStatus::callCenterActionableStatusValues());
    }
}
