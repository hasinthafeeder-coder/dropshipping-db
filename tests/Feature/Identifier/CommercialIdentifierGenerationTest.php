<?php

namespace Tests\Feature\Identifier;

use Carbon\CarbonImmutable;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Services\Order\OrderNumberGenerator;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\SupplierFulfillmentQueryService;
use Feeder\Core\Services\Order\SupplierInvoiceService;
use Feeder\Core\Support\MarketBusinessDateResolver;
use Feeder\Core\Support\ProductVariantBarcodeGenerator;
use Illuminate\Support\Facades\DB;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class CommercialIdentifierGenerationTest extends TestCase
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

    public function test_product_barcode_is_six_digits_with_market_prefix_and_increments(): void
    {
        $lk = $this->marketByCode('lk');
        $my = $this->marketByCode('my');
        $generator = app(ProductVariantBarcodeGenerator::class);

        $first = $generator->allocateNext($lk);
        $second = $generator->allocateNext($lk);
        $myFirst = $generator->allocateNext($my);

        $this->assertMatchesRegularExpression('/^1\d{5}$/', $first);
        $this->assertMatchesRegularExpression('/^1\d{5}$/', $second);
        $this->assertSame(((int) $first) + 1, (int) $second);
        $this->assertMatchesRegularExpression('/^2\d{5}$/', $myFirst);
        $this->assertNotSame($first, $myFirst);
    }

    public function test_order_number_uses_market_local_business_date_and_resets_daily(): void
    {
        $lk = $this->marketByCode('lk');
        $generator = app(OrderNumberGenerator::class);
        $resolver = app(MarketBusinessDateResolver::class);

        $dayOne = CarbonImmutable::parse('2026-07-19 10:00:00', 'UTC'); // Colombo day 200
        $dayTwo = CarbonImmutable::parse('2026-07-20 10:00:00', 'UTC');

        $this->assertSame('2026-07-19', $resolver->businessDateString($lk, $dayOne));

        $a = $generator->allocateNext($lk, $dayOne);
        $b = $generator->allocateNext($lk, $dayOne);
        $c = $generator->allocateNext($lk, $dayTwo);

        $this->assertSame(11, strlen($a));
        $this->assertTrue(ctype_digit($a));
        $this->assertSame('1', $a[0]);
        $this->assertSame(substr($a, 0, 6), substr($b, 0, 6));
        $this->assertSame(((int) substr($a, 6)) + 1, (int) substr($b, 6));
        $this->assertNotSame(substr($a, 0, 6), substr($c, 0, 6));
        $this->assertSame(1, (int) substr($c, 6));
    }

    public function test_created_order_gets_eleven_digit_number_and_invoice_mirrors_it(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $order = app(OrderService::class)->create([
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'customer' => [
                'display_name' => 'ID Test Customer',
                'primary_country_id' => $this->countryByIso('LK')->id,
                'primary_phone' => '0771234567',
                'primary_phone_country_id' => $this->countryByIso('LK')->id,
            ],
            'address' => [
                'recipient_name' => 'ID Test Customer',
                'line1' => '12 Test Street',
                'city_name' => 'Colombo',
                'country_id' => $this->countryByIso('LK')->id,
            ],
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
            ],
            'created_by' => $reseller->id,
        ]);

        $this->assertMatchesRegularExpression('/^1\d{10}$/', (string) $order->order_number);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $variant->fresh()->barcode);

        $fulfillment = $order->fulfillment()->create([
            'status' => \Feeder\Core\Enums\SupplierFulfillmentStatus::PRINT,
            'created_by' => $supplier->id,
            'updated_by' => $supplier->id,
        ]);

        $invoice = app(SupplierInvoiceService::class)->ensureInvoiceNumber($fulfillment->fresh());
        $this->assertSame($order->order_number, $invoice);
        $this->assertSame($order->order_number, $fulfillment->fresh()->invoice_number);
    }

    public function test_find_owned_order_does_not_match_numeric_primary_key(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $this->assignSupplierToReseller($reseller, $supplier);
        $variant = $this->makeSupplierProductVariant($supplier)['variant'];

        $order = app(OrderService::class)->create([
            'market_id' => $this->marketByCode('lk')->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'customer' => [
                'display_name' => 'Lookup Customer',
                'primary_country_id' => $this->countryByIso('LK')->id,
                'primary_phone' => '0779998888',
                'primary_phone_country_id' => $this->countryByIso('LK')->id,
            ],
            'address' => [
                'recipient_name' => 'Lookup Customer',
                'line1' => '1 Lookup Lane',
                'city_name' => 'Colombo',
                'country_id' => $this->countryByIso('LK')->id,
            ],
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                    'unit_selling_price' => 250,
                ],
            ],
            'created_by' => $reseller->id,
        ]);

        $query = app(SupplierFulfillmentQueryService::class);

        $byNumber = $query->findOwnedOrder($supplier->id, (string) $order->order_number);
        $this->assertNotNull($byNumber);
        $this->assertSame($order->id, $byNumber->id);

        $byPk = $query->findOwnedOrder($supplier->id, (string) $order->id);
        $this->assertNull($byPk);

        $order->forceFill(['legacy_order_number' => 'ORD-2026-000999'])->save();
        $byLegacy = $query->findOwnedOrder($supplier->id, 'ORD-2026-000999');
        $this->assertNotNull($byLegacy);
        $this->assertSame($order->id, $byLegacy->id);
    }

    public function test_variant_model_auto_generates_market_barcode(): void
    {
        $supplier = $this->makeSupplierUser();
        $created = $this->makeSupplierProductVariant($supplier);
        $barcode = $created['variant']->fresh()->barcode;

        $this->assertMatchesRegularExpression('/^1\d{5}$/', (string) $barcode);
        $this->assertTrue(ProductVariant::query()->where('barcode', $barcode)->exists());
    }
}
