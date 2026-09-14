<?php

namespace Tests\Feature\Order;

use Feeder\Core\Enums\CribImportStatus;
use Feeder\Core\Models\CribImportBatch;
use Feeder\Core\Models\CribRecord;
use Feeder\Core\Services\Order\CribLookupService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CribFoundationTest extends TestCase
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

    public function test_unique_market_and_normalized_phone(): void
    {
        $market = $this->marketByCode('lk');
        $country = $this->countryByIso('LK');
        $batch = $this->makeBatch($market->id);

        CribRecord::query()->create([
            'market_id' => $market->id,
            'country_id' => $country->id,
            'normalized_phone' => '0771212121',
            'risk_level' => 'HIGH',
            'is_active' => true,
            'last_import_batch_id' => $batch->id,
        ]);

        $this->expectException(QueryException::class);

        CribRecord::query()->create([
            'market_id' => $market->id,
            'country_id' => $country->id,
            'normalized_phone' => '0771212121',
            'risk_level' => 'LOW',
            'is_active' => true,
            'last_import_batch_id' => $batch->id,
        ]);
    }

    public function test_batch_lookup_and_active_inactive_state(): void
    {
        $market = $this->marketByCode('lk');
        $country = $this->countryByIso('LK');
        $batch = $this->makeBatch($market->id);

        CribRecord::query()->create([
            'market_id' => $market->id,
            'country_id' => $country->id,
            'normalized_phone' => '0771000001',
            'risk_level' => 'HIGH',
            'is_active' => true,
            'last_import_batch_id' => $batch->id,
        ]);

        CribRecord::query()->create([
            'market_id' => $market->id,
            'country_id' => $country->id,
            'normalized_phone' => '0771000002',
            'risk_level' => 'MEDIUM',
            'is_active' => false,
            'last_import_batch_id' => $batch->id,
        ]);

        $service = app(CribLookupService::class);

        $activeMap = $service->findByPhones($market->id, ['0771000001', '0771000002'], true);
        $this->assertCount(1, $activeMap);
        $this->assertTrue($activeMap->has('0771000001'));

        $allMap = $service->findByPhones($market->id, ['0771000001', '0771000002'], false);
        $this->assertCount(2, $allMap);

        $single = $service->findByPhone($market->id, '0771000001');
        $this->assertNotNull($single);
        $this->assertSame($batch->id, $single->last_import_batch_id);
    }

    private function makeBatch(int $marketId): CribImportBatch
    {
        return CribImportBatch::query()->create([
            'market_id' => $marketId,
            'source_name' => 'local-file',
            'source_file_hash' => hash('sha256', 'crib-'.uniqid()),
            'imported_at' => now(),
            'record_count' => 2,
            'status' => CribImportStatus::COMPLETED,
        ]);
    }
}
