<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\CustomerBanEventType;
use Feeder\Core\Enums\CustomerBanStatus;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\CustomerBan;
use Feeder\Core\Models\CustomerBanEvent;
use Feeder\Core\Services\Order\CustomerBanService;
use Feeder\Core\Services\Order\CustomerIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CustomerBanFoundationTest extends TestCase
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

    public function test_one_active_ban_per_customer(): void
    {
        $actor = $this->makeResellerUser();
        $customer = $this->makeCustomer();
        $service = app(CustomerBanService::class);

        $service->ban($customer, $actor->id, $actor->company_id, 'Fraud');

        $this->expectException(ValidationException::class);
        $service->ban($customer->fresh(), $actor->id, $actor->company_id, 'Again');
    }

    public function test_active_ban_unique_constraint_enforced_at_database(): void
    {
        $actor = $this->makeResellerUser();
        $customer = $this->makeCustomer();

        CustomerBan::query()->create([
            'customer_id' => $customer->id,
            'status' => CustomerBanStatus::ACTIVE,
            'reason' => 'First',
            'banned_by_user_id' => $actor->id,
            'banned_by_company_id' => $actor->company_id,
            'banned_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        CustomerBan::query()->create([
            'customer_id' => $customer->id,
            'status' => CustomerBanStatus::ACTIVE,
            'reason' => 'Second',
            'banned_by_user_id' => $actor->id,
            'banned_by_company_id' => $actor->company_id,
            'banned_at' => now(),
        ]);
    }

    public function test_ban_and_lift_events_are_recorded(): void
    {
        $actor = $this->makeResellerUser();
        $customer = $this->makeCustomer();
        $service = app(CustomerBanService::class);

        $ban = $service->ban($customer, $actor->id, $actor->company_id, 'Risk');

        $this->assertTrue($customer->fresh()->is_banned);
        $this->assertDatabaseHas('customer_ban_events', [
            'customer_ban_id' => $ban->id,
            'event_type' => CustomerBanEventType::BANNED->value,
        ]);

        $lifted = $service->lift($ban, $actor->id, $actor->company_id, 'Cleared');

        $this->assertSame(CustomerBanStatus::LIFTED, $lifted->status);
        $this->assertFalse($customer->fresh()->is_banned);
        $this->assertSame(2, CustomerBanEvent::query()->where('customer_ban_id', $ban->id)->count());
        $this->assertDatabaseHas('customer_ban_events', [
            'customer_ban_id' => $ban->id,
            'event_type' => CustomerBanEventType::LIFTED->value,
        ]);
    }

    private function makeCustomer(): Customer
    {
        $countryId = $this->countryByIso('LK')->id;

        return app(CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => 'Banned Person',
            'primary_country_id' => $countryId,
            'primary_phone' => '075'.random_int(1000000, 9999999),
            'primary_phone_country_id' => $countryId,
        ])['customer'];
    }
}
