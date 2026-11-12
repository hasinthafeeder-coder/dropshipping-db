<?php

namespace Tests\Feature\Meeting;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\MeetingCheckInMethod;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\ResellerMeetingStatus;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\ResellerMeetingAttendance;
use Feeder\Core\Models\ResellerMeetingCheckInToken;
use Feeder\Core\Models\User;
use Feeder\Core\Services\Meeting\MeetingAttendanceReportService;
use Feeder\Core\Services\Meeting\MeetingCheckInService;
use Feeder\Core\Services\Meeting\MeetingCheckInTokenService;
use Feeder\Core\Services\Meeting\MeetingEligibilityService;
use Feeder\Core\Services\Meeting\MeetingExcuseService;
use Feeder\Core\Services\Meeting\MeetingManagementService;
use Feeder\Core\Services\Meeting\MeetingReminderService;
use Feeder\Core\Services\UuidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SetsUpOrderFoundationData;
use Tests\TestCase;

class ResellerMeetingAttendancePhase4Test extends TestCase
{
    use SetsUpOrderFoundationData;

    private MeetingManagementService $meetings;

    private MeetingCheckInService $checkIns;

    private MeetingCheckInTokenService $tokens;

    private MeetingExcuseService $excuses;

    private MeetingAttendanceReportService $reports;

    private MeetingReminderService $reminders;

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

        $this->meetings = app(MeetingManagementService::class);
        $this->checkIns = app(MeetingCheckInService::class);
        $this->tokens = app(MeetingCheckInTokenService::class);
        $this->excuses = app(MeetingExcuseService::class);
        $this->reports = app(MeetingAttendanceReportService::class);
        $this->reminders = app(MeetingReminderService::class);
        $this->eligibility = app(MeetingEligibilityService::class);
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

