<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Services\Courier\CourierLocationSyncService;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class FardarLocationImportTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private CourierLocationSyncService $sync;

    /** @var list<string> */
    private array $tempFiles = [];

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
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        \Illuminate\Support\Facades\DB::beginTransaction();

        $this->sync = app(CourierLocationSyncService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_import_creates_fardar_district_and_city(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
        ]);

        $result = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->statesCreated);
        $this->assertSame(1, $result->citiesCreated);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '5',
            'name' => 'Colombo',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'name' => 'Akarawita',
            'city_name' => 'Akarawita',
            'district_name' => 'Colombo',
            'external_city_code' => '1',
            'external_district_code' => '5',
            'is_active' => 1,
        ]);

        $state = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '5')
            ->first();
        $this->assertNotNull($state);

        $city = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '1')
            ->first();
        $this->assertNotNull($city);
        $this->assertSame($state->id, $city->courier_state_id);
    }

    public function test_reimport_does_not_create_duplicates(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
            ['5', 'Colombo', '2', 'Akuregoda'],
        ]);

        $first = $this->sync->importFromCsv($courier, $path);
        $second = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(0, $first->errors);
        $this->assertSame(0, $second->errors);
        $this->assertSame(1, $first->statesCreated);
        $this->assertSame(2, $first->citiesCreated);
        $this->assertSame(0, $second->statesCreated);
        $this->assertSame(1, $second->statesUpdated);
        $this->assertSame(0, $second->citiesCreated);
        $this->assertSame(2, $second->citiesUpdated);
        $this->assertSame(1, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(2, CourierCity::query()->where('courier_id', $courier->id)->count());
    }

    public function test_changed_district_and_city_names_are_updated(): void
    {
        $courier = $this->makeFardarCourier();
        $initial = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
        ]);
        $this->sync->importFromCsv($courier, $initial);

        $updated = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo Metro', '1', 'Akarawita North'],
        ]);
        $result = $this->sync->importFromCsv($courier, $updated);

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->statesUpdated);
        $this->assertSame(1, $result->citiesUpdated);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '5',
            'name' => 'Colombo Metro',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'name' => 'Akarawita North',
            'city_name' => 'Akarawita North',
            'district_name' => 'Colombo Metro',
            'external_city_code' => '1',
            'external_district_code' => '5',
        ]);
    }

    public function test_city_parent_district_relationship_is_updated(): void
    {
        $courier = $this->makeFardarCourier();
        $initial = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
            ['10', 'Gampaha', '99', 'Placeholder'],
        ]);
        $this->sync->importFromCsv($courier, $initial);

        $moved = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['10', 'Gampaha', '1', 'Akarawita'],
        ]);
        $result = $this->sync->importFromCsv($courier, $moved);

        $this->assertSame(0, $result->errors);

        $gampaha = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '10')
            ->first();
        $this->assertNotNull($gampaha);

        $city = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '1')
            ->first();
        $this->assertNotNull($city);
        $this->assertSame($gampaha->id, $city->courier_state_id);
        $this->assertSame('Gampaha', $city->district_name);
        $this->assertSame('10', $city->external_district_code);
    }

    public function test_external_ids_are_preserved_as_strings(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['05', 'Colombo', '01', 'Akarawita'],
        ]);

        $this->sync->importFromCsv($courier, $path);

        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '5',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'external_city_code' => '1',
            'external_district_code' => '5',
        ]);
    }

    public function test_malformed_rows_are_reported_and_not_imported(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
            ['', 'Colombo', '2', 'Akuregoda'],
            ['5', '', '3', 'Angoda'],
            ['5', 'Colombo', '', 'Athurugiriya'],
            ['5', 'Colombo', '4', ''],
            ['abc', 'Colombo', '5', 'Avissawella'],
            ['5', 'Colombo', 'xyz', 'Badulla'],
            ['', '', '', ''],
        ]);

        $result = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(1, $result->statesCreated);
        $this->assertSame(1, $result->citiesCreated);
        $this->assertGreaterThanOrEqual(1, $result->rowsSkipped);
        $this->assertSame(6, $result->errors);
        $this->assertSame(1, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(1, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertFalse(
            CourierCity::query()->where('courier_id', $courier->id)->where('external_id', '2')->exists()
        );
    }

    public function test_duplicate_csv_city_rows_do_not_create_duplicates(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
            ['5', 'Colombo', '1', 'Akarawita Updated'],
        ]);

        $result = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->rowsSkipped);
        $this->assertSame(1, CourierCity::query()->where('courier_id', $courier->id)->count());
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'name' => 'Akarawita Updated',
        ]);
    }

    public function test_import_does_not_affect_royal_or_transexpress_locations(): void
    {
        $fardar = $this->makeFardarCourier();
        $royal = Courier::query()->updateOrCreate(
            ['code' => 'ROYAL'],
            ['name' => 'Royal Express', 'is_active' => true]
        );
        $transexpress = Courier::query()->updateOrCreate(
            ['code' => 'TRANSEXPRESS'],
            ['name' => 'TransExpress', 'is_active' => true]
        );

        $royalState = CourierState::query()->updateOrCreate(
            [
                'courier_id' => $royal->id,
                'external_id' => 'iso-royal-5',
            ],
            [
                'name' => 'Royal Isolation State',
                'is_active' => true,
            ]
        );
        CourierCity::query()->updateOrCreate(
            [
                'courier_id' => $royal->id,
                'external_id' => 'iso-royal-1',
            ],
            [
                'courier_state_id' => $royalState->id,
                'name' => 'Royal Isolation City',
                'city_name' => 'Royal Isolation City',
                'district_name' => 'Royal Isolation State',
                'external_city_code' => 'iso-royal-1',
                'external_district_code' => 'iso-royal-5',
                'is_active' => true,
            ]
        );

        $txState = CourierState::query()->updateOrCreate(
            [
                'courier_id' => $transexpress->id,
                'external_id' => 'iso-tx-5',
            ],
            [
                'name' => 'TX Isolation State',
                'is_active' => true,
            ]
        );
        CourierCity::query()->updateOrCreate(
            [
                'courier_id' => $transexpress->id,
                'external_id' => 'iso-tx-1',
            ],
            [
                'courier_state_id' => $txState->id,
                'name' => 'TX Isolation City',
                'city_name' => 'TX Isolation City',
                'district_name' => 'TX Isolation State',
                'external_city_code' => 'iso-tx-1',
                'external_district_code' => 'iso-tx-5',
                'is_active' => true,
            ]
        );

        $royalStatesBefore = CourierState::query()->where('courier_id', $royal->id)->count();
        $royalCitiesBefore = CourierCity::query()->where('courier_id', $royal->id)->count();
        $txStatesBefore = CourierState::query()->where('courier_id', $transexpress->id)->count();
        $txCitiesBefore = CourierCity::query()->where('courier_id', $transexpress->id)->count();

        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
        ]);
        $this->sync->importFromCsv($fardar, $path);

        $this->assertSame(1, CourierState::query()->where('courier_id', $fardar->id)->count());
        $this->assertSame(1, CourierCity::query()->where('courier_id', $fardar->id)->count());
        $this->assertSame($royalStatesBefore, CourierState::query()->where('courier_id', $royal->id)->count());
        $this->assertSame($royalCitiesBefore, CourierCity::query()->where('courier_id', $royal->id)->count());
        $this->assertSame($txStatesBefore, CourierState::query()->where('courier_id', $transexpress->id)->count());
        $this->assertSame($txCitiesBefore, CourierCity::query()->where('courier_id', $transexpress->id)->count());
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $royal->id,
            'external_id' => 'iso-royal-5',
            'name' => 'Royal Isolation State',
        ]);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $transexpress->id,
            'external_id' => 'iso-tx-5',
            'name' => 'TX Isolation State',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $royal->id,
            'external_id' => 'iso-royal-1',
            'name' => 'Royal Isolation City',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $transexpress->id,
            'external_id' => 'iso-tx-1',
            'name' => 'TX Isolation City',
        ]);
    }

    public function test_import_command_for_fardar(): void
    {
        $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'district name', 'city id', 'city name'],
            ['5', 'Colombo', '1', 'Akarawita'],
        ]);

        $this->artisan('courier:import-locations', [
            'courier' => 'FARDAR',
            'file' => $path,
        ])
            ->expectsOutputToContain('Fardar location import completed.')
            ->expectsOutputToContain('Districts created: 1')
            ->expectsOutputToContain('Cities created: 1')
            ->assertSuccessful();
    }

    public function test_missing_file_fails(): void
    {
        $this->makeFardarCourier();

        $this->artisan('courier:import-locations', [
            'courier' => 'FARDAR',
            'file' => 'D:\\nonexistent\\fardar-locations.csv',
        ])->assertFailed();
    }

    public function test_missing_headers_fail(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['district id', 'city id', 'city name'],
            ['5', '1', 'Akarawita'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required header');

        $this->sync->importFromCsv($courier, $path);
    }

    public function test_api_sync_is_rejected_for_fardar(): void
    {
        $courier = $this->makeFardarCourier();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('courier:import-locations');

        $this->sync->sync($courier);
    }

    private function makeFardarCourier(): Courier
    {
        return Courier::query()->updateOrCreate(
            ['code' => 'FARDAR'],
            [
                'name' => 'Fardar Express Domestic',
                'is_active' => true,
            ]
        );
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fardar_csv_');
        $this->assertNotFalse($path);

        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
        $this->tempFiles[] = $path;

        return $path;
    }
}
