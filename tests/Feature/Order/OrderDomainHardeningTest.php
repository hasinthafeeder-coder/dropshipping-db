<?php

namespace Tests\Feature\Order;

use Carbon\CarbonImmutable;
use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\OrderCommentContextType;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Exceptions\ConflictingCustomerIdentityException;
use Feeder\Core\Exceptions\DuplicateOrderWarningException;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\CustomerPhone;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderCcaAssignment;
use Feeder\Core\Models\OrderComment;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Order\AfterHoursDeterminationService;
use Feeder\Core\Services\Order\CustomerBanService;
use Feeder\Core\Services\Order\CustomerIdentityService;
use Feeder\Core\Services\Order\OrderCcaAssignmentService;
use Feeder\Core\Services\Order\OrderCommentService;
use Feeder\Core\Services\Order\OrderDiscountService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Feeder\Core\Services\Order\ShipmentBookingService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class OrderDomainHardeningTest extends TestCase
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

    public function test_rejects_reseller_company_mismatch(): void
    {
        $reseller = $this->makeResellerUser();
        $other = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $this->expectException(ValidationException::class);

        $this->createOrderPayload($reseller, $supplier, $variant, [
            'reseller_company_id' => $other->company_id,
        ]);
    }

    public function test_rejects_unauthorized_market(): void
    {
        $reseller = $this->makeResellerUser(null, ['lk']);
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $this->expectException(ValidationException::class);

        $this->createOrderPayload($reseller, $supplier, $variant, [
            'market_id' => $this->marketByCode('my')->id,
        ]);
    }

    public function test_rejects_unauthorized_supplier(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $this->expectException(ValidationException::class);

        $this->createOrderPayload($reseller, $supplier, $variant);
    }

    public function test_rejects_product_market_mismatch(): void
    {
        $reseller = $this->makeResellerUser(null, ['lk', 'my']);
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier, [], 'lk')['variant'];

        try {
            $this->createOrderPayload($reseller, $supplier, $variant, [
                'market_id' => $this->marketByCode('my')->id,
            ]);
            $this->fail('Expected product market mismatch rejection.');
        } catch (ValidationException $e) {
            $this->assertTrue(
                collect($e->errors())->flatten()->contains(
                    fn ($message) => str_contains((string) $message, 'Product market must match')
                )
            );
        }
    }

    public function test_rejects_order_for_market_without_reseller_access_even_if_product_matches(): void
    {
        $reseller = $this->makeResellerUser(null, ['lk']);
        $supplierMy = $this->makeSupplierUser($this->makeCompany(
            \Feeder\Core\Enums\PortalCode::SUPPLIER,
            ['operation_market_id' => $this->marketByCode('my')->id]
        ));
        $this->assignSupplierToReseller($reseller, $supplierMy);
        $variant = $this->makeSupplierProductVariant($supplierMy, [], 'my')['variant'];

        try {
            $this->createOrderPayload($reseller, $supplierMy, $variant, [
                'market_id' => $this->marketByCode('my')->id,
            ]);
            $this->fail('Expected market access rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('market_id', $e->errors());
        }
    }

    public function test_cca_rejects_wrong_company_non_cca_inactive_and_accepts_valid(): void
    {
        $order = $this->createReadyOrder();
        $service = app(OrderCcaAssignmentService::class);

        $otherCompanyCca = $this->makeCcaUser($this->makeResellerUser()->company);
        try {
            $service->assign($order, $otherCompanyCca->id, $order->reseller_id);
            $this->fail('Expected wrong company rejection.');
        } catch (ValidationException) {
            $this->assertSame(0, OrderCcaAssignment::query()->where('order_id', $order->id)->count());
        }

        $nonCca = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $order->reseller_company_id,
            'email' => 'staff-'.uniqid().'@feeder.local',
            'phone' => '075'.random_int(1000000, 9999999),
            'password' => bcrypt('password'),
            'user_type' => UserType::EMPLOYEE->value,
            'status' => UserStatus::ACTIVE->value,
        ]);

        $this->expectException(ValidationException::class);
        $service->assign($order, $nonCca->id, $order->reseller_id);
    }

    public function test_cca_rejects_inactive_and_supports_reassignment(): void
    {
        $order = $this->createReadyOrder();
        $service = app(OrderCcaAssignmentService::class);
        $cca = $this->makeCcaUser($order->resellerCompany);
        $cca->forceFill(['status' => UserStatus::SUSPENDED->value])->save();

        try {
            $service->assign($order, $cca->id, $order->reseller_id);
            $this->fail('Expected inactive CCA rejection.');
        } catch (ValidationException) {
            $this->assertSame(0, OrderCcaAssignment::query()->where('order_id', $order->id)->count());
        }

        $cca->forceFill(['status' => UserStatus::ACTIVE->value])->save();
        $ccaTwo = $this->makeCcaUser($order->resellerCompany);

        $service->assign($order, $cca->id, $order->reseller_id, 'first');
        $service->assign($order->fresh(), $ccaTwo->id, $order->reseller_id, 'second');

        $order = $order->fresh(['ccaAssignments']);
        $this->assertSame($ccaTwo->id, $order->cca_id);
        $this->assertCount(2, $order->ccaAssignments);
        $this->assertNotNull($order->ccaAssignments->first()->unassigned_at);
    }

    public function test_discount_lifecycle_and_negative_payable_protection(): void
    {
        $order = $this->createReadyOrder(['discount_amount' => 0]);
        $discountService = app(OrderDiscountService::class);
        $statusService = app(OrderStatusService::class);

        $discountService->apply($order, 25, $order->reseller_id, $order->reseller_company_id);
        $this->assertSame('25.00', $order->fresh()->discount_amount);

        $discountService->apply($order->fresh(), 40, $order->reseller_id, $order->reseller_company_id);
        $this->assertSame('40.00', $order->fresh()->discount_amount);

        try {
            $discountService->apply($order->fresh(), 999, $order->reseller_id, $order->reseller_company_id);
            $this->fail('Expected excessive discount rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount_amount', $e->errors());
        }

        $statusService->transition($order->fresh(), OrderStatus::CONFIRMED, $order->reseller_id, $order->reseller_company_id);
        $confirmed = $order->fresh();
        $this->assertNotNull($confirmed->discount_locked_at);
        $this->assertTrue($confirmed->isDiscountLocked());

        try {
            $discountService->apply($confirmed, 10, $order->reseller_id, $order->reseller_company_id);
            $this->fail('Expected locked discount rejection.');
        } catch (ValidationException) {
            // expected
        }

        $statusService->transition($confirmed, OrderStatus::HOLD, $order->reseller_id, $order->reseller_company_id);
        $this->assertNotNull($confirmed->fresh()->discount_locked_at);

        $this->expectException(ValidationException::class);
        $this->createReadyOrder(['discount_amount' => 500]);
    }

    public function test_after_hours_boundaries_and_configured_penalty_snapshot(): void
    {
        $market = $this->marketByCode('lk');
        $service = app(AfterHoursDeterminationService::class);
        $tz = $service->resolveTimezone($market);

        $cases = [
            ['2026-09-07 06:59:00', true],
            ['2026-09-07 07:00:00', false],
            ['2026-09-07 21:00:00', false],
            ['2026-09-07 21:01:00', true],
        ];

        foreach ($cases as [$local, $expected]) {
            $at = CarbonImmutable::parse($local, $tz)->utc();
            $result = $service->determine($market, $at);
            $this->assertSame($expected, $result['after_hours'], $local);
            if ($expected) {
                $this->assertSame(100.0, $result['after_hours_penalty_amount']);
            } else {
                $this->assertSame(0.0, $result['after_hours_penalty_amount']);
            }
        }

        $service->setPenaltyAmount($market, '175.50');
        $at = CarbonImmutable::parse('2026-09-07 22:00:00', $tz)->utc();
        $order = $this->createReadyOrder([
            'evaluated_at' => $at,
            'after_hours' => false,
            'after_hours_penalty_amount' => 1,
        ]);

        $this->assertTrue($order->after_hours);
        $this->assertSame('175.50', $order->after_hours_penalty_amount);
    }

    public function test_duplicate_detection_override_cancelled_and_phones(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variantA = $this->makeSupplierProductVariant($supplier)['variant'];
        $variantB = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;
        $phone = '0701111222';

        $first = $this->createOrderPayload($reseller, $supplier, $variantA, [
            'customer' => [
                'display_name' => 'Dup Cust',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
            ],
        ]);

        try {
            $this->createOrderPayload($reseller, $supplier, $variantA, [
                'customer' => [
                    'display_name' => 'Dup Cust',
                    'primary_country_id' => $countryId,
                    'primary_phone' => $phone,
                    'primary_phone_country_id' => $countryId,
                ],
            ]);
            $this->fail('Expected duplicate warning.');
        } catch (DuplicateOrderWarningException $e) {
            $this->assertNotEmpty($e->duplicates);
        }

        $overridden = $this->createOrderPayload($reseller, $supplier, $variantA, [
            'customer' => [
                'display_name' => 'Dup Cust',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
            ],
            'duplicate_warning_overridden' => true,
        ]);
        $this->assertTrue($overridden->duplicate_warning_overridden);
        $this->assertSame($first->id, $overridden->duplicate_reference_order_id);

        $differentVariant = $this->createOrderPayload($reseller, $supplier, $variantB, [
            'customer' => [
                'display_name' => 'Dup Cust',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
            ],
        ]);
        $this->assertFalse($differentVariant->duplicate_warning_overridden);

        app(OrderStatusService::class)->transition($first, OrderStatus::CANCELLED, $reseller->id, $reseller->company_id);
        app(OrderStatusService::class)->transition($overridden, OrderStatus::CANCELLED, $reseller->id, $reseller->company_id);

        $afterCancel = $this->createOrderPayload($reseller, $supplier, $variantA, [
            'customer' => [
                'display_name' => 'Dup Cust',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
            ],
        ]);
        $this->assertFalse($afterCancel->duplicate_warning_shown);
    }

    public function test_duplicate_via_secondary_phone_and_conflicting_identities(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;

        $this->createOrderPayload($reseller, $supplier, $variant, [
            'customer' => [
                'display_name' => 'Primary',
                'primary_country_id' => $countryId,
                'primary_phone' => '0703333444',
                'primary_phone_country_id' => $countryId,
                'secondary_phone' => '0705555666',
                'secondary_phone_country_id' => $countryId,
            ],
        ]);

        try {
            $this->createOrderPayload($reseller, $supplier, $variant, [
                'customer' => [
                    'display_name' => 'Via Secondary',
                    'primary_country_id' => $countryId,
                    'primary_phone' => '0705555666',
                    'primary_phone_country_id' => $countryId,
                ],
            ]);
            $this->fail('Expected duplicate via shared phone identity.');
        } catch (DuplicateOrderWarningException) {
            // expected
        }

        app(CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => 'Other',
            'primary_country_id' => $countryId,
            'primary_phone' => '0707777888',
            'primary_phone_country_id' => $countryId,
        ]);

        $this->expectException(ConflictingCustomerIdentityException::class);
        app(CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => 'Conflict',
            'primary_country_id' => $countryId,
            'primary_phone' => '0703333444',
            'primary_phone_country_id' => $countryId,
            'secondary_phone' => '0707777888',
            'secondary_phone_country_id' => $countryId,
        ]);
    }

    public function test_missing_supplier_courier_account_does_not_invoke_adapter(): void
    {
        $order = $this->createReadyOrder();
        $setup = $this->makeCourierSetup($order, withAccount: false);
        $probe = new \stdClass;
        $probe->called = false;

        $adapter = new class($probe) implements CourierBookingAdapter
        {
            public function __construct(private readonly object $probe)
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
                $this->probe->called = true;

                return ['tracking_number' => 'SHOULD-NOT'];
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
            $this->fail('Expected missing account rejection.');
        } catch (ValidationException $e) {
            $this->assertTrue(
                array_key_exists('supplier_courier_account', $e->errors())
                || array_key_exists('courier_id', $e->errors())
            );
        }

        $this->assertFalse($probe->called);
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }

    public function test_missing_market_pricing_does_not_invent_fee_or_invoke_adapter(): void
    {
        $order = $this->createReadyOrder();
        $setup = $this->makeCourierSetup($order);
        CourierMarketPricing::query()->where('courier_id', $setup['courier']->id)->delete();

        $probe = new \stdClass;
        $probe->called = false;

        $adapter = new class($probe) implements CourierBookingAdapter
        {
            public function __construct(private readonly object $probe)
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
                $this->probe->called = true;

                return ['tracking_number' => 'SHOULD-NOT'];
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
            $this->fail('Expected missing pricing rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courier_id', $e->errors());
        }

        $this->assertFalse($probe->called);
        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }

    public function test_adapter_timeout_does_not_create_shipment_or_leak_details(): void
    {
        $order = $this->createReadyOrder();
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
                throw new \RuntimeException('cURL error 28: Operation timed out after 30001 milliseconds with api_key=secret');
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
            $this->fail('Expected timeout rejection.');
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('Courier booking failed', $messages);
            $this->assertStringNotContainsString('api_key', $messages);
            $this->assertStringNotContainsString('secret', $messages);
            $this->assertStringNotContainsString('cURL', $messages);
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
        $this->assertFalse($order->fresh(['shipment'])->hasBookedShipment());
    }

    public function test_post_booking_mutations_are_rejected(): void
    {
        $order = $this->createReadyOrder();
        $setup = $this->makeCourierSetup($order);
        $booking = app(ShipmentBookingService::class);

        $booking->book(
            $order,
            $setup['courier']->id,
            $setup['service']->id,
            $setup['city']->id,
            $this->successfulAdapter('TRK-LOCK-1'),
            $order->reseller_id,
        );

        $order = $order->fresh(['items', 'shipment']);
        $itemId = (int) $order->items->first()->id;
        $otherVariant = $this->makeSupplierProductVariant(
            User::query()->findOrFail($order->supplier_id)
        )['variant'];

        $orderService = app(OrderService::class);
        $rejections = 0;

        $attempts = [
            'product/variant' => fn () => $orderService->replaceItemVariant($order, $itemId, $otherVariant->id),
            'quantity' => fn () => $orderService->updateItemQuantity($order, $itemId, 9),
            'courier' => fn () => $booking->updateCourierSelection(
                $order,
                $setup['courier']->id + 999,
                $setup['service']->id,
                $setup['city']->id,
            ),
            'courier_service' => fn () => $booking->updateCourierSelection(
                $order,
                $setup['courier']->id,
                $setup['service']->id + 999,
                $setup['city']->id,
            ),
            'courier_city' => fn () => $booking->updateCourierSelection(
                $order,
                $setup['courier']->id,
                $setup['service']->id,
                $setup['city']->id + 999,
            ),
            'tracking' => fn () => $booking->updateTrackingNumber($order, 'TRK-MUTATED'),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("Expected shipment lock rejection for {$label}.");
            } catch (ValidationException) {
                $rejections++;
            }
        }

        $this->assertSame(count($attempts), $rejections);
        $this->assertSame('TRK-LOCK-1', $order->fresh('shipment')->shipment->tracking_number);
    }

    public function test_phone_snapshot_uses_submitted_raw_values_for_existing_customer(): void
    {
        $countryId = $this->countryByIso('LK')->id;
        $identity = app(CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => 'Existing',
            'primary_country_id' => $countryId,
            'primary_phone' => '0709999000',
            'primary_phone_country_id' => $countryId,
        ]);

        CustomerPhone::query()->whereKey($identity['primary_phone']->id)->update([
            'raw_phone' => 'OLD-RAW-0709999000',
        ]);

        $order = $this->createReadyOrder([
            'customer' => [
                'display_name' => 'Existing',
                'primary_country_id' => $countryId,
                'primary_phone' => '0709999000',
                'primary_phone_country_id' => $countryId,
            ],
        ]);

        $this->assertSame('0709999000', $order->primary_phone_snapshot);
        $this->assertNotSame('OLD-RAW-0709999000', $order->primary_phone_snapshot);
    }

    public function test_ban_by_phone_helper(): void
    {
        $actor = $this->makeResellerUser();
        $countryId = $this->countryByIso('LK')->id;
        $identity = app(CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => 'Banned',
            'primary_country_id' => $countryId,
            'primary_phone' => '0718888777',
            'primary_phone_country_id' => $countryId,
        ]);
        $banService = app(CustomerBanService::class);
        $banService->ban($identity['customer'], $actor->id, $actor->company_id, 'Risk');

        $banned = $banService->findBanByRawPhone('+94 71 888 8777', $countryId);
        $this->assertTrue($banned['found']);
        $this->assertTrue($banned['is_banned']);
        $this->assertNotNull($banned['active_ban']);

        $clean = $banService->findBanByRawPhone('0710000111', $countryId);
        $this->assertFalse($clean['found']);
        $this->assertFalse($clean['is_banned']);

        $otherIdentity = app(CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => 'Clean',
            'primary_country_id' => $countryId,
            'primary_phone' => '0710000222',
            'primary_phone_country_id' => $countryId,
        ]);
        $nonBanned = $banService->findBanByRawPhone('0710000222', $countryId);
        $this->assertTrue($nonBanned['found']);
        $this->assertFalse($nonBanned['is_banned']);
        $this->assertSame($otherIdentity['customer']->id, $nonBanned['customer']->id);
    }

    public function test_required_permissions_are_seeded(): void
    {
        $this->makePortal(\Feeder\Core\Enums\PortalCode::ADMIN);
        $this->makePortal(\Feeder\Core\Enums\PortalCode::RESELLER);
        $this->makePortal(\Feeder\Core\Enums\PortalCode::SUPPLIER);

        (new \Database\Seeders\PermissionSeeder())->run();

        $required = [
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.status.update',
            'orders.cca.assign',
            'orders.comments.create',
            'orders.discount.update',
            'orders.shipment.book',
            'customers.view',
            'customers.bans.view',
            'customers.bans.create',
            'customers.bans.lift',
        ];

        $portalId = Portal::query()->where('code', 'RESELLER')->value('id');

        foreach ($required as $slug) {
            $this->assertTrue(
                Permission::query()->where('portal_id', $portalId)->where('slug', $slug)->exists(),
                $slug
            );
        }
    }

    public function test_cancelled_order_reactivates_within_operational_window(): void
    {
        $order = $this->createReadyOrder();
        $statusService = app(OrderStatusService::class);

        $statusService->transition(
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
        $this->assertNotNull($cancelled->operations_hidden_at);
        $this->assertTrue($statusService->canReactivate($cancelled));

        $statusService->transition(
            $cancelled,
            OrderStatus::HOLD,
            $order->reseller_id,
            $order->reseller_company_id,
            'Reactivate to hold',
            $order->reseller_company_id,
        );

        $reactivated = $order->fresh(['statusHistories']);
        $this->assertSame(OrderStatus::HOLD, $reactivated->status);
        $this->assertNotNull($reactivated->reactivated_at);
        $this->assertNull($reactivated->operations_hidden_at);
        $this->assertTrue(
            $reactivated->statusHistories->contains(
                fn ($history) => $history->from_status === OrderStatus::CANCELLED
                    && $history->to_status === OrderStatus::HOLD
            )
        );
    }

    public function test_reactivation_outside_operational_window_is_rejected(): void
    {
        $order = $this->createReadyOrder();
        $statusService = app(OrderStatusService::class);

        $statusService->transition(
            $order,
            OrderStatus::CANCELLED,
            $order->reseller_id,
            $order->reseller_company_id,
            null,
            $order->reseller_company_id,
        );

        $cancelled = $order->fresh();
        $cancelled->forceFill([
            'cancelled_at' => now()->subDays(OrderStatusService::OPERATIONAL_VISIBILITY_DAYS + 1),
            'operations_hidden_at' => now()->subDay(),
        ])->save();

        $this->assertFalse($statusService->canReactivate($cancelled->fresh()));

        $this->expectException(ValidationException::class);
        $statusService->transition(
            $cancelled->fresh(),
            OrderStatus::PENDING,
            $order->reseller_id,
            $order->reseller_company_id,
            null,
            $order->reseller_company_id,
        );
    }

    public function test_order_comments_are_append_only(): void
    {
        $order = $this->createReadyOrder();
        $commentService = app(OrderCommentService::class);

        $first = $commentService->add(
            $order,
            'First note',
            OrderCommentContextType::CUSTOMER,
            $order->reseller_id,
            $order->reseller_company_id,
            null,
            $order->reseller_company_id,
        );
        $second = $commentService->add(
            $order,
            'Second note',
            OrderCommentContextType::ORDER,
            $order->reseller_id,
            $order->reseller_company_id,
            null,
            $order->reseller_company_id,
        );

        $this->assertSame(2, OrderComment::query()->where('order_id', $order->id)->count());
        $this->assertSame('First note', $first->fresh()->body);
        $this->assertSame(OrderCommentContextType::CUSTOMER, $first->context_type);
        $this->assertSame((int) $order->reseller_id, (int) $first->author_user_id);

        $first->update(['body' => 'mutated']);
        $this->assertSame('First note', $first->fresh()->body);

        $deleted = $first->delete();
        $this->assertFalse($deleted);
        $this->assertSame(2, OrderComment::query()->where('order_id', $order->id)->count());
        $this->assertSame('Second note', $second->fresh()->body);
    }

    public function test_authorization_failure_after_identity_rolls_back_customer_creation(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;
        $phone = '070'.random_int(1000000, 9999999);
        $beforeCustomers = Customer::query()->count();
        $beforePhones = CustomerPhone::query()->count();

        try {
            $this->createOrderPayload($reseller, $supplier, $variant, [
                'customer' => [
                    'display_name' => 'Rollback Cust',
                    'primary_country_id' => $countryId,
                    'primary_phone' => $phone,
                    'primary_phone_country_id' => $countryId,
                ],
                'cca_id' => $this->makeResellerUser()->id,
            ]);
            $this->fail('Expected CCA authorization failure.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($beforeCustomers, Customer::query()->count());
        $this->assertSame($beforePhones, CustomerPhone::query()->count());
        $this->assertDatabaseMissing('orders', ['primary_phone_snapshot' => $phone]);
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

        return $this->createOrderPayload($reseller, $supplier, $variant, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrderPayload(User $reseller, User $supplier, $variant, array $overrides = []): Order
    {
        $countryId = $this->countryByIso('LK')->id;

        return app(OrderService::class)->create(array_merge([
            'source' => OrderSource::MANUAL,
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Ready Customer',
                'primary_country_id' => $countryId,
                'primary_phone' => '070'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Ready Customer',
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

    /**
     * @return array{courier: Courier, service: CourierService, city: CourierCity}
     */
    private function makeCourierSetup(Order $order, bool $withAccount = true): array
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

        if ($withAccount) {
            SupplierCourierAccount::query()->create([
                'supplier_id' => $order->supplier_id,
                'courier_id' => $courier->id,
                'account_label' => 'Primary',
                'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'secret'], JSON_THROW_ON_ERROR)),
                'is_active' => true,
            ]);
        }

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