    public function test_eligibility_includes_all_active_reseller_companies_without_approved_at(): void
    {
        $withApprovedAt = $this->makeEligibleReseller(Carbon::parse('2037-01-15'));
        $futureApprovedAt = $this->makeEligibleReseller(Carbon::parse('2037-03-20'));
        $missingDate = $this->makeResellerUser();
        $missingDate->company->forceFill(['approved_at' => null])->save();

        $meeting = $this->meetings->create(
            $this->admin,
            'March Meeting',
            '2037-03-10 10:00:00',
        );

        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($withApprovedAt->company->fresh(), $meeting));
        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($futureApprovedAt->company->fresh(), $meeting));
        $this->assertTrue($this->eligibility->isCompanyEligibleForMeeting($missingDate->company->fresh(), $meeting));

        $owners = $this->eligibility->eligibleResellerOwnersFor($meeting);
        $this->assertTrue($owners->contains(fn (User $user): bool => (int) $user->id === (int) $withApprovedAt->id));
        $this->assertTrue($owners->contains(fn (User $user): bool => (int) $user->id === (int) $futureApprovedAt->id));
        $this->assertTrue($owners->contains(fn (User $user): bool => (int) $user->id === (int) $missingDate->id));
    }

    public function test_qr_token_is_secure_opaque_and_contains_no_personal_data(): void
    {
        $reseller = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $meeting = $this->meetings->create($this->admin, 'Token Meeting', '2037-04-15 09:00:00');

        $token = $this->tokens->activeTokenForCompany($meeting, $reseller->company);
        $this->assertNotNull($token);
        $this->assertTrue($this->tokens->assertTokenIsUnpredictable($token->token));
        $this->assertSame(64, strlen($token->token));
        $this->assertDoesNotMatchRegularExpression('/^\d+$/', $token->token);
        $this->assertStringNotContainsString((string) $reseller->id, $token->token);
        $this->assertStringNotContainsString((string) $reseller->company_id, $token->token);
        $this->assertStringNotContainsString((string) $reseller->email, $token->token);
        $this->assertStringNotContainsString((string) $reseller->company->uuid, $token->token);
        $this->assertNotSame((string) $meeting->id, $token->token);
    }

    public function test_duplicate_qr_scans_do_not_duplicate_attendance(): void
    {
        $reseller = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $meeting = $this->meetings->create($this->admin, 'Scan Meeting', '2037-05-12 10:00:00');
        $token = $this->tokens->activeTokenForCompany($meeting, $reseller->company);
        $this->assertNotNull($token);

        $first = $this->checkIns->checkInByToken($this->admin, $token->token);
        $second = $this->checkIns->checkInByToken($this->admin, $token->token);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame((int) $first['attendance']->id, (int) $second['attendance']->id);
        $this->assertSame(1, ResellerMeetingAttendance::query()->where('meeting_id', $meeting->id)->count());
        $this->assertSame(MeetingCheckInMethod::QR, $first['attendance']->check_in_method);
        $this->assertSame((int) $this->admin->id, (int) $first['attendance']->checked_in_by);
    }

    public function test_manual_check_in_records_attendance_and_is_idempotent(): void
    {
        $reseller = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $meeting = $this->meetings->create($this->admin, 'Manual Meeting', '2037-06-08 10:00:00');

        $first = $this->checkIns->checkInManually($this->admin, $meeting, $reseller->email);
        $second = $this->checkIns->checkInManually($this->admin, $meeting, $reseller->company->uuid);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(MeetingCheckInMethod::MANUAL, $first['attendance']->check_in_method);
        $this->assertSame(1, ResellerMeetingAttendance::query()->where('meeting_id', $meeting->id)->count());
    }

    public function test_approved_excuse_is_auditable_and_excluded_from_absence_counts(): void
    {
        $reseller = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $meeting = $this->meetings->create($this->admin, 'Excuse Meeting', '2037-07-10 10:00:00');

        $excuse = $this->excuses->approve(
            $this->admin,
            $meeting,
            $reseller->company,
            'Medical appointment with hospital letter',
        );

        $this->assertSame('Medical appointment with hospital letter', $excuse->reason);
        $this->assertSame((int) $this->admin->id, (int) $excuse->approved_by);
        $this->assertNotNull($excuse->approved_at);

        $report = $this->reports->meetingReport($meeting);
        $row = collect($report['rows'])->firstWhere('company_id', (int) $reseller->company_id);

        $this->assertSame('EXCUSED', $row['status']);
        $this->assertSame(1, $report['excused_count']);
        $this->assertSame(max(0, $report['eligible_count'] - 1), $report['absent_count']);
        $this->assertSame(0, $this->reports->countedAbsencesForCompany($reseller->company, 2037));
    }

    public function test_cancelled_meetings_never_count_as_absences(): void
    {
        $reseller = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $meeting = $this->meetings->create($this->admin, 'Cancel Meeting', '2037-08-10 10:00:00');

        $this->meetings->cancel($meeting, $this->admin, 'Venue unavailable');

        $report = $this->reports->meetingReport($meeting->fresh());
        $this->assertSame(0, $report['absent_count']);

        $summary = collect($this->reports->calendarYearCompliance(2037))
            ->firstWhere('company_id', (int) $reseller->company_id);

        $this->assertNull($summary);
        $this->assertSame(0, $this->reports->countedAbsencesForCompany($reseller->company, 2037));
        $this->assertTrue(
            ResellerMeetingCheckInToken::query()
                ->where('meeting_id', $meeting->id)
                ->whereNotNull('revoked_at')
                ->exists()
        );
    }

    public function test_absence_counting_and_review_flag_without_auto_suspension(): void
    {
        $reseller = $this->makeEligibleReseller(Carbon::parse('2036-12-01'));
        $company = $reseller->company;

        foreach ([1, 2, 3, 4] as $month) {
            $meeting = $this->meetings->create(
                $this->admin,
                'Absence Meeting '.$month,
                sprintf('2037-0%d-10 10:00:00', $month),
            );

            if ($month === 1) {
                $this->checkIns->checkInManually($this->admin, $meeting, $company);
            }
        }

        $this->assertSame(3, $this->reports->countedAbsencesForCompany($company->fresh(), 2037));
        $this->assertFalse($this->reports->isFlaggedForReview($company->fresh(), 2037));

        $fifth = $this->meetings->create($this->admin, 'Absence Meeting 5', '2037-05-10 10:00:00');
        unset($fifth);

        $this->assertSame(4, $this->reports->countedAbsencesForCompany($company->fresh(), 2037));
        $this->assertTrue($this->reports->isFlaggedForReview($company->fresh(), 2037));

        $company->refresh();
        $this->assertSame(CompanyStatus::ACTIVE, $company->status);
        $resellerStatus = $reseller->fresh()->status;
        $this->assertSame(
            UserStatus::ACTIVE->value,
            $resellerStatus instanceof UserStatus ? $resellerStatus->value : $resellerStatus
        );
        $this->assertTrue($this->reports->reviewFlaggedCompanies(2037)->contains(
            fn (array $row): bool => (int) $row['company_id'] === (int) $company->id
        ));
    }

    public function test_meeting_reminders_are_idempotent_for_three_and_one_day_offsets(): void
    {
        Carbon::setTestNow(Carbon::parse('2037-09-07 08:00:00'));

        $reseller = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $meeting = $this->meetings->create($this->admin, 'Reminder Meeting', '2037-09-10 10:00:00');

        $activeResellerCount = app(\Feeder\Core\Services\Notification\ResellerUserNotificationRecipientResolver::class)
            ->allEligibleResellerUsers()
            ->count();

        $first = $this->reminders->processDueReminders($this->admin, Carbon::parse('2037-09-07'));
        $second = $this->reminders->processDueReminders($this->admin, Carbon::parse('2037-09-07'));

        $this->assertSame($activeResellerCount, $first['three_day']);
        $this->assertSame(0, $first['one_day']);
        $this->assertSame($activeResellerCount, $second['three_day']);
        $this->assertSame(
            (int) $first['notifications'][0]->id,
            (int) $second['notifications'][0]->id
        );

        $key = $this->reminders->eventKey('3d', (int) $meeting->id, (int) $reseller->id);
        $this->assertSame(1, Notification::query()->where('source_event_key', $key)->count());
        $this->assertSame(
            NotificationType::MEETING_REMINDER,
            Notification::query()->where('source_event_key', $key)->first()?->type
        );

        $dayBefore = $this->reminders->processDueReminders($this->admin, Carbon::parse('2037-09-09'));
        $dayBeforeAgain = $this->reminders->processDueReminders($this->admin, Carbon::parse('2037-09-09'));

        $this->assertSame($activeResellerCount, $dayBefore['one_day']);
        $this->assertSame($activeResellerCount, $dayBeforeAgain['one_day']);
        $this->assertSame(
            1,
            Notification::query()
                ->where('source_event_key', $this->reminders->eventKey('1d', (int) $meeting->id, (int) $reseller->id))
                ->count()
        );
    }

    public function test_only_one_active_meeting_per_calendar_month(): void
    {
        $this->meetings->create($this->admin, 'First October', '2037-10-05 10:00:00');

        $this->expectException(ValidationException::class);
        $this->meetings->create($this->admin, 'Second October', '2037-10-20 10:00:00');
    }

    public function test_cancelled_month_can_be_reused_for_a_new_active_meeting(): void
    {
        $first = $this->meetings->create($this->admin, 'Cancelled October', '2037-10-05 10:00:00');
        $this->meetings->cancel($first, $this->admin, 'Reschedule');

        $second = $this->meetings->create($this->admin, 'Replacement October', '2037-10-25 10:00:00');

        $this->assertTrue($second->isScheduled());
        $this->assertSame('2037-10', $second->active_month_key);
        $this->assertSame(ResellerMeetingStatus::CANCELLED, $first->fresh()->status);
    }

    public function test_unauthorized_attendance_path_rejects_inactive_reseller_company(): void
    {
        $inactive = $this->makeEligibleReseller(Carbon::parse('2037-01-01'));
        $inactive->company->forceFill(['status' => CompanyStatus::SUSPENDED->value])->save();
        $meeting = $this->meetings->create($this->admin, 'Scope Meeting', '2037-11-10 10:00:00');

        $this->expectException(ValidationException::class);
        $this->checkIns->checkInManually($this->admin, $meeting, $inactive->company->fresh());
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
            'email' => 'admin-meet-'.Str::lower(Str::random(6)).'@feeder.local',
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
