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
use Feeder\Core\Services\Courier\SupplierCourierAccountService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressAuthService;
use Feeder\Core\Services\Courier\TransExpress\TransExpressBookingAdapter;
use Feeder\Core\Services\Courier\TransExpress\TransExpressCourierAccountSetupService;
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

class TransExpressBookingIntegrationTest extends TestCase
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
            'services.transexpress.base_url' => 'https://portal.transexpress.lk/api',
            'feeder.transexpress.base_url' => 'https://portal.transexpress.lk/api',
            'feeder.courier_booking_adapters.TRANSEXPRESS' => TransExpressBookingAdapter::class,
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
            '*/api/login/client' => Http::response([
                'token' => 'transexpress-token-abc',
                'status' => 'success',
            ], 200),
        ]);

        $supplier = $this->makeSupplierUser();
        $setup = $this->makeTransExpressCourierLocations();
        $actorId = $this->makeAdminActor()->id;

        $account = app(TransExpressCourierAccountSetupService::class)->create($supplier, [
            'courier_id' => $setup['courier']->id,
            'account_label' => 'TransExpress Primary',
            'credentials' => [
                'email' => 'supplier-a@example.com',
                'password' => 'secret-password',
            ],
            'is_default' => true,
        ], $actorId);

        $credentials = $account->getCredentials();
        $this->assertSame('supplier-a@example.com', $credentials['email']);
        $this->assertSame('secret-password', $credentials['password']);
        $this->assertSame('transexpress-token-abc', $credentials['token']);
        $this->assertNotSame('secret-password', $account->credentials_encrypted);

        $presented = app(SupplierCourierAccountService::class)->presentAccount($account);
        $encoded = json_encode($presented);
        $this->assertStringNotContainsString('secret-password', $encoded);
        $this->assertStringNotContainsString('transexpress-token-abc', $encoded);
        $this->assertSame('Configured', collect($presented['credential_display'])->firstWhere('key', 'token')['display']);
    }

    public function test_failed_login_does_not_create_account(): void
    {
        Http::fake([
            '*/api/login/client' => Http::response([
                'message' => 'Invalid credentials',
            ], 401),
        ]);

        $supplier = $this->makeSupplierUser();
        $setup = $this->makeTransExpressCourierLocations();

        try {
            app(TransExpressCourierAccountSetupService::class)->create($supplier, [
                'courier_id' => $setup['courier']->id,
                'account_label' => 'Should Fail',
                'credentials' => [
                    'email' => 'bad@example.com',
                    'password' => 'wrong',
                ],
            ], $this->makeAdminActor()->id);

            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credentials', $e->errors());
        }

        $this->assertSame(0, SupplierCourierAccount::query()->where('supplier_id', $supplier->id)->count());
    }

    public function test_login_rejects_missing_token(): void
    {
        Http::fake([
            '*/api/login/client' => Http::response([
                'status' => 'success',
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return a token');

        app(TransExpressAuthService::class)->login('a@example.com', 'secret');
    }

    public function test_login_rejects_malformed_json(): void
    {
        Http::fake([
            '*/api/login/client' => Http::response('not-json', 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->expectException(\RuntimeException::class);

        app(TransExpressAuthService::class)->login('a@example.com', 'secret');
    }

    public function test_login_http_error_maps_to_validation(): void
    {
        Http::fake([
            '*/api/login/client' => Http::response(['message' => 'Server busy'], 500),
        ]);

        try {
            app(TransExpressAuthService::class)->login('a@example.com', 'secret');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credentials', $e->errors());
            $this->assertStringNotContainsString('secret', strtolower($e->errors()['credentials'][0]));
        }
    }

    public function test_token_refresh_retries_booking_once_and_saves_new_token(): void
    {
        $loginCalls = 0;
        $orderCalls = 0;

        Http::fake(function ($request) use (&$loginCalls, &$orderCalls) {
            if (str_contains($request->url(), '/login/client')) {
                $loginCalls++;

                return Http::response([
                    'token' => 'fresh-token-'.$loginCalls,
                    'status' => 'success',
                ], 200);
            }

            if (str_contains($request->url(), '/orders/upload/single-auto')) {
                $orderCalls++;

                if ($orderCalls === 1) {
                    $this->assertSame('Bearer stale-token', $request->header('Authorization')[0] ?? null);

                    return Http::response(['message' => 'Unauthenticated.'], 401);
                }

                $this->assertSame('Bearer fresh-token-1', $request->header('Authorization')[0] ?? null);

                return Http::response([
                    'success' => 'Record successfully added',
                    'order' => [
                        'waybill_id' => 'BG243897',
                        'order_no' => 75757575,
                    ],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableTransExpressOrder(totalWeight: 2.3);
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
            app(TransExpressBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('BG243897', $shipment->tracking_number);
        $this->assertSame(1, $loginCalls);
        $this->assertSame(2, $orderCalls);
        $this->assertSame('fresh-token-1', $account->fresh()->getCredentials()['token']);
    }

    public function test_second_401_does_not_loop_infinitely(): void
    {
        $orderCalls = 0;

        Http::fake(function ($request) use (&$orderCalls) {
            if (str_contains($request->url(), '/login/client')) {
                return Http::response([
                    'token' => 'still-bad',
                    'status' => 'success',
                ], 200);
            }

            if (str_contains($request->url(), '/orders/upload/single-auto')) {
                $orderCalls++;

                return Http::response(['message' => 'Unauthenticated.'], 401);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableTransExpressOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(TransExpressBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('booking', $e->errors());
        }

        $this->assertSame(2, $orderCalls);
        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    public function test_supplier_isolation_uses_own_transexpress_account(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/login/client')) {
                return Http::response(['token' => 'unused', 'status' => 'success'], 200);
            }

            if (str_contains($request->url(), '/orders/upload/single-auto')) {
                $auth = $request->header('Authorization')[0] ?? '';
                $this->assertSame('Bearer token-A', $auth);

                return Http::response([
                    'success' => 'Record successfully added',
                    'order' => ['waybill_id' => 'WAYBILL-A'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $locations = $this->makeTransExpressCourierLocations();

        $accountA = $this->makeTransExpressAccount($supplierA, $locations, 'token-A', 'a@example.com');
        $this->makeTransExpressAccount($supplierB, $locations, 'token-B', 'b@example.com');

        $order = $this->makeOrderForSupplier($supplierA, totalWeight: 1.0);
        $this->attachPricingAndService($locations['courier'], $order);

        $shipment = app(ShipmentBookingService::class)->book(
            $order,
            $locations['courier']->id,
            $locations['service']->id,
            $locations['destinationCity']->id,
            app(TransExpressBookingAdapter::class),
            $order->reseller_id,
        );

        $this->assertSame('WAYBILL-A', $shipment->tracking_number);
        $this->assertSame($accountA->id, $shipment->supplier_courier_account_id);
    }

    public function test_payload_maps_order_fields_and_provider_city_id(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/orders/upload/single-auto')) {
                $payload = $request->data();

                $this->assertSame(75757575, $payload['order_no']);
                $this->assertSame('John Doe', $payload['customer_name']);
                $this->assertSame('Address line 1, Address line 2, Address line 3', $payload['address']);
                $this->assertStringContainsString('Fragile Serum', $payload['description']);
                $this->assertSame('0794535345', $payload['phone_no']);
                $this->assertSame('0792445546', $payload['phone_no2']);
                $this->assertSame(864, $payload['city_id']);
                $this->assertIsInt($payload['cod']);
                // Fee: first kg 650 + ceil(2.3-1)=2 * 150 = 950; COD = 500 + 950
                $this->assertSame(1450, $payload['cod']);
                $this->assertArrayNotHasKey('destination_city_name', $payload);
                $this->assertSame('Bearer valid-token', $request->header('Authorization')[0] ?? null);

                return Http::response([
                    'success' => 'Record successfully added',
                    'order' => ['waybill_id' => 'BG999'],
                ], 200);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableTransExpressOrder(
            totalWeight: 2.3,
            itemsSubtotal: 500,
            productName: 'Fragile Serum',
        );

        $shipment = app(ShipmentBookingService::class)->book(
            $context['order'],
            $context['courier']->id,
            $context['service']->id,
            $context['destinationCity']->id,
            app(TransExpressBookingAdapter::class),
            $context['order']->reseller_id,
        );

        $this->assertSame('BG999', $shipment->tracking_number);
        $this->assertSame(ShipmentStatus::BOOKED, $shipment->status);
        $this->assertTrue($context['order']->fresh(['shipment'])->hasBookedShipment());

        $this->expectException(ValidationException::class);
        app(OrderShipmentLockService::class)->assertShipmentMutable($context['order']->fresh(['shipment']));
    }

    public function test_provider_validation_error_is_normalized(): void
    {
        $orderCalls = 0;

        Http::fake(function ($request) use (&$orderCalls) {
            if (str_contains($request->url(), '/orders/upload/single-auto')) {
                $orderCalls++;

                return Http::response([
                    'message' => 'The city_id field is invalid.',
                ], 422);
            }

            return Http::response(['message' => 'unexpected'], 500);
        });

        $context = $this->makeBookableTransExpressOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(TransExpressBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected CourierProviderBookingException');
        } catch (CourierProviderBookingException $e) {
            $this->assertSame('http_error', $e->debug['failure_type']);
            $this->assertSame(422, $e->debug['http_status']);
            $this->assertStringContainsString('city_id field is invalid', $e->errors()['booking'][0]);
            $this->assertStringNotContainsString('token', strtolower($e->errors()['booking'][0]));
        }

        $this->assertSame(1, $orderCalls);
        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    public function test_provider_500_error_is_normalized(): void
    {
        Http::fake([
            '*/orders/upload/single-auto' => Http::response(['message' => 'Upstream failure'], 500),
        ]);

        $context = $this->makeBookableTransExpressOrder();

        try {
            app(ShipmentBookingService::class)->book(
                $context['order'],
                $context['courier']->id,
                $context['service']->id,
                $context['destinationCity']->id,
                app(TransExpressBookingAdapter::class),
                $context['order']->reseller_id,
            );
            $this->fail('Expected CourierProviderBookingException for 500');
        } catch (CourierProviderBookingException $e) {
            $this->assertSame(500, $e->debug['http_status']);
            $this->assertStringNotContainsString('token', strtolower($e->errors()['booking'][0]));
        }

        $this->assertDatabaseMissing('shipments', ['order_id' => $context['order']->id]);
    }

    public function test_connection_test_refreshes_token_without_exposing_secrets(): void
    {
        Http::fake([
            '*/api/login/client' => Http::response([
                'token' => 'rotated-token',
                'status' => 'success',
            ], 200),
        ]);

        $supplier = $this->makeSupplierUser();
        $locations = $this->makeTransExpressCourierLocations();
        $account = $this->makeTransExpressAccount($supplier, $locations, 'old-token', 'conn@example.com');

        $result = app(CourierConnectionService::class)->testAccount($account);

        $this->assertTrue($result->successful);
        $this->assertStringNotContainsString('rotated-token', $result->message);
        $this->assertStringNotContainsString('password', strtolower($result->message));
        $this->assertSame('rotated-token', $account->fresh()->getCredentials()['token']);
    }

    public function test_credential_schema_exposes_email_password_not_token_or_url(): void
    {
        $schema = app(CourierCredentialSchemaRegistry::class)->forCode('TRANSEXPRESS');
        $keys = array_map(static fn ($field) => $field->key, $schema->fields());

        $this->assertSame(['email', 'password'], $keys);
        $this->assertNotContains('base_url', $keys);
        $this->assertNotContains('token', $keys);
    }

    public function test_auth_service_requires_success_status_and_token(): void
    {
        Http::fake([
            '*/api/login/client' => Http::response([
                'token' => 'tok',
                'status' => 'success',
            ], 200),
        ]);

        $token = app(TransExpressAuthService::class)->login('a@example.com', 'secret');
        $this->assertSame('tok', $token);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/login/client')
                && ($request->data()['email'] ?? null) === 'a@example.com'
                && ! $request->hasHeader('Authorization');
        });
    }

    /**
     * @return array{
     *     courier: Courier,
     *     service: CourierService,
     *     state: CourierState,
     *     destinationCity: CourierCity
     * }
     */
    private function makeTransExpressCourierLocations(): array
    {
        $courier = Courier::query()->updateOrCreate(
            ['code' => 'TRANSEXPRESS'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'TransExpress',
                'is_active' => true,
            ]
        );

        $service = CourierService::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'code' => 'STANDARD'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Standard',
                'external_service_id' => 'transexpress-standard',
                'is_active' => true,
            ]
        );

        $state = CourierState::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => '5'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Colombo',
                'is_active' => true,
            ]
        );

        $destinationCity = CourierCity::query()->updateOrCreate(
            ['courier_id' => $courier->id, 'external_id' => '864'],
            [
                'uuid' => (string) Str::uuid(),
                'courier_state_id' => $state->id,
                'name' => 'Sample City',
                'city_name' => 'Sample City',
                'district_name' => 'Colombo',
                'external_city_code' => '864',
                'external_district_code' => '5',
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
    private function makeTransExpressAccount(
        $supplier,
        array $locations,
        string $token,
        string $email,
    ): SupplierCourierAccount {
        return SupplierCourierAccount::query()->create([
            'supplier_id' => $supplier->id,
            'courier_id' => $locations['courier']->id,
            'account_label' => 'TransExpress '.$email,
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'email' => $email,
                'password' => 'secret-password',
                'token' => $token,
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
    private function makeBookableTransExpressOrder(
        float $totalWeight = 1.5,
        float $itemsSubtotal = 500,
        string $productName = 'Product Snapshot',
    ): array {
        $locations = $this->makeTransExpressCourierLocations();
        $supplier = $this->makeSupplierUser();
        $account = $this->makeTransExpressAccount($supplier, $locations, 'valid-token', 'book@example.com');
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
                'first_kg_fee' => 650,
                'additional_kg_fee' => 150,
                'is_active' => true,
            ]
        );
    }

    private function makeAdminActor(): User
    {
        return User::query()->create([
            'uuid' => UuidService::generate(),
            'email' => 'admin-te-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);
    }
}
