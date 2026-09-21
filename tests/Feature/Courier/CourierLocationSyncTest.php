<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Courier\CourierLocationSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CourierLocationSyncTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private CourierLocationSyncService $sync;

    /** @var array{base_url: string, token: string, tenant: string} */
    private array $credentials;

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
            'services.curfox.token' => 'test-token-not-real',
            'services.curfox.tenant' => 'royalexpress',
            'feeder.curfox.base_url' => 'https://v2-dashboards.api.curfox.com',
            'feeder.curfox.token' => 'test-token-not-real',
            'feeder.curfox.tenant' => 'royalexpress',
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        \Illuminate\Support\Facades\DB::beginTransaction();

        $this->sync = app(CourierLocationSyncService::class);
        $this->credentials = [
            'base_url' => 'https://v2-dashboards.api.curfox.com',
            'token' => 'test-token-not-real',
            'tenant' => 'royalexpress',
        ];
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_state_import_creates_courier_states(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-0001', 'Colombo', true),
                $this->statePayload(2, 'ST-0002', 'Gampaha', true),
            ],
            []
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(2, $result->statesCreated);
        $this->assertSame(0, $result->statesUpdated);
        $this->assertSame(0, $result->errors);
        $this->assertSame(2, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'external_ref_no' => 'ST-0001',
            'name' => 'Colombo',
            'is_active' => 1,
        ]);
    }

    public function test_city_import_creates_courier_cities_from_city_api(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-0001', 'Colombo', true),
            ],
            [
                $this->cityPayload(260, 'CT-0260', 'Akarawita', '10732', 1, true),
                $this->cityPayload(261, 'CT-0261', 'Athurugiriya', '10150', 1, true),
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(2, $result->citiesCreated);
        $this->assertSame(2, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '260',
            'external_ref_no' => 'CT-0260',
            'name' => 'Akarawita',
            'postal_code' => '10732',
            'city_name' => 'Akarawita',
            'is_active' => 1,
        ]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/public/merchant/city'));
    }

    public function test_city_references_locally_resolved_state(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(10, 'ST-10', 'Colombo District', true),
            ],
            [
                $this->cityPayload(100, 'CT-100', 'Colombo', '00100', 10, true),
            ]
        );

        $this->sync->sync($courier, $this->credentials);

        $state = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '10')
            ->firstOrFail();

        $city = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '100')
            ->firstOrFail();

        $this->assertSame($state->id, $city->courier_state_id);
        $this->assertNotSame(10, $city->courier_state_id);
        $this->assertSame('Colombo District', $city->district_name);
        $this->assertSame('10', $city->external_district_code);
    }

    public function test_external_ids_are_preserved_from_api(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-0001', 'Colombo', true),
            ],
            [
                $this->cityPayload(260, 'CT-0260', 'Akarawita', '10732', 1, true),
            ]
        );

        $this->sync->sync($courier, $this->credentials);

        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '1',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '260',
        ]);
    }

    public function test_import_is_idempotent_across_runs(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-0001', 'Colombo', true),
            ],
            [
                $this->cityPayload(260, 'CT-0260', 'Akarawita', '10732', 1, true),
            ]
        );

        $first = $this->sync->sync($courier, $this->credentials);
        $second = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $first->statesCreated);
        $this->assertSame(1, $first->citiesCreated);
        $this->assertSame(0, $second->statesCreated);
        $this->assertSame(1, $second->statesUpdated);
        $this->assertSame(0, $second->citiesCreated);
        $this->assertSame(1, $second->citiesUpdated);
        $this->assertSame(1, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(1, CourierCity::query()->where('courier_id', $courier->id)->count());
    }

    public function test_changed_api_data_updates_existing_rows(): void
    {
        $courier = $this->makeRoyalCourier();

        CourierState::query()->create([
            'courier_id' => $courier->id,
            'external_id' => '5',
            'external_ref_no' => 'OLD',
            'name' => 'Old Name',
            'is_active' => true,
        ]);

        $state = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '5')
            ->firstOrFail();

        CourierCity::query()->create([
            'courier_id' => $courier->id,
            'courier_state_id' => $state->id,
            'external_id' => '70',
            'external_ref_no' => 'OLD-CITY',
            'name' => 'Old City',
            'postal_code' => '000',
            'district_name' => 'Old Name',
            'city_name' => 'Old City',
            'external_city_code' => '70',
            'is_active' => true,
        ]);

        $this->fakeLocationApis(
            [
                $this->statePayload(5, 'NEW-REF', 'Updated State', false),
            ],
            [
                $this->cityPayload(70, 'NEW-70', 'Negombo', '11500', 5, false),
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(0, $result->statesCreated);
        $this->assertSame(1, $result->statesUpdated);
        $this->assertSame(0, $result->citiesCreated);
        $this->assertSame(1, $result->citiesUpdated);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '5',
            'external_ref_no' => 'NEW-REF',
            'name' => 'Updated State',
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '70',
            'name' => 'Negombo',
            'postal_code' => '11500',
            'external_ref_no' => 'NEW-70',
            'district_name' => 'Updated State',
            'is_active' => 0,
        ]);
    }

    public function test_state_rename_without_city_payload_cascades_district_name(): void
    {
        $courier = $this->makeRoyalCourier();

        $state = CourierState::query()->updateOrCreate(
            [
                'courier_id' => $courier->id,
                'external_id' => '2',
            ],
            [
                'external_ref_no' => 'ST-0002',
                'name' => 'Gampaha',
                'is_active' => true,
            ]
        );

        $city = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('courier_state_id', $state->id)
            ->first();

        if ($city === null) {
            $city = CourierCity::query()->create([
                'courier_id' => $courier->id,
                'courier_state_id' => $state->id,
                'external_id' => 'cascade-279',
                'external_ref_no' => 'CT-C279',
                'name' => 'Bopeththa',
                'city_name' => 'Bopeththa',
                'district_name' => 'Stale District',
                'external_city_code' => 'cascade-279',
                'external_district_code' => '2',
                'is_active' => true,
            ]);
        } else {
            $city->district_name = 'Stale District';
            $city->save();
        }

        $this->fakeLocationApis(
            [
                $this->statePayload(2, 'ST-0002', 'Ratnapura', true),
            ],
            []
        );

        $result = $this->sync->syncStatesOnly($courier, $this->credentials);

        $this->assertSame(0, $result->errors);
        $this->assertDatabaseHas('courier_states', [
            'id' => $state->id,
            'external_id' => '2',
            'name' => 'Ratnapura',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'id' => $city->id,
            'district_name' => 'Ratnapura',
            'external_district_code' => '2',
        ]);
    }

    public function test_respects_city_is_active_without_deleting(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-1', 'Western', true),
            ],
            [
                $this->cityPayload(10, 'C10', 'Active City', null, 1, true),
                $this->cityPayload(11, 'C11', 'Inactive City', null, 1, false),
            ]
        );

        $this->sync->sync($courier, $this->credentials);

        $this->assertSame(2, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '11',
            'name' => 'Inactive City',
            'is_active' => 0,
        ]);
    }

    public function test_locations_remain_scoped_to_royal_courier(): void
    {
        $royal = $this->makeRoyalCourier();
        $other = Courier::query()->updateOrCreate(
            ['code' => 'OTHER'],
            ['name' => 'Other Courier', 'is_active' => true]
        );

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-0001', 'Colombo', true),
            ],
            [
                $this->cityPayload(260, 'CT-0260', 'Akarawita', '10732', 1, true),
            ]
        );

        $this->sync->sync($royal, $this->credentials);

        $this->assertSame(1, CourierState::query()->where('courier_id', $royal->id)->count());
        $this->assertSame(1, CourierCity::query()->where('courier_id', $royal->id)->count());
        $this->assertSame(0, CourierState::query()->where('courier_id', $other->id)->count());
        $this->assertSame(0, CourierCity::query()->where('courier_id', $other->id)->count());
    }

    public function test_multi_courier_external_id_isolation(): void
    {
        $royal = $this->makeRoyalCourier();
        $other = Courier::query()->updateOrCreate(
            ['code' => 'OTHER'],
            ['name' => 'Other Courier', 'is_active' => true]
        );

        CourierState::query()->create([
            'courier_id' => $other->id,
            'external_id' => '1',
            'external_ref_no' => 'OTHER-ST-1',
            'name' => 'Other State One',
            'is_active' => true,
        ]);

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-0001', 'Royal State One', true),
            ],
            []
        );

        $this->sync->sync($royal, $this->credentials);

        $this->assertSame(1, CourierState::query()->where('courier_id', $royal->id)->where('external_id', '1')->count());
        $this->assertSame(1, CourierState::query()->where('courier_id', $other->id)->where('external_id', '1')->count());
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $royal->id,
            'external_id' => '1',
            'name' => 'Royal State One',
        ]);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $other->id,
            'external_id' => '1',
            'name' => 'Other State One',
        ]);
    }

    public function test_city_with_unknown_state_is_not_saved(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-1', 'Known State', true),
            ],
            [
                $this->cityPayload(50, 'C50', 'Orphan City', null, 999, true),
                $this->cityPayload(51, 'C51', 'Valid City', null, 1, true),
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $result->citiesSkipped);
        $this->assertSame(1, $result->citiesCreated);
        $this->assertFalse(
            CourierCity::query()->where('courier_id', $courier->id)->where('external_id', '50')->exists()
        );
        $this->assertTrue(
            CourierCity::query()->where('courier_id', $courier->id)->where('external_id', '51')->exists()
        );
    }

    public function test_pagination_processes_multiple_city_pages(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/api/public/merchant/state')) {
                return Http::response([
                    'data' => [
                        $this->statePayload(1, 'ST-1', 'Western', true),
                    ],
                ], 200);
            }

            if (str_contains($url, '/api/public/merchant/city')) {
                // Force pagination fallback: noPagination still reports last_page > 1.
                if (str_contains($url, 'noPagination')) {
                    return Http::response([
                        'data' => [
                            $this->cityPayload(1, 'C1', 'City Page One', null, 1, true),
                        ],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 2,
                            'per_page' => 1,
                            'total' => 2,
                        ],
                    ], 200);
                }

                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                if ($page === 1) {
                    return Http::response([
                        'data' => [
                            $this->cityPayload(1, 'C1', 'City Page One', null, 1, true),
                        ],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 2,
                            'per_page' => 1,
                            'total' => 2,
                        ],
                    ], 200);
                }

                return Http::response([
                    'data' => [
                        $this->cityPayload(2, 'C2', 'City Page Two', null, 1, true),
                    ],
                    'meta' => [
                        'current_page' => 2,
                        'last_page' => 2,
                        'per_page' => 1,
                        'total' => 2,
                    ],
                ], 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(0, $result->errors);
        $this->assertSame(2, $result->citiesCreated);
        $this->assertSame(2, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'name' => 'City Page One',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '2',
            'name' => 'City Page Two',
        ]);
    }

    public function test_api_failure_does_not_write_location_data(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake([
            '*/api/public/merchant/state*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertGreaterThan(0, $result->errors);
        $this->assertSame(0, $result->statesCreated);
        $this->assertSame(0, $result->citiesCreated);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(0, CourierCity::query()->where('courier_id', $courier->id)->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/public/merchant/city'));
    }

    public function test_malformed_response_does_not_write_location_data(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake([
            '*/api/public/merchant/state*' => Http::response('not-json-at-all', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $result->errors);
        $this->assertStringContainsString('invalid JSON', $result->errorMessages[0]);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
    }

    public function test_missing_data_key_is_an_error(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake([
            '*/api/public/merchant/state*' => Http::response([
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $result->errors);
        $this->assertStringContainsString('missing required "data" key', $result->errorMessages[0]);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
    }

    public function test_deleted_at_state_is_imported_as_inactive(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                [
                    'id' => 9,
                    'ref_no' => 'ST-9',
                    'name' => 'Retired State',
                    'deleted_at' => '2026-01-01T00:00:00.000000Z',
                ],
            ],
            []
        );

        $this->sync->sync($courier, $this->credentials);

        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '9',
            'is_active' => 0,
        ]);
    }

    public function test_sync_uses_both_state_and_city_endpoints(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-1', 'Western', true),
            ],
            [
                $this->cityPayload(1, 'C1', 'City One', null, 1, true),
            ]
        );

        $this->sync->sync($courier, $this->credentials);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/public/merchant/state'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/public/merchant/city'));
        $this->assertSame(1, CourierCity::query()->where('courier_id', $courier->id)->count());
    }

    public function test_sync_does_not_require_supplier_courier_account(): void
    {
        $courier = $this->makeRoyalCourier();
        $accountsBefore = SupplierCourierAccount::query()->where('courier_id', $courier->id)->count();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-1', 'Western', true),
            ],
            [
                $this->cityPayload(1, 'C1', 'City One', null, 1, true),
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->statesCreated);
        $this->assertSame(1, $result->citiesCreated);
        $this->assertSame(
            $accountsBefore,
            SupplierCourierAccount::query()->where('courier_id', $courier->id)->count()
        );
    }

    public function test_sync_command_uses_config_credentials_not_supplier_accounts(): void
    {
        $courier = $this->makeRoyalCourier();
        $accountsBefore = SupplierCourierAccount::query()->where('courier_id', $courier->id)->count();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-1', 'Western', true),
            ],
            []
        );

        $this->artisan('courier:sync-locations', [
            'courier' => 'ROYAL',
        ])
            ->expectsOutputToContain('ROYAL location sync completed.')
            ->expectsOutputToContain('not supplier accounts')
            ->expectsOutputToContain('Created: 1')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/api/public/merchant/state')
                && $request->hasHeader('Authorization', 'Bearer test-token-not-real')
                && $request->hasHeader('X-tenant', 'royalexpress')
                && $request->hasHeader('Accept', 'application/json');
        });

        $this->assertSame(
            $accountsBefore,
            SupplierCourierAccount::query()->where('courier_id', $courier->id)->count()
        );
    }

    public function test_http_401_error_includes_status_and_body(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake([
            '*/api/public/merchant/state*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $result->errors);
        $this->assertNotEmpty($result->errorMessages);
        $message = $result->errorMessages[0];
        $this->assertStringContainsString('HTTP 401', $message);
        $this->assertStringContainsString('http_401_unauthorized', $message);
        $this->assertStringContainsString('phase=authentication', $message);
        $this->assertStringContainsString('Unauthorized', $message);
        $this->assertStringContainsString('/api/public/merchant/state', $message);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
    }

    public function test_http_403_error_includes_status_and_body(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake([
            '*/api/public/merchant/state*' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $result->errors);
        $message = $result->errorMessages[0];
        $this->assertStringContainsString('HTTP 403', $message);
        $this->assertStringContainsString('http_403_forbidden', $message);
        $this->assertStringContainsString('Forbidden', $message);
        $this->assertSame(0, CourierCity::query()->where('courier_id', $courier->id)->count());
    }

    public function test_connection_exception_exposes_underlying_message(): void
    {
        $courier = $this->makeRoyalCourier();

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 60: SSL certificate problem: unable to get local issuer certificate'
            );
        });

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(1, $result->errors);
        $message = $result->errorMessages[0];
        $this->assertStringContainsString('phase=connection', $message);
        $this->assertStringContainsString('ssl_certificate', $message);
        $this->assertStringContainsString('cURL error 60', $message);
        $this->assertStringContainsString('ConnectionException', $message);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
    }

    public function test_token_override_is_used_and_not_persisted_to_config_source(): void
    {
        $this->makeRoyalCourier();

        $this->fakeLocationApis(
            [
                $this->statePayload(1, 'ST-1', 'Western', true),
            ],
            []
        );

        $this->artisan('courier:sync-locations', [
            'courier' => 'ROYAL',
            '--token' => 'override-token-value',
        ])
            ->expectsOutputToContain('temporary --token override')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer override-token-value'));
        $this->assertSame('test-token-not-real', config('services.curfox.token'));
    }

    public function test_bearer_prefix_is_normalized_to_single_bearer(): void
    {
        $courier = $this->makeRoyalCourier();

        $this->fakeLocationApis([], []);

        $this->sync->sync($courier, [
            'base_url' => 'https://v2-dashboards.api.curfox.com',
            'token' => 'Bearer already-prefixed-token',
            'tenant' => 'royalexpress',
        ]);

        Http::assertSent(function (Request $request) {
            $header = $request->header('Authorization')[0] ?? '';

            return $header === 'Bearer already-prefixed-token'
                && ! str_contains($header, 'Bearer Bearer');
        });
    }

    public function test_command_token_override_normalizes_bearer_prefix(): void
    {
        $this->makeRoyalCourier();

        $this->fakeLocationApis([], []);

        $this->artisan('courier:sync-locations', [
            'courier' => 'ROYAL',
            '--token' => 'Bearer cli-override-token',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer cli-override-token'));
    }

    public function test_command_does_not_leak_token_in_error_output(): void
    {
        $this->makeRoyalCourier();
        $secret = 'super-secret-token-value-xyz';

        config([
            'services.curfox.token' => $secret,
            'feeder.curfox.token' => $secret,
        ]);

        Http::fake([
            '*/api/public/merchant/state*' => Http::response([
                'message' => 'Denied Bearer '.$secret,
            ], 401),
        ]);

        $this->artisan('courier:sync-locations', [
            'courier' => 'ROYAL',
        ])
            ->expectsOutputToContain('HTTP 401')
            ->doesntExpectOutputToContain($secret)
            ->assertFailed();
    }

    public function test_normalize_bearer_token_helper(): void
    {
        $this->assertSame('abc', \Feeder\Core\Services\Courier\Curfox\CurfoxApiClient::normalizeBearerToken('abc'));
        $this->assertSame('abc', \Feeder\Core\Services\Courier\Curfox\CurfoxApiClient::normalizeBearerToken('Bearer abc'));
        $this->assertSame('abc', \Feeder\Core\Services\Courier\Curfox\CurfoxApiClient::normalizeBearerToken('bearer abc'));
        $this->assertSame('', \Feeder\Core\Services\Courier\Curfox\CurfoxApiClient::normalizeBearerToken('   '));
    }

    public function test_credentials_from_config_helper(): void
    {
        $credentials = $this->sync->credentialsFromConfig();

        $this->assertSame('https://v2-dashboards.api.curfox.com', $credentials['base_url']);
        $this->assertSame('test-token-not-real', $credentials['token']);
        $this->assertSame('royalexpress', $credentials['tenant']);
    }

    private function makeRoyalCourier(): Courier
    {
        return Courier::query()->updateOrCreate(
            ['code' => 'ROYAL'],
            [
                'name' => 'Royal Express',
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $states
     * @param  list<array<string, mixed>>  $cities
     */
    private function fakeLocationApis(array $states, array $cities): void
    {
        Http::fake(function (Request $request) use ($states, $cities) {
            $url = $request->url();

            if (str_contains($url, '/api/public/merchant/state')) {
                return Http::response(['data' => $states], 200);
            }

            if (str_contains($url, '/api/public/merchant/city')) {
                return Http::response(['data' => $cities], 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(
        int $id,
        string $refNo,
        string $name,
        bool $isActive,
    ): array {
        return [
            'id' => $id,
            'ref_no' => $refNo,
            'name' => $name,
            'is_active' => $isActive,
            'deleted_at' => null,
            'country_id' => 1,
            'has_child' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cityPayload(
        int $id,
        string $refNo,
        string $name,
        ?string $postalCode,
        int $stateId,
        bool $isActive,
    ): array {
        return [
            'id' => $id,
            'ref_no' => $refNo,
            'name' => $name,
            'postal_code' => $postalCode,
            'state_id' => $stateId,
            'country_id' => 1,
            'is_active' => $isActive,
        ];
    }
}
