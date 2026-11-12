<?php

namespace Tests\Feature\Notification;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationDeliveryChannel;
use Feeder\Core\Enums\NotificationDeliveryStatus;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\File;
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
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class NotificationFoundationTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private NotificationPublicationService $publisher;

    private NotificationInboxService $inbox;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_admin_announcement_creates_channel_independent_record_with_in_app_delivery(): void
    {
        $admin = $this->makeAdminUser();
        $supplier = $this->makeSupplierUser();

        $notification = $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Platform maintenance',
            body: 'Supplier portal downtime tonight.',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            sourceEventKey: 'test:announcement:'.$supplier->uuid,
        ));

        $this->assertSame(NotificationType::ANNOUNCEMENT, $notification->type);
        $this->assertSame(10, strlen((string) $notification->uuid));

        $recipient = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('recipient_user_id', $supplier->id)
            ->first();

        $this->assertNotNull($recipient);
        $this->assertSame((int) $supplier->company_id, (int) $recipient->recipient_company_id);
        $this->assertNull($recipient->read_at);

        $delivery = NotificationDelivery::query()
            ->where('notification_recipient_id', $recipient->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame(NotificationDeliveryChannel::IN_APP, $delivery->channel);
        $this->assertSame(NotificationDeliveryStatus::DELIVERED, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_supplier_recipient_isolation_prevents_cross_company_inbox_access(): void
    {
        $admin = $this->makeAdminUser();
        $supplierA = $this->makeSupplierUser();
        $supplierB = $this->makeSupplierUser();

        $this->publisher->publishSupplierMessage($admin, new PublishNotificationData(
            type: NotificationType::SUPPLIER_MESSAGE,
            title: 'Private note',
            body: 'Only supplier A should see this.',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplierA->id],
        ));

        $visibleA = $this->inbox->visibleRecipientsFor($supplierA);
        $visibleB = $this->inbox->visibleRecipientsFor($supplierB);

        $this->assertCount(1, $visibleA);
        $this->assertCount(0, $visibleB);

        $foreignUuid = (string) $visibleA->first()->uuid;

        $this->expectException(ValidationException::class);
        $this->inbox->markRead($supplierB, $foreignUuid);
    }

    public function test_publication_and_expiry_windows_control_visibility(): void
    {
        $admin = $this->makeAdminUser();
        $supplier = $this->makeSupplierUser();

        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Future notice',
            body: 'Not visible yet.',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            publishAt: '2026-11-10 12:00:00',
            expiresAt: '2026-11-10 18:00:00',
        ));

        $this->assertCount(0, $this->inbox->visibleRecipientsFor($supplier));

        Carbon::setTestNow('2026-11-10 13:00:00');
        $this->assertCount(1, $this->inbox->visibleRecipientsFor($supplier));

        Carbon::setTestNow('2026-11-10 18:00:00');
        $this->assertCount(0, $this->inbox->visibleRecipientsFor($supplier));
    }

    public function test_mark_read_is_idempotent_for_recipient_owner(): void
    {
        $admin = $this->makeAdminUser();
        $supplier = $this->makeSupplierUser();

        Carbon::setTestNow('2026-11-10 10:00:00');

        $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Read me',
            body: 'Body',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            publishAt: '2026-11-10 10:00:00',
        ));

        $recipient = $this->inbox->visibleRecipientsFor($supplier)->first();
        $this->assertNotNull($recipient);
        $this->assertSame(1, $this->inbox->unreadCountFor($supplier));

        Carbon::setTestNow('2026-11-10 11:00:00');
        $first = $this->inbox->markRead($supplier, (string) $recipient->uuid);
        $this->assertNotNull($first->read_at);
        $this->assertSame(0, $this->inbox->unreadCountFor($supplier));

        Carbon::setTestNow('2026-11-10 11:05:00');
        $second = $this->inbox->markRead($supplier, (string) $recipient->uuid);
        $this->assertTrue($first->read_at->equalTo($second->read_at));
    }

    public function test_duplicate_source_event_key_is_idempotent(): void
    {
        $admin = $this->makeAdminUser();
        $supplier = $this->makeSupplierUser();
        $eventKey = 'domain.event:supplier-welcome:'.$supplier->id;

        $first = $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Welcome',
            body: 'First create',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            sourceEventType: 'supplier.welcome',
            sourceEventKey: $eventKey,
        ));

        $second = $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Welcome again',
            body: 'Should not create a second row',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            sourceEventType: 'supplier.welcome',
            sourceEventKey: $eventKey,
        ));

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame('Welcome', $second->title);
        $this->assertSame(
            1,
            Notification::query()->where('source_event_key', $eventKey)->count()
        );
        $this->assertSame(
            1,
            NotificationRecipient::query()->where('notification_id', $first->id)->count()
        );
    }

    public function test_ineligible_supplier_targets_are_rejected_server_side(): void
    {
        $admin = $this->makeAdminUser();
        $activeSupplier = $this->makeSupplierUser();
        $pendingCompany = $this->makeCompany(PortalCode::SUPPLIER, [
            'operation_market_id' => $this->marketByCode('lk')->id,
            'status' => CompanyStatus::PENDING->value,
        ]);
        $pendingSupplier = $this->makeSupplierUser($pendingCompany);
        $pendingSupplier->forceFill(['status' => UserStatus::PENDING->value])->save();

        $this->expectException(ValidationException::class);

        $this->publisher->publishSupplierMessage($admin, new PublishNotificationData(
            type: NotificationType::SUPPLIER_MESSAGE,
            title: 'Nope',
            body: 'Includes ineligible recipient',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $activeSupplier->id, (int) $pendingSupplier->id],
        ));
    }

    public function test_optional_banner_must_use_notification_banner_file_category(): void
    {
        $admin = $this->makeAdminUser();
        $supplier = $this->makeSupplierUser();

        $validBanner = $this->makeFile(FileCategory::NOTIFICATION_BANNER);
        $wrongCategory = $this->makeFile(FileCategory::PRODUCT_IMAGE);

        $notification = $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Bannered',
            body: 'Has banner',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            bannerFileUuid: $validBanner->uuid,
        ));

        $this->assertSame($validBanner->uuid, $notification->banner_file_uuid);

        $this->expectException(ValidationException::class);
        $this->publisher->publishAnnouncement($admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Bad banner',
            body: 'Wrong category',
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: [(int) $supplier->id],
            bannerFileUuid: $wrongCategory->uuid,
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

    private function makeFile(FileCategory $category): File
    {
        return File::query()->create([
            'uuid' => UuidService::generate(),
            'application' => 'ADMIN',
            'entity_type' => 'NOTIFICATION',
            'entity_uuid' => UuidService::generate(),
            'category' => $category->value,
            'disk' => 'feeder',
            'path' => 'notification-banners/'.Str::uuid().'.jpg',
            'original_name' => 'banner.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'checksum' => hash('sha256', Str::random(16)),
            'visibility' => 'PRIVATE',
            'status' => 'ACTIVE',
        ]);
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

        if (! Schema::hasColumn('notification_recipients', 'opened_at')) {
            $migration = require database_path('migrations/2026_11_10_160000_add_opened_at_to_notification_recipients_table.php');
            $migration->up();
        }
    }
}
