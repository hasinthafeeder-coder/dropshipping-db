<?php

namespace Tests\Feature\Notification;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\NotificationRecipient;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Meeting\MeetingCheckInTokenService;
use Feeder\Core\Services\Meeting\MeetingEligibilityService;
use Feeder\Core\Services\Meeting\MeetingManagementService;
use Feeder\Core\Services\Meeting\MeetingNotificationService;
use Feeder\Core\Services\Notification\NotificationInboxService;
use Feeder\Core\Services\Notification\NotificationPublicationService;
use Feeder\Core\Services\Notification\PublishNotificationData;
use Feeder\Core\Services\UuidService;
use Feeder\Core\Support\BusinessTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

/**
 * Regression for the production failure where admin publishes succeeded in DB
 * but reseller portals saw nothing:
 *  - datetime-local wall-clock stored as UTC → publish_at in the future
 *  - meeting notices filtered by attendance eligibility (approved_at) → 0 recipients
 */
class PortalInboxDeliveryRegressionTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private NotificationPublicationService $publisher;

    private NotificationInboxService $inbox;

    private MeetingManagementService $meetings;

    private MeetingNotificationService $meetingNotifications;

    private MeetingCheckInTokenService $tokens;

    private MeetingEligibilityService $eligibility;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
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
        $this->ensureMeetingSchema();

        DB::beginTransaction();
        $this->seedMarketLookups();

        $this->publisher = app(NotificationPublicationService::class);
        $this->inbox = app(NotificationInboxService::class);
        $this->meetings = app(MeetingManagementService::class);
        $this->meetingNotifications = app(MeetingNotificationService::class);
        $this->tokens = app(MeetingCheckInTokenService::class);
        $this->eligibility = app(MeetingEligibilityService::class);
        $this->admin = $this->makeAdminUser();

        Carbon::setTestNow(Carbon::parse('2026-11-11 07:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_colombo_wall_clock_publish_at_is_visible_immediately_in_reseller_inbox(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();

        // Reproduces admin datetime-local "12:52" while UTC now is 07:30.
        $wallClock = '2026-11-11T12:52';
        $publishAtUtc = BusinessTimezone::parseWallClockToUtc($wallClock);

        $this->assertTrue(
            $publishAtUtc->lte(Carbon::now('UTC')),
            'Colombo wall-clock near local noon must convert to a publish_at <= UTC now.'
        );

        // Contrasting the confirmed bug: naive UTC storage would hide the notice.
        $naiveUtc = Carbon::parse('2026-11-11 12:52:00', 'UTC');
        $this->assertTrue($naiveUtc->gt(Carbon::now('UTC')));

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Immediate after timezone fix',
            body: 'Should appear in reseller inbox and live poll now.',
            audienceType: NotificationAudienceType::ALL_ELIGIBLE_RESELLERS,
            publishAt: $publishAtUtc,
            expiresAt: BusinessTimezone::parseWallClockToUtc('2026-11-27T13:52'),
        ));

        $recipient = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('recipient_user_id', $reseller->id)
            ->where('recipient_company_id', $reseller->company_id)
            ->first();

        $this->assertNotNull($recipient);
        $this->assertSame(0, NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('recipient_user_id', $supplier->id)
            ->count());

        $this->assertTrue($notification->isPublishedAt(Carbon::now('UTC')));
        $this->assertSame(1, $this->inbox->unreadCountFor($reseller));

        $live = $this->inbox->liveSnapshotFor($reseller);
        $this->assertSame(1, $live['unread_count']);
        $this->assertTrue(
            $live['recipients']->contains(
                fn ($row): bool => (int) $row->id === (int) $recipient->id
            )
        );
        $this->assertSame((int) $recipient->id, $live['latest_recipient_id']);
    }

    public function test_all_users_audience_targets_active_resellers_and_suppliers(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'All users blast',
            body: 'Everyone active',
            audienceType: NotificationAudienceType::ALL_USERS,
        ));

        $ids = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->pluck('recipient_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $reseller->id, $ids);
        $this->assertContains((int) $supplier->id, $ids);
        $this->assertContains((int) $this->admin->id, $ids);
        $this->assertSame(1, $this->inbox->unreadCountFor($reseller));
        $this->assertSame(1, $this->inbox->unreadCountFor($supplier));
    }

    public function test_all_active_suppliers_audience_excludes_resellers(): void
    {
        $reseller = $this->makeResellerUser();
        $supplier = $this->makeSupplierUser();

        $notification = $this->publisher->publishAnnouncement($this->admin, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: 'Supplier blast',
            body: 'Suppliers only',
            audienceType: NotificationAudienceType::ALL_ELIGIBLE_SUPPLIERS,
        ));

        $ids = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->pluck('recipient_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $supplier->id, $ids);
        $this->assertNotContains((int) $reseller->id, $ids);
        $this->assertSame(0, $this->inbox->unreadCountFor($reseller));
        $this->assertSame(1, $this->inbox->unreadCountFor($supplier));
    }

    public function test_meeting_create_notifies_active_resellers_without_approved_at_and_live_poll_sees_them(): void
    {
        $reseller = $this->makeResellerUser();
        $reseller->company->forceFill([
            'approved_at' => null,
            'status' => CompanyStatus::ACTIVE->value,
        ])->save();

        $meeting = $this->meetings->create(
            $this->admin,
            'No approved_at townhall',
            '2037-06-20 10:00:00',
        );

        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($reseller->company, $meeting));
        $this->assertNotNull($this->tokens->activeTokenForCompany($meeting, $reseller->company));

        $key = $this->meetingNotifications->eventKey((int) $meeting->id, (int) $reseller->id);
        $notification = Notification::query()->where('source_event_key', $key)->first();
        $this->assertNotNull($notification, 'Active reseller without approved_at must still get meet.pub.');

        $recipient = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('recipient_user_id', $reseller->id)
            ->first();
        $this->assertNotNull($recipient);

        $this->assertSame(1, $this->inbox->unreadMeetingCountFor($reseller));
        $live = $this->inbox->liveSnapshotFor($reseller);
        $this->assertSame(1, $live['unread_meeting_count']);
        $this->assertTrue(
            $live['recipients']->contains(
                fn ($row): bool => (int) $row->id === (int) $recipient->id
                    && (int) $row->recipient_user_id === (int) $reseller->id
                    && (int) $row->recipient_company_id === (int) $reseller->company_id
            )
        );
    }

    private function makeAdminUser(): User
    {
        $company = $this->makeCompany(PortalCode::ADMIN);

        $user = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $company->id,
            'email' => 'admin-inbox-reg-'.Str::lower(Str::random(6)).'@feeder.local',
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

        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'meeting_id')) {
            $migration = require database_path('migrations/2026_11_11_100000_add_meeting_id_to_notifications_table.php');
            $migration->up();
        }
    }

    private function ensureMeetingSchema(): void
    {
        if (Schema::hasTable('reseller_meetings')
            && Schema::hasTable('reseller_meeting_check_in_tokens')
        ) {
            return;
        }

        $migration = require database_path('migrations/2026_11_10_150000_create_reseller_meeting_attendance_tables.php');
        $migration->up();
    }
}
