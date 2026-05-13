<?php

namespace Tests\Feature\Mail;

use App\Mail\Event\EventsDigestMail;
use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventsDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // EventsDigestService queries communityevents globally (no WHERE groupid in SQL).
        // Rows from parallel test classes can slip through DatabaseTransactions isolation.
        // Delete inside the current transaction so leaked rows are hidden without affecting
        // other test classes (the DELETE is rolled back with this test's transaction).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['communityevents_images', 'communityevents_dates', 'communityevents_groups', 'communityevents', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createEvent(int $groupId, string $title = 'Test Event', int $daysFromNow = 7, array $fields = []): int
    {
        $eventId = DB::table('communityevents')->insertGetId(array_merge([
            'title'       => $title,
            'location'    => 'Test Hall, Test Town',
            'description' => 'A test event description.',
            'pending'     => 0,
            'deleted'     => 0,
            'added'       => now(),
        ], $fields));

        DB::table('communityevents_groups')->insert([
            'eventid' => $eventId,
            'groupid' => $groupId,
        ]);

        DB::table('communityevents_dates')->insert([
            'eventid' => $eventId,
            'start'   => now()->addDays($daysFromNow),
            'end'     => now()->addDays($daysFromNow)->addHours(2),
        ]);

        return $eventId;
    }

    private function addEventImage(int $eventId, ?string $externalUrl = null): int
    {
        return DB::table('communityevents_images')->insertGetId([
            'eventid'     => $eventId,
            'contenttype' => 'image/jpeg',
            'archived'    => 0,
            'externalmods' => $externalUrl ? json_encode(['url' => $externalUrl]) : null,
        ]);
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

    public function test_skips_past_events(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        // Event 1 day in the past — should not be included
        $this->createEvent($group->id, 'Past Event', -1);

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

    public function test_event_with_image_populates_image_url(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent($group->id, 'Photo Event');
        $this->addEventImage($eventId, 'https://example.com/photo.jpg');

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['imageUrl'] === 'https://example.com/photo.jpg';
        });
    }

    public function test_event_without_image_has_null_image_url(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent($group->id, 'No Photo Event');
        // No addEventImage call

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['imageUrl'] === null;
        });
    }

    public function test_archived_image_is_excluded(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent($group->id, 'Archived Image Event');
        DB::table('communityevents_images')->insert([
            'eventid'     => $eventId,
            'contenttype' => 'image/jpeg',
            'archived'    => 1,
            'externalmods' => json_encode(['url' => 'https://example.com/archived.jpg']),
        ]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['imageUrl'] === null;
        });
    }

    public function test_contact_fields_are_passed_through(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent($group->id, 'Contactable Event', 7, [
            'contactname'  => 'Jane Smith',
            'contactphone' => '01234 567890',
            'contactemail' => 'jane@example.com',
            'contacturl'   => 'https://example.com',
        ]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            $e = $mail->events[0];
            return $e['contactname']  === 'Jane Smith'
                && $e['contactphone'] === '01234 567890'
                && $e['contactemail'] === 'jane@example.com'
                && $e['contacturl']   === 'https://example.com';
        });
    }

    public function test_event_with_no_contact_fields_has_nulls(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent($group->id, 'Minimal Event');

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            $e = $mail->events[0];
            return $e['contactname']  === null
                && $e['contactphone'] === null
                && $e['contactemail'] === null
                && $e['contacturl']   === null;
        });
    }

    public function test_description_is_passed_through(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent($group->id, 'Described Event', 7, [
            'description' => 'A detailed description of the event.',
        ]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['description'] === 'A detailed description of the event.';
        });
    }

    public function test_first_image_used_when_multiple_exist(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent($group->id, 'Multi-image Event');
        $this->addEventImage($eventId, 'https://example.com/first.jpg');
        $this->addEventImage($eventId, 'https://example.com/second.jpg');

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['imageUrl'] === 'https://example.com/first.jpg';
        });
    }

    public function test_event_with_no_end_time_has_null_end(): void
    {
        Mail::fake();

        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = DB::table('communityevents')->insertGetId([
            'title'    => 'No End Time Event',
            'location' => 'Somewhere',
            'pending'  => 0,
            'deleted'  => 0,
            'added'    => now(),
        ]);
        DB::table('communityevents_groups')->insert(['eventid' => $eventId, 'groupid' => $group->id]);
        DB::table('communityevents_dates')->insert([
            'eventid' => $eventId,
            'start'   => now()->addDays(7),
            'end'     => '0000-00-00 00:00:00',  // sentinel for "no end time" — column is NOT NULL
        ]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['end'] === null;
        });
    }
}
