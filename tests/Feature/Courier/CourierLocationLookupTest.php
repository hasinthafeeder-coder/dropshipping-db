<?php

namespace Tests\Feature\Courier;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierCity;
use Feeder\Core\Models\CourierState;
use Feeder\Core\Services\Order\OrderCourierLookupService;
use Illuminate\Support\Str;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CourierLocationLookupTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private OrderCourierLookupService $lookup;

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

        $this->seedMarketLookups();
        $this->lookup = app(OrderCourierLookupService::class);
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_states_are_scoped_to_the_selected_courier(): void
    {
        $royal = $this->makeCourier('LKP_ROYAL');
        $other = $this->makeCourier('LKP_OTHER');

        $this->makeState($royal, '1', 'Colombo');
        $this->makeState($other, '1', 'Western Other');

        $royalStates = $this->lookup->districtsForCourier((int) $royal->id);
        $names = array_column($royalStates, 'district');

        $this->assertContains('Colombo', $names);
        $this->assertNotContains('Western Other', $names);
        $this->assertCount(1, $royalStates);
    }

    public function test_cities_are_scoped_to_courier_and_state(): void
    {
        $royal = $this->makeCourier('LKP_ROYAL2');
        $colombo = $this->makeState($royal, '1', 'Colombo');
        $gampaha = $this->makeState($royal, '3', 'Gampaha');
        $dehiwala = $this->makeCity($royal, $colombo, '1491', 'Dehiwala');
        $this->makeCity($royal, $gampaha, '2099', 'Negombo');

        $cities = $this->lookup->citiesForCourierAndState((int) $royal->id, (int) $colombo->id);
        $ids = array_column($cities, 'id');
        $names = array_column($cities, 'city_name');

        $this->assertSame([(int) $dehiwala->id], $ids);
        $this->assertContains('Dehiwala', $names);
        $this->assertNotContains('Negombo', $names);
    }

    public function test_same_city_name_across_couriers_resolves_to_selected_courier_record(): void
    {
        $royal = $this->makeCourier('LKP_ROYAL3');
        $other = $this->makeCourier('LKP_FARDAR');

        $royalState = $this->makeState($royal, '1', 'Colombo');
        $otherState = $this->makeState($other, '5', 'Colombo');
        $royalCity = $this->makeCity($royal, $royalState, '1491', 'Dehiwala');
        $otherCity = $this->makeCity($other, $otherState, '1900', 'Dehiwala');

        $royalCities = $this->lookup->citiesForCourierAndState((int) $royal->id, (int) $royalState->id);
        $this->assertCount(1, $royalCities);
        $this->assertSame((int) $royalCity->id, $royalCities[0]['id']);
        $this->assertSame((int) $royalState->id, $royalCities[0]['courier_state_id']);
        $this->assertNotSame((int) $otherCity->id, $royalCities[0]['id']);

        $resolved = $this->lookup->requireCityForCourier((int) $royal->id, (int) $royalCity->id);
        $this->assertSame((int) $royal->id, (int) $resolved->courier_id);
        $this->assertSame('Dehiwala', $resolved->city_name);
        $this->assertSame('1491', (string) $resolved->external_id);
    }

    public function test_cities_for_foreign_state_id_are_rejected(): void
    {
        $royal = $this->makeCourier('LKP_ROYAL4');
        $other = $this->makeCourier('LKP_OTHER2');
        $this->makeState($royal, '1', 'Colombo');
        $foreignState = $this->makeState($other, '1', 'Colombo');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->lookup->citiesForCourierAndState((int) $royal->id, (int) $foreignState->id);
    }

    private function makeCourier(string $code): Courier
    {
        return Courier::query()->create([
            'code' => $code.'-'.Str::upper(Str::random(4)),
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function makeState(Courier $courier, string $externalId, string $name): CourierState
    {
        return CourierState::query()->create([
            'courier_id' => $courier->id,
            'external_id' => $externalId,
            'external_ref_no' => 'ST-'.$externalId,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function makeCity(
        Courier $courier,
        CourierState $state,
        string $externalId,
        string $name,
    ): CourierCity {
        return CourierCity::query()->create([
            'courier_id' => $courier->id,
            'courier_state_id' => $state->id,
            'external_id' => $externalId,
            'external_ref_no' => 'CT-'.$externalId,
            'name' => $name,
            'city_name' => $name,
            'district_name' => $state->name,
            'external_city_code' => $externalId,
            'external_district_code' => (string) $state->external_id,
            'is_active' => true,
        ]);
    }
}
