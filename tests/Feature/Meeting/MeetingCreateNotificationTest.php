<?php

namespace Tests\Feature\Meeting;

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
use Feeder\Core\Services\Meeting\MeetingReminderService;
use Feeder\Core\Services\Notification\NotificationInboxService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class MeetingCreateNotificationTest extends TestCase
{
    use SetsUpOrderFoundationData;

    private MeetingManagementService $meetings;

    private MeetingNotificationService $meetingNotifications;

    private MeetingReminderService $reminders;

    private NotificationInboxService $inbox;

    private MeetingCheckInTokenService $tokens;

    private MeetingEligibilityService $eligibility;

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

        $this->ensureMeetingSchema();
        $this->ensureNotificationSchema();

        DB::beginTransaction();

        $this->seedMarketLookups();
        $this->admin = $this->makeAdminUser();
        $this->meetings = app(MeetingManagementService::class);
        $this->meetingNotifications = app(MeetingNotificationService::class);
        $this->reminders = app(MeetingReminderService::class);
        $this->inbox = app(NotificationInboxService::class);
        $this->tokens = app(MeetingCheckInTokenService::class);
        $this->eligibility = app(MeetingEligibilityService::class);

        Carbon::setTestNow('2037-01-05 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_creating_meeting_notifies_all_active_resellers_not_only_attendance_eligible(): void
    {
        $attendanceEligible = $this->makeEligibleReseller(Carbon::parse('2036-12-01'));
        $attendanceIneligible = $this->makeEligibleReseller(Carbon::parse('2037-02-01'));
        $noApprovedAt = $this->makeResellerUser();
        $noApprovedAt->company->forceFill(['approved_at' => null])->save();
        $supplier = $this->makeSupplierUser();

        $meeting = $this->meetings->create(
            $this->admin,
            'January Townhall',
            '2037-01-20 10:00:00',
            description: 'Monthly meeting',
            location: 'Colombo HQ',
        );

        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($attendanceEligible->company, $meeting));
        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($attendanceIneligible->company, $meeting));
        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($noApprovedAt->company, $meeting));
        $this->assertTrue($this->eligibility->eligibleResellerOwnersFor($meeting)->contains(
            fn (User $user): bool => (int) $user->id === (int) $noApprovedAt->id
        ));

        foreach ([$attendanceEligible, $attendanceIneligible, $noApprovedAt] as $reseller) {
            $key = $this->meetingNotifications->eventKey((int) $meeting->id, (int) $reseller->id);
            $notification = Notification::query()->where('source_event_key', $key)->first();
            $this->assertNotNull($notification, 'Create-time meet.pub missing for user '.$reseller->id);
            $this->assertSame(NotificationType::MEETING_REMINDER, $notification->type);
            $this->assertSame((int) $meeting->id, (int) $notification->meeting_id);
            $this->assertSame(1, NotificationRecipient::query()
                ->where('notification_id', $notification->id)
                ->where('recipient_user_id', $reseller->id)
                ->where('recipient_company_id', $reseller->company_id)
                ->count());
            $this->assertSame(1, $this->inbox->unreadMeetingCountFor($reseller));
        }

        $this->assertSame(
            0,
            Notification::query()
                ->where('meeting_id', $meeting->id)
                ->whereHas('recipients', fn ($q) => $q->where('recipient_user_id', $supplier->id))
                ->count()
        );

        // Active reseller companies receive unique QR tokens regardless of approved_at.
        $this->assertNotNull($this->tokens->activeTokenForCompany($meeting, $attendanceEligible->company));
        $this->assertNotNull($this->tokens->activeTokenForCompany($meeting, $attendanceIneligible->company));
        $this->assertNotNull($this->tokens->activeTokenForCompany($meeting, $noApprovedAt->company));
        $this->assertNotSame(
            $this->tokens->activeTokenForCompany($meeting, $attendanceEligible->company)?->token,
            $this->tokens->activeTokenForCompany($meeting, $noApprovedAt->company)?->token
        );

        $live = $this->inbox->liveSnapshotFor($noApprovedAt);
        $this->assertSame(1, $live['unread_meeting_count']);
        $this->assertTrue($live['recipients']->isNotEmpty());
    }

    public function test_create_time_notification_is_idempotent_preserves_reminders_and_qr_ownership(): void
    {
        $eligibleA = $this->makeEligibleReseller(Carbon::parse('2036-12-01'));
        $eligibleB = $this->makeEligibleReseller(Carbon::parse('2036-12-01'));

        $meeting = $this->meetings->create(
            $this->admin,
            'Idempotent Meeting',
            '2037-01-20 10:00:00',
        );

        $keyA = $this->meetingNotifications->eventKey((int) $meeting->id, (int) $eligibleA->id);
        $keyB = $this->meetingNotifications->eventKey((int) $meeting->id, (int) $eligibleB->id);
        $firstIdA = (int) Notification::query()->where('source_event_key', $keyA)->value('id');
        $firstIdB = (int) Notification::query()->where('source_event_key', $keyB)->value('id');
        $this->assertGreaterThan(0, $firstIdA);
        $this->assertGreaterThan(0, $firstIdB);
        $this->assertNotSame($firstIdA, $firstIdB);

        $activeResellerCount = app(\Feeder\Core\Services\Notification\ResellerUserNotificationRecipientResolver::class)
            ->allEligibleResellerUsers()
            ->count();
        $again = $this->meetingNotifications->publishCreatedMeeting($this->admin, $meeting);
        $this->assertCount($activeResellerCount, $again);
        $this->assertSame(1, Notification::query()->where('source_event_key', $keyA)->count());
        $this->assertSame(1, Notification::query()->where('source_event_key', $keyB)->count());
        $this->assertSame(
            $activeResellerCount,
            Notification::query()->where('source_event_key', 'like', 'meet.pub:'.$meeting->id.':%')->count()
        );

        $tokenA = $this->tokens->activeTokenForCompany($meeting, $eligibleA->company);
        $tokenB = $this->tokens->activeTokenForCompany($meeting, $eligibleB->company);
        $this->assertNotNull($tokenA);
        $this->assertNotNull($tokenB);
        $this->assertNotSame($tokenA->token, $tokenB->token);

        $openedA = $this->inbox->markOpenedByNotificationUuid($eligibleA, (string) Notification::query()->whereKey($firstIdA)->value('uuid'));
        $this->assertSame((int) $meeting->id, (int) $openedA->notification?->meeting_id);
        $this->assertSame((int) $eligibleA->id, (int) $openedA->recipient_user_id);

        Carbon::setTestNow('2037-01-17 08:00:00');
        $reminders = $this->reminders->processDueReminders($this->admin, Carbon::parse('2037-01-17'));
        $this->assertSame($activeResellerCount, $reminders['three_day']);

        $reminderKeyA = $this->reminders->eventKey('3d', (int) $meeting->id, (int) $eligibleA->id);
        $this->assertSame(1, Notification::query()->where('source_event_key', $keyA)->count());
        $this->assertSame(1, Notification::query()->where('source_event_key', $reminderKeyA)->count());
        $this->assertNotSame(
            $firstIdA,
            (int) Notification::query()->where('source_event_key', $reminderKeyA)->value('id')
        );

        Carbon::setTestNow('2037-01-19 08:00:00');
        $oneDay = $this->reminders->processDueReminders($this->admin, Carbon::parse('2037-01-19'));
        $this->assertSame($activeResellerCount, $oneDay['one_day']);
        $oneDayKeyA = $this->reminders->eventKey('1d', (int) $meeting->id, (int) $eligibleA->id);
        $this->assertSame(1, Notification::query()->where('source_event_key', $oneDayKeyA)->count());
        $this->assertSame(1, Notification::query()->where('source_event_key', $keyA)->count());
    }

    public function test_create_time_notice_survives_when_scheduled_at_equals_publish_moment(): void
    {
        $eligible = $this->makeEligibleReseller(Carbon::parse('2036-12-01'));
        Carbon::setTestNow('2037-03-10 10:00:00');

        $meeting = $this->meetings->create(
            $this->admin,
            'Edge Schedule Meeting',
            '2037-03-10 10:00:00',
        );

        $key = $this->meetingNotifications->eventKey((int) $meeting->id, (int) $eligible->id);
        $notification = Notification::query()->where('source_event_key', $key)->first();

        $this->assertNotNull($notification);
        $this->assertTrue(
            Carbon::parse($notification->expires_at)->gt(Carbon::parse($notification->publish_at))
        );
        $this->assertSame(1, $this->inbox->unreadMeetingCountFor($eligible));
    }

    private function makeEligibleReseller(Carbon $approvedAt): User
    {
        $user = $this->makeResellerUser();
        $user->company->forceFill([
            'approved_at' => $approvedAt,
            'status' => CompanyStatus::ACTIVE->value,
        ])->save();

        return $user->fresh(['company']);
    }

    private function makeAdminUser(): User
    {
        $company = $this->makeCompany(PortalCode::ADMIN);

        $user = User::query()->create([
            'uuid' => UuidService::generate(),
            'company_id' => $company->id,
            'email' => 'admin-meet-notify-'.Str::lower(Str::random(6)).'@feeder.local',
            'phone' => '070'.random_int(1000000, 9999999),
            'password' => Hash::make('password'),
            'user_type' => UserType::SUPER_ADMIN->value,
            'status' => UserStatus::ACTIVE->value,
            'phone_verified_at' => now(),
        ]);

        $company->forceFill(['owner_user_id' => $user->id])->save();

        return $user;
    }

    private function ensureMeetingSchema(): void
    {
        if (Schema::hasTable('reseller_meetings')
            && Schema::hasTable('reseller_meeting_check_in_tokens')
            && Schema::hasTable('reseller_meeting_attendances')
            && Schema::hasTable('reseller_meeting_excuses')
        ) {
            return;
        }

        $migration = require database_path('migrations/2026_11_10_150000_create_reseller_meeting_attendance_tables.php');
        $migration->up();
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
}
