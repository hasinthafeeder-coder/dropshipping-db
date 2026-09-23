<?php

namespace Tests\Feature\Order;

use Feeder\Core\Contracts\Courier\CourierBookingAdapter;
use Feeder\Core\Enums\OrderCommentContextType;
use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderComment;
use Feeder\Core\Models\OrderPaymentSubmission;
use Feeder\Core\Models\OrderStatusHistory;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Order\OrderPaymentReviewService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Feeder\Core\Services\Order\ShipmentBookingService;
use Feeder\Core\Services\UuidService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class OrderPaymentReviewPhase1Test extends TestCase
{
    use SetsUpOrderFoundationData;

    private OrderPaymentReviewService $paymentReview;

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
            'feeder.file_server.url' => 'http://files.test',
            'feeder.file_server.api_key' => 'test-file-key',
            'cache.default' => 'array',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::beginTransaction();

        $this->seedMarketLookups();
        $this->paymentReview = app(OrderPaymentReviewService::class);
        $this->fakeFileUpload();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_valid_bank_transfer_submission_succeeds_as_pending_review(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();

        $submission = $this->submit($reseller, $order);

        $this->assertSame(OrderPaymentMethod::BANK_TRANSFER, $submission->method);
        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $submission->review_status);
        $this->assertSame((int) $order->id, (int) $submission->order_id);
        $this->assertSame((int) $reseller->id, (int) $submission->submitted_by_user_id);
        $this->assertNotNull($submission->submitted_at);
        $this->assertSame('FILEUUID01', $submission->slip_file_uuid);
        $this->assertNull($submission->slip_path);
        $this->assertTrue($this->paymentReview->hasPendingApproval($order));
        $this->assertFalse($this->paymentReview->canEditOrder($order->fresh()));

        $this->assertTrue(
            OrderComment::query()
                ->where('order_id', $order->id)
                ->where('context_type', OrderCommentContextType::SYSTEM)
                ->where('body', 'Bank transfer payment approval requested')
                ->exists()
        );
    }

    public function test_duplicate_pending_submission_is_rejected(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $this->submit($reseller, $order);

        $this->expectException(ValidationException::class);
        $this->submit($reseller, $order);
    }

    public function test_rejected_submission_allows_new_submission(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $first = $this->submit($reseller, $order);
        $admin = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_REJECT,
        ]);

        $this->paymentReview->reject($first, $admin, 'Unclear slip');

        $this->fakeFileUpload('FILEUUID02');
        $second = $this->submit($reseller, $order, 'REF-2');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(OrderPaymentReviewStatus::REJECTED, $first->fresh()->review_status);
        $this->assertSame('Unclear slip', $first->fresh()->review_note);
        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $second->review_status);
        $this->assertSame('FILEUUID01', $first->fresh()->slip_file_uuid);
    }

    public function test_approved_submission_cannot_be_resubmitted(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);
        $admin = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_APPROVE,
        ]);

        $this->paymentReview->approve($submission, $admin);

        $this->expectException(ValidationException::class);
        $this->submit($reseller, $order->fresh());
    }

    public function test_cancelled_order_cannot_submit_payment(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        app(OrderStatusService::class)->transition(
            $order,
            OrderStatus::CANCELLED,
            (int) $reseller->id,
            (int) $reseller->company_id,
            null,
            (int) $reseller->company_id,
        );

        $this->expectException(ValidationException::class);
        $this->submit($reseller, $order->fresh());
    }

    public function test_confirmed_order_cannot_submit_payment(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        app(OrderStatusService::class)->transition(
            $order,
            OrderStatus::CONFIRMED,
            (int) $reseller->id,
            (int) $reseller->company_id,
            null,
            (int) $reseller->company_id,
        );

        $this->expectException(ValidationException::class);
        $this->submit($reseller, $order->fresh());
    }

    public function test_wrong_company_cannot_submit_payment(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $other = $this->makeResellerUser();

        try {
            $this->paymentReview->submitBankTransfer(
                $order,
                $other,
                UploadedFile::fake()->create('slip.pdf', 120, 'application/pdf'),
                'REF-X',
                100,
                'Payment for order',
                (int) $other->company_id,
            );
            $this->fail('Expected company mismatch rejection.');
        } catch (ValidationException $e) {
            $this->assertTrue(
                collect($e->errors())->flatten()->contains(
                    fn ($message) => str_contains((string) $message, 'different reseller company')
                )
            );
        }
    }

    public function test_bank_transfer_fails_when_courier_not_assigned(): void
    {
        [$reseller, $order] = $this->makeReadyOrder(assignCourier: false);

        try {
            $this->submit($reseller, $order);
            $this->fail('Expected missing courier rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('courier_id', $e->errors());
        }
    }

    public function test_bank_transfer_fails_when_supplier_courier_account_missing(): void
    {
        [$reseller, $order] = $this->makeReadyOrder(withAccount: false);

        try {
            $this->submit($reseller, $order);
            $this->fail('Expected missing account rejection.');
        } catch (ValidationException $e) {
            // Eligible courier lookup requires an active supplier account, so the
            // existing OrderCourierLookupService surfaces this as courier unavailability.
            $this->assertTrue(
                collect($e->errors())->flatten()->contains(
                    fn ($message) => str_contains((string) $message, 'not available')
                        || str_contains((string) $message, 'supplier courier account')
                )
            );
        }
    }

    public function test_valid_courier_configuration_allows_submission(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();

        $submission = $this->submit($reseller, $order);

        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $submission->review_status);
    }

    public function test_order_edit_fails_while_payment_pending(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $this->submit($reseller, $order);

        $countryId = $this->countryByIso('LK')->id;
        $variantId = (int) $order->items()->first()->product_variant_id;

        try {
            app(OrderService::class)->updateDetails($order->fresh(), [
                'customer' => [
                    'display_name' => 'Changed',
                    'primary_phone' => '070'.random_int(1000000, 9999999),
                    'primary_phone_country_id' => $countryId,
                ],
                'address' => [
                    'recipient_name' => 'Changed',
                    'line1' => '2 New Road',
                    'city_name' => 'Colombo',
                    'country_id' => $countryId,
                ],
                'items' => [
                    [
                        'product_variant_id' => $variantId,
                        'quantity' => 1,
                        'unit_selling_price' => 250,
                    ],
                ],
            ], (int) $reseller->id, (int) $reseller->company_id);
            $this->fail('Expected pending lock on edit.');
        } catch (ValidationException $e) {
            $this->assertTrue(
                collect($e->errors())->flatten()->contains(
                    fn ($message) => str_contains((string) $message, 'pending approval')
                )
            );
        }
    }

    public function test_status_change_and_cancellation_fail_while_payment_pending(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $this->submit($reseller, $order);
        $statusService = app(OrderStatusService::class);

        foreach ([
            OrderStatus::FIRST_ATTEMPT,
            OrderStatus::SECOND_ATTEMPT,
            OrderStatus::THIRD_ATTEMPT,
            OrderStatus::HOLD,
            OrderStatus::CONFIRMED,
            OrderStatus::CANCELLED,
        ] as $status) {
            try {
                $statusService->transition(
                    $order->fresh(),
                    $status,
                    (int) $reseller->id,
                    (int) $reseller->company_id,
                    null,
                    (int) $reseller->company_id,
                );
                $this->fail('Expected pending lock for status '.$status->value);
            } catch (ValidationException $e) {
                $this->assertTrue(
                    collect($e->errors())->flatten()->contains(
                        fn ($message) => str_contains((string) $message, 'pending approval')
                    )
                );
            }
        }
    }

    public function test_shipment_booking_fails_while_payment_pending(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $setup = $this->lastCourierSetup;
        $this->submit($reseller, $order);

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
                return [
                    'tracking_number' => 'TRK-LOCKED',
                    'external_booking_ref' => 'EXT-LOCKED',
                    'raw_response' => ['ok' => true],
                ];
            }
        };

        try {
            app(ShipmentBookingService::class)->book(
                $order->fresh(),
                (int) $setup['courier']->id,
                (int) $setup['service']->id,
                (int) $setup['city']->id,
                $adapter,
                (int) $reseller->id,
                (int) $reseller->company_id,
            );
            $this->fail('Expected pending lock on booking.');
        } catch (ValidationException $e) {
            $this->assertTrue(
                collect($e->errors())->flatten()->contains(
                    fn ($message) => str_contains((string) $message, 'pending approval')
                )
            );
        }
    }

    public function test_pending_submission_can_be_approved_and_confirms_order(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);
        $admin = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_APPROVE,
        ]);

        $approved = $this->paymentReview->approve($submission, $admin);
        $order = $order->fresh();

        $this->assertSame(OrderPaymentReviewStatus::APPROVED, $approved->review_status);
        $this->assertSame((int) $admin->id, (int) $approved->reviewed_by_user_id);
        $this->assertNotNull($approved->reviewed_at);
        $this->assertSame(OrderStatus::CONFIRMED, $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertNotNull($order->discount_locked_at);
        $this->assertFalse($this->paymentReview->hasPendingApproval($order));

        $this->assertTrue(
            OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('to_status', OrderStatus::CONFIRMED)
                ->exists()
        );

        $this->assertTrue(
            OrderComment::query()
                ->where('order_id', $order->id)
                ->where('body', 'Bank transfer payment approved')
                ->exists()
        );
    }

    public function test_approval_cannot_be_repeated_and_rejected_cannot_be_approved(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);
        $admin = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_APPROVE,
            OrderPaymentReviewService::PERMISSION_REJECT,
        ]);

        $this->paymentReview->approve($submission, $admin);

        try {
            $this->paymentReview->approve($submission->fresh(), $admin);
            $this->fail('Expected repeat approval rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        [$reseller2, $order2] = $this->makeReadyOrder();
        $rejected = $this->submit($reseller2, $order2);
        $this->paymentReview->reject($rejected, $admin, 'Bad proof');

        try {
            $this->paymentReview->approve($rejected->fresh(), $admin);
            $this->fail('Expected rejected→approved rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }
    }

    public function test_pending_submission_can_be_rejected_without_changing_order_status(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $originalStatus = $order->status;
        $submission = $this->submit($reseller, $order);
        $admin = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_REJECT,
        ]);

        $rejected = $this->paymentReview->reject($submission, $admin, 'Blurry image');

        $this->assertSame(OrderPaymentReviewStatus::REJECTED, $rejected->review_status);
        $this->assertSame((int) $admin->id, (int) $rejected->reviewed_by_user_id);
        $this->assertNotNull($rejected->reviewed_at);
        $this->assertSame('Blurry image', $rejected->review_note);
        $this->assertSame($originalStatus, $order->fresh()->status);
        $this->assertTrue($this->paymentReview->canEditOrder($order->fresh()));

        $this->fakeFileUpload('FILEUUID03');
        $newSubmission = $this->submit($reseller, $order->fresh(), 'REF-RETRY');
        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $newSubmission->review_status);
        $this->assertSame(OrderPaymentReviewStatus::REJECTED, $rejected->fresh()->review_status);
        $this->assertSame('Blurry image', $rejected->fresh()->review_note);
    }

    public function test_unauthorized_admin_and_reseller_cannot_approve_or_reject(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);

        $unauthorizedAdmin = $this->makeAdminWithPermissions(['orders.view']);
        $rejectOnly = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_REJECT,
        ]);

        try {
            $this->paymentReview->approve($submission, $unauthorizedAdmin);
            $this->fail('Expected unauthorized approve.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        try {
            $this->paymentReview->reject($submission, $unauthorizedAdmin, 'Nope');
            $this->fail('Expected unauthorized reject.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        try {
            $this->paymentReview->approve($submission, $reseller);
            $this->fail('Expected reseller approve rejection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        try {
            $this->paymentReview->approve($submission, $rejectOnly);
            $this->fail('Expected reject-only admin cannot approve.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $submission->fresh()->review_status);
    }

    public function test_cod_orders_remain_unaffected_without_payment_submission(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();

        $this->assertFalse($this->paymentReview->hasPendingApproval($order));
        $this->assertTrue($this->paymentReview->canEditOrder($order));

        $confirmed = app(OrderStatusService::class)->transition(
            $order,
            OrderStatus::CONFIRMED,
            (int) $reseller->id,
            (int) $reseller->company_id,
            null,
            (int) $reseller->company_id,
        );

        $this->assertSame(OrderStatus::CONFIRMED, $confirmed->status);
        $this->assertSame(0, OrderPaymentSubmission::query()->where('order_id', $order->id)->count());
    }

    public function test_order_status_enum_has_no_pending_approval(): void
    {
        $values = array_map(static fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        $this->assertNotContains('PENDING_APPROVAL', $values);
    }

    /** @var array{courier: Courier, service: CourierService, city: CourierCity}|null */
    private ?array $lastCourierSetup = null;

    /**
     * @return array{0: User, 1: Order}
     */
    private function makeReadyOrder(bool $assignCourier = true, bool $withAccount = true): array
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $order = app(OrderService::class)->create([
            'source' => OrderSource::MANUAL,
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'created_by' => $reseller->id,
            'customer' => [
                'display_name' => 'Pay Customer',
                'primary_country_id' => $this->countryByIso('LK')->id,
                'primary_phone' => '070'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $this->countryByIso('LK')->id,
            ],
            'address' => [
                'recipient_name' => 'Pay Customer',
                'line1' => '1 Payment Road',
                'city_name' => 'Colombo',
                'country_id' => $this->countryByIso('LK')->id,
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
        ]);

        $setup = $this->makeCourierSetup($order, $withAccount);
        $this->lastCourierSetup = $setup;

        if ($assignCourier) {
            $order->forceFill([
                'draft_courier_id' => $setup['courier']->id,
                'draft_courier_service_id' => $setup['service']->id,
                'draft_courier_city_id' => $setup['city']->id,
            ])->save();
        }

        return [$reseller, $order->fresh(['items'])];
    }

    private function submit(User $reseller, Order $order, string $reference = 'REF-001'): OrderPaymentSubmission
    {
        return $this->paymentReview->submitBankTransfer(
            $order,
            $reseller,
            UploadedFile::fake()->create('slip.pdf', 120, 'application/pdf'),
            $reference,
            350.50,
            'Bank transfer payment for order',
            (int) $reseller->company_id,
        );
    }

    private function fakeFileUpload(string $uuid = 'FILEUUID01'): void
    {
        Http::fake([
            'files.test/*' => Http::response([
                'message' => 'File uploaded successfully.',
                'file' => [
                    'uuid' => $uuid,
                    'application' => 'RESELLER',
                    'entity_type' => 'ORDER_PAYMENT',
                    'entity_uuid' => 'ENTITY0001',
                    'category' => 'PAYMENT_PROOF',
                    'original_name' => 'slip.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 120,
                ],
            ], 201),
        ]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function makeAdminWithPermissions(array $permissionSlugs): User
    {
        $portal = Portal::query()->firstOrCreate(
            ['code' => PortalCode::ADMIN->value],
            [
                'uuid' => UuidService::generate(),
                'name' => 'Admin Portal',
                'subdomain' => 'admin-'.Str::lower(Str::random(4)),
                'description' => 'Admin Portal',
                'is_active' => true,
            ]
        );

        $role = Role::query()->create([
            'uuid' => UuidService::generate(),
            'portal_id' => $portal->id,
            'company_id' => null,
            'slug' => 'admin-pay-'.Str::lower(Str::random(6)),
            'name' => 'Admin Payment Reviewer',
            'description' => 'Test admin payment role',
            'is_system' => false,
        ]);

        $permissionIds = [];
        foreach ($permissionSlugs as $slug) {
            $permission = Permission::query()->firstOrCreate(
                [
                    'portal_id' => $portal->id,
                    'slug' => $slug,
                ],
                [
                    'uuid' => UuidService::generate(),
                    'module' => 'Orders',
                    'group' => 'Orders',
                    'name' => $slug,
                    'description' => null,
                    'sort_order' => 10,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);

        return User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => null,
            'role_id' => $role->id,
            'email' => 'admin-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '071'.random_int(1000000, 9999999),
            'password' => bcrypt('password'),
            'user_type' => \Feeder\Core\Enums\UserType::OWNER->value,
            'status' => \Feeder\Core\Enums\UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ])->fresh(['role']);
    }

    /**
     * @return array{courier: Courier, service: CourierService, city: CourierCity}
     */
    private function makeCourierSetup(Order $order, bool $withAccount = true): array
    {
        $courier = Courier::query()->create([
            'code' => 'PAY'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Payment Courier',
            'is_active' => true,
        ]);

        $service = CourierService::query()->create([
            'courier_id' => $courier->id,
            'code' => 'STD',
            'name' => 'Standard',
            'external_service_id' => 'ext-std',
            'is_active' => true,
        ]);

        $city = CourierCity::query()->create([
            'courier_id' => $courier->id,
            'district_name' => 'Colombo',
            'city_name' => 'Colombo 03',
            'external_city_code' => 'CMB03'.Str::upper(Str::random(3)),
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
}
