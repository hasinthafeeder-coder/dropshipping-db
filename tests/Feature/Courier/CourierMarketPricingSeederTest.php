<?php

namespace Tests\Feature\Courier;

use Database\Seeders\FardarExpressCourierSeeder;
use Database\Seeders\RoyalExpressCourierSeeder;
use Database\Seeders\TransExpressCourierSeeder;
use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierMarketPricing;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CourierMarketPricingSeederTest extends TestCase
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
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        \Illuminate\Support\Facades\DB::beginTransaction();

        $this->seedMarketLookups();
    }

    protected function tearDown(): void
    {
        if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_royal_seeder_provisions_market_pricing_for_active_markets(): void
    {
        $this->seed(RoyalExpressCourierSeeder::class);

        $courier = Courier::query()->where('code', 'ROYAL')->first();
        $this->assertNotNull($courier);

        $lk = $this->marketByCode('lk');
        $pricing = CourierMarketPricing::query()
            ->where('courier_id', $courier->id)
            ->where('market_id', $lk->id)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($pricing);
        $this->assertSame((int) $lk->currency_id, (int) $pricing->currency_id);
    }

    public function test_courier_seeders_are_idempotent_for_pricing(): void
    {
        $this->seed(RoyalExpressCourierSeeder::class);
        $this->seed(TransExpressCourierSeeder::class);
        $this->seed(FardarExpressCourierSeeder::class);

        $before = CourierMarketPricing::query()->count();

        $this->seed(RoyalExpressCourierSeeder::class);
        $this->seed(TransExpressCourierSeeder::class);
        $this->seed(FardarExpressCourierSeeder::class);

        $this->assertSame($before, CourierMarketPricing::query()->count());
    }
}
