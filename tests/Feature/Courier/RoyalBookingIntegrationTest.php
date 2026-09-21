<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Exceptions\CourierProviderBookingException;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ShipmentStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
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
use Feeder\Core\Services\Courier\Curfox\CurfoxAuthService;
use Feeder\Core\Services\Courier\Curfox\RoyalBookingAdapter;
use Feeder\Core\Services\Courier\Curfox\RoyalCourierAccountSetupService;
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Feeder\Core\Services\Order\OrderShipmentLockService;
use Feeder\Core\Services\Order\ShipmentBookingService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class RoyalBookingIntegrationTest extends TestCase
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
            'services.curfox.base_url' => 'https://v2-dashboards.api.curfox.com',
            'services.curfox.tenant' => 'royalexpress',
            'feeder.curfox.base_url' => 'https://v2-dashboards.api.curfox.com',
            'feeder.curfox.tenant' => 'royalexpress',
            'feeder.courier_booking_adapters.ROYAL' => RoyalBookingAdapter::class,
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

    public function test_login_success_saves_encrypted_token_and_hides_secrets(): void
    {
        Http::fake([
            '*/api/public/merchant/login' => Http::response([
                'message' => 'Login Successfully',
                'user' => ['merchant_id' => '99', 'email' => 'a@example.com'],
                'token' => 'royal-token-abc',
            ], 200),
        ]);

        $supplier = $this->makeSupplierUser();
        $setup = $this->makeRoyalCourierLocations();
        $actorId = $this->makeAdminActor()->id;

        $account = app(RoyalCourierAccountSetupService::class)->create($supplier, [
            'courier_id' => $setup['courier']->id,
            'account_label' => 'ROYAL Primary',
            'credentials' => [
                'email' => 'supplier-a@example.com',
                'password' => 'secret-password',
            ],
            'meta' => [
                'merchant_business_id' => '2',
                'origin_state_id' => $setup['state']->id,
                'origin_city_id' => $setup['originCity']->id,
            ],
            'is_default' => true,
        ], $actorId);

        $credentials = $account->getCredentials();
        $this->assertSame('supplier-a@example.com', $credentials['email']);
        $this->assertSame('secret-password', $credentials['password']);
        $this->assertSame('royal-token-abc', $credentials['token']);
        $this->assertSame('2', $account->meta_json['merchant_business_id']);
        $this->assertSame('Colombo Suburbs', $account->meta_json['origin_state_name']);
        $this->assertSame('Aggona', $account->meta_json['origin_city_name']);
        $this->assertNotSame('secret-password', $account->credentials_encrypted);

        $presented = app(SupplierCourierAccountService::class)->presentAccount($account);
        $encoded = json_encode($presented);
        $this->assertStringNotContainsString('secret-password', $encoded);
        $this->assertStringNotContainsString('royal-token-abc', $encoded);
        $this->assertSame('Configured', collect($presented['credential_display'])->firstWhere('key', 'token')['display']);
    }

    public function test_failed_login_does_not_create_account(): void
    {
        Http::fake([
            '*/api/public/merchant/login' => Http::response([
                'message' => 'Invalid credentials',
            ], 401),
        ]);

        $supplier = $this->makeSupplierUser();
        $setup = $this->makeRoyalCourierLocations();

        try {
            app(RoyalCourierAccountSetupService::class)->create($supplier, [
                'courier_id' => $setup['courier']->id,
                'account_label' => 'Should Fail',
                'credentials' => [
                    'email' => 'bad@example.com',
                    'password' => 'wrong',
                ],
                'meta' => [
                    'merchant_business_id' => '2',
                    'origin_state_id' => $setup['state']->id,
                    'origin_city_id' => $setup['originCity']->id,
                ],
            ], $this->makeAdminActor()->id);

            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credentials', $e->errors());
        }

        $this->assertSame(0, SupplierCourierAccount::query()->where('supplier_id', $supplier->id)->count());
    }

    public function test_origin_city_must_belong_to_selected_state(): void
    {
        Http::fake([
            '*/api/public/merchant/login' => Http::response([
                'token' => 'token',
            ], 200),
        ]);

        $supplier = $this->makeSupplierUser();
        $setup = $this->makeRoyalCourierLocations();

        $otherState = CourierState::query()->create([
            'courier_id' => $setup['courier']->id,
            'external_id' => 'state-other',
            'name' => 'Other State',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(RoyalCourierAccountSetupService::class)->create($supplier, [
            'courier_id' => $setup['courier']->id,
            'account_label' => 'Bad Origin',
            'credentials' => [
                'email' => 'a@example.com',
                'password' => 'secret',
            ],
            'meta' => [
                'merchant_business_id' => '2',
                'origin_state_id' => $otherState->id,
                'origin_city_id' => $setup['originCity']->id,
            ],
        ], $this->makeAdminActor()->id);
    }

    public function test_token_refresh_retries_booking_once_and_saves_new_token(): void
    {
        $loginCalls = 0;
        $orderCalls = 0;

        Http::fake(function ($request) use (&$loginCalls, &$orderCalls) {
            if (str_contains($request->url(), '/merchant/login')) {
                $loginCalls++;

                return Http::response(['token' => 'fresh-token-'.$loginCalls], 200);
            }

            if (str_contains($request->url(), '/merchant/order/single')) {
                $orderCalls++;

                if ($orderCalls === 1) {
                    $this->assertSame('Bearer stale-token', $request->header('Authorization')[0] ?? null);

                    return Http::response(['message' => 'Unauthenticated.'], 401);
                }

                $this->assertSame('Bearer fresh-token-1', $request->header('Authorization')[0] ?? null);

                return Http::response([
                    'message' => 'Orders Created Successfully',
                    'data' => ['AU145'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableRoyalOrder(totalWeight: 2.3);
        $account = $context['account'];
        $account->setCredentials([
            'email' => 'supplier-a@example.com',
            'password' => 'secret-password',
            'token' => 'stale-token',
        ]);
        $account->save();

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(RoyalBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('AU145', $shipment->tracking_number);
        $this->assertSame(1, $loginCalls);
        $this->assertSame(2, $orderCalls);
        $this->assertSame('fresh-token-1', $account->fresh()->getCredentials()['token']);
    }

    public function test_second_401_does_not_loop_infinitely(): void
    {
        $orderCalls = 0;

        Http::fake(function ($request) use (&$orderCalls) {
            if (str_contains($request->url(), '/merchant/login')) {
                return Http::response(['token' => 'still-bad'], 200);
            }

            if (str_contains($request->url(), '/merchant/order/single')) {
                $orderCalls++;

                return Http::response(['message' => 'Unauthenticated.'], 401);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableRoyalOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(RoyalBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('booking', $e->errors());
        }

        $this->assertSame(2, $orderCalls);
        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    public function test_supplier_isolation_uses_own_royal_account(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/merchant/login')) {
                return Http::response(['token' => 'unused'], 200);
            }

            if (str_contains($request->url(), '/merchant/order/single')) {
                $payload = $request->data();
                $this->assertSame('111', $payload['general_data']['merchant_business_id']);
                $auth = $request->header('Authorization')[0] ?? '';
                $this->assertSame('Bearer token-A', $auth);

                return Http::response(['data' => ['WAYBILL-A']], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $locations = $this->makeRoyalCourierLocations();

        $accountA = $this->makeRoyalAccount($supplierA, $locations, 'token-A', '111', 'a@example.com');
        $this->makeRoyalAccount($supplierB, $locations, 'token-B', '222', 'b@example.com');

        $order = $this->makeOrderForSupplier($supplierA, totalWeight: 1.0);
        $this->attachPricingAndService($locations['courier'], $order);

        $shipment = app(ShipmentBookingService::class)->book(
            $order,
            $locations['courier']->id,
            $locations['service']->id,
            $locations['destinationCity']->id,
            app(RoyalBookingAdapter::class),
            $order->reseller_id,
        );

        $this->assertSame('WAYBILL-A', $shipment->tracking_number);
        $this->assertSame($accountA->id, $shipment->supplier_courier_account_id);
    }

    public function test_payload_uses_meta_origin_destination_ceil_weight_and_order_total_cod(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/merchant/order/single')) {
                $payload = $request->data();
                $orderData = $payload['order_data'][0];

                $this->assertSame('2', $payload['general_data']['merchant_business_id']);
                $this->assertSame('Aggona', $payload['general_data']['origin_city_name']);
                $this->assertSame('Colombo Suburbs', $payload['general_data']['origin_state_name']);
                $this->assertSame('Colombo 02', $orderData['destination_city_name']);
                $this->assertSame('Colombo', $orderData['destination_state_name']);
                $this->assertSame(3, $orderData['weight']);
                $this->assertArrayNotHasKey('waybill_number', $orderData);
                $this->assertIsInt($orderData['cod']);
                // Fee: first kg 700 + ceil(2.3-1)=2 * 200 = 1100; COD = 500 + 1100
                $this->assertSame(1600, $orderData['cod']);
                $this->assertNotEmpty($orderData['order_no']);
                $this->assertStringContainsString('Fragile Serum', $orderData['description']);

                return Http::response(['data' => ['AU999']], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableRoyalOrder(
            totalWeight: 2.3,
            itemsSubtotal: 500,
            productName: 'Fragile Serum',
        );

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(RoyalBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('AU999', $shipment->tracking_number);
        $this->assertSame(ShipmentStatus::BOOKED, $shipment->status);
        $this->assertTrue($context['order']->fresh(['shipment'])->hasBookedShipment());

        $this->expectException(ValidationException::class);
        app(OrderShipmentLockService::class)->assertShipmentMutable($context['order']->fresh(['shipment']));
    }

    public function test_payload_uses_linked_royal_state_name_not_stale_district_name(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/merchant/order/single')) {
                $payload = $request->data();
                $orderData = $payload['order_data'][0];

                $this->assertSame('Colombo 02', $orderData['destination_city_name']);
                $this->assertSame('Colombo', $orderData['destination_state_name']);
                $this->assertArrayNotHasKey('destination_city_id', $orderData);
                $this->assertArrayNotHasKey('city_id', $orderData);

                return Http::response(['data' => ['AU-STATE']], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableRoyalOrder();
        $context['destinationCity']->district_name = 'Wrong District';
        $context['destinationCity']->save();

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(RoyalBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('AU-STATE', $shipment->tracking_number);
        $this->assertSame((int) $context['destinationCity']->id, (int) $shipment->courier_city_id);
    }

    public function test_rate_card_and_validation_errors_do_not_retry_or_create_shipment(): void
    {
        $orderCalls = 0;

        Http::fake(function ($request) use (&$orderCalls) {
            if (str_contains($request->url(), '/merchant/order/single')) {
                $orderCalls++;

                $payload = $request->data();
                $orderData = $payload['order_data'][0] ?? [];

                // Preserve name-based booking; provider city IDs are stored but not sent.
                $this->assertArrayHasKey('origin_city_name', $payload['general_data']);
                $this->assertArrayNotHasKey('origin_city_id', $payload['general_data']);
                $this->assertArrayHasKey('destination_city_name', $orderData);
                $this->assertArrayNotHasKey('destination_city_id', $orderData);

                // Live Curfox shape for missing merchant rate-card coverage.
                return Http::response([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'rate_card.destination_city_id' => [
                            'There is no rate card assigned to you with the following city combination - Nugegoda to Dehiwala!',
                        ],
                    ],
                ], 422);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableRoyalOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(RoyalBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected ValidationException');
        } catch (CourierProviderBookingException $e) {
            $this->assertSame('http_error', $e->debug['failure_type']);
            $this->assertSame(422, $e->debug['http_status']);
            $this->assertSame(
                'There is no rate card assigned to you with the following city combination - Nugegoda to Dehiwala!',
                $e->debug['response']['errors']['rate_card.destination_city_id'][0] ?? null,
            );
            $this->assertStringContainsString('The given data was invalid.', $e->errors()['booking'][0]);
            $this->assertStringNotContainsString('token', strtolower($e->errors()['booking'][0]));
        }

        $this->assertSame(1, $orderCalls);
        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
        $this->assertFalse($context['order']->fresh(['shipment'])->hasBookedShipment());
    }

    public function test_connection_test_refreshes_token_without_exposing_secrets(): void
    {
        Http::fake([
            '*/api/public/merchant/login' => Http::response(['token' => 'rotated-token'], 200),
        ]);

        $supplier = $this->makeSupplierUser();
        $locations = $this->makeRoyalCourierLocations();
        $account = $this->makeRoyalAccount($supplier, $locations, 'old-token', '2', 'conn@example.com');

        $result = app(CourierConnectionService::class)->testAccount($account);

        $this->assertTrue($result->successful);
        $this->assertStringNotContainsString('rotated-token', $result->message);
        $this->assertStringNotContainsString('password', strtolower($result->message));
        $this->assertSame('rotated-token', $account->fresh()->getCredentials()['token']);
    }

    public function test_credential_schema_exposes_email_password_not_global_url(): void
    {
        $schema = app(CourierCredentialSchemaRegistry::class)->forCode('ROYAL');
        $keys = array_map(static fn ($field) => $field->key, $schema->fields());

        $this->assertSame(['email', 'password'], $keys);
        $this->assertNotContains('base_url', $keys);
        $this->assertNotContains('tenant', $keys);
        $this->assertNotContains('token', $keys);
    }

    public function test_auth_service_does_not_infer_merchant_business_id_from_login(): void
    {
        Http::fake([
            '*/api/public/merchant/login' => Http::response([
                'token' => 'tok',
                'user' => ['merchant_id' => 'should-not-be-used'],
            ], 200),
        ]);

        $token = app(CurfoxAuthService::class)->login('a@example.com', 'secret');
        $this->assertSame('tok', $token);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/merchant/login')
                && $request->hasHeader('X-tenant', 'royalexpress')
                && ($request->data()['email'] ?? null) === 'a@example.com';
        });
    }

    /**
     * @return array{
     *     courier: Courier,
     *     service: CourierService,
     *     state: CourierState,
     *     originCity: CourierCity,
     *     destinationCity: CourierCity
     * }
     */
    private function makeRoyalCourierLocations(): array
    {
        $courier = Courier::query()->updateOrCreate(
            ['code' => 'ROYAL'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Royal Express',
                'is_active' => true,
            ]
        );

        $service = CourierService::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'code' => 'STANDARD'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Standard',
                'external_service_id' => 'royal-standard',
                'is_active' => true,
            ]
        );

        $state = CourierState::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => 'royal-state-1'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Colombo Suburbs',
                'is_active' => true,
            ]
        );

        $destState = CourierState::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => 'royal-state-2'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Colombo',
                'is_active' => true,
            ]
        );

        $originCity = CourierCity::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => 'royal-city-origin'],
            [
                'uuid' => (string) Str::uuid(),
                'courier_state_id' => $state->id,
                'name' => 'Aggona',
                'city_name' => 'Aggona',
                'district_name' => 'Colombo Suburbs',
                'external_city_code' => 'royal-city-origin',
                'external_district_code' => 'royal-state-1',
                'is_active' => true,
            ]
        );

        $destinationCity = CourierCity::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => 'royal-city-dest'],
            [
                'uuid' => (string) Str::uuid(),
                'courier_state_id' => $destState->id,
                'name' => 'Colombo 02',
                'city_name' => 'Colombo 02',
                'district_name' => 'Colombo',
                'external_city_code' => 'royal-city-dest',
                'external_district_code' => 'royal-state-2',
                'is_active' => true,
            ]
        );

        return [
            'courier' => $courier,
            'service' => $service,
            'state' => $state,
            'originCity' => $originCity,
            'destinationCity' => $destinationCity,
        ];
    }

    /**
     * @param  array{
     *     courier: Courier,
     *     service: CourierService,
     *     state: CourierState,
     *     originCity: CourierCity,
     *     destinationCity: CourierCity
     * }  $locations
     */
    private function makeRoyalAccount(
        $supplier,
        array $locations,
        string $token,
        string $merchantBusinessId,
        string $email,
    ): SupplierCourierAccount {
        return SupplierCourierAccount::query()->create([
            'supplier_id' => $supplier->id,
            'courier_id' => $locations['courier']->id,
            'account_label' => 'ROYAL '.$email,
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'email' => $email,
                'password' => 'secret-password',
                'token' => $token,
            ], JSON_THROW_ON_ERROR)),
            'meta_json' => [
                'merchant_business_id' => $merchantBusinessId,
                'origin_state_id' => $locations['state']->id,
                'origin_city_id' => $locations['originCity']->id,
                'origin_state_name' => 'Colombo Suburbs',
                'origin_city_name' => 'Aggona',
            ],
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
    private function makeBookableRoyalOrder(
        float $totalWeight = 1.5,
        float $itemsSubtotal = 500,
        string $productName = 'Product Snapshot',
    ): array {
        $locations = $this->makeRoyalCourierLocations();
        $supplier = $this->makeSupplierUser();
        $account = $this->makeRoyalAccount($supplier, $locations, 'valid-token', '2', 'book@example.com');
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
            'city_name' => 'Colombo',
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
                'first_kg_fee' => 700,
                'additional_kg_fee' => 200,
                'is_active' => true,
            ]
        );
    }

    private function makeAdminActor(): User
    {
        return User::query()->create([
            'uuid' => UuidService::generate(),
            'email' => 'admin-royal-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);
    }
}
