<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventsDigestCommandTest extends TestCase
{
    private function createEvent(int $groupId, string $title = 'Test Event', int $daysFromNow = 7): int
    {
        $eventId = DB::table('communityevents')->insertGetId([
            'title' => $title,
            'location' => 'Test Hall, Test Town',
            'description' => 'A test event description.',
            'pending' => 0,
            'deleted' => 0,
            'added' => now(),
        ]);

        DB::table('communityevents_groups')->insert([
            'eventid' => $eventId,
            'groupid' => $groupId,
        ]);

        DB::table('communityevents_dates')->insert([
            'eventid' => $eventId,
            'start' => now()->addDays($daysFromNow),
            'end' => now()->addDays($daysFromNow)->addHours(2),
        ]);

        return $eventId;
    }

    public function test_smoke_no_groups(): void
    {
        Mail::fake();

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_with_no_upcoming_events(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        // No events created for the group

        $this->artisan('mail:events-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_to_members_with_events_enabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createEvent($group->id);

        $member1 = $this->createTestUser();
        $this->createMembership($member1, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $member2 = $this->createTestUser();
        $this->createMembership($member2, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 2 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_skips_members_with_events_disabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createEvent($group->id);

        $memberOptedIn = $this->createTestUser();
        $this->createMembership($memberOptedIn, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $memberOptedOut = $this->createTestUser();
        $this->createMembership($memberOptedOut, $group, [
            'eventsallowed' => 0,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_members_with_email_frequency_zero(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createEvent($group->id);

        $memberActive = $this->createTestUser();
        $this->createMembership($memberActive, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $memberOptedOut = $this->createTestUser();
        $this->createMembership($memberOptedOut, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 0,
        ]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_deleted_users(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createEvent($group->id);

        $deletedUser = $this->createTestUser(['deleted' => now()]);
        $this->createMembership($deletedUser, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_recently_sent(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        // Mark as sent only 1 day ago (< 3 days threshold)
        DB::table('groups')->where('id', $group->id)
            ->update(['lasteventsroundup' => now()->subDays(1)]);

        $this->createEvent($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_group_not_sent_in_3_days(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        // Last sent 4 days ago (>= 3 days threshold)
        DB::table('groups')->where('id', $group->id)
            ->update(['lasteventsroundup' => now()->subDays(4)]);

        $this->createEvent($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_updates_lasteventsroundup_after_sending(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createEvent($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->assertExitCode(0);

        $this->assertNotNull(
            DB::table('groups')->where('id', $group->id)->value('lasteventsroundup')
        );
    }

    public function test_skips_events_more_than_30_days_away(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        // Event 35 days from now — outside the 30-day window
        $this->createEvent($group->id, 'Far Future Event', 35);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_freegle_group(): void
    {
        Mail::fake();

        $group = $this->createTestGroup(['type' => Group::TYPE_OTHER]);
        $this->createEvent($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_emails(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createEvent($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send 1 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        // Should not update lasteventsroundup in dry-run
        $this->assertNull(
            DB::table('groups')->where('id', $group->id)->value('lasteventsroundup')
        );
    }

    public function test_skips_group_with_communityevents_disabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['settings' => json_encode(['communityevents' => false])]);

        $this->createEvent($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'eventsallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:events-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
