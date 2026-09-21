<?php

namespace Tests\Feature\Order;

use Feeder\Core\Models\Courier;
use Feeder\Core\Models\CourierMarketPricing;
use Feeder\Core\Models\SupplierCourierAccount;
use Feeder\Core\Services\Order\OrderCourierLookupService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

/**
 * Regression: reseller courier selection requires BOTH an active supplier
 * courier account and active market pricing for the order market.
 *
 * Connected accounts alone are not enough — missing pricing yields an empty
 * dropdown (the Supplier 5 / ROYAL create-order failure mode).
 */
class OrderCourierLookupEligibilityTest extends TestCase
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

    public function test_assigned_supplier_with_royal_account_and_pricing_returns_courier(): void
    {
        $supplier = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');
        $courier = $this->makeCourier('ROYAL_ELIG');

        $this->attachAccount($supplier->id, $courier->id);
        $this->attachPricing($courier->id, (int) $market->id, (int) $market->currency_id);

        $result = $this->lookup->eligibleCouriersForSupplierMarket((int) $supplier->id, (int) $market->id);

        $this->assertCount(1, $result);
        $this->assertSame((int) $courier->id, $result[0]['id']);
        $this->assertSame('ROYAL_ELIG', $result[0]['code']);
    }

    public function test_multiple_active_accounts_return_all_priced_couriers(): void
    {
        $supplier = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');
        $first = $this->makeCourier('MULTI_A');
        $second = $this->makeCourier('MULTI_B');

        $this->attachAccount($supplier->id, $first->id);
        $this->attachAccount($supplier->id, $second->id);
        $this->attachPricing($first->id, (int) $market->id, (int) $market->currency_id);
        $this->attachPricing($second->id, (int) $market->id, (int) $market->currency_id);

        $result = $this->lookup->eligibleCouriersForSupplierMarket((int) $supplier->id, (int) $market->id);
        $codes = array_column($result, 'code');

        $this->assertCount(2, $result);
        $this->assertContains('MULTI_A', $codes);
        $this->assertContains('MULTI_B', $codes);
    }

    public function test_unrelated_supplier_accounts_are_not_returned(): void
    {
        $assigned = $this->makeSupplierUser();
        $foreign = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');
        $courier = $this->makeCourier('FOREIGN_ONLY');

        $this->attachAccount($foreign->id, $courier->id);
        $this->attachPricing($courier->id, (int) $market->id, (int) $market->currency_id);

        $result = $this->lookup->eligibleCouriersForSupplierMarket((int) $assigned->id, (int) $market->id);

        $this->assertSame([], $result);
    }

    public function test_supplier_without_courier_account_returns_empty_list(): void
    {
        $supplier = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');
        $courier = $this->makeCourier('NO_ACCT');

        $this->attachPricing($courier->id, (int) $market->id, (int) $market->currency_id);

        $result = $this->lookup->eligibleCouriersForSupplierMarket((int) $supplier->id, (int) $market->id);

        $this->assertSame([], $result);
    }

    public function test_account_without_market_pricing_returns_empty_list(): void
    {
        $supplier = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');
        $courier = $this->makeCourier('NO_PRICE');

        $this->attachAccount($supplier->id, $courier->id);

        $result = $this->lookup->eligibleCouriersForSupplierMarket((int) $supplier->id, (int) $market->id);

        $this->assertSame([], $result);
    }

    public function test_inactive_account_or_inactive_pricing_is_excluded(): void
    {
        $supplier = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');
        $inactiveAccountCourier = $this->makeCourier('INACT_ACCT');
        $inactivePricingCourier = $this->makeCourier('INACT_PRICE');

        SupplierCourierAccount::query()->create([
            'supplier_id' => $supplier->id,
            'courier_id' => $inactiveAccountCourier->id,
            'account_label' => 'Inactive',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'x'], JSON_THROW_ON_ERROR)),
            'is_active' => false,
        ]);
        $this->attachPricing($inactiveAccountCourier->id, (int) $market->id, (int) $market->currency_id);

        $this->attachAccount($supplier->id, $inactivePricingCourier->id);
        CourierMarketPricing::query()->create([
            'courier_id' => $inactivePricingCourier->id,
            'market_id' => $market->id,
            'currency_id' => $market->currency_id,
            'first_kg_fee' => 100,
            'additional_kg_fee' => 10,
            'is_active' => false,
        ]);

        $result = $this->lookup->eligibleCouriersForSupplierMarket((int) $supplier->id, (int) $market->id);

        $this->assertSame([], $result);
    }

    private function makeCourier(string $code): Courier
    {
        return Courier::query()->create([
            'code' => $code,
            'name' => $code.' Courier',
            'is_active' => true,
        ]);
    }

    private function attachAccount(int $supplierId, int $courierId): void
    {
        SupplierCourierAccount::query()->create([
            'supplier_id' => $supplierId,
            'courier_id' => $courierId,
            'account_label' => 'Primary',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'api_key' => 'secret-'.Str::lower(Str::random(6)),
            ], JSON_THROW_ON_ERROR)),
            'is_active' => true,
        ]);
    }

    private function attachPricing(int $courierId, int $marketId, int $currencyId): void
    {
        CourierMarketPricing::query()->create([
            'courier_id' => $courierId,
            'market_id' => $marketId,
            'currency_id' => $currencyId,
            'first_kg_fee' => 700,
            'additional_kg_fee' => 200,
            'is_active' => true,
        ]);
    }
}
