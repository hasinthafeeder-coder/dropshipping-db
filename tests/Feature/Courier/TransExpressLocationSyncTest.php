<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Services\Courier\CourierLocationSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class TransExpressLocationSyncTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private CourierLocationSyncService $sync;

    /** @var array{base_url: string} */
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
            'services.transexpress.base_url' => 'https://portal.transexpress.lk/api',
            'feeder.transexpress.base_url' => 'https://portal.transexpress.lk/api',
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        \Illuminate\Support\Facades\DB::beginTransaction();

        $this->sync = app(CourierLocationSyncService::class);
        $this->credentials = [
            'base_url' => 'https://portal.transexpress.lk/api',
        ];
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_province_sync_creates_district_states_from_district_api(): void
    {
        $courier = $this->makeTransExpressCourier();

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 1, 'text' => 'Western'],
            ],
            districtsByProvince: [
                '1' => [
                    ['id' => 5, 'text' => 'Colombo'],
                ],
            ],
            citiesByDistrict: [
                '5' => [],
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->statesCreated);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '5',
            'external_ref_no' => '1',
            'name' => 'Colombo',
            'is_active' => 1,
        ]);
        // Provinces are not stored as courier_states (district IDs collide; UI needs districts).
        $this->assertFalse(
            CourierState::query()
                ->where('courier_id', $courier->id)
                ->where('external_id', '1')
                ->where('name', 'Western')
                ->exists()
        );
    }

    public function test_district_sync_associates_districts_with_province_id(): void
    {
        $courier = $this->makeTransExpressCourier();

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 7, 'text' => 'Uva'],
            ],
            districtsByProvince: [
                '7' => [
                    ['id' => 3, 'text' => 'Badulla'],
                    ['id' => 18, 'text' => 'Monaragala'],
                ],
            ],
            citiesByDistrict: [
                '3' => [],
                '18' => [],
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(0, $result->errors);
        $this->assertSame(2, $result->statesCreated);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '3',
            'external_ref_no' => '7',
            'name' => 'Badulla',
        ]);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '18',
            'external_ref_no' => '7',
            'name' => 'Monaragala',
        ]);
    }

    public function test_city_sync_stores_external_city_id_and_district_relationship(): void
    {
        $courier = $this->makeTransExpressCourier();

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 1, 'text' => 'Western'],
            ],
            districtsByProvince: [
                '1' => [
                    ['id' => 5, 'text' => 'Colombo'],
                ],
            ],
            citiesByDistrict: [
                '5' => [
                    ['id' => 864, 'text' => 'Colombo 01'],
                    ['id' => 865, 'text' => 'Colombo 02'],
                ],
            ]
        );

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertSame(0, $result->errors);
        $this->assertSame(2, $result->citiesCreated);

        $state = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '5')
            ->firstOrFail();

        $city = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '864')
            ->firstOrFail();

        $this->assertSame($state->id, $city->courier_state_id);
        $this->assertSame('Colombo 01', $city->name);
        $this->assertSame('Colombo 01', $city->city_name);
        $this->assertSame('Colombo', $city->district_name);
        $this->assertSame('864', $city->external_city_code);
        $this->assertSame('5', $city->external_district_code);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/cities?district_id=5')
            || str_contains($request->url(), '/cities?district_id%3D5')
            || (str_contains($request->url(), '/cities') && str_contains($request->url(), 'district_id=5')));
    }

    public function test_sync_is_idempotent_across_runs(): void
    {
        $courier = $this->makeTransExpressCourier();

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 1, 'text' => 'Western'],
            ],
            districtsByProvince: [
                '1' => [
                    ['id' => 5, 'text' => 'Colombo'],
                ],
            ],
            citiesByDistrict: [
                '5' => [
                    ['id' => 864, 'text' => 'Colombo 01'],
                ],
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

    public function test_changed_names_update_existing_rows_by_external_id(): void
    {
        $courier = $this->makeTransExpressCourier();

        $state = CourierState::query()->create([
            'courier_id' => $courier->id,
            'external_id' => '5',
            'external_ref_no' => '1',
            'name' => 'Old District',
            'is_active' => true,
        ]);

        CourierCity::query()->create([
            'courier_id' => $courier->id,
            'courier_state_id' => $state->id,
            'external_id' => '864',
            'name' => 'Old City',
            'district_name' => 'Old District',
            'city_name' => 'Old City',
            'external_city_code' => '864',
            'external_district_code' => '5',
            'is_active' => true,
        ]);

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 1, 'text' => 'Western'],
            ],
            districtsByProvince: [
                '1' => [
                    ['id' => 5, 'text' => 'Colombo'],
                ],
            ],
            citiesByDistrict: [
                '5' => [
                    ['id' => 864, 'text' => 'Colombo 01'],
                ],
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
            'name' => 'Colombo',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '864',
            'name' => 'Colombo 01',
            'city_name' => 'Colombo 01',
            'district_name' => 'Colombo',
        ]);
    }

    public function test_api_failure_does_not_write_location_data(): void
    {
        $courier = $this->makeTransExpressCourier();

        Http::fake([
            '*/provinces*' => Http::response(['message' => 'Service Unavailable'], 503),
        ]);

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertGreaterThan(0, $result->errors);
        $this->assertSame(0, $result->statesCreated);
        $this->assertSame(0, $result->citiesCreated);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(0, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertStringContainsString('HTTP 503', $result->errorMessages[0]);
    }

    public function test_district_api_failure_does_not_persist_partial_data(): void
    {
        $courier = $this->makeTransExpressCourier();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/provinces')) {
                return Http::response([
                    ['id' => 1, 'text' => 'Western'],
                ], 200);
            }

            if (str_contains($url, '/districts')) {
                return Http::response(['message' => 'Upstream error'], 500);
            }

            return Http::response(['message' => 'Not found'], 404);
        });

        $result = $this->sync->sync($courier, $this->credentials);

        $this->assertGreaterThan(0, $result->errors);
        $this->assertSame(0, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(0, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertStringContainsString('districts', $result->errorMessages[0]);
    }

    public function test_does_not_invent_city_district_from_global_cities_endpoint(): void
    {
        $courier = $this->makeTransExpressCourier();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/provinces')) {
                return Http::response([
                    ['id' => 1, 'text' => 'Western'],
                ], 200);
            }

            if (str_contains($url, '/districts')) {
                return Http::response([
                    ['id' => 5, 'text' => 'Colombo'],
                ], 200);
            }

            if (str_contains($url, '/cities') && str_contains($url, 'district_id=')) {
                return Http::response([
                    ['id' => 864, 'text' => 'Colombo 01'],
                ], 200);
            }

            // Global /cities without district_id must never be used for relationships.
            if (str_contains($url, '/cities')) {
                return Http::response([
                    ['id' => 1, 'text' => 'Andiyagala'],
                    ['id' => 2, 'text' => 'Angamuwa'],
                ], 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });

        $this->sync->sync($courier, $this->credentials);

        $this->assertTrue(
            CourierCity::query()->where('courier_id', $courier->id)->where('external_id', '864')->exists()
        );
        $this->assertFalse(
            CourierCity::query()->where('courier_id', $courier->id)->where('external_id', '1')->exists()
        );

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/cities')
                && str_contains($request->url(), 'district_id=');
        });
        Http::assertNotSent(function (Request $request) {
            $url = $request->url();

            return str_contains($url, '/cities') && ! str_contains($url, 'district_id=');
        });
    }

    public function test_sync_command_for_transexpress(): void
    {
        $this->makeTransExpressCourier();

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 1, 'text' => 'Western'],
            ],
            districtsByProvince: [
                '1' => [
                    ['id' => 5, 'text' => 'Colombo'],
                ],
            ],
            citiesByDistrict: [
                '5' => [
                    ['id' => 864, 'text' => 'Colombo 01'],
                ],
            ]
        );

        $this->artisan('courier:sync-locations', [
            'courier' => 'TRANSEXPRESS',
        ])
            ->expectsOutputToContain('TRANSEXPRESS location sync completed.')
            ->expectsOutputToContain('no authentication required')
            ->expectsOutputToContain('Districts (courier_states):')
            ->expectsOutputToContain('Created: 1')
            ->assertSuccessful();
    }

    public function test_locations_remain_scoped_to_transexpress_courier(): void
    {
        $transexpress = $this->makeTransExpressCourier();
        $other = Courier::query()->updateOrCreate(
            ['code' => 'OTHER'],
            ['name' => 'Other Courier', 'is_active' => true]
        );

        $this->fakeTransExpressApis(
            provinces: [
                ['id' => 1, 'text' => 'Western'],
            ],
            districtsByProvince: [
                '1' => [
                    ['id' => 5, 'text' => 'Colombo'],
                ],
            ],
            citiesByDistrict: [
                '5' => [
                    ['id' => 864, 'text' => 'Colombo 01'],
                ],
            ]
        );

        $this->sync->sync($transexpress, $this->credentials);

        $this->assertSame(1, CourierState::query()->where('courier_id', $transexpress->id)->count());
        $this->assertSame(1, CourierCity::query()->where('courier_id', $transexpress->id)->count());
        $this->assertSame(0, CourierState::query()->where('courier_id', $other->id)->count());
        $this->assertSame(0, CourierCity::query()->where('courier_id', $other->id)->count());
    }

    private function makeTransExpressCourier(): Courier
    {
        return Courier::query()->updateOrCreate(
            ['code' => 'TRANSEXPRESS'],
            [
                'name' => 'TransExpress',
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  list<array{id: int, text: string}>  $provinces
     * @param  array<string, list<array{id: int, text: string}>>  $districtsByProvince
     * @param  array<string, list<array{id: int, text: string}>>  $citiesByDistrict
     */
    private function fakeTransExpressApis(
        array $provinces,
        array $districtsByProvince,
        array $citiesByDistrict,
    ): void {
        Http::fake(function (Request $request) use ($provinces, $districtsByProvince, $citiesByDistrict) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (str_contains($url, '/provinces')) {
                return Http::response($provinces, 200);
            }

            if (str_contains($url, '/districts')) {
                $provinceId = (string) ($query['province_id'] ?? '');

                return Http::response($districtsByProvince[$provinceId] ?? [], 200);
            }

            if (str_contains($url, '/cities')) {
                $districtId = (string) ($query['district_id'] ?? '');

                return Http::response($citiesByDistrict[$districtId] ?? [], 200);
            }

            return Http::response(['message' => 'Not found'], 404);
        });
    }
}
