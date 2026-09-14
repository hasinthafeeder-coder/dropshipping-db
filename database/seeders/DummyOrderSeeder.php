<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Feeder\Core\Enums\CribImportStatus;
use Feeder\Core\Enums\OrderAssignmentState;
use Feeder\Core\Enums\OrderCcaAssignmentOrigin;
use Feeder\Core\Enums\OrderCommentContextType;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\CribImportBatch;
use Feeder\Core\Models\CribRecord;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\Market;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\ResellerSupplierAssignment;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Order\CallCenterAgentEligibilityService;
use Feeder\Core\Services\Order\CustomerBanService;
use Feeder\Core\Services\Order\OrderCcaAssignmentService;
use Feeder\Core\Services\Order\OrderCommentService;
use Feeder\Core\Services\Order\OrderService;
use Feeder\Core\Services\Order\OrderStatusService;
use Feeder\Core\Support\CustomerPhoneNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development/testing seeder: realistic dummy orders for reseller phone 0799000001.
 *
 * Safe to re-run: removes only previous DummyOrderSeeder rows for that reseller company
 * (identified by Test Customer names + reserved phone prefix), then recreates.
 *
 * Does not create products, suppliers, markets, reseller accounts, or call courier APIs.
 * Prefer domain services so production invariants stay intact.
 *
 * Run:
 *   php artisan db:seed --class=DummyOrderSeeder
 */
class DummyOrderSeeder extends Seeder
{
    private const ORDER_COUNT = 75;

    private const RESELLER_PHONE = '0799000001';

    private const CUSTOMER_NAME_PREFIX = 'Test Customer ';

    /** Reserved SL-local mobiles: 0709110001 … 0709110099 */
    private const CUSTOMER_PHONE_PREFIX = '070911';

    private const SECONDARY_PHONE_PREFIX = '071911';

    private const SEEDER_MARKER = 'DummyOrderSeeder';

    private const CUSTOMER_POOL_SIZE = 28;

    private OrderService $orderService;

    private OrderStatusService $statusService;

    private OrderCcaAssignmentService $assignmentService;

    private OrderCommentService $commentService;

    private CustomerBanService $banService;

    private CallCenterAgentEligibilityService $ccaEligibility;

    private CustomerPhoneNormalizer $phoneNormalizer;

    /** @var list<string> */
    private array $commentBodies = [
        'Customer requested evening delivery',
        'Customer confirmed address',
        'Follow up tomorrow',
        'Customer requested call back',
        'Order confirmed by customer',
        'Left voicemail — retry afternoon',
        'Customer asked for alternate contact number',
    ];

