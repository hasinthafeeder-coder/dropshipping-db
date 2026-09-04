<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Database\Support\ProductCatalogSeedData;
use Database\Support\ProductCatalogStockPlanner;
use Database\Support\SeedProductImageFactory;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\GoodsReceivedNote;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\User;
use Feeder\Core\Services\CompanyCommissionService;
use Feeder\Core\Services\GoodsReceivedNoteService;
use Feeder\Core\Services\ProductMarketLanguageService;
use Feeder\Core\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductCatalogSeeder extends Seeder
{
    private const PRODUCTS_PER_SUPPLIER = 15;

    private const MIN_TOTAL_PRODUCTS = 200;

    private const MAX_TOTAL_PRODUCTS = 500;

    private int $globalProductIndex = 0;

    private int $globalVariantIndex = 0;

    public function run(): void
    {
        $this->ensurePrerequisites();

        $suppliersByMarket = $this->discoverSuppliersByMarket();

        if ($suppliersByMarket->isEmpty()) {
            $this->command?->warn('No active supplier accounts with operation markets found. Skipping product catalog seed.');

            return;
        }

        $targetTotal = $this->resolveTargetProductCount($suppliersByMarket);
        $existingSeeded = Product::query()
            ->where('slug', 'like', ProductCatalogSeedData::SEED_SLUG_PREFIX.'%')
            ->count();

        if ($existingSeeded >= $targetTotal) {
            $this->command?->info("Product catalog seed data already present ({$existingSeeded} products). Skipping.");

            return;
        }

        $categories = $this->prepareCategories();
        $imageFactory = new SeedProductImageFactory();
        $imageFactory->initialize();

        $productService = app(ProductService::class);
        $grnService = app(GoodsReceivedNoteService::class);
        $languageService = app(ProductMarketLanguageService::class);
        $commissionService = app(CompanyCommissionService::class);

        $definitions = collect(ProductCatalogSeedData::productDefinitions());
        $created = 0;

        foreach ($suppliersByMarket as $marketId => $suppliers) {
            /** @var Market $market */
            $market = Market::query()->with('currency')->findOrFail($marketId);
            $marketCode = strtolower((string) $market->code);
            $defaultCommission = $commissionService->resolveDefaultForMarket($market);

            foreach ($suppliers as $supplierIndex => $supplier) {
                for ($sequence = 1; $sequence <= self::PRODUCTS_PER_SUPPLIER; $sequence++) {
                    if ($existingSeeded + $created >= $targetTotal) {
                        break 3;
                    }

                    $definition = $definitions[($supplierIndex * self::PRODUCTS_PER_SUPPLIER + $sequence - 1) % $definitions->count()];
                    $slug = $this->buildSeedSlug($marketCode, (int) $supplier->id, $sequence);

                    if (Product::query()->where('slug', $slug)->exists()) {
                        continue;
                    }

                    $category = $this->resolveCategory($categories, $definition['category_slug']);
                    $priceLocked = $this->globalProductIndex % 2 === 0;
                    $webVisible = $this->globalProductIndex % 5 !== 0;
                    $multiVariant = $definition['multi_variant'] && (($this->globalProductIndex % 10) < 4);

                    $variantNames = $multiVariant
                        ? ProductCatalogSeedData::variantTemplates()[$definition['variant_template']] ?? ['Standard']
                        : ['Standard'];

                    $variants = [];
                    $variantPricing = [];

                    $variantStartIndex = $this->globalVariantIndex;

                    foreach ($variantNames as $variantOrder => $variantName) {
                        $pricing = $this->buildVariantPricing(
                            $marketCode,
                            $definition['tier'],
                            $priceLocked,
                            $variantStartIndex + $variantOrder,
                            $defaultCommission
                        );

                        $variants[] = [
                            'name' => $variantName,
                            'barcode' => $this->buildBarcode($marketCode, (int) $supplier->id, $sequence, $variantOrder),
                            'cost' => $pricing['cost'],
                            'selling_price' => $pricing['selling_price'],
                            'suggested_price' => $pricing['suggested_price'],
                            'suggested_price_min' => $pricing['suggested_price_min'],
                            'suggested_price_max' => $pricing['suggested_price_max'],
                            'weight' => $this->deterministicWeight($variantStartIndex + $variantOrder),
                            'company_commission' => $pricing['company_commission'],
                            'sort_order' => $variantOrder,
                            'is_active' => true,
                            'created_by' => $supplier->id,
                            'updated_by' => $supplier->id,
                        ];

                        $variantPricing[] = $pricing;
                    }

                    $product = $productService->createProduct(
                        [
                            'supplier_id' => $supplier->id,
                            'category_id' => $category->id,
                            'name' => $definition['name'],
                            'slug' => $slug,
                            'status' => ProductStatus::ACTIVE,
                            'system_visible' => true,
                            'web_visible' => $webVisible,
                            'price_locked' => $priceLocked,
                            'created_by' => $supplier->id,
                            'updated_by' => $supplier->id,
                        ],
                        $this->buildDescriptions($languageService, $market, $definition['name']),
                        $variants,
                        $imageFactory->imagePayloads($this->globalProductIndex),
                    );

                    $this->seedGrnsForProduct(
                        $grnService,
                        $supplier,
                        $product,
                        $variantPricing,
                        $variantStartIndex
                    );

                    $created++;
                    $this->globalProductIndex++;
                    $this->globalVariantIndex += count($variants);
                }
            }
        }

        $this->command?->info("Product catalog seeder created {$created} products.");
    }

    private function ensurePrerequisites(): void
    {
        $this->call([
            CountrySeeder::class,
            CurrencySeeder::class,
            MarketSeeder::class,
            MarketDefaultCompanyCommissionSeeder::class,
        ]);

        if (ProductCategory::query()->where('is_active', true)->count() === 0) {
            $this->call(ProductCategorySeeder::class);
        }
    }

    /**
     * @return Collection<int, Collection<int, User>>
     */
    private function discoverSuppliersByMarket(): Collection
    {
        return User::query()
            ->with('company.operationMarket.currency')
            ->where('user_type', UserType::OWNER->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->whereHas('company', function ($query) {
                $query->where('status', CompanyStatus::ACTIVE->value)
                    ->whereNotNull('operation_market_id')
                    ->whereHas('portal', fn ($portal) => $portal->where('code', PortalCode::SUPPLIER->value));
            })
            ->orderBy('id')
            ->get()
            ->groupBy(fn (User $supplier) => (int) $supplier->company->operation_market_id)
            ->filter(function (Collection $suppliers, int $marketId) {
                return Market::query()
                    ->whereKey($marketId)
                    ->where('is_active', true)
                    ->exists();
            });
    }

    /**
     * @param  Collection<int, Collection<int, User>>  $suppliersByMarket
     */
    private function resolveTargetProductCount(Collection $suppliersByMarket): int
    {
        $supplierCount = (int) $suppliersByMarket->flatten(1)->count();
        $calculated = $supplierCount * self::PRODUCTS_PER_SUPPLIER;

        return min(self::MAX_TOTAL_PRODUCTS, max(self::MIN_TOTAL_PRODUCTS, $calculated));
    }

    /**
     * @return Collection<string, ProductCategory>
     */
    private function prepareCategories(): Collection
    {
        $categoriesBySlug = ProductCategory::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('slug');

        foreach (ProductCatalogSeedData::extraCategories() as $slug => $definition) {
            if ($categoriesBySlug->has($slug)) {
                continue;
            }

            $parentId = null;

            if ($definition['parent_slug'] !== '') {
                $parent = $categoriesBySlug->get($definition['parent_slug']);

                if ($parent === null) {
                    continue;
                }

                $parentId = $parent->id;
            }

            $category = ProductCategory::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'id' => (string) Str::uuid(),
                    'parent_id' => $parentId,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'sort_order' => 100,
                    'is_active' => true,
                ]
            );

            $categoriesBySlug->put($slug, $category);
        }

        return $categoriesBySlug;
    }

    /**
     * @param  Collection<string, ProductCategory>  $categories
     */
    private function resolveCategory(Collection $categories, string $preferredSlug): ProductCategory
    {
        if ($categories->has($preferredSlug)) {
            return $categories->get($preferredSlug);
        }

        $leafCategories = $categories->filter(function (ProductCategory $category) use ($categories) {
            return ! $categories->contains('parent_id', $category->id);
        });

        if ($leafCategories->isNotEmpty()) {
            return $leafCategories->values()->get($this->globalProductIndex % $leafCategories->count());
        }

        return $categories->first();
    }

    /**
     * @return list<array{language_code: string, description: ?string}>
     */
    private function buildDescriptions(
        ProductMarketLanguageService $languageService,
        Market $market,
        string $productName
    ): array {
        $descriptions = [];
        $marketName = $market->name;

        foreach ($languageService->languageCodesForMarket($market) as $languageCode) {
            $description = match ($languageCode) {
                'en' => ProductCatalogSeedData::englishDescription($productName, $marketName),
                'si' => ProductCatalogSeedData::sinhalaDescription($productName),
                'ta' => ProductCatalogSeedData::tamilDescription($productName),
                'ms' => ProductCatalogSeedData::malayDescription($productName),
                default => ProductCatalogSeedData::englishDescription($productName, $marketName),
            };

            $descriptions[] = [
                'language_code' => $languageCode,
                'description' => $description,
            ];
        }

        return $descriptions;
    }

    /**
     * @return array{
     *     cost: float,
     *     selling_price: float,
     *     suggested_price: ?float,
     *     suggested_price_min: ?float,
     *     suggested_price_max: ?float,
     *     company_commission: string
     * }
     */
    private function buildVariantPricing(
        string $marketCode,
        string $tier,
        bool $priceLocked,
        int $variantIndex,
        string $defaultCommission
    ): array {
        $bands = ProductCatalogSeedData::priceBands()[$marketCode][$tier] ?? ProductCatalogSeedData::priceBands()['lk'][$tier];
        $ratio = $this->deterministicRatio($variantIndex);

        $cost = round($bands['cost'][0] + (($bands['cost'][1] - $bands['cost'][0]) * $ratio), 2);
        $sell = round($bands['sell'][0] + (($bands['sell'][1] - $bands['sell'][0]) * $ratio), 2);

        $commission = $this->varyCommission($defaultCommission, $variantIndex);

        if ($priceLocked) {
            $suggested = round($sell * (1 + (0.08 + ($this->deterministicRatio($variantIndex + 3) * 0.12))), 2);

            return [
                'cost' => $cost,
                'selling_price' => $sell,
                'suggested_price' => $suggested,
                'suggested_price_min' => null,
                'suggested_price_max' => null,
                'company_commission' => $commission,
            ];
        }

        $min = round($sell * 0.92, 2);
        $max = round($sell * (1.12 + ($this->deterministicRatio($variantIndex + 7) * 0.08)), 2);

        if ($max < $min) {
            $max = $min;
        }

        return [
            'cost' => $cost,
            'selling_price' => 0.00,
            'suggested_price' => null,
            'suggested_price_min' => $min,
            'suggested_price_max' => $max,
            'company_commission' => $commission,
        ];
    }

    /**
     * @param  list<array{
     *     cost: float,
     *     selling_price: float,
     *     suggested_price: ?float,
     *     suggested_price_min: ?float,
     *     suggested_price_max: ?float,
     *     company_commission: string
     * }>  $variantPricing
     */
    private function seedGrnsForProduct(
        GoodsReceivedNoteService $grnService,
        User $supplier,
        Product $product,
        array $variantPricing,
        int $variantStartIndex
    ): void {
        $product->load('variants');

        /** @var array<int, list<array{received_quantity: int, damaged_quantity: int, days_ago: int}>> $plans */
        $plans = [];

        foreach ($product->variants as $variantIndex => $variant) {
            $plans[$variant->id] = ProductCatalogStockPlanner::planGrnReceipts(
                $variantStartIndex + $variantIndex,
                (float) ($variantPricing[$variantIndex]['cost'] ?? $variant->cost)
            );
        }

        $maxGrns = max(array_map('count', $plans));

        for ($grnIndex = 0; $grnIndex < $maxGrns; $grnIndex++) {
            $items = [];
            $daysAgo = 7;

            foreach ($product->variants as $variantIndex => $variant) {
                $receipt = $plans[$variant->id][$grnIndex] ?? null;

                if ($receipt === null) {
                    continue;
                }

                $daysAgo = max($daysAgo, $receipt['days_ago']);

                $items[] = [
                    'product_id' => $product->id,
                    'product_variant_id' => $variant->id,
                    'received_quantity' => $receipt['received_quantity'],
                    'damaged_quantity' => $receipt['damaged_quantity'],
                    'unit_cost' => $variantPricing[$variantIndex]['cost'] ?? $variant->cost,
                ];
            }

            if ($items === []) {
                continue;
            }

            $receivedDate = Carbon::today()->subDays($daysAgo)->toDateString();
            $invoiceNumber = sprintf(
                'INV-SEED-%s-%06d',
                strtoupper(substr(ProductCatalogSeedData::SEED_VERSION, 0, 2)),
                $this->globalProductIndex * 10 + $grnIndex + 1
            );

            if ($this->grnAlreadyExists($supplier->id, $invoiceNumber)) {
                continue;
            }

            $grnService->createGrn(
                (int) $supplier->id,
                [
                    'invoice_number' => $invoiceNumber,
                    'received_date' => $receivedDate,
                    'notes' => 'Seeded GRN for catalog testing.',
                    'created_by' => $supplier->id,
                    'updated_by' => $supplier->id,
                ],
                $items
            );
        }
    }

    private function grnAlreadyExists(int $supplierId, string $invoiceNumber): bool
    {
        return GoodsReceivedNote::query()
            ->where('supplier_id', $supplierId)
            ->where('invoice_number', $invoiceNumber)
            ->exists();
    }

    private function buildSeedSlug(string $marketCode, int $supplierId, int $sequence): string
    {
        return sprintf(
            '%s-%s-s%d-%03d',
            ProductCatalogSeedData::SEED_SLUG_PREFIX,
            $marketCode,
            $supplierId,
            $sequence
        );
    }

    private function buildBarcode(string $marketCode, int $supplierId, int $sequence, int $variantOrder): string
    {
        return sprintf(
            'SEED%s%s%04d%02d',
            strtoupper($marketCode),
            str_pad((string) $supplierId, 4, '0', STR_PAD_LEFT),
            $sequence,
            $variantOrder
        );
    }

    private function deterministicRatio(int $seed): float
    {
        $hash = crc32('catalog-seed-'.$seed);

        return ($hash % 1000) / 1000;
    }

    private function deterministicWeight(int $seed): float
    {
        return round(0.08 + ($this->deterministicRatio($seed) * 1.8), 3);
    }

    private function varyCommission(string $defaultCommission, int $variantIndex): string
    {
        $base = (float) $defaultCommission;
        $modifier = 0.85 + ($this->deterministicRatio($variantIndex + 11) * 0.3);

        return number_format(max(0, $base * $modifier), 2, '.', '');
    }
}
