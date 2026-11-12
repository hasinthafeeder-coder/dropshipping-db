<?php

namespace Tests\Feature\Notification;

use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\OrderSource;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Customer;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\ResellerEarningObligation;
use Feeder\Core\Models\ResellerSettlementBatch;
use Feeder\Core\Models\ResellerSettlementInvoice;
use Feeder\Core\Models\SupplierSettlementBatch;
use Feeder\Core\Models\SupplierSettlementInvoice;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Notification\FinancialNotificationService;
use Feeder\Core\Services\Notification\NotificationInboxService;
use Feeder\Core\Services\Notification\NotificationPublicationService;
use Feeder\Core\Services\Notification\PublishNotificationData;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class FinancialNotificationPhase3Test extends TestCase
{
    use SetsUpOrderFoundationData;

    private FinancialNotificationService $financeNotifications;

    private NotificationInboxService $inbox;

    private User $admin;

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

        $this->ensureNotificationSchema();

        DB::beginTransaction();

        $this->seedMarketLookups();

        $this->admin = $this->makeAdminUser();
        $this->financeNotifications = app(FinancialNotificationService::class);
        $this->inbox = app(NotificationInboxService::class);

        Carbon::setTestNow('2026-11-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_delivery_notifies_reseller_and_supplier_with_stable_idempotency(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $order = $this->makeDeliveredOrder($reseller, $supplier);
        $obligation = $this->makeEarning($order, $reseller, '125.50');

        $first = $this->financeNotifications->notifyOrderDelivered($this->admin, $order, $obligation);
        $second = $this->financeNotifications->notifyOrderDelivered($this->admin, $order, $obligation);

        $this->assertNotNull($first['reseller']);
        $this->assertNotNull($first['supplier']);
        $this->assertSame((int) $first['reseller']->id, (int) $second['reseller']->id);
        $this->assertSame((int) $first['supplier']->id, (int) $second['supplier']->id);

        $this->assertSame(1, Notification::query()->where('source_event_key', 'finance.order_delivered.reseller:'.$order->id)->count());
        $this->assertSame(1, Notification::query()->where('source_event_key', 'finance.order_delivered.supplier:'.$order->id)->count());

        $this->assertSame(1, $this->inbox->unreadCountFor($reseller));
        $this->assertSame(1, $this->inbox->unreadCountFor($supplier));
        $this->assertStringContainsString('125.50', (string) $first['reseller']->body);
        $this->assertStringContainsString('Supplier cost snapshot', (string) $first['supplier']->body);
    }

    public function test_returned_and_undelivered_orders_do_not_notify_delivery_income(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $returned = $this->makeDeliveredOrder($reseller, $supplier, OrderStatus::RETURNED);
        $dispatched = $this->makeDeliveredOrder($reseller, $supplier, OrderStatus::DISPATCHED);

        $returnedResult = $this->financeNotifications->notifyOrderDelivered($this->admin, $returned);
        $dispatchedResult = $this->financeNotifications->notifyOrderDelivered($this->admin, $dispatched);

        $this->assertNull($returnedResult['reseller']);
        $this->assertNull($returnedResult['supplier']);
        $this->assertNull($dispatchedResult['reseller']);
        $this->assertNull($dispatchedResult['supplier']);
        $this->assertSame(0, $this->inbox->unreadCountFor($reseller));
        $this->assertSame(0, $this->inbox->unreadCountFor($supplier));
    }

    public function test_recipient_isolation_keeps_reseller_and_supplier_finances_separate(): void
    {
        $resellerA = $this->makeResellerUser();
        $resellerB = $this->makeResellerUser();
        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $order = $this->makeDeliveredOrder($resellerA, $supplierA);
        $obligation = $this->makeEarning($order, $resellerA, '88.00');

        $this->financeNotifications->notifyOrderDelivered($this->admin, $order, $obligation);

        $this->assertSame(1, $this->inbox->unreadCountFor($resellerA));
        $this->assertSame(0, $this->inbox->unreadCountFor($resellerB));
        $this->assertSame(1, $this->inbox->unreadCountFor($supplierA));
        $this->assertSame(0, $this->inbox->unreadCountFor($supplierB));

        $visibleA = $this->inbox->visibleRecipientsFor($resellerA)->first();
        $this->assertNotNull($visibleA);
        $this->assertStringContainsString('88.00', (string) $visibleA->notification->body);
        $this->assertStringNotContainsString($supplierB->email, (string) $visibleA->notification->body);

        $this->expectException(ValidationException::class);
        $this->inbox->markRead($resellerB, (string) $visibleA->uuid);
    }

    public function test_daily_summary_aggregates_by_business_date_boundaries(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();

        $firstSameDay = $this->makeDeliveredOrder($reseller, $supplier);
        $secondSameDay = $this->makeDeliveredOrder($reseller, $supplier);
        $outWindow = $this->makeDeliveredOrder($reseller, $supplier);
        $this->makeEarning($firstSameDay, $reseller, '10.00', '2026-11-09');
        $this->makeEarning($secondSameDay, $reseller, '15.00', '2026-11-09');
        $this->makeEarning($outWindow, $reseller, '99.00', '2026-11-08');

        $summary = $this->financeNotifications->publishDailySummaries($this->admin, '2026-11-09');
        $again = $this->financeNotifications->publishDailySummaries($this->admin, '2026-11-09');

        $this->assertSame(1, $summary['reseller_count']);
        $this->assertSame(1, $summary['supplier_count']);
        $this->assertSame(1, $again['reseller_count']);

        $notification = Notification::query()
            ->where('source_event_key', 'finance.daily_summary.reseller:'.$reseller->id.':2026-11-09')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(1, Notification::query()
            ->where('source_event_key', 'finance.daily_summary.reseller:'.$reseller->id.':2026-11-09')
            ->count());
        $this->assertStringContainsString('Delivered orders: 2', (string) $notification->body);
        $this->assertStringContainsString('25.00', (string) $notification->body);
        $this->assertStringNotContainsString('99.00', (string) $notification->body);
    }

    public function test_settlement_ready_and_completed_notify_actual_recipients(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $market = $this->marketByCode('lk');

        $resellerBatch = ResellerSettlementBatch::query()->create([
            'uuid' => UuidService::generate(),
            'batch_number' => 'RSB-'.Str::upper(Str::random(6)),
            'market_id' => $market->id,
            'market_code_snapshot' => 'lk',
            'timezone_snapshot' => 'Asia/Colombo',
            'settlement_date' => '2026-11-09',
            'kind' => ResellerSettlementBatch::KIND_DAILY,
            'report_type' => ResellerSettlementBatch::REPORT_TYPE_PLUS,
            'sequence' => 1,
            'status' => ResellerSettlementBatch::STATUS_LOCKED,
            'generation_source' => ResellerSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $this->admin->id,
            'generated_at' => now(),
            'locked_by' => $this->admin->id,
            'locked_at' => now(),
        ]);

        $resellerInvoice = ResellerSettlementInvoice::query()->create([
            'uuid' => UuidService::generate(),
            'invoice_number' => 'RSI-'.Str::upper(Str::random(6)),
            'batch_id' => $resellerBatch->id,
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'reseller_name_snapshot' => $reseller->company?->name ?? 'Reseller',
            'currency_id' => $market->currency_id,
            'currency_code_snapshot' => 'LKR',
            'gross_earnings_total' => '100.00',
            'debt_deduction_total' => '0.00',
            'adjustment_total' => '0.00',
            'transfer_fee_amount' => '0.00',
            'transfer_fee_status' => ResellerSettlementInvoice::TRANSFER_FEE_STATUS_NOT_CONFIGURED,
            'previous_invoice_offset_total' => '0.00',
            'net_payable' => '90.00',
            'amount_paid' => '90.00',
            'offset_applied_total' => '0.00',
            'outstanding_balance' => '0.00',
            'payment_status' => ResellerSettlementInvoice::PAYMENT_STATUS_PAID,
            'instruction_status' => ResellerSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $ready = $this->financeNotifications->notifyResellerSettlementReady($this->admin, $resellerBatch->fresh('invoices'));
        $completed = $this->financeNotifications->notifyResellerSettlementCompleted($this->admin, $resellerInvoice->fresh());
        $completedAgain = $this->financeNotifications->notifyResellerSettlementCompleted($this->admin, $resellerInvoice->fresh());

        $this->assertCount(1, $ready);
        $this->assertNotNull($completed);
        $this->assertSame((int) $completed->id, (int) $completedAgain->id);
        $this->assertGreaterThanOrEqual(2, $this->inbox->visibleRecipientsFor($reseller)->count());

        $supplierBatch = SupplierSettlementBatch::query()->create([
            'uuid' => UuidService::generate(),
            'batch_number' => 'SSB-'.Str::upper(Str::random(6)),
            'market_id' => $market->id,
            'market_code_snapshot' => 'lk',
            'timezone_snapshot' => 'Asia/Colombo',
            'settlement_date' => '2026-11-09',
            'kind' => SupplierSettlementBatch::KIND_DAILY,
            'sequence' => 1,
            'status' => SupplierSettlementBatch::STATUS_LOCKED,
            'generation_source' => SupplierSettlementBatch::GENERATION_SOURCE_MANUAL,
            'generated_by' => $this->admin->id,
            'generated_at' => now(),
            'locked_by' => $this->admin->id,
            'locked_at' => now(),
        ]);

        $supplierInvoice = SupplierSettlementInvoice::query()->create([
            'uuid' => UuidService::generate(),
            'invoice_number' => 'SSI-'.Str::upper(Str::random(6)),
            'batch_id' => $supplierBatch->id,
            'supplier_company_id' => $supplier->company_id,
            'supplier_company_name_snapshot' => $supplier->company?->name,
            'currency_id' => $market->currency_id,
            'currency_code_snapshot' => 'LKR',
            'payable_total' => '40.00',
            'net_payable' => '40.00',
            'payment_status' => SupplierSettlementInvoice::PAYMENT_STATUS_PAID,
            'instruction_status' => SupplierSettlementInvoice::INSTRUCTION_STATUS_ACTIVE,
            'issued_at' => now(),
        ]);

        $supplierReady = $this->financeNotifications->notifySupplierSettlementReady($this->admin, $supplierBatch->fresh('invoices'));
        $supplierCompleted = $this->financeNotifications->notifySupplierSettlementCompleted($this->admin, $supplierInvoice->fresh());

        $this->assertCount(1, $supplierReady);
        $this->assertNotNull($supplierCompleted);
        $this->assertGreaterThanOrEqual(2, $this->inbox->visibleRecipientsFor($supplier)->count());
    }

    public function test_financial_correction_uses_distinct_event_key_and_failures_do_not_throw(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();
        $order = $this->makeDeliveredOrder($reseller, $supplier, OrderStatus::RETURNED);
        $obligation = $this->makeEarning($order, $reseller, '50.00');

        $correction = $this->financeNotifications->notifyUnsupportedDeliveryCorrection(
            $this->admin,
            $order,
            $obligation,
            'Delivery confirmation reversal/correction would rewrite financial history.',
        );

        $this->assertNotNull($correction);
        $this->assertSame(NotificationType::FINANCIAL_CORRECTION, $correction->type);
        $this->assertStringContainsString('CORRECTION NOTICE', (string) $correction->body);

        Log::spy();

        $brokenOrder = new Order([
            'id' => 0,
            'order_number' => 'NOPE',
            'status' => OrderStatus::DELIVERED->value,
            'reseller_id' => 999999999,
            'supplier_id' => null,
        ]);

        $result = $this->financeNotifications->notifyOrderDelivered($this->admin, $brokenOrder);
        $this->assertNull($result['reseller']);
        $this->assertNull($result['supplier']);
    }

    public function test_manual_publish_rejects_financial_types(): void
    {
        $reseller = $this->makeResellerUser();

        $this->expectException(ValidationException::class);

        app(NotificationPublicationService::class)->publish($this->admin, new PublishNotificationData(
            type: NotificationType::ORDER_DELIVERED,
            title: 'Blocked',
            body: 'Should not publish through admin workflow',
            audienceType: NotificationAudienceType::SELECTED_RESELLER_USERS,
            recipientUserIds: [(int) $reseller->id],
        ));
    }

    private function makeAdminUser(): User
    {
        $company = $this->makeCompany(PortalCode::ADMIN);

        $user = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $company->id,
            'email' => 'admin-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);

        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user;
    }

    private function makeDeliveredOrder(
        User $reseller,
        User $supplier,
        OrderStatus $status = OrderStatus::DELIVERED,
    ): Order {
        $market = $this->marketByCode('lk');

        $customer = Customer::query()->create([
            'uuid' => UuidService::generate(),
            'display_name' => 'Notify Customer '.Str::upper(Str::random(4)),
            'primary_country_id' => $this->countryByIso('LK')->id,
            'is_banned' => false,
            'created_by' => $reseller->id,
        ]);

        return Order::query()->create([
            'uuid' => UuidService::generate(),
            'order_number' => (string) random_int(10000000, 99999999),
            'source' => OrderSource::MANUAL->value,
            'status' => $status->value,
            'market_id' => $market->id,
            'currency_id' => $market->currency_id,
            'market_code_snapshot' => $market->code,
            'currency_code_snapshot' => 'LKR',
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'supplier_id' => $supplier->id,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->display_name,
            'primary_phone_snapshot' => '077'.random_int(1000000, 9999999),
            'primary_phone_country_id' => $this->countryByIso('LK')->id,
            'items_subtotal' => '100.00',
            'discount_amount' => '0.00',
            'courier_fee_amount' => '0.00',
            'customer_payable_amount' => '100.00',
            'total_weight' => '1.000',
            'created_by' => $reseller->id,
        ]);
    }

    private function makeEarning(
        Order $order,
        User $reseller,
        string $gross,
        string $businessDate = '2026-11-10',
    ): ResellerEarningObligation {
        $market = $this->marketByCode('lk');

        return ResellerEarningObligation::query()->create([
            'uuid' => UuidService::generate(),
            'obligation_number' => 'REO-'.Str::upper(Str::random(8)),
            'reseller_id' => $reseller->id,
            'reseller_company_id' => $reseller->company_id,
            'order_id' => $order->id,
            'market_id' => $market->id,
            'currency_id' => $market->currency_id,
            'currency_code_snapshot' => 'LKR',
            'business_date' => $businessDate,
            'cod_amount_snapshot' => '200.00',
            'supplier_cost_snapshot' => '40.00',
            'service_charge_snapshot' => '10.00',
            'courier_charge_snapshot' => '20.00',
            'company_commission_snapshot' => '5.00',
            'gross_reseller_profit' => $gross,
            'source_type' => ResellerEarningObligation::SOURCE_COURIER_RECEIPT_CONFIRMATION,
            'source_idempotency_key' => 'earning:order:'.$order->id,
            'recorded_by' => $this->admin->id,
        ]);
    }

    private function ensureNotificationSchema(): void
    {
        if (Schema::hasTable('notifications')
            && Schema::hasTable('notification_recipients')
            && Schema::hasTable('notification_deliveries')
        ) {
            return;
        }

        $migration = require database_path('migrations/2026_11_10_120000_create_notification_foundation_tables.php');
        $migration->up();
    }
}