    public function run(): void
    {
        $this->orderService = app(OrderService::class);
        $this->statusService = app(OrderStatusService::class);
        $this->assignmentService = app(OrderCcaAssignmentService::class);
        $this->commentService = app(OrderCommentService::class);
        $this->banService = app(CustomerBanService::class);
        $this->ccaEligibility = app(CallCenterAgentEligibilityService::class);
        $this->phoneNormalizer = app(CustomerPhoneNormalizer::class);

        $reseller = $this->resolveReseller();
        $company = $reseller->company;
        $markets = $this->resolveMarkets($company);
        $this->ensureAfterHoursPenaltiesConfigured($markets);
        $catalog = $this->resolveSellableCatalog($reseller, $markets);
        $ccas = $this->resolveEligibleCcas($company);

        if ($ccas->isEmpty()) {
            throw new RuntimeException(
                'DummyOrderSeeder requires at least one eligible active CCA in company "'.$company->name.'".'
            );
        }

        $this->purgePreviousDummyOrders((int) $company->id);
        $this->purgePreviousDummyCrib($markets);

        $customers = $this->prepareCustomers($reseller, $markets->first());
        $this->seedCribRecords($markets->first(), $customers);
        $this->seedBannedCustomers($reseller, $customers);

        $scenarios = $this->buildScenarios($ccas);
        $createdOrders = [];
        $stats = [
            'by_assignment' => [
                OrderAssignmentState::ASSIGNED->value => 0,
                OrderAssignmentState::UNASSIGNED->value => 0,
                OrderAssignmentState::POOL->value => 0,
            ],
            'by_status' => [],
            'by_creator' => ['reseller' => 0, 'cca' => 0],
            'by_source' => [],
            'by_origin' => [
                OrderCcaAssignmentOrigin::DIRECT->value => 0,
                OrderCcaAssignmentOrigin::MANUAL_CREATE->value => 0,
                OrderCcaAssignmentOrigin::POOL_CLAIM->value => 0,
            ],
            'comments' => 0,
            'pool_claims' => 0,
        ];

        foreach (OrderStatus::cases() as $status) {
            $stats['by_status'][$status->value] = 0;
        }
        foreach (OrderSource::cases() as $source) {
            $stats['by_source'][$source->value] = 0;
        }

        $poolClaimCount = 0;

        try {
            for ($i = 0; $i < self::ORDER_COUNT; $i++) {
                $scenario = $scenarios[$i];
                $createdAt = $this->historicalCreatedAt($i);

                Carbon::setTestNow($createdAt);

                $customer = $customers[$i % count($customers)];
                $lineBundle = $this->pickLineBundle($catalog, $i);
                $creatorIsCca = $scenario['creator'] === 'cca';
                $creator = $creatorIsCca ? $ccas->random() : $reseller;

                $discount = $this->pickDiscount((float) $lineBundle['subtotal_estimate'], $i);
                $source = $scenario['source'];

                $order = $this->orderService->create([
                    'source' => $source,
                    'market_id' => $lineBundle['market_id'],
                    'reseller_id' => (int) $reseller->id,
                    'reseller_company_id' => (int) $company->id,
                    'supplier_id' => $lineBundle['supplier_id'],
                    'created_by' => (int) $creator->id,
                    'duplicate_warning_overridden' => true,
                    'after_hours_warning_shown' => $i % 7 === 0,
                    'evaluated_at' => CarbonImmutable::instance($createdAt),
                    'discount_amount' => $discount,
                    'courier_fee_amount' => 0,
                    'customer' => [
                        'display_name' => $customer['display_name'],
                        'primary_country_id' => $customer['country_id'],
                        'primary_phone' => $customer['primary_phone'],
                        'primary_phone_country_id' => $customer['country_id'],
                        'secondary_phone' => $customer['secondary_phone'],
                        'secondary_phone_country_id' => $customer['secondary_phone'] !== null
                            ? $customer['country_id']
                            : null,
                    ],
                    'address' => [
                        'recipient_name' => $customer['display_name'],
                        'line1' => $customer['address_line1'],
                        'line2' => $customer['address_line2'],
                        'city_name' => $customer['city_name'],
                        'district_name' => $customer['district_name'],
                        'postal_code' => $customer['postal_code'],
                        'country_id' => $customer['country_id'],
                        'full_address_text' => $customer['address_line1'].', '.$customer['city_name'],
                    ],
                    'items' => $lineBundle['items'],
                ]);

                // Assignment + status applied through domain services under frozen clock.
                $originUsed = $this->applyAssignmentScenario(
                    $order,
                    $scenario,
                    $reseller,
                    $ccas,
                    $creatorIsCca ? $creator : null,
                    $poolClaimCount,
                );

                if ($originUsed === OrderCcaAssignmentOrigin::POOL_CLAIM) {
                    $poolClaimCount++;
                    $stats['pool_claims']++;
                }
                if ($originUsed !== null) {
                    $stats['by_origin'][$originUsed->value]++;
                }

                $order = $order->fresh();
                $this->applyStatusPath($order, $scenario['status'], $creator, (int) $company->id, $createdAt);

                $order = $order->fresh();
                $commentsAdded = $this->maybeAddComments($order, $creator, (int) $company->id, $i);
                $stats['comments'] += $commentsAdded;

                $order = $order->fresh();
                $state = $order->assignmentState();
                $stats['by_assignment'][$state->value]++;
                $statusValue = $order->status instanceof OrderStatus
                    ? $order->status->value
                    : (string) $order->status;
                $stats['by_status'][$statusValue] = ($stats['by_status'][$statusValue] ?? 0) + 1;
                $stats['by_creator'][$creatorIsCca ? 'cca' : 'reseller']++;
                $sourceValue = $order->source instanceof OrderSource
                    ? $order->source->value
                    : (string) $order->source;
                $stats['by_source'][$sourceValue] = ($stats['by_source'][$sourceValue] ?? 0) + 1;

                $createdOrders[] = $order->id;
            }
        } finally {
            Carbon::setTestNow();
        }

        $bannedCount = Customer::query()
            ->where('display_name', 'like', self::CUSTOMER_NAME_PREFIX.'%')
            ->where('is_banned', true)
            ->count();

        $cribCount = CribRecord::query()
            ->where('normalized_phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')
            ->where('is_active', true)
            ->count();

        $customersWithHistory = Customer::query()
            ->where('display_name', 'like', self::CUSTOMER_NAME_PREFIX.'%')
            ->whereHas('orders', fn ($q) => $q->where('reseller_company_id', $company->id))
            ->withCount(['orders' => fn ($q) => $q->where('reseller_company_id', $company->id)])
            ->get()
            ->filter(fn (Customer $c) => $c->orders_count > 1)
            ->count();

        $this->printSummary(
            $reseller,
            $company,
            count($createdOrders),
            $stats,
            count($customers),
            $customersWithHistory,
            $bannedCount,
            $cribCount,
            $catalog->sum(fn ($group) => $group['variants']->count()),
            $ccas,
        );
    }

