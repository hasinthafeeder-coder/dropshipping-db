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

    public function test_district_mapping_shares_one_state_across_cities(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', '5 Kanuwa', '3', 'Outstation', '6', 'Galle'],
            ['2', '6 Kanuwa', '3', 'Outstation', '6', 'Galle'],
            ['3', 'Adadola', '3', 'Outstation', '6', 'Galle'],
        ]);

        $result = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(0, $result->errors);
        $this->assertSame(1, $result->statesCreated);
        $this->assertSame(3, $result->citiesCreated);
        $this->assertSame(1, CourierState::query()->where('courier_id', $courier->id)->count());
        $this->assertSame(3, CourierCity::query()->where('courier_id', $courier->id)->count());

        $state = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '6')
            ->first();
        $this->assertNotNull($state);
        $this->assertSame('Galle', $state->name);

        $cities = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->orderBy('external_id')
            ->get();
        $this->assertSame(['5 Kanuwa', '6 Kanuwa', 'Adadola'], $cities->pluck('name')->all());
        $this->assertTrue($cities->every(fn (CourierCity $city) => $city->courier_state_id === $state->id));
    }

    public function test_provider_ids_are_preserved(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['176', 'Warapitiya', '3', 'Outstation', '1', 'Ampara'],
        ]);

        $this->sync->importFromCsv($courier, $path);

        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'name' => 'Ampara',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '176',
            'name' => 'Warapitiya',
            'city_name' => 'Warapitiya',
            'district_name' => 'Ampara',
            'external_city_code' => '176',
            'external_district_code' => '1',
        ]);
    }

    public function test_multiple_districts_create_separate_states(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['176', 'Warapitiya', '3', 'Outstation', '1', 'Ampara'],
            ['1464', 'Mawela', '3', 'Outstation', '11', 'Kandy'],
            ['2830', 'Kelanimulla', '2', 'Suburbs', '5', 'Colombo'],
            ['5373', 'Uduthuththiripitiya', '3', 'Outstation', '7', 'Gampaha'],
            ['1', '5 Kanuwa', '3', 'Outstation', '6', 'Galle'],
        ]);

        $result = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(0, $result->errors);
        $this->assertSame(5, $result->statesCreated);
        $this->assertSame(5, $result->citiesCreated);

        $names = CourierState::query()
            ->where('courier_id', $courier->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame(['Ampara', 'Colombo', 'Galle', 'Gampaha', 'Kandy'], $names);
    }

    public function test_city_associated_by_district_id_not_name(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['176', 'Warapitiya', '3', 'Outstation', '1', 'Ampara'],
            ['6300', 'Galagedarah Homagama', '3', 'Outstation', '5', 'Colombo'],
        ]);

        $this->sync->importFromCsv($courier, $path);

        $ampara = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '1')
            ->first();
        $colombo = CourierState::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '5')
            ->first();
        $this->assertNotNull($ampara);
        $this->assertNotNull($colombo);

        $warapitiya = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '176')
            ->first();
        $homagama = CourierCity::query()
            ->where('courier_id', $courier->id)
            ->where('external_id', '6300')
            ->first();

        $this->assertNotNull($warapitiya);
        $this->assertNotNull($homagama);
        $this->assertSame($ampara->id, $warapitiya->courier_state_id);
        $this->assertSame($colombo->id, $homagama->courier_state_id);
    }

    public function test_reimport_does_not_create_duplicates(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', '5 Kanuwa', '3', 'Outstation', '6', 'Galle'],
            ['2', '6 Kanuwa', '3', 'Outstation', '6', 'Galle'],
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
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', 'Akarawita', '2', 'Suburbs', '5', 'Colombo'],
        ]);
        $this->sync->importFromCsv($courier, $initial);

        $updated = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', 'Akarawita North', '2', 'Suburbs', '5', 'Colombo Metro'],
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
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', 'Akarawita', '2', 'Suburbs', '5', 'Colombo'],
            ['99', 'Placeholder', '3', 'Outstation', '10', 'Gampaha'],
        ]);
        $this->sync->importFromCsv($courier, $initial);

        $moved = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', 'Akarawita', '3', 'Outstation', '10', 'Gampaha'],
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

    public function test_external_ids_normalize_leading_zeros(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['01', 'Akarawita', '02', 'Suburbs', '05', 'Colombo'],
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
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', 'Akarawita', '2', 'Suburbs', '5', 'Colombo'],
            ['2', 'Akuregoda', '2', 'Suburbs', '', 'Colombo'],
            ['3', 'Angoda', '2', 'Suburbs', '5', ''],
            ['', 'Athurugiriya', '2', 'Suburbs', '5', 'Colombo'],
            ['4', '', '2', 'Suburbs', '5', 'Colombo'],
            ['5', 'Avissawella', '2', 'Suburbs', 'abc', 'Colombo'],
            ['xyz', 'Badulla', '2', 'Suburbs', '5', 'Colombo'],
            ['', '', '', '', '', ''],
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
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', 'Akarawita', '2', 'Suburbs', '5', 'Colombo'],
            ['1', 'Akarawita Updated', '2', 'Suburbs', '5', 'Colombo'],
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

    public function test_import_does_not_affect_royal_or_transexpress_locations_with_similar_names(): void
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
                'external_id' => 'iso-royal-galle',
            ],
            [
                'name' => 'Galle',
                'is_active' => true,
            ]
        );
        CourierCity::query()->updateOrCreate(
            [
                'courier_id' => $royal->id,
                'external_id' => 'iso-royal-kanuwa',
            ],
            [
                'courier_state_id' => $royalState->id,
                'name' => '5 Kanuwa',
                'city_name' => '5 Kanuwa',
                'district_name' => 'Galle',
                'external_city_code' => 'iso-royal-kanuwa',
                'external_district_code' => 'iso-royal-galle',
                'is_active' => true,
            ]
        );

        $txState = CourierState::query()->updateOrCreate(
            [
                'courier_id' => $transexpress->id,
                'external_id' => 'iso-tx-galle',
            ],
            [
                'name' => 'Galle',
                'is_active' => true,
            ]
        );
        CourierCity::query()->updateOrCreate(
            [
                'courier_id' => $transexpress->id,
                'external_id' => 'iso-tx-kanuwa',
            ],
            [
                'courier_state_id' => $txState->id,
                'name' => '5 Kanuwa',
                'city_name' => '5 Kanuwa',
                'district_name' => 'Galle',
                'external_city_code' => 'iso-tx-kanuwa',
                'external_district_code' => 'iso-tx-galle',
                'is_active' => true,
            ]
        );

        $royalStatesBefore = CourierState::query()->where('courier_id', $royal->id)->count();
        $royalCitiesBefore = CourierCity::query()->where('courier_id', $royal->id)->count();
        $txStatesBefore = CourierState::query()->where('courier_id', $transexpress->id)->count();
        $txCitiesBefore = CourierCity::query()->where('courier_id', $transexpress->id)->count();

        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', '5 Kanuwa', '3', 'Outstation', '6', 'Galle'],
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
            'external_id' => 'iso-royal-galle',
            'name' => 'Galle',
        ]);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $transexpress->id,
            'external_id' => 'iso-tx-galle',
            'name' => 'Galle',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $royal->id,
            'external_id' => 'iso-royal-kanuwa',
            'name' => '5 Kanuwa',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $transexpress->id,
            'external_id' => 'iso-tx-kanuwa',
            'name' => '5 Kanuwa',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $fardar->id,
            'external_id' => '1',
            'name' => '5 Kanuwa',
            'external_district_code' => '6',
        ]);
    }

    public function test_csv_parsing_handles_quotes_utf8_whitespace_blank_lines_and_crlf(): void
    {
        $courier = $this->makeFardarCourier();

        $csv = "city_id,city_name,zone_id,zone name,district_id,district name\r\n"
            ."1,\"5 Kanuwa\",3,Outstation,6,Galle\r\n"
            ."\r\n"
            ."  2  ,  6 Kanuwa  ,  3  ,  Outstation  ,  6  ,  Galle  \r\n"
            ."3,කොළඹ උතුර,2,Suburbs,5,Colombo\r\n"
            ."3,කොළඹ උතුර,2,Suburbs,5,Colombo\r\n";

        $path = $this->writeRawCsv($csv);
        $result = $this->sync->importFromCsv($courier, $path);

        $this->assertSame(0, $result->errors);
        $this->assertSame(2, $result->rowsSkipped);
        $this->assertSame(2, $result->statesCreated);
        $this->assertSame(3, $result->citiesCreated);

        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '1',
            'name' => '5 Kanuwa',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '2',
            'name' => '6 Kanuwa',
        ]);
        $this->assertDatabaseHas('courier_cities', [
            'courier_id' => $courier->id,
            'external_id' => '3',
            'name' => 'කොළඹ උතුර',
        ]);
    }

    public function test_zones_are_not_persisted(): void
    {
        $courier = $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['2830', 'Kelanimulla', '2', 'Suburbs', '5', 'Colombo'],
            ['1', '5 Kanuwa', '3', 'Outstation', '6', 'Galle'],
        ]);

        $this->sync->importFromCsv($courier, $path);

        $this->assertDatabaseMissing('courier_states', [
            'courier_id' => $courier->id,
            'name' => 'Suburbs',
        ]);
        $this->assertDatabaseMissing('courier_states', [
            'courier_id' => $courier->id,
            'name' => 'Outstation',
        ]);
        $this->assertDatabaseMissing('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '2',
            'name' => 'Suburbs',
        ]);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '5',
            'name' => 'Colombo',
        ]);
        $this->assertDatabaseHas('courier_states', [
            'courier_id' => $courier->id,
            'external_id' => '6',
            'name' => 'Galle',
        ]);
    }

    public function test_import_command_for_fardar(): void
    {
        $this->makeFardarCourier();
        $path = $this->writeCsv([
            ['city_id', 'city_name', 'zone_id', 'zone name', 'district_id', 'district name'],
            ['1', '5 Kanuwa', '3', 'Outstation', '6', 'Galle'],
        ]);

        $this->artisan('courier:import-locations', [
            'courier' => 'FARDAR',
            'file' => $path,
        ])
            ->expectsOutputToContain('FARDAR LOCATION IMPORT')
            ->expectsOutputToContain('FARDAR DATABASE TOTALS')
            ->expectsOutputToContain('States: 1')
            ->expectsOutputToContain('Cities: 1')
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
            ['city_id', 'city_name', 'zone_id'],
            ['1', '5 Kanuwa', '3'],
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

    private function writeRawCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fardar_raw_');
        $this->assertNotFalse($path);

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
