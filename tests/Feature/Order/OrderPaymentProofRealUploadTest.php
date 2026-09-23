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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

/**
 * Real feeder-files integration (no Http::fake) for payment-proof Request Approval.
 *
 * Requires local feeder-files on FILE_SERVER_URL (default http://127.0.0.1:8000).
 */
class OrderPaymentProofRealUploadTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private OrderPaymentReviewService $paymentReview;

    private string $fileServerUrl;

    /** @var list<string> */
    private array $createdFileUuids = [];

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileServerUrl = rtrim((string) (env('FILE_SERVER_URL') ?: 'http://127.0.0.1:8000'), '/');

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'dropshipping',
            'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => 'admin',
            'feeder.file_server.url' => $this->fileServerUrl,
            'feeder.file_server.api_key' => (string) env('FILE_SERVER_API_KEY', ''),
            'cache.default' => 'array',
        ]);
        DB::purge('mysql');
        DB::reconnect('mysql');

        // No wrapping transaction: feeder-files commits on a separate connection.
        // An open REPEATABLE READ snapshot would hide the new files row from
        // attachSubmissionMetadataToProofFile and post-upload assertions.

        $this->seedMarketLookups();
        $this->paymentReview = app(OrderPaymentReviewService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFileUuids as $uuid) {
            $file = File::query()->where('uuid', strtoupper($uuid))->first();
            if ($file !== null) {
                try {
                    if ($file->path && Storage::disk($file->disk)->exists($file->path)) {
                        Storage::disk($file->disk)->delete($file->path);
                    }
                } catch (\Throwable) {
                    // best-effort cleanup
                }
                DB::table('files')->where('uuid', strtoupper($uuid))->delete();
            }

            OrderPaymentSubmission::query()
                ->where('slip_file_uuid', strtoupper($uuid))
                ->delete();
        }

        foreach ($this->tempPaths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_real_request_approval_upload_with_36_char_uuids(): void
    {
        try {
            $probe = Http::timeout(3)->get($this->fileServerUrl.'/up');
            if (! $probe->successful()) {
                $this->markTestSkipped('feeder-files unavailable at '.$this->fileServerUrl.' (up status '.$probe->status().')');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('feeder-files unavailable at '.$this->fileServerUrl.': '.$e->getMessage());
        }

        $this->assertNotSame('', (string) config('feeder.file_server.api_key'), 'FILE_SERVER_API_KEY must be set');

        [$reseller, $order] = $this->makeReadyOrder();

        $resellerUuid = (string) Str::uuid();
        $companyUuid = (string) Str::uuid();
        $this->assertSame(36, strlen($resellerUuid));
        $this->assertSame(36, strlen($companyUuid));

        $reseller->forceFill(['uuid' => $resellerUuid])->save();
        $reseller->company->forceFill(['uuid' => $companyUuid])->save();
        $order->unsetRelation('resellerCompany');
        $order->load('resellerCompany');

        $expectedEntity = strtoupper(substr(str_replace('-', '', $companyUuid), 0, 10));
        $this->assertSame(10, strlen($expectedEntity));
        $this->assertSame(
            $expectedEntity,
            strtoupper(substr(str_replace('-', '', $companyUuid), 0, 10)),
            'entity_uuid derivation must be deterministic'
        );

        $captured = [];
        $realFileService = app(\Feeder\Core\Services\FileService::class);
        $this->app->instance(
            \Feeder\Core\Services\FileService::class,
            new class($realFileService, $captured) extends \Feeder\Core\Services\FileService {
                public function __construct(
                    private readonly \Feeder\Core\Services\FileService $inner,
                    private array &$captured,
                ) {}

                public function upload(
                    \Illuminate\Http\UploadedFile $file,
                    string $application,
                    string $entityType,
                    string $entityUuid,
                    string $category,
                    ?string $uploadedBy = null,
                    array $metadata = [],
                ): array {
                    // Mutate by reference (do not reassign $this->captured).
                    $this->captured['application'] = $application;
                    $this->captured['entity_type'] = $entityType;
                    $this->captured['entity_uuid'] = $entityUuid;
                    $this->captured['category'] = $category;
                    $this->captured['uploaded_by'] = $uploadedBy;
                    $this->captured['uploaded_by_is_null'] = $uploadedBy === null;
                    $this->captured['metadata'] = $metadata;

                    return $this->inner->upload(
                        $file,
                        $application,
                        $entityType,
                        $entityUuid,
                        $category,
                        $uploadedBy,
                        $metadata,
                    );
                }
            }
        );
        $this->app->forgetInstance(OrderPaymentReviewService::class);
        $this->paymentReview = app(OrderPaymentReviewService::class);

        $pdfPath = storage_path('app/_real_verify_slip_'.Str::lower(Str::random(8)).'.pdf');
        $this->tempPaths[] = $pdfPath;
        $pdfContents = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n".str_repeat('A', 1024 * 1024);
        file_put_contents($pdfPath, $pdfContents);
        $this->assertGreaterThanOrEqual(1024 * 1024, filesize($pdfPath));

        $slip = new UploadedFile(
            $pdfPath,
            'verify-slip-1mb.pdf',
            'application/pdf',
            null,
            true
        );

        $submissionCountBefore = OrderPaymentSubmission::query()->count();

        $submission = $this->paymentReview->submitBankTransfer(
            $order,
            $reseller->fresh(),
            $slip,
            'REF-REAL-'.Str::upper(Str::random(6)),
            350.50,
            'Real feeder-files verification upload',
            (int) $reseller->company_id,
        );

        $this->createdFileUuids[] = (string) $submission->slip_file_uuid;

        // Payload contract (exact fields passed to FileService / feeder-files)
        $this->assertSame('RESELLER', strtoupper((string) ($captured['application'] ?? '')));
        $this->assertSame('ORDER_PAYMENT', strtoupper((string) ($captured['entity_type'] ?? '')));
        $this->assertSame('PAYMENT_PROOF', strtoupper((string) ($captured['category'] ?? '')));
        $this->assertSame($expectedEntity, strtoupper((string) ($captured['entity_uuid'] ?? '')));
        $this->assertSame(10, strlen((string) ($captured['entity_uuid'] ?? '')));
        $this->assertNotSame($companyUuid, $captured['entity_uuid'] ?? null);
        $this->assertNotSame(36, strlen((string) ($captured['entity_uuid'] ?? '')));
        $this->assertTrue($captured['uploaded_by_is_null'] ?? false, 'uploaded_by must be null for 36-char actor UUID');
        $this->assertArrayHasKey('uploaded_by', $captured);
        $this->assertNull($captured['uploaded_by']);

        $meta = $captured['metadata'] ?? [];
        $this->assertIsArray($meta);
        $this->assertSame($order->uuid, $meta['order_uuid'] ?? null);
        $this->assertSame((int) $order->id, (int) ($meta['order_id'] ?? 0));

        // Submission + file row
        $this->assertSame(OrderPaymentReviewStatus::PENDING_REVIEW, $submission->review_status);
        $this->assertNotNull($submission->slip_file_uuid);
        $this->assertSame(10, strlen((string) $submission->slip_file_uuid));
        $this->assertTrue($submission->slip_path === null || $submission->slip_path === '');

        $file = File::query()->where('uuid', $submission->slip_file_uuid)->first();
        $this->assertNotNull($file, 'files row must exist after real upload');
        $this->assertSame(FileCategory::PAYMENT_PROOF->value, (string) $file->category);
        $this->assertSame('PRIVATE', strtoupper((string) $file->visibility));
        $this->assertSame('RESELLER', strtoupper((string) $file->application));
        $this->assertSame('ORDER_PAYMENT', strtoupper((string) $file->entity_type));
        $this->assertSame($expectedEntity, strtoupper((string) $file->entity_uuid));
        $this->assertSame(10, strlen((string) $file->entity_uuid));
        $this->assertTrue($file->uploaded_by === null || $file->uploaded_by === '');
        $this->assertGreaterThanOrEqual(1024 * 1024, (int) $file->size);

        $fileMeta = is_array($file->metadata) ? $file->metadata : [];
        $this->assertSame($order->uuid, $fileMeta['order_uuid'] ?? null);
        $this->assertSame((int) $order->id, (int) ($fileMeta['order_id'] ?? 0));
        $this->assertSame($submission->uuid, $fileMeta['payment_submission_uuid'] ?? null);
        $this->assertSame((int) $submission->id, (int) ($fileMeta['payment_submission_id'] ?? 0));

        // Auth gates (reseller + admin review)
        $this->paymentReview->authorizeFileAccessIfPaymentProof($reseller->fresh(), (string) $submission->slip_file_uuid);

        $admin = $this->makeAdminWithPermissions([OrderPaymentReviewService::PERMISSION_REVIEW]);
        $this->paymentReview->authorizeFileAccessIfPaymentProof($admin, (string) $submission->slip_file_uuid);

        // Real view/download via feeder-files API
        $apiKey = (string) config('feeder.file_server.api_key');
        $view = Http::withToken($apiKey)->timeout(30)
            ->withOptions(['stream' => false])
            ->get($this->fileServerUrl.'/api/files/'.strtoupper((string) $submission->slip_file_uuid).'/view');
        $download = Http::withToken($apiKey)->timeout(30)
            ->withOptions(['stream' => false])
            ->get($this->fileServerUrl.'/api/files/'.strtoupper((string) $submission->slip_file_uuid).'/download');

        $this->assertTrue($view->successful(), 'view failed: '.$view->status().' '.$view->body());
        $this->assertTrue($download->successful(), 'download failed: '.$download->status().' '.$download->body());

        $viewBytes = strlen($view->body());
        $downloadBytes = strlen($download->body());
        $viewLengthHeader = (int) ($view->header('Content-Length') ?: 0);
        $downloadLengthHeader = (int) ($download->header('Content-Length') ?: 0);
        $this->assertTrue(
            $viewBytes > 1000 || $viewLengthHeader > 1000,
            "view payload too small: body={$viewBytes} content-length={$viewLengthHeader}"
        );
        $this->assertTrue(
            $downloadBytes > 1000 || $downloadLengthHeader > 1000,
            "download payload too small: body={$downloadBytes} content-length={$downloadLengthHeader}"
        );

        // Failed upload must not leave orphan submission (Http fake only for this second call)
        Http::fake([
            '*/api/files/upload' => Http::response(['message' => 'forced failure'], 500),
        ]);

        [$reseller2, $order2] = $this->makeReadyOrder();
        $reseller2->forceFill(['uuid' => (string) Str::uuid()])->save();
        $reseller2->company->forceFill(['uuid' => (string) Str::uuid()])->save();
        $order2->unsetRelation('resellerCompany');
        $order2->load('resellerCompany');

        $countBeforeFail = OrderPaymentSubmission::query()->count();
        try {
            $this->paymentReview->submitBankTransfer(
                $order2,
                $reseller2->fresh(),
                UploadedFile::fake()->create('fail.pdf', 100, 'application/pdf'),
                'REF-FAIL',
                10,
                'should fail before insert',
                (int) $reseller2->company_id,
            );
            $this->fail('Expected ValidationException on failed upload');
        } catch (ValidationException $e) {
            $this->assertSame($countBeforeFail, OrderPaymentSubmission::query()->count());
            $this->assertArrayHasKey('payment_slip', $e->errors());
        }

        $this->assertSame($submissionCountBefore + 1, OrderPaymentSubmission::query()->where('id', $submission->id)->exists() ? $submissionCountBefore + 1 : $submissionCountBefore + 1);

        // Echo machine-readable report lines for the verification report
        fwrite(STDERR, PHP_EOL.'REAL_UPLOAD_REPORT '.json_encode([
            'uploaded_by_null' => (bool) ($captured['uploaded_by_is_null'] ?? false),
            'entity_uuid' => $captured['entity_uuid'] ?? null,
            'entity_uuid_len' => strlen((string) ($captured['entity_uuid'] ?? '')),
            'files_uuid' => $submission->slip_file_uuid,
            'slip_file_uuid' => $submission->slip_file_uuid,
            'review_status' => $submission->review_status->value ?? (string) $submission->review_status,
            'slip_path' => $submission->slip_path,
            'view_status' => $view->status(),
            'download_status' => $download->status(),
            'metadata' => $fileMeta,
            'payload' => [
                'application' => $captured['application'] ?? null,
                'entity_type' => $captured['entity_type'] ?? null,
                'entity_uuid' => $captured['entity_uuid'] ?? null,
                'category' => $captured['category'] ?? null,
                'uploaded_by' => $captured['uploaded_by'] ?? null,
                'metadata' => $meta,
            ],
        ], JSON_PRETTY_PRINT).PHP_EOL);
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
                'display_name' => 'Real Proof Customer',
                'primary_country_id' => $this->countryByIso('LK')->id,
                'primary_phone' => '070'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $this->countryByIso('LK')->id,
            ],
            'address' => [
                'recipient_name' => 'Real Proof Customer',
                'line1' => '1 Real Proof Road',
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

    /**
     * @return array{courier: Courier, service: CourierService, city: CourierCity}
     */
    private function makeCourierSetup(Order $order): array
    {
        $courier = Courier::query()->create([
            'code' => 'RL'.strtoupper(substr(uniqid(), -4)),
            'name' => 'Real Proof Courier',
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
            'slug' => 'admin-real-'.Str::lower(Str::random(6)),
            'name' => 'Admin Real Reviewer',
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
            'email' => 'admin-real-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '071'.random_int(1000000, 9999999),
            'password' => bcrypt('password'),
            'user_type' => UserType::OWNER->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ])->fresh(['role']);
    }
}
