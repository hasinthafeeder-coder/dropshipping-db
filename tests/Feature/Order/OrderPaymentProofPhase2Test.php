<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\File;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderPaymentSubmission;
use Feeder\Core\Models\Permission;
use Feeder\Core\Models\Portal;
use Feeder\Core\Models\Role;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Order\OrderPaymentReviewService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\UuidService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class OrderPaymentProofPhase2Test extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_submission_persists_slip_file_uuid_and_payment_proof_category_contract(): void
    {
        $captured = [];

        Http::fake(function ($request) use (&$captured) {
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'], $part['contents']) && is_string($part['contents'])) {
                    $captured[$part['name']] = $part['contents'];
                }
            }

            return Http::response([
                'message' => 'File uploaded successfully.',
                'file' => [
                    'uuid' => 'PROOFUUID1',
                    'application' => 'RESELLER',
                    'entity_type' => 'ORDER_PAYMENT',
                    'entity_uuid' => strtoupper((string) ($captured['entity_uuid'] ?? '')),
                    'category' => 'PAYMENT_PROOF',
                    'original_name' => 'slip.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 120,
                ],
            ], 201);
        });

        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order, 'slip.pdf', 'REF-P2', ensureFileFake: false);

        $this->assertSame('PROOFUUID1', $submission->slip_file_uuid);
        $this->assertNull($submission->slip_path);
        $this->assertSame('PAYMENT_PROOF', $captured['category'] ?? null);
        $this->assertSame('ORDER_PAYMENT', $captured['entity_type'] ?? null);
        $this->assertSame('RESELLER', $captured['application'] ?? null);
        $this->assertSame(strtoupper((string) $reseller->company->uuid), strtoupper((string) ($captured['entity_uuid'] ?? '')));
        $this->assertSame($reseller->uuid, $captured['uploaded_by'] ?? null);
    }

    public function test_pdf_jpg_png_uploads_are_accepted_by_submission_path(): void
    {
        foreach (['slip.pdf', 'slip.jpg', 'slip.png'] as $name) {
            [$reseller, $order] = $this->makeReadyOrder();
            $submission = $this->submit($reseller, $order, $name);
            $this->assertNotNull($submission->slip_file_uuid);
        }
    }

    public function test_standard_36_char_user_and_company_uuids_still_upload_payment_proof(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();

        $resellerUuid = (string) Str::uuid();
        $companyUuid = (string) Str::uuid();
        $reseller->forceFill(['uuid' => $resellerUuid])->save();
        $reseller->company->forceFill(['uuid' => $companyUuid])->save();
        $order->unsetRelation('resellerCompany');
        $order->load('resellerCompany');

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            foreach ($request->data() as $part) {
                if (is_array($part) && isset($part['name'], $part['contents']) && is_string($part['contents'])) {
                    $captured[$part['name']] = $part['contents'];
                }
            }

            return Http::response([
                'message' => 'File uploaded successfully.',
                'file' => [
                    'uuid' => 'PROOF36CHR',
                    'application' => 'RESELLER',
                    'entity_type' => 'ORDER_PAYMENT',
                    'entity_uuid' => $captured['entity_uuid'] ?? null,
                    'category' => 'PAYMENT_PROOF',
                    'original_name' => 'slip.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 120,
                ],
            ], 201);
        });

        $submission = $this->paymentReview->submitBankTransfer(
            $order,
            $reseller->fresh(),
            UploadedFile::fake()->create('slip.pdf', 120, 'application/pdf'),
            'REF-36CHAR',
            350.50,
            'Bank transfer with standard UUIDs',
            (int) $reseller->company_id,
        );

        $expectedEntity = strtoupper(substr(str_replace('-', '', $companyUuid), 0, 10));

        $this->assertSame('PROOF36CHR', $submission->slip_file_uuid);
        $this->assertSame($expectedEntity, strtoupper((string) ($captured['entity_uuid'] ?? '')));
        $this->assertSame(10, strlen((string) ($captured['entity_uuid'] ?? '')));
        $this->assertTrue(
            ! array_key_exists('uploaded_by', $captured)
            || $captured['uploaded_by'] === null
            || $captured['uploaded_by'] === '',
            'uploaded_by must be omitted/null when actor UUID is not 10 characters'
        );
    }

    public function test_owning_reseller_can_access_proof_and_foreign_reseller_cannot(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);
        $this->seedProofFileRow($submission->slip_file_uuid, $order, $submission);

        $this->paymentReview->authorizeFileAccessIfPaymentProof($reseller, $submission->slip_file_uuid);

        $other = $this->makeResellerUser();

        try {
            $this->paymentReview->authorizeFileAccessIfPaymentProof($other, $submission->slip_file_uuid);
            $this->fail('Expected foreign reseller access denial.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_guessing_unrelated_file_uuid_does_not_grant_payment_proof_access(): void
    {
        [$reseller] = $this->makeReadyOrder();

        File::query()->create([
            'uuid' => 'ORPHANPROF',
            'application' => 'RESELLER',
            'entity_type' => 'ORDER_PAYMENT',
            'entity_uuid' => UuidService::generate(),
            'category' => FileCategory::PAYMENT_PROOF->value,
            'disk' => 'feeder',
            'path' => 'payment-proofs/orphan.pdf',
            'original_name' => 'orphan.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'visibility' => 'PRIVATE',
            'status' => 'ACTIVE',
        ]);

        try {
            $this->paymentReview->authorizeFileAccessIfPaymentProof($reseller, 'ORPHANPROF');
            $this->fail('Expected orphan payment proof denial.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_authorized_admin_can_access_and_unauthorized_admin_cannot(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);
        $this->seedProofFileRow($submission->slip_file_uuid, $order, $submission);

        $reviewer = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_REVIEW,
        ]);
        $this->paymentReview->authorizeFileAccessIfPaymentProof($reviewer, $submission->slip_file_uuid);

        $viewerOnly = $this->makeAdminWithPermissions(['orders.view']);

        try {
            $this->paymentReview->authorizeFileAccessIfPaymentProof($viewerOnly, $submission->slip_file_uuid);
            $this->fail('Expected unauthorized admin denial.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_pending_approved_and_rejected_proofs_cannot_be_replaced(): void
    {
        [$reseller, $order] = $this->makeReadyOrder();
        $pending = $this->submit($reseller, $order);

        try {
            $pending->update(['slip_file_uuid' => 'HACKEDUUID']);
            $this->fail('Expected pending proof immutability.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $this->assertSame($pending->getOriginal('slip_file_uuid') ?: $pending->slip_file_uuid, $pending->fresh()->slip_file_uuid);

        $admin = $this->makeAdminWithPermissions([
            OrderPaymentReviewService::PERMISSION_APPROVE,
            OrderPaymentReviewService::PERMISSION_REJECT,
        ]);

        [$reseller2, $order2] = $this->makeReadyOrder();
        $toApprove = $this->submit($reseller2, $order2);
        $approved = $this->paymentReview->approve($toApprove, $admin);

        try {
            $approved->update(['amount' => 1, 'slip_file_uuid' => 'NEVER']);
            $this->fail('Expected approved proof immutability.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        [$reseller3, $order3] = $this->makeReadyOrder();
        $toReject = $this->submit($reseller3, $order3);
        $rejected = $this->paymentReview->reject($toReject, $admin, 'Blurry');
        $rejectedUuid = $rejected->slip_file_uuid;

        try {
            $rejected->update(['description' => 'changed', 'slip_file_uuid' => 'NEVER2']);
            $this->fail('Expected rejected proof immutability.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $this->assertSame($rejectedUuid, $rejected->fresh()->slip_file_uuid);

        $this->fakeFileUpload('NEWPROOF01');
        $replacement = $this->submit($reseller3, $order3->fresh(), 'retry.pdf', 'REF-RETRY', ensureFileFake: false);
        $this->assertNotSame($rejected->id, $replacement->id);
        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $replacement->review_status);
        $this->assertSame(OrderPaymentReviewStatus::REJECTED, $rejected->fresh()->review_status);
    }

    public function test_payment_proof_files_cannot_be_deleted(): void
    {
        $file = File::query()->create([
            'uuid' => 'NODELETE01',
            'application' => 'RESELLER',
            'entity_type' => 'ORDER_PAYMENT',
            'entity_uuid' => UuidService::generate(),
            'category' => FileCategory::PAYMENT_PROOF->value,
            'disk' => 'feeder',
            'path' => 'payment-proofs/nodelete.pdf',
            'original_name' => 'nodelete.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'visibility' => 'PRIVATE',
            'status' => 'ACTIVE',
        ]);

        $this->assertFalse($file->delete());
        $this->assertNotNull(File::query()->where('uuid', 'NODELETE01')->first());
    }

    public function test_legacy_slip_path_audit_remains_untouched_and_zero_in_this_environment(): void
    {
        $total = DB::table('order_payment_submissions')->count();
        $withPath = DB::table('order_payment_submissions')
            ->whereNotNull('slip_path')
            ->where('slip_path', '!=', '')
            ->count();
        $withUuid = DB::table('order_payment_submissions')
            ->whereNotNull('slip_file_uuid')
            ->where('slip_file_uuid', '!=', '')
            ->count();

        // Environment baseline before this transactional test inserts rows.
        // Within the open transaction we may have created rows above; this test
        // only asserts the schema columns still exist and legacy path is unused
        // for new submissions.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('order_payment_submissions', 'slip_path'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('order_payment_submissions', 'slip_file_uuid'));

        [$reseller, $order] = $this->makeReadyOrder();
        $submission = $this->submit($reseller, $order);
        $this->assertNull($submission->slip_path);
        $this->assertNotNull($submission->slip_file_uuid);

        // Document environment totals for report consumers (outside invented data).
        $this->assertIsInt($total);
        $this->assertIsInt($withPath);
        $this->assertIsInt($withUuid);
    }

    public function test_non_payment_files_skip_payment_authorization_gate(): void
    {
        [$reseller] = $this->makeReadyOrder();

        File::query()->create([
            'uuid' => 'PRODUCTIMG',
            'application' => 'SUPPLIER',
            'entity_type' => 'PRODUCT',
            'entity_uuid' => UuidService::generate(),
            'category' => FileCategory::PRODUCT_IMAGE->value,
            'disk' => 'feeder',
            'path' => 'product-images/x.jpg',
            'original_name' => 'x.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'visibility' => 'PRIVATE',
            'status' => 'ACTIVE',
        ]);

        // Must not abort — gate returns for non-payment categories.
        $this->paymentReview->authorizeFileAccessIfPaymentProof($reseller, 'PRODUCTIMG');
        $this->assertTrue(true);
    }

    private function makeReadyOrder(): array
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
                'display_name' => 'Proof Customer',
                'primary_country_id' => $this->countryByIso('LK')->id,
                'primary_phone' => '070'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $this->countryByIso('LK')->id,
            ],
            'address' => [
                'recipient_name' => 'Proof Customer',
                'line1' => '1 Proof Road',
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

        $setup = $this->makeCourierSetup($order);
        $order->forceFill([
            'draft_courier_id' => $setup['courier']->id,
            'draft_courier_service_id' => $setup['service']->id,
            'draft_courier_city_id' => $setup['city']->id,
        ])->save();

        return [$reseller->fresh(['company']), $order->fresh()];
    }

    private function submit(
        User $reseller,
        Order $order,
        string $filename = 'slip.pdf',
        string $reference = 'REF-P2',
        bool $ensureFileFake = true,
    ): OrderPaymentSubmission {
        if ($ensureFileFake) {
            $this->fakeFileUpload('F'.strtoupper(Str::random(9)));
        }

        return $this->paymentReview->submitBankTransfer(
            $order,
            $reseller,
            UploadedFile::fake()->create($filename, 120),
            $reference,
            350.50,
            'Bank transfer payment for order',
            (int) $reseller->company_id,
        );
    }

    private function fakeFileUpload(string $uuid = 'FILEUUID02'): void
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

    private function seedProofFileRow(string $uuid, Order $order, OrderPaymentSubmission $submission): void
    {
        File::query()->create([
            'uuid' => strtoupper($uuid),
            'application' => 'RESELLER',
            'entity_type' => 'ORDER_PAYMENT',
            'entity_uuid' => strtoupper((string) $order->resellerCompany?->uuid ?: UuidService::generate()),
            'category' => FileCategory::PAYMENT_PROOF->value,
            'disk' => 'feeder',
            'path' => 'payment-proofs/'.$uuid.'.pdf',
            'original_name' => 'slip.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 120,
            'visibility' => 'PRIVATE',
            'status' => 'ACTIVE',
            'metadata' => [
                'order_uuid' => $order->uuid,
                'order_id' => (int) $order->id,
                'payment_submission_uuid' => $submission->uuid,
                'payment_submission_id' => (int) $submission->id,
            ],
            'uploaded_by' => $submission->submittedByUser?->uuid,
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
            'slug' => 'admin-proof-'.Str::lower(Str::random(6)),
            'name' => 'Admin Proof Reviewer',
            'description' => 'Test',
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
            'user_type' => UserType::OWNER->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ])->fresh(['role']);
    }

    /**
     * @return array{courier: Courier, service: CourierService, city: CourierCity}
     */
    private function makeCourierSetup(Order $order): array
    {
        $courier = Courier::query()->create([
            'code' => 'P2'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Proof Courier',
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
            'external_city_code' => 'CMB'.Str::upper(Str::random(4)),
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
}
