<?php

namespace Tests\Feature\Identifier;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Order\CustomerIdentityService;
use Feeder\Core\Services\Order\OrderNumberGenerator;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Support\CommercialIdentifier;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

/**
 * Order-number allocation when the daily counter is missing or behind stored orders.
 *
 * Writes stay inside the test transaction and are rolled back. This does not
 * run the load-test generator.
 */
class OrderNumberSequenceReconciliationTest extends TestCase
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
        $this->assertGreaterThan(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_missing_sri_lanka_sequence_continues_after_existing_october_14_orders(): void
    {
        $lk = $this->marketByCode('lk');
        $at = CarbonImmutable::parse('2026-10-14 06:30:00', 'UTC');
        $businessDate = app(MarketBusinessDateResolver::class)->businessDateString($lk, $at);
        $this->assertSame('2026-10-14', $businessDate);

        $before = $this->requireOccupiedRange('12628700001', '12628701000', 1000);
        $orderCount = DB::table('orders')->count();
        $this->forgetSequence((int) $lk->id, $businessDate);

        $generator = app(OrderNumberGenerator::class);
        $first = $generator->allocateNext($lk, $at);

        $this->assertSame('12628701001', $first);
        $this->assertSame(1001, $this->sequenceValue((int) $lk->id, $businessDate));

        $second = $generator->allocateNext($lk, $at);

        $this->assertSame('12628701002', $second);
        $this->assertSame(1002, $this->sequenceValue((int) $lk->id, $businessDate));
        $this->assertSame($before, $this->requireOccupiedRange('12628700001', '12628701000', 1000));
        $this->assertSame($orderCount, DB::table('orders')->count());
        $this->assertNull(DB::table('orders')->where('order_number', $first)->value('id'));
        $this->assertNull(DB::table('orders')->where('order_number', $second)->value('id'));
    }

    public function test_stale_counter_allocates_above_the_highest_existing_suffix(): void
    {
        $lk = $this->marketByCode('lk');
        $at = CarbonImmutable::parse('2026-10-14 06:30:00', 'UTC');
        $businessDate = '2026-10-14';
        $before = $this->requireOccupiedRange('12628700001', '12628701000', 1000);

        $this->assertGreaterThan(0, DB::transactionLevel());
        DB::table('order_number_sequences')->updateOrInsert(
            ['market_id' => $lk->id, 'business_date' => $businessDate],
            [
                'last_value' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $generator = app(OrderNumberGenerator::class);
        $first = $generator->allocateNext($lk, $at);
        $second = $generator->allocateNext($lk, $at);

        $this->assertSame('12628701001', $first);
        $this->assertSame('12628701002', $second);
        $this->assertSame(1002, $this->sequenceValue((int) $lk->id, $businessDate));
        $this->assertSame($before, $this->requireOccupiedRange('12628700001', '12628701000', 1000));
    }

    public function test_first_and_second_allocation_on_an_unused_market_day(): void
    {
        $lk = $this->marketByCode('lk');
        $at = CarbonImmutable::parse('2099-06-15 02:00:00', 'UTC');
        $businessDate = app(MarketBusinessDateResolver::class)->businessDateString($lk, $at);
        $this->assertPrefixUnused($this->number($lk, $at, 1));
        $this->forgetSequence((int) $lk->id, $businessDate);

        $generator = app(OrderNumberGenerator::class);
        $first = $generator->allocateNext($lk, $at);
        $second = $generator->allocateNext($lk, $at);

        $this->assertSame($this->number($lk, $at, 1), $first);
        $this->assertSame($this->number($lk, $at, 2), $second);
        $this->assertSame(1, (int) substr($first, 6));
        $this->assertSame(2, (int) substr($second, 6));
        $this->assertSame(substr($first, 0, 6), substr($second, 0, 6));
        $this->assertSame(2, $this->sequenceValue((int) $lk->id, $businessDate));
    }

    public function test_sri_lanka_and_malaysia_counters_stay_independent(): void
    {
        $lk = $this->marketByCode('lk');
        $my = $this->marketByCode('my');
        $resolver = app(MarketBusinessDateResolver::class);
        $day = CarbonImmutable::parse('2099-08-01 02:00:00', 'UTC');
        $nextDay = CarbonImmutable::parse('2099-08-02 02:00:00', 'UTC');

        foreach ([$lk, $my] as $market) {
            foreach ([$day, $nextDay] as $moment) {
                $this->assertPrefixUnused($this->number($market, $moment, 1));
                $this->forgetSequence((int) $market->id, $resolver->businessDateString($market, $moment));
            }
        }

        $generator = app(OrderNumberGenerator::class);
        $lkFirst = $generator->allocateNext($lk, $day);
        $myFirst = $generator->allocateNext($my, $day);
        $lkSecond = $generator->allocateNext($lk, $day);
        $myNextDay = $generator->allocateNext($my, $nextDay);
        $lkNextDay = $generator->allocateNext($lk, $nextDay);

        $this->assertSame($this->number($lk, $day, 1), $lkFirst);
        $this->assertSame($this->number($my, $day, 1), $myFirst);
        $this->assertSame($this->number($lk, $day, 2), $lkSecond);
        $this->assertSame($this->number($my, $nextDay, 1), $myNextDay);
        $this->assertSame($this->number($lk, $nextDay, 1), $lkNextDay);
        $this->assertNotSame($lkFirst[0], $myFirst[0]);
        $this->assertSame(2, $this->sequenceValue((int) $lk->id, $resolver->businessDateString($lk, $day)));
        $this->assertSame(1, $this->sequenceValue((int) $my->id, $resolver->businessDateString($my, $day)));
        $this->assertSame(1, $this->sequenceValue((int) $lk->id, $resolver->businessDateString($lk, $nextDay)));
        $this->assertSame(1, $this->sequenceValue((int) $my->id, $resolver->businessDateString($my, $nextDay)));
    }

    public function test_order_create_skips_a_duplicate_number_and_rolls_allocation_back(): void
    {
        $frozen = CarbonImmutable::now('UTC');
        Carbon::setTestNow($frozen);

        $lk = $this->marketByCode('lk');
        $resolver = app(MarketBusinessDateResolver::class);
        $businessDate = $resolver->businessDateString($lk, $frozen);
        $prefix = substr($this->number($lk, $frozen, 1), 0, 6);
        $collision = $this->number($lk, $frozen, 1);
        $preExisting = $this->prefixSnapshot($prefix);

        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];
        $countryId = $this->countryByIso('LK')->id;

        if (! DB::table('orders')->where('order_number', $collision)->exists()) {
            $phone = '076'.random_int(1000000, 9999999);
            $customer = app(CustomerIdentityService::class)->resolveOrCreate([
                'display_name' => 'Sequence Collision',
                'primary_country_id' => $countryId,
                'primary_phone' => $phone,
                'primary_phone_country_id' => $countryId,
                'created_by' => $reseller->id,
            ])['customer'];
            $this->insertOccupiedNumber($lk, $collision, $reseller, $supplier, (int) $customer->id, $countryId, $phone);
        }

        $occupied = $this->prefixSnapshot($prefix);
        $highest = $this->highestSuffix($prefix);
        $expected = $this->number($lk, $frozen, $highest + 1);
        $this->assertNotSame($collision, $expected);
        $this->assertGreaterThan($highest, (int) substr($expected, 6));
        $this->forgetSequence((int) $lk->id, $businessDate);

        $payload = $this->createPayload($lk, $reseller, $supplier, (int) $variant->id, $countryId);

        try {
            DB::transaction(function () use ($payload, $collision): void {
                $created = app(OrderService::class)->create($payload);
                $this->assertNotSame($collision, (string) $created->order_number);
                throw new RuntimeException('rollback-check');
            });
            $this->fail('The rolled-back order create was committed.');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'rollback-check') {
                throw $exception;
            }
        }

        $this->assertNull($this->sequenceValue((int) $lk->id, $businessDate));
        $this->assertSame($occupied, $this->prefixSnapshot($prefix));
        $this->assertFalse(DB::table('orders')->where('order_number', $expected)->exists());
        $this->assertSame($preExisting, $this->rowsForIds($preExisting));

        $created = app(OrderService::class)->create($payload);

        $this->assertSame($expected, (string) $created->order_number);
        $this->assertSame(1, DB::table('orders')->where('order_number', $expected)->count());
        $this->assertSame($highest + 1, $this->sequenceValue((int) $lk->id, $businessDate));
        $this->assertSame($occupied, $this->rowsForIds($occupied));
        $this->assertSame($preExisting, $this->rowsForIds($preExisting));
    }

    private function number(Market $market, CarbonImmutable $at, int $suffix): string
    {
        $date = app(MarketBusinessDateResolver::class)->resolve($market, $at);

        return CommercialIdentifier::formatOrderNumber(
            (int) $market->market_number,
            (int) $date->format('y'),
            ((int) $date->format('z')) + 1,
            $suffix,
        );
    }

    private function forgetSequence(int $marketId, string $businessDate): void
    {
        $this->assertGreaterThan(0, DB::transactionLevel());

        DB::table('order_number_sequences')
            ->where('market_id', $marketId)
            ->where('business_date', $businessDate)
            ->delete();
    }

    private function sequenceValue(int $marketId, string $businessDate): ?int
    {
        $value = DB::table('order_number_sequences')
            ->where('market_id', $marketId)
            ->where('business_date', $businessDate)
            ->value('last_value');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array{id: int, order_number: string, updated_at: string, supplier_id: int, status: string, market_id: int}>
     */
    private function requireOccupiedRange(string $first, string $last, int $count): array
    {
        $rows = DB::table('orders')
            ->where('order_number', '>=', $first)
            ->where('order_number', '<=', $last)
            ->whereRaw('CHAR_LENGTH(order_number) = 11')
            ->orderBy('order_number')
            ->get(['id', 'order_number', 'updated_at', 'supplier_id', 'status', 'market_id'])
            ->map(fn (object $row): array => $this->orderRow($row))
            ->all();

        $this->assertCount($count, $rows);
        $this->assertSame($first, $rows[0]['order_number']);
        $this->assertSame($last, $rows[$count - 1]['order_number']);

        return $rows;
    }

    private function assertPrefixUnused(string $orderNumber): void
    {
        $prefix = substr($orderNumber, 0, 6);
        $count = DB::table('orders')
            ->where('order_number', 'like', $prefix.'_____')
            ->count();

        $this->assertSame(0, $count, 'Refusing to allocate over stored order numbers for '.$prefix.'.');
    }

    /**
     * @return array<int, array{id: int, order_number: string, updated_at: string, supplier_id: int, status: string, market_id: int}>
     */
    private function prefixSnapshot(string $prefix): array
    {
        $snapshot = [];
        $rows = DB::table('orders')
            ->where('order_number', 'like', $prefix.'_____')
            ->orderBy('id')
            ->get(['id', 'order_number', 'updated_at', 'supplier_id', 'status', 'market_id']);

        foreach ($rows as $row) {
            $cast = $this->orderRow($row);
            $snapshot[$cast['id']] = $cast;
        }

        return $snapshot;
    }

    /**
     * @param  array<int, array{id: int, order_number: string, updated_at: string, supplier_id: int, status: string, market_id: int}>  $rows
     * @return array<int, array{id: int, order_number: string, updated_at: string, supplier_id: int, status: string, market_id: int}>
     */
    private function rowsForIds(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $current = [];
        $loaded = DB::table('orders')
            ->whereIn('id', array_keys($rows))
            ->orderBy('id')
            ->get(['id', 'order_number', 'updated_at', 'supplier_id', 'status', 'market_id']);

        foreach ($loaded as $row) {
            $cast = $this->orderRow($row);
            $current[$cast['id']] = $cast;
        }

        return $current;
    }

    private function highestSuffix(string $prefix): int
    {
        $highest = DB::table('orders')
            ->where('order_number', 'like', $prefix.'_____')
            ->orderByDesc('order_number')
            ->value('order_number');

        if (! is_string($highest) || strlen($highest) !== 11) {
            return 0;
        }

        return (int) substr($highest, 6);
    }

    /**
     * @return array{id: int, order_number: string, updated_at: string, supplier_id: int, status: string, market_id: int}
     */
    private function orderRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'order_number' => (string) $row->order_number,
            'updated_at' => (string) $row->updated_at,
            'supplier_id' => (int) $row->supplier_id,
            'status' => (string) $row->status,
            'market_id' => (int) $row->market_id,
        ];
    }

    private function insertOccupiedNumber(
        Market $market,
        string $orderNumber,
        User $reseller,
        User $supplier,
        int $customerId,
        int $countryId,
        string $phone,
    ): void {
        $this->assertGreaterThan(0, DB::transactionLevel());
        $this->assertFalse(DB::table('orders')->where('order_number', $orderNumber)->exists());

        $market->loadMissing('currency');

        DB::table('orders')->insert([
            'uuid' => (string) Str::uuid(),
            'order_number' => $orderNumber,
            'source' => OrderSource::MANUAL->value,
            'status' => OrderStatus::PENDING->value,
            'market_id' => $market->id,
            'currency_id' => $market->currency_id,
            'market_code_snapshot' => $market->code,
            'currency_code_snapshot' => (string) $market->currency->iso_code,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'customer_id' => $customerId,
            'customer_name_snapshot' => 'Sequence Collision',
            'primary_phone_snapshot' => $phone,
            'primary_phone_country_id' => $countryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(Market $market, User $reseller, User $supplier, int $variantId, int $countryId): array
    {
        return [
            'market_id' => $market->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'customer' => [
                'display_name' => 'Sequence Create Customer',
                'primary_country_id' => $countryId,
                'primary_phone' => '077'.random_int(1000000, 9999999),
                'primary_phone_country_id' => $countryId,
            ],
            'address' => [
                'recipient_name' => 'Sequence Create Customer',
                'line1' => '12 Sequence Street',
                'city_name' => 'Colombo',
                'country_id' => $countryId,
            ],
            'items' => [
                [
                    'product_variant_id' => $variantId,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
            ],
            'created_by' => $reseller->id,
        ];
    }
}
