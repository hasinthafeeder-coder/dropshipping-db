<?php

namespace Tests\Feature\Order;

use Feeder\Core\Exceptions\ConflictingCustomerIdentityException;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\CustomerPhone;
use Feeder\Core\Services\Order\CustomerIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CustomerIdentityFoundationTest extends TestCase
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

    public function test_same_normalized_phone_resolves_same_customer(): void
    {
        $service = app(CustomerIdentityService::class);
        $countryId = $this->countryByIso('LK')->id;

        $first = $service->resolveOrCreate([
            'display_name' => 'Alice',
            'primary_country_id' => $countryId,
            'primary_phone' => '0771234567',
            'primary_phone_country_id' => $countryId,
        ]);

        $second = $service->resolveOrCreate([
            'display_name' => 'Alice Updated',
            'primary_country_id' => $countryId,
            'primary_phone' => '+94 77 123 4567',
            'primary_phone_country_id' => $countryId,
        ]);

        $this->assertSame($first['customer']->id, $second['customer']->id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, CustomerPhone::query()->count());
    }

    public function test_duplicate_phone_cannot_create_second_identity(): void
    {
        $countryId = $this->countryByIso('LK')->id;

        CustomerPhone::query()->create([
            'customer_id' => Customer::query()->create([
                'display_name' => 'Bob',
                'primary_country_id' => $countryId,
            ])->id,
            'country_id' => $countryId,
            'normalized_phone' => '0779998877',
            'raw_phone' => '0779998877',
            'is_primary' => true,
        ]);

        $this->expectException(QueryException::class);

        CustomerPhone::query()->create([
            'customer_id' => Customer::query()->create([
                'display_name' => 'Other',
                'primary_country_id' => $countryId,
            ])->id,
            'country_id' => $countryId,
            'normalized_phone' => '0779998877',
            'raw_phone' => '0779998877',
            'is_primary' => true,
        ]);
    }

    public function test_two_phones_can_belong_to_same_customer(): void
    {
        $service = app(CustomerIdentityService::class);
        $countryId = $this->countryByIso('LK')->id;

        $result = $service->resolveOrCreate([
            'display_name' => 'Carla',
            'primary_country_id' => $countryId,
            'primary_phone' => '0711111111',
            'primary_phone_country_id' => $countryId,
            'secondary_phone' => '0722222222',
            'secondary_phone_country_id' => $countryId,
        ]);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(2, CustomerPhone::query()->where('customer_id', $result['customer']->id)->count());
        $this->assertNotNull($result['secondary_phone']);
    }

    public function test_conflicting_phone_identities_are_detected(): void
    {
        $service = app(CustomerIdentityService::class);
        $countryId = $this->countryByIso('LK')->id;

        $service->resolveOrCreate([
            'display_name' => 'Customer A',
            'primary_country_id' => $countryId,
            'primary_phone' => '0713333333',
            'primary_phone_country_id' => $countryId,
        ]);

        $service->resolveOrCreate([
            'display_name' => 'Customer B',
            'primary_country_id' => $countryId,
            'primary_phone' => '0714444444',
            'primary_phone_country_id' => $countryId,
        ]);

        $this->expectException(ConflictingCustomerIdentityException::class);

        $service->resolveOrCreate([
            'display_name' => 'Conflict',
            'primary_country_id' => $countryId,
            'primary_phone' => '0713333333',
            'primary_phone_country_id' => $countryId,
            'secondary_phone' => '0714444444',
            'secondary_phone_country_id' => $countryId,
        ]);
    }
}