    private function resolveReseller(): User
    {
        $user = User::query()
            ->where('phone', self::RESELLER_PHONE)
            ->with(['company.portal', 'company.owner'])
            ->first();

        if ($user === null) {
            throw new RuntimeException(
                'DummyOrderSeeder: reseller with phone '.self::RESELLER_PHONE.' was not found. '
                .'Refusing to create a reseller.'
            );
        }

        if ($user->company === null || ! $user->company->isResellerCompany()) {
            throw new RuntimeException(
                'DummyOrderSeeder: user '.self::RESELLER_PHONE.' is not attached to a reseller company.'
            );
        }

        $status = $user->status instanceof UserStatus
            ? $user->status
            : UserStatus::tryFrom((string) $user->status);

        if ($status !== UserStatus::ACTIVE) {
            throw new RuntimeException(
                'DummyOrderSeeder: reseller '.self::RESELLER_PHONE.' is not ACTIVE.'
            );
        }

        return $user;
    }

    /**
     * OrderService requires after_hours_penalty market settings when creation
     * falls outside 07:00–21:00. Fill missing defaults only — never overwrite.
     *
     * @param  Collection<int, Market>  $markets
     */
    private function ensureAfterHoursPenaltiesConfigured(Collection $markets): void
    {
        $afterHours = app(\Feeder\Core\Services\Order\AfterHoursDeterminationService::class);

        foreach ($markets as $market) {
            if ($afterHours->hasPenaltyAmount($market)) {
                continue;
            }

            $code = strtolower((string) $market->code);
            $default = \Feeder\Core\Services\Order\AfterHoursDeterminationService::MARKET_DEFAULTS[$code] ?? null;

            if ($default === null) {
                throw new RuntimeException(
                    'DummyOrderSeeder: after-hours penalty is not configured for market '.$market->code
                    .' and no MARKET_DEFAULTS entry exists.'
                );
            }

            $afterHours->setPenaltyAmount($market, $default);
            $this->command?->warn(
                'Configured missing after_hours_penalty for market '.$market->code.' = '.$default
                .' (required for OrderService; existing values are never overwritten).'
            );
        }
    }

    /**
     * @return Collection<int, Market>
     */
    private function resolveMarkets($company): Collection
    {
        $markets = $company->allowedMarkets()
            ->with(['currency', 'country'])
            ->where('markets.is_active', true)
            ->get();

        if ($markets->isEmpty()) {
            throw new RuntimeException(
                'DummyOrderSeeder: reseller company "'.$company->name.'" has no accessible active markets.'
            );
        }

        return $markets;
    }

