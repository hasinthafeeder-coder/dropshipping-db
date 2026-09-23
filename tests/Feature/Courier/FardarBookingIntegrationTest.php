<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ShipmentStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Exceptions\CourierProviderBookingException;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\CourierService;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderAddress;
use Feeder\Core\Models\OrderItem;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Courier\CourierConnectionService;
use Feeder\Core\Services\Courier\CourierCredentialSchemaRegistry;
use Feeder\Core\Services\Courier\Fardar\FardarBookingAdapter;
use Feeder\Core\Services\Courier\Fardar\FardarCourierAccountSetupService;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Feeder\Core\Services\Order\ShipmentBookingService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class FardarBookingIntegrationTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private const FAKE_CLIENT_ID = '1000';

    private const FAKE_API_KEY = 'test-fardar-api-key-xyz';

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
            'services.fardar.base_url' => 'https://www.fdedomestic.com',
            'feeder.fardar.base_url' => 'https://www.fdedomestic.com',
            'feeder.courier_booking_adapters.FARDAR' => FardarBookingAdapter::class,
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

    public function test_fardar_account_accepts_client_id_and_api_key_encrypted(): void
    {
        $supplier = $this->makeSupplierUser();
        $setup = $this->makeFardarCourierLocations();
        $actorId = $this->makeAdminActor()->id;

        $account = app(FardarCourierAccountSetupService::class)->create($supplier, [
            'courier_id' => $setup['courier']->id,
            'account_label' => 'Fardar Primary',
            'credentials' => [
                'client_id' => self::FAKE_CLIENT_ID,
                'api_key' => self::FAKE_API_KEY,
            ],
            'is_default' => true,
        ], $actorId);

        $credentials = $account->getCredentials();
        $this->assertSame(self::FAKE_CLIENT_ID, $credentials['client_id']);
        $this->assertSame(self::FAKE_API_KEY, $credentials['api_key']);
        $this->assertNotSame(self::FAKE_API_KEY, $account->credentials_encrypted);
        $this->assertStringNotContainsString(self::FAKE_API_KEY, (string) $account->credentials_encrypted);

        $presented = app(SupplierCourierAccountService::class)->presentAccount($account);
        $encoded = json_encode($presented);
        $this->assertStringNotContainsString(self::FAKE_API_KEY, $encoded);
        $this->assertSame('Configured', collect($presented['credential_display'])->firstWhere('key', 'api_key')['display']);
        $this->assertNotSame(self::FAKE_CLIENT_ID, collect($presented['credential_display'])->firstWhere('key', 'client_id')['display']);
    }

    public function test_api_key_never_returned_to_frontend_presentation(): void
    {
        $context = $this->makeBookableFardarOrder();
        $presented = app(SupplierCourierAccountService::class)->presentAccount($context['account']);
        $encoded = json_encode($presented);

        $this->assertStringNotContainsString(self::FAKE_API_KEY, $encoded);
        $this->assertArrayNotHasKey('credentials', $presented);
        $this->assertArrayNotHasKey('api_key', $presented);
    }

    public function test_credential_schema_exposes_client_id_and_api_key_only(): void
    {
        $schema = app(CourierCredentialSchemaRegistry::class)->forCode('FARDAR');
        $keys = array_map(static fn ($field) => $field->key, $schema->fields());

        $this->assertSame(['client_id', 'api_key'], $keys);
        $this->assertNotContains('email', $keys);
        $this->assertNotContains('password', $keys);
        $this->assertNotContains('token', $keys);
        $this->assertNotContains('base_url', $keys);
        $this->assertNotContains('merchant_id', $keys);
    }

    public function test_connection_test_validates_format_without_remote_parcel(): void
    {
        Http::fake();

        $context = $this->makeBookableFardarOrder();
        $result = app(CourierConnectionService::class)->testAccount($context['account']);

        $this->assertTrue($result->successful);
        $this->assertStringContainsString('Credential format validated', $result->message);
        $this->assertStringNotContainsString(self::FAKE_API_KEY, $result->message);
        Http::assertNothingSent();
    }

    public function test_supplier_isolation_uses_own_fardar_account(): void
    {
        Http::fake(function ($request) {
            $payload = $request->data();
            $this->assertSame(self::FAKE_CLIENT_ID, $payload['client_id'] ?? null);
            $this->assertSame('api-key-A-secret', $payload['api_key'] ?? null);

            return Http::response([
                'status' => 200,
                'waybill_no' => 'WAYBILL-A',
            ], 200);
        });

        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $locations = $this->makeFardarCourierLocations();

        $accountA = $this->makeFardarAccount($supplierA, $locations, self::FAKE_CLIENT_ID, 'api-key-A-secret');
        $this->makeFardarAccount($supplierB, $locations, '2000', 'api-key-B-secret');

        $order = $this->makeOrderForSupplier($supplierA, totalWeight: 1.0);
        $this->attachPricingAndService($locations['courier'], $order);

        $shipment = app(ShipmentBookingService::class)->book(
            $order,
            $locations['courier']->id,
            $locations['service']->id,
            $locations['destinationCity']->id,
            app(FardarBookingAdapter::class),
            $order->reseller_id,
        );

        $this->assertSame('WAYBILL-A', $shipment->tracking_number);
        $this->assertSame($accountA->id, $shipment->supplier_courier_account_id);
    }

    public function test_create_parcel_uses_form_encoding_not_json(): void
    {
        Http::fake(function ($request) {
            $this->assertTrue(str_contains($request->url(), '/api/parcel/new_api_v1.php'));
            $this->assertSame('POST', $request->method());

            $contentType = strtolower((string) ($request->header('Content-Type')[0] ?? ''));
            $this->assertStringContainsString('application/x-www-form-urlencoded', $contentType);
            $this->assertStringNotContainsString('application/json', $contentType);

            $body = (string) $request->body();
            $this->assertStringNotContainsString('{', $body);
            $this->assertStringContainsString('client_id=', $body);
            $this->assertStringContainsString('api_key=', $body);
            $this->assertStringContainsString('exchange=0', $body);

            return Http::response([
                'status' => 200,
                'waybill_no' => '10000001',
            ], 200);
        });

        $context = $this->makeBookableFardarOrder(totalWeight: 2.3);

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(FardarBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('10000001', $shipment->tracking_number);
        $this->assertSame(ShipmentStatus::BOOKED, $shipment->status);
    }

    public function test_payload_maps_order_fields_city_name_weight_amount_and_exchange_zero(): void
    {
        Http::fake(function ($request) {
            $payload = $request->data();

            $this->assertSame(self::FAKE_CLIENT_ID, $payload['client_id']);
            $this->assertSame(self::FAKE_API_KEY, $payload['api_key']);
            $this->assertSame(75757575, (int) $payload['order_id']);
            $this->assertSame('1', (string) $payload['parcel_weight']);
            $this->assertStringContainsString('Fragile Serum', (string) $payload['parcel_description']);
            $this->assertSame('John Doe', $payload['recipient_name']);
            $this->assertSame('0794535345', $payload['recipient_contact_1']);
            $this->assertSame('0792445546', $payload['recipient_contact_2']);
            $this->assertSame('Address line 1, Address line 2, Address line 3', $payload['recipient_address']);
            $this->assertSame('Matara', $payload['recipient_city']);
            $this->assertNotSame('864', (string) $payload['recipient_city']);
            // Fee still uses real order weight: first kg 600 + ceil(2.3-1)=2 * 100 = 800; COD = 500 + 800
            $this->assertSame('1300', (string) $payload['amount']);
            $this->assertSame('0', (string) $payload['exchange']);

            return Http::response([
                'status' => 200,
                'waybill_no' => '10000099',
            ], 200);
        });

        $context = $this->makeBookableFardarOrder(
            totalWeight: 2.3,
            itemsSubtotal: 500,
            productName: 'Fragile Serum',
        );

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(FardarBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('10000099', $shipment->tracking_number);
        $this->assertSame(2.3, (float) $shipment->weight_snapshot);
        $this->assertSame(2.3, (float) $context['order']->fresh()->total_weight);
    }

    public function test_success_requires_provider_status_200_and_waybill(): void
    {
        Http::fake([
            '*/api/parcel/new_api_v1.php' => Http::response([
                'status' => 200,
                'waybill_no' => '10000001',
            ], 200),
        ]);

        $context = $this->makeBookableFardarOrder();

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(FardarBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('10000001', $shipment->tracking_number);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $context['order']->id,
            'tracking_number' => '10000001',
        ]);
    }

    public function test_missing_waybill_causes_booking_failure(): void
    {
        Http::fake([
            '*/api/parcel/new_api_v1.php' => Http::response([
                'status' => 200,
            ], 200),
        ]);

        $context = $this->makeBookableFardarOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(FardarBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected CourierProviderBookingException');
        } catch (CourierProviderBookingException $e) {
            $this->assertSame('application_rejection', $e->debug['failure_type']);
            $this->assertStringContainsString('waybill', strtolower($e->errors()['booking'][0]));
            $this->assertStringNotContainsString(self::FAKE_API_KEY, $e->errors()['booking'][0]);
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    public function test_malformed_json_causes_booking_failure(): void
    {
        Http::fake([
            '*/api/parcel/new_api_v1.php' => Http::response('not-json', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $context = $this->makeBookableFardarOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(FardarBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected CourierProviderBookingException');
        } catch (CourierProviderBookingException $e) {
            $this->assertSame('invalid_json', $e->debug['failure_type']);
            $this->assertStringNotContainsString(self::FAKE_API_KEY, $e->errors()['booking'][0]);
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    #[DataProvider('providerStatusFailureProvider')]
    public function test_provider_status_codes_are_mapped(int $status, string $expectedFragment): void
    {
        Http::fake([
            '*/api/parcel/new_api_v1.php' => Http::response([
                'status' => $status,
            ], 200),
        ]);

        $context = $this->makeBookableFardarOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(FardarBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected CourierProviderBookingException for status '.$status);
        } catch (CourierProviderBookingException $e) {
            $this->assertSame('application_rejection', $e->debug['failure_type']);
            $this->assertStringContainsString($expectedFragment, $e->errors()['booking'][0]);
            $this->assertStringNotContainsString(self::FAKE_API_KEY, $e->errors()['booking'][0]);
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function providerStatusFailureProvider(): array
    {
        return [
            '201 inactive client' => [201, 'inactive client'],
            '202 invalid order id' => [202, 'order ID'],
            '203 invalid weight' => [203, 'weight'],
            '204 invalid description' => [204, 'description'],
            '205 invalid name' => [205, 'name'],
            '206 contact 1' => [206, 'contact number 1'],
            '207 contact 2' => [207, 'contact number 2'],
            '208 address' => [208, 'address'],
            '209 city' => [209, 'city'],
            '210 insert failed' => [210, 'insert'],
            '211 api key' => [211, 'API key'],
            '212 client credentials' => [212, 'client credentials'],
            '213 exchange' => [213, 'exchange'],
            '214 maintenance' => [214, 'maintenance'],
        ];
    }

    #[DataProvider('fixedBookingWeightCases')]
    public function test_courier_api_weight_is_always_fixed_one_kg_regardless_of_order_weight(
        float $orderWeightKg,
    ): void {
        Http::fake(function ($request) {
            $payload = $request->data();
            $this->assertSame('1', (string) $payload['parcel_weight']);

            return Http::response([
                'status' => 200,
                'waybill_no' => '10000099',
            ], 200);
        });

        $context = $this->makeBookableFardarOrder(totalWeight: $orderWeightKg);

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(FardarBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('10000099', $shipment->tracking_number);
        $this->assertSame($orderWeightKg, (float) $shipment->weight_snapshot);
        $this->assertSame($orderWeightKg, (float) $context['order']->fresh()->total_weight);
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function fixedBookingWeightCases(): array
    {
        return [
            '0.5 kg order' => [0.5],
            '1 kg order' => [1.0],
            '5 kg order' => [5.0],
        ];
    }

    public function test_zero_order_weight_still_sends_fixed_one_kg_to_courier(): void
    {
        Http::fake(function ($request) {
            $payload = $request->data();
            $this->assertSame('1', (string) $payload['parcel_weight']);

            return Http::response([
                'status' => 200,
                'waybill_no' => '10000001',
            ], 200);
        });

        $context = $this->makeBookableFardarOrder(totalWeight: 0);

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(FardarBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('10000001', $shipment->tracking_number);
        $this->assertSame(0.0, (float) $shipment->weight_snapshot);
        $this->assertSame(0.0, (float) $context['order']->fresh()->total_weight);
    }

    public function test_api_key_does_not_appear_in_exception_or_logs(): void
    {
        Log::spy();

        Http::fake([
            '*/api/parcel/new_api_v1.php' => Http::response([
                'status' => 211,
            ], 200),
        ]);

        $context = $this->makeBookableFardarOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(FardarBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected CourierProviderBookingException');
        } catch (CourierProviderBookingException $e) {
            $encoded = json_encode($e->debug);
            $this->assertStringNotContainsString(self::FAKE_API_KEY, $encoded);
            $this->assertArrayNotHasKey('api_key', $e->debug['request'] ?? []);
            $this->assertStringContainsString('API key', $e->errors()['booking'][0]);
            $this->assertStringNotContainsString(self::FAKE_API_KEY, $e->errors()['booking'][0]);
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                $encoded = json_encode($context);

                return $message === 'fardar_booking_failed'
                    && ! str_contains($encoded, self::FAKE_API_KEY);
            })
            ->atLeast()
            ->once();
    }

    /**
     * @return array{
     *     courier: Courier,
     *     service: CourierService,
     *     state: CourierState,
     *     destinationCity: CourierCity
     * }
     */
    private function makeFardarCourierLocations(): array
    {
        $courier = Courier::query()->updateOrCreate(
            ['code' => 'FARDAR'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Fardar Express Domestic',
                'is_active' => true,
            ]
        );

        $service = CourierService::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'code' => 'STANDARD'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Standard',
                'external_service_id' => 'fardar-standard',
                'is_active' => true,
            ]
        );

        $state = CourierState::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => '12'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Matara',
                'is_active' => true,
            ]
        );

        $destinationCity = CourierCity::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => '864'],
            [
                'uuid' => (string) Str::uuid(),
                'courier_state_id' => $state->id,
                'name' => 'Matara',
                'city_name' => 'Matara',
                'district_name' => 'Matara',
                'external_city_code' => '864',
                'external_district_code' => '12',
                'is_active' => true,
            ]
        );

        return [
            'courier' => $courier,
            'service' => $service,
            'state' => $state,
            'destinationCity' => $destinationCity,
        ];
    }

    /**
     * @param  array{
     *     courier: Courier,
     *     service: CourierService,
     *     state: CourierState,
     *     destinationCity: CourierCity
     * }  $locations
     */
    private function makeFardarAccount(
        $supplier,
        array $locations,
        string $clientId,
        string $apiKey,
    ): SupplierCourierAccount {
        return SupplierCourierAccount::query()->create([
            'supplier_id' => $supplier->id,
            'courier_id' => $locations['courier']->id,
            'account_label' => 'Fardar '.$clientId,
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'client_id' => $clientId,
                'api_key' => $apiKey,
            ], JSON_THROW_ON_ERROR)),
            'meta_json' => null,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @return array{
     *     order: Order,
     *     courier: Courier,
     *     service: CourierService,
     *     destinationCity: CourierCity,
     *     account: SupplierCourierAccount
     * }
     */
    private function makeBookableFardarOrder(
        float $totalWeight = 1.5,
        float $itemsSubtotal = 500,
        string $productName = 'Product Snapshot',
    ): array {
        $locations = $this->makeFardarCourierLocations();
        $supplier = $this->makeSupplierUser();
        $account = $this->makeFardarAccount($supplier, $locations, self::FAKE_CLIENT_ID, self::FAKE_API_KEY);
        $order = $this->makeOrderForSupplier($supplier, $totalWeight, $itemsSubtotal, $productName);
        $this->attachPricingAndService($locations['courier'], $order);

        return [
            'order' => $order,
            'courier' => $locations['courier'],
            'service' => $locations['service'],
            'destinationCity' => $locations['destinationCity'],
            'account' => $account,
        ];
    }

    private function makeOrderForSupplier(
        $supplier,
        float $totalWeight = 1.5,
        float $itemsSubtotal = 500,
        string $productName = 'Product Snapshot',
    ): Order {
        $reseller = $this->makeResellerUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $market = $this->marketByCode('lk');
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $customer = Customer::query()->create([
            'uuid' => UuidService::generate(),
            'display_name' => 'John Doe',
            'primary_country_id' => $this->countryByIso('LK')->id,
            'is_banned' => false,
        ]);

        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_number' => '75757575',
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
            'customer_name_snapshot' => 'John Doe',
            'primary_phone_snapshot' => '0794535345',
            'secondary_phone_snapshot' => '0792445546',
            'primary_phone_country_id' => $this->countryByIso('LK')->id,
            'items_subtotal' => $itemsSubtotal,
            'discount_amount' => 0,
            'courier_fee_amount' => 0,
            'customer_payable_amount' => $itemsSubtotal,
            'total_weight' => $totalWeight,
            'created_by' => $reseller->id,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $productName,
            'variant_name_snapshot' => $variant->name,
            'barcode_snapshot' => $variant->barcode,
            'quantity' => 1,
            'unit_selling_price' => $itemsSubtotal,
            'unit_cost_snapshot' => 100,
            'unit_company_commission_snapshot' => 50,
            'unit_weight_snapshot' => $totalWeight,
            'line_selling_total' => $itemsSubtotal,
            'line_weight_total' => $totalWeight,
        ]);

        OrderAddress::query()->create([
            'order_id' => $order->id,
            'recipient_name' => 'John Doe',
            'line1' => 'Address line 1, Address line 2, Address line 3',
            'full_address_text' => 'Address line 1, Address line 2, Address line 3',
            'city_name' => 'Matara',
            'country_id' => $this->countryByIso('LK')->id,
        ]);

        return $order->fresh(['items', 'address']);
    }

    private function attachPricingAndService(Courier $courier, Order $order): void
    {
        CourierMarketPricing::query()->updateOrCreate(
            [
                'courier_id' => $courier->id,
                'market_id' => $order->market_id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'currency_id' => $order->currency_id,
                'first_kg_fee' => 600,
                'additional_kg_fee' => 100,
                'is_active' => true,
            ]
        );
    }

    private function makeAdminActor(): User
    {
        return User::query()->create([
            'uuid' => UuidService::generate(),
            'email' => 'admin-fardar-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);
    }
}
