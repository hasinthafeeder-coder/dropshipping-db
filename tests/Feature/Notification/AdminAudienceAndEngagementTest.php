<?php

namespace Tests\Feature\Notification;

use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationDeliveryChannel;
use Feeder\Core\Enums\NotificationDeliveryStatus;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\NotificationDelivery;
use Feeder\Core\Models\NotificationRecipient;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Notification\NotificationInboxService;
use Feeder\Core\Services\Notification\NotificationPublicationService;
use Feeder\Core\Services\Notification\PublishNotificationData;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class AdminAudienceAndEngagementTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private NotificationPublicationService $publisher;

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

        $this->publisher = app(NotificationPublicationService::class);
        $this->inbox = app(NotificationInboxService::class);
        $this->admin = $this->makeAdminUser();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_admin_announcement_to_all_eligible_resellers_creates_recipients(): void
    {
        $resellerA = $this->makeResellerUser();
        $resellerB = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'All reseller notice',
            body: 'Visible to every eligible reseller',
            audienceType: NotificationAudienceType::ALL_ELIGIBLE_RESELLERS,
        ));

        $recipientUserIds = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->pluck('recipient_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $resellerA->id, $recipientUserIds);
        $this->assertContains((int) $resellerB->id, $recipientUserIds);
        $this->assertNotContains((int) $supplier->id, $recipientUserIds);

        $this->assertSame(1, $this->inbox->unreadCountFor($resellerA));
        $this->assertSame(0, $this->inbox->unreadCountFor($supplier));

        $delivery = NotificationDelivery::query()
            ->whereHas('recipient', fn ($q) => $q
                ->where('notification_id', $notification->id)
                ->where('recipient_user_id', $resellerA->id))
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame(NotificationDeliveryChannel::IN_APP, $delivery->channel);
        $this->assertSame(NotificationDeliveryStatus::DELIVERED, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_admin_announcement_to_all_eligible_suppliers_creates_recipients(): void
    {
        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();
        $reseller = $this->makeResellerUser();

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'All supplier notice',
            body: 'Visible to every eligible supplier',
            audienceType: NotificationAudienceType::ALL_ELIGIBLE_SUPPLIERS,
        ));

        $recipientUserIds = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->pluck('recipient_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $supplierA->id, $recipientUserIds);
        $this->assertContains((int) $supplierB->id, $recipientUserIds);
        $this->assertNotContains((int) $reseller->id, $recipientUserIds);
        $this->assertSame(1, $this->inbox->unreadCountFor($supplierA));
        $this->assertSame(0, $this->inbox->unreadCountFor($reseller));
    }

    public function test_opening_detail_sets_opened_and_read_without_duplicating_recipients(): void
    {
        $reseller = $this->makeResellerUser();

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Open me',
            body: 'Detail engagement',
            audienceType: NotificationAudienceType::ALL_ELIGIBLE_RESELLERS,
            sourceEventKey: 'test:open:'.UuidService::generate(),
        ));

        $beforeCount = NotificationRecipient::query()->where('notification_id', $notification->id)->count();

        $opened = $this->inbox->markOpenedByNotificationUuid($reseller, (string) $notification->uuid);
        $openedAgain = $this->inbox->markOpenedByNotificationUuid($reseller, (string) $notification->uuid);

        $this->assertNotNull($opened->opened_at);
        $this->assertNotNull($opened->read_at);
        $this->assertSame((int) $opened->id, (int) $openedAgain->id);
        $this->assertSame(
            $beforeCount,
            NotificationRecipient::query()->where('notification_id', $notification->id)->count()
        );
        $this->assertSame(0, $this->inbox->unreadCountFor($reseller));
        $this->assertSame(
            1,
            Notification::query()->where('source_event_key', $notification->source_event_key)->count()
        );
    }

    public function test_recipient_isolation_across_companies(): void
    {
        $resellerA = $this->makeResellerUser();
        $resellerB = $this->makeResellerUser();

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Isolation check',
            body: 'Company scoped',
            audienceType: NotificationAudienceType::ALL_ELIGIBLE_RESELLERS,
        ));

        $visibleA = $this->inbox->findVisibleByNotificationUuid($resellerA, (string) $notification->uuid);
        $this->assertSame((int) $resellerA->id, (int) $visibleA->recipient_user_id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->inbox->markRead(
            $resellerB,
            (string) $visibleA->uuid,
        );
    }

    private function makeAdminUser(): User
    {
        $company = $this->makeCompany(PortalCode::ADMIN);

        $user = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $company->id,
            'email' => 'admin-aud-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);

        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user;
    }

    private function ensureNotificationSchema(): void
    {
        if (! Schema::hasTable('notifications')
            || ! Schema::hasTable('notification_recipients')
            || ! Schema::hasTable('notification_deliveries')
        ) {
            $migration = require database_path('migrations/2026_11_10_120000_create_notification_foundation_tables.php');
            $migration->up();
        }

        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'product_id')) {
            $migration = require database_path('migrations/2026_11_10_130000_add_product_and_publisher_company_to_notifications_table.php');
            $migration->up();
        }

        if (! Schema::hasColumn('notification_recipients', 'opened_at')) {
            $migration = require database_path('migrations/2026_11_10_160000_add_opened_at_to_notification_recipients_table.php');
            $migration->up();
        }
    }
}