    /**
     * Group active variants by supplier+market for valid single-supplier orders.
     *
     * @return Collection<int, array{supplier_id: int, market_id: int, variants: Collection<int, ProductVariant>}>
     */
    private function resolveSellableCatalog(User $reseller, Collection $markets): Collection
    {
        $supplierIds = ResellerSupplierAssignment::query()
            ->where('reseller_id', $reseller->id)
            ->pluck('supplier_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($supplierIds === []) {
            throw new RuntimeException(
                'DummyOrderSeeder: reseller has no assigned suppliers.'
            );
        }

        $marketIds = $markets->pluck('id')->map(fn ($id) => (int) $id)->all();

        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', function ($query) use ($supplierIds, $marketIds) {
                $query->whereIn('supplier_id', $supplierIds)
                    ->whereIn('market_id', $marketIds)
                    ->where('status', ProductStatus::ACTIVE->value)
                    ->where('system_visible', true);
            })
            ->with(['product:id,name,supplier_id,market_id,status,system_visible'])
            ->get();

        if ($variants->isEmpty()) {
            throw new RuntimeException(
                'DummyOrderSeeder: no active sellable product variants for this reseller\'s suppliers/markets.'
            );
        }

        return $variants
            ->groupBy(fn (ProductVariant $variant) => $variant->product->supplier_id.'|'.$variant->product->market_id)
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'supplier_id' => (int) $first->product->supplier_id,
                    'market_id' => (int) $first->product->market_id,
                    'variants' => $group->values(),
                ];
            })
            ->values()
            ->filter(fn (array $group) => $group['variants']->isNotEmpty())
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveEligibleCcas($company): Collection
    {
        return User::query()
            ->where('company_id', $company->id)
            ->whereHas('role', fn ($q) => $q->where('slug', CallCenterAgentEligibilityService::ROLE_SLUG))
            ->with(['profile', 'role.portal'])
            ->orderBy('id')
            ->get()
            ->filter(fn (User $cca) => $this->ccaEligibility->isEligible($cca, (int) $company->id))
            ->values();
    }

    private function purgePreviousDummyOrders(int $resellerCompanyId): void
    {
        $orderIds = Order::query()
            ->where('reseller_company_id', $resellerCompanyId)
            ->where('customer_name_snapshot', 'like', self::CUSTOMER_NAME_PREFIX.'%')
            ->where('primary_phone_snapshot', 'like', self::CUSTOMER_PHONE_PREFIX.'%')
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return;
        }

        // Clear self-FK references among dummy orders before delete.
        Order::query()
            ->whereIn('duplicate_reference_order_id', $orderIds)
            ->update(['duplicate_reference_order_id' => null]);

        Order::query()->whereIn('id', $orderIds)->delete();

        $this->command?->info('Purged '.$orderIds->count().' previous DummyOrderSeeder order(s).');
    }

    private function purgePreviousDummyCrib(Collection $markets): void
    {
        $marketIds = $markets->pluck('id')->all();

        CribRecord::query()
            ->whereIn('market_id', $marketIds)
            ->where('normalized_phone', 'like', self::CUSTOMER_PHONE_PREFIX.'%')
            ->delete();

        CribImportBatch::query()
            ->whereIn('market_id', $marketIds)
            ->where('source_name', self::SEEDER_MARKER)
            ->delete();
    }

    /**
     * @return list<array{
     *     display_name: string,
     *     primary_phone: string,
     *     secondary_phone: ?string,
     *     country_id: int,
     *     address_line1: string,
     *     address_line2: ?string,
     *     city_name: string,
     *     district_name: string,
     *     postal_code: string,
     *     banned: bool,
     *     crib: ?array{risk_level: string, risk_code: string, risk_summary: string}
     * }>
     */
    private function prepareCustomers(User $reseller, Market $market): array
    {
        $countryId = (int) $market->country_id;
        $customers = [];

        $cities = [
            ['Colombo', 'Colombo', '00100'],
            ['Negombo', 'Gampaha', '11500'],
            ['Kandy', 'Kandy', '20000'],
            ['Galle', 'Galle', '80000'],
            ['Jaffna', 'Jaffna', '40000'],
        ];

        for ($i = 1; $i <= self::CUSTOMER_POOL_SIZE; $i++) {
            $city = $cities[($i - 1) % count($cities)];
            $hasSecondary = $i % 3 === 0;
            $banned = in_array($i, [5, 17], true);
            $crib = null;

            if (in_array($i, [3, 8, 12, 20], true)) {
                $crib = match ($i) {
                    3 => [
                        'risk_level' => 'HIGH',
                        'risk_code' => 'CRIB_HIGH_DEFAULT',
                        'risk_summary' => 'Dummy CRIB: repeated payment defaults reported.',
                    ],
                    8 => [
                        'risk_level' => 'MEDIUM',
                        'risk_code' => 'CRIB_MED_DISPUTE',
                        'risk_summary' => 'Dummy CRIB: prior delivery dispute on COD orders.',
                    ],
                    12 => [
                        'risk_level' => 'LOW',
                        'risk_code' => 'CRIB_LOW_WATCH',
                        'risk_summary' => 'Dummy CRIB: watchlist — minor late payments.',
                    ],
                    default => [
                        'risk_level' => 'HIGH',
                        'risk_code' => 'CRIB_FRAUD_FLAG',
                        'risk_summary' => 'Dummy CRIB: suspected fraud pattern on phone.',
                    ],
                };
            }

            $customers[] = [
                'display_name' => self::CUSTOMER_NAME_PREFIX.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'primary_phone' => self::CUSTOMER_PHONE_PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'secondary_phone' => $hasSecondary
                    ? self::SECONDARY_PHONE_PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT)
                    : null,
                'country_id' => $countryId,
                'address_line1' => 'No. '.$i.', Dummy Seeder Lane',
                'address_line2' => $i % 2 === 0 ? 'Near test landmark' : null,
                'city_name' => $city[0],
                'district_name' => $city[1],
                'postal_code' => $city[2],
                'banned' => $banned,
                'crib' => $crib,
            ];
        }

        return $customers;
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     */
    private function seedCribRecords(Market $market, array $customers): void
    {
        $batch = CribImportBatch::query()->create([
            'market_id' => $market->id,
            'source_name' => self::SEEDER_MARKER,
            'source_file_hash' => sha1(self::SEEDER_MARKER.'|'.now()->toDateString()),
            'imported_at' => now(),
            'record_count' => 0,
            'status' => CribImportStatus::COMPLETED->value,
            'notes' => 'Generated by DummyOrderSeeder for Order UI testing.',
            'created_by' => null,
        ]);

        $count = 0;
        $country = $market->country;

        foreach ($customers as $customer) {
            if ($customer['crib'] === null) {
                continue;
            }

            $normalized = $this->phoneNormalizer->normalize($customer['primary_phone'], $country);

            CribRecord::query()->updateOrCreate(
                [
                    'market_id' => $market->id,
                    'normalized_phone' => $normalized,
                ],
                [
                    'country_id' => (int) $market->country_id,
                    'risk_level' => $customer['crib']['risk_level'],
                    'risk_code' => $customer['crib']['risk_code'],
                    'risk_summary' => $customer['crib']['risk_summary'],
                    'is_active' => true,
                    'last_import_batch_id' => $batch->id,
                    'source_updated_at' => now(),
                    'raw_payload' => [
                        'seeder' => self::SEEDER_MARKER,
                        'display_name' => $customer['display_name'],
                    ],
                ]
            );
            $count++;
        }

        $batch->update(['record_count' => $count]);
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     */
    private function seedBannedCustomers(User $reseller, array $customers): void
    {
        foreach ($customers as $customer) {
            if (! $customer['banned']) {
                continue;
            }

            // Ensure customer/phone rows exist via a no-op identity create path:
            // place a tiny provisional lookup by creating identity through a throwaway approach.
            // Use CustomerIdentity via OrderService would create orders — instead resolve phones directly.
            $this->ensureCustomerExistsForBan($reseller, $customer);
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function ensureCustomerExistsForBan(User $reseller, array $customer): void
    {
        $identity = app(\Feeder\Core\Services\Order\CustomerIdentityService::class)->resolveOrCreate([
            'display_name' => $customer['display_name'],
            'primary_country_id' => $customer['country_id'],
            'primary_phone' => $customer['primary_phone'],
            'primary_phone_country_id' => $customer['country_id'],
            'secondary_phone' => $customer['secondary_phone'],
            'secondary_phone_country_id' => $customer['secondary_phone'] !== null
                ? $customer['country_id']
                : null,
            'created_by' => $reseller->id,
        ]);

        $model = $identity['customer'];

        if ($model->is_banned) {
            return;
        }

        $this->banService->ban(
            $model,
            (int) $reseller->id,
            (int) $reseller->company_id,
            'DummyOrderSeeder: test global ban for Order Create UI',
        );
    }

    /**
     * @param  Collection<int, User>  $ccas
     * @return list<array{assignment: string, status: OrderStatus, source: OrderSource, creator: string, origin?: string}>
     */
    private function buildScenarios(Collection $ccas): array
    {
        $scenarios = [];

        // Rough thirds for assignment states, with CCA-created / pool-claim / meta mix.
        for ($i = 0; $i < self::ORDER_COUNT; $i++) {
            $assignment = match (true) {
                $i < 28 => OrderAssignmentState::ASSIGNED->value,
                $i < 52 => OrderAssignmentState::UNASSIGNED->value,
                default => OrderAssignmentState::POOL->value,
            };

            $creator = 'reseller';
            $origin = OrderCcaAssignmentOrigin::DIRECT->value;

            // CCA-created orders must end Assigned to creator (manual_create).
            if ($i < 12) {
                $assignment = OrderAssignmentState::ASSIGNED->value;
                $creator = 'cca';
                $origin = OrderCcaAssignmentOrigin::MANUAL_CREATE->value;
            }

            // A few pool-claim examples among assigned (indices 12–15).
            if ($i >= 12 && $i <= 15) {
                $assignment = OrderAssignmentState::ASSIGNED->value;
                $creator = 'reseller';
                $origin = OrderCcaAssignmentOrigin::POOL_CLAIM->value;
            }

            $status = $this->statusForIndex($i, $assignment);
            $source = match (true) {
                $i % 11 === 0 => OrderSource::META_IMPORT,
                $i % 19 === 0 => OrderSource::API,
                default => OrderSource::MANUAL,
            };

            $scenarios[] = [
                'assignment' => $assignment,
                'status' => $status,
                'source' => $source,
                'creator' => $creator,
                'origin' => $origin,
            ];
        }

        return $scenarios;
    }

    private function statusForIndex(int $i, string $assignment): OrderStatus
    {
        // Cancelled examples (including one recent for reactivation UI).
        if (in_array($i, [16, 17, 18, 40, 55, 70], true)) {
            return OrderStatus::CANCELLED;
        }

        // Confirmed reseller examples.
        if ($i >= 20 && $i <= 32 && $assignment !== OrderAssignmentState::POOL->value) {
            return OrderStatus::CONFIRMED;
        }

        return match ($i % 10) {
            0, 1 => OrderStatus::PENDING,
            2 => OrderStatus::CONFIRMED,
            3 => OrderStatus::FIRST_ATTEMPT,
            4 => OrderStatus::SECOND_ATTEMPT,
            5 => OrderStatus::THIRD_ATTEMPT,
            6, 7 => OrderStatus::HOLD,
            8 => OrderStatus::CONFIRMED,
            default => OrderStatus::PENDING,
        };
    }

    private function historicalCreatedAt(int $index): Carbon
    {
        // Spread across today → ~40 days ago with deterministic offsets.
        $daysAgo = match (true) {
            $index < 8 => 0,
            $index < 16 => 1,
            $index < 30 => random_int(2, 6),
            $index < 50 => random_int(7, 20),
            default => random_int(21, 40),
        };

        // Index 16 = recently cancelled (within 14-day window).
        if ($index === 16) {
            $daysAgo = 2;
        }
        // Index 70 = older cancelled (outside window for hide testing).
        if ($index === 70) {
            $daysAgo = 20;
        }

        // Keep wall-clock within market operating hours (07:00–21:00) so after-hours
        // is deterministic and mostly false; a few evening samples still exercise it.
        $hour = ($index % 11 === 0) ? 22 : (10 + ($index % 8));

        return now()
            ->subDays($daysAgo)
            ->setTime($hour, ($index * 7) % 60, ($index * 3) % 60);
    }

    /**
     * @param  Collection<int, array{supplier_id: int, market_id: int, variants: Collection<int, ProductVariant>}>  $catalog
     * @return array{supplier_id: int, market_id: int, items: list<array{product_variant_id: int, quantity: int, unit_selling_price: float}>, subtotal_estimate: float}
     */
    private function pickLineBundle(Collection $catalog, int $index): array
    {
        $group = $catalog[$index % $catalog->count()];
        $variants = $group['variants'];
        $lineCount = 1 + ($index % 3);
        $lineCount = min($lineCount, $variants->count());

        $picked = $variants->values()->slice(($index * 2) % max(1, $variants->count() - $lineCount + 1), $lineCount);
        if ($picked->count() < $lineCount) {
            $picked = $variants->take($lineCount);
        }

        $items = [];
        $subtotal = 0.0;

        foreach ($picked as $offset => $variant) {
            $qty = 1 + (($index + $offset) % 5);
            $price = round((float) $variant->selling_price, 2);
            $items[] = [
                'product_variant_id' => (int) $variant->id,
                'quantity' => $qty,
                'unit_selling_price' => $price,
            ];
            $subtotal += $price * $qty;
        }

        return [
            'supplier_id' => $group['supplier_id'],
            'market_id' => $group['market_id'],
            'items' => $items,
            'subtotal_estimate' => round($subtotal, 2),
        ];
    }

    private function pickDiscount(float $subtotal, int $index): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        return match ($index % 5) {
            0 => 0.0,
            1 => round(min(50, $subtotal * 0.05), 2),
            2 => round(min($subtotal * 0.15, $subtotal - 1), 2),
            3 => 0.0,
            default => round(min(100, $subtotal * 0.08), 2),
        };
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  Collection<int, User>  $ccas
     */
    private function applyAssignmentScenario(
        Order $order,
        array $scenario,
        User $reseller,
        Collection $ccas,
        ?User $ccaCreator,
        int $poolClaimCountSoFar,
    ): ?OrderCcaAssignmentOrigin {
        $companyId = (int) $reseller->company_id;

        if ($scenario['creator'] === 'cca' && $ccaCreator !== null) {
            $this->assignmentService->assign(
                $order,
                (int) $ccaCreator->id,
                (int) $ccaCreator->id,
                'DummyOrderSeeder: CCA manual create',
                $companyId,
                OrderCcaAssignmentOrigin::MANUAL_CREATE,
            );

            return OrderCcaAssignmentOrigin::MANUAL_CREATE;
        }

        if (($scenario['origin'] ?? null) === OrderCcaAssignmentOrigin::POOL_CLAIM->value) {
            // One-active-pool-claim rule: only the first claim in a batch can remain
            // without a follow-up status change; subsequent claims get a status nudge
            // inside applyStatusPath. Between claims we transition HOLD briefly here
            // for prior claimed orders is handled by status path on each order itself.
            // For this order: pool → claim.
            $cca = $ccas[$poolClaimCountSoFar % $ccas->count()];

            // If CCA already has a blocking claim from an earlier seeded order in this run,
            // clear the block with a status change on that prior order via service — handled
            // because each pool_claim order receives a non-PENDING status in statusForIndex.
            // Still, within this loop the previous claim may lack a status change yet.
            if ($this->assignmentService->hasBlockingActivePoolClaim($cca, $companyId)) {
                $blocking = $this->assignmentService->blockingActivePoolClaim($cca, $companyId);
                if ($blocking?->order !== null) {
                    Carbon::setTestNow(now()->addMinutes(5));
                    $this->statusService->transition(
                        $blocking->order->fresh(),
                        OrderStatus::HOLD,
                        (int) $cca->id,
                        $companyId,
                        'DummyOrderSeeder: unlock pool claim for next claim example',
                        $companyId,
                    );
                    Carbon::setTestNow(now()->addMinutes(1));
                }
            }

            $this->assignmentService->moveToPool($order->fresh(), (int) $reseller->id, $companyId);
            $this->assignmentService->claimFromPool($order->fresh(), $cca, $companyId);

            return OrderCcaAssignmentOrigin::POOL_CLAIM;
        }

        return match ($scenario['assignment']) {
            OrderAssignmentState::ASSIGNED->value => $this->assignDirect($order, $reseller, $ccas),
            OrderAssignmentState::POOL->value => $this->movePool($order, $reseller),
            default => $this->leaveUnassigned($order, $reseller),
        };
    }

    /**
     * @param  Collection<int, User>  $ccas
     */
    private function assignDirect(Order $order, User $reseller, Collection $ccas): OrderCcaAssignmentOrigin
    {
        $cca = $ccas->random();
        $this->assignmentService->assign(
            $order,
            (int) $cca->id,
            (int) $reseller->id,
            'DummyOrderSeeder: direct assignment',
            (int) $reseller->company_id,
            OrderCcaAssignmentOrigin::DIRECT,
        );

        return OrderCcaAssignmentOrigin::DIRECT;
    }

    private function movePool(Order $order, User $reseller): ?OrderCcaAssignmentOrigin
    {
        $this->assignmentService->moveToPool(
            $order,
            (int) $reseller->id,
            (int) $reseller->company_id,
        );

        return null;
    }

    private function leaveUnassigned(Order $order, User $reseller): ?OrderCcaAssignmentOrigin
    {
        // Explicit unassign keeps available_in_pool = false after create defaults.
        $this->assignmentService->unassign(
            $order,
            (int) $reseller->id,
            (int) $reseller->company_id,
        );

        return null;
    }

    private function applyStatusPath(
        Order $order,
        OrderStatus $target,
        User $actor,
        int $companyId,
        Carbon $createdAt,
    ): void {
        if ($target === OrderStatus::PENDING) {
            return;
        }

        $path = match ($target) {
            OrderStatus::CONFIRMED => [OrderStatus::CONFIRMED],
            OrderStatus::HOLD => [OrderStatus::HOLD],
            OrderStatus::FIRST_ATTEMPT => [OrderStatus::FIRST_ATTEMPT],
            OrderStatus::SECOND_ATTEMPT => [OrderStatus::FIRST_ATTEMPT, OrderStatus::SECOND_ATTEMPT],
            OrderStatus::THIRD_ATTEMPT => [
                OrderStatus::FIRST_ATTEMPT,
                OrderStatus::SECOND_ATTEMPT,
                OrderStatus::THIRD_ATTEMPT,
            ],
            OrderStatus::CANCELLED => [OrderStatus::CANCELLED],
            default => [OrderStatus::CONFIRMED],
        };

        $cursor = $createdAt->copy();

        foreach ($path as $stepIndex => $status) {
            $cursor = $cursor->copy()->addHours(2 + $stepIndex);
            Carbon::setTestNow($cursor);

            $this->statusService->transition(
                $order->fresh(),
                $status,
                (int) $actor->id,
                $companyId,
                'DummyOrderSeeder: '.$status->label(),
                $companyId,
            );
        }
    }

    private function maybeAddComments(Order $order, User $actor, int $companyId, int $index): int
    {
        if ($index % 3 !== 0) {
            return 0;
        }

        $count = 1 + ($index % 2);
        $added = 0;

        for ($c = 0; $c < $count; $c++) {
            Carbon::setTestNow(now()->addMinutes(10 + ($c * 5)));
            $body = $this->commentBodies[($index + $c) % count($this->commentBodies)];
            $context = $c === 0 ? OrderCommentContextType::ORDER : OrderCommentContextType::CCA;

            $this->commentService->add(
                $order->fresh(),
                $body,
                $context,
                (int) $actor->id,
                $companyId,
                self::SEEDER_MARKER,
                $companyId,
            );
            $added++;
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  Collection<int, User>  $ccas
     */
    private function printSummary(
        User $reseller,
        $company,
        int $orderCount,
        array $stats,
        int $customerCount,
        int $customersWithHistory,
        int $bannedCount,
        int $cribCount,
        int $variantPoolSize,
        Collection $ccas,
    ): void {
        $lines = [
            '',
            'Dummy Order Seeder completed',
            '',
            'Reseller:',
            self::RESELLER_PHONE,
            'Company: '.$company->name.' (#'.$company->id.')',
            '',
            'Orders created:',
            (string) $orderCount,
            '',
            'Assignment state:',
            'Assigned: '.$stats['by_assignment'][OrderAssignmentState::ASSIGNED->value],
            'Unassigned: '.$stats['by_assignment'][OrderAssignmentState::UNASSIGNED->value],
            'Order Pool: '.$stats['by_assignment'][OrderAssignmentState::POOL->value],
            '',
            'Created by:',
            'Reseller: '.$stats['by_creator']['reseller'],
            'CCA: '.$stats['by_creator']['cca'],
            '',
            'Assignment origins:',
            'direct: '.$stats['by_origin'][OrderCcaAssignmentOrigin::DIRECT->value],
            'manual_create: '.$stats['by_origin'][OrderCcaAssignmentOrigin::MANUAL_CREATE->value],
            'pool_claim: '.$stats['by_origin'][OrderCcaAssignmentOrigin::POOL_CLAIM->value],
            '',
            'Statuses:',
        ];

        foreach (OrderStatus::cases() as $status) {
            $lines[] = $status->label().': '.($stats['by_status'][$status->value] ?? 0);
        }

        $lines[] = '';
        $lines[] = 'Sources:';
        foreach (OrderSource::cases() as $source) {
            $lines[] = $source->label().': '.($stats['by_source'][$source->value] ?? 0);
        }

        $lines = array_merge($lines, [
            '',
            'Customers:',
            (string) $customerCount,
            'Customers with order history (>1 order):',
            (string) $customersWithHistory,
            'Banned customers:',
            (string) $bannedCount,
            'CRIB records:',
            (string) $cribCount,
            'Comments:',
            (string) $stats['comments'],
            '',
            'Catalog reused:',
            'Sellable variant pool size: '.$variantPoolSize,
            'Eligible CCAs: '.$ccas->count(),
            '',
            'Note: OrderStatus has no PROCESSING/SHIPPED/DELIVERED/RETURNED — used real enum values only.',
            'Note: No shipments created (no external courier API calls).',
            '',
        ]);

        foreach ($lines as $line) {
            $this->command?->info($line);
        }
    }
}
