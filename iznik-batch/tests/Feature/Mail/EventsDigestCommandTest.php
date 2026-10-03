<?php

namespace Tests\Feature\Mail;

use App\Mail\Event\EventsDigestMail;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventsDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // EventsDigestService queries communityevents / users globally.
        // Rows from parallel test classes can slip through DatabaseTransactions
        // isolation. Delete inside the current transaction so leaked rows are
        // hidden without affecting other test classes (the DELETE rolls back with
        // this test's transaction).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['communityevents_images', 'communityevents_dates', 'communityevents', 'users_digests', 'users_emails', 'users'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createEvent(string $title = 'Test Event', int $daysFromNow = 7, array $fields = []): int
    {
        $eventId = DB::table('communityevents')->insertGetId(array_merge([
            'title'       => $title,
            'location'    => 'Test Hall, Test Town',
            'description' => 'A test event description.',
            'pending'     => 0,
            'deleted'     => 0,
            'added'       => now(),
        ], $fields));

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

    private function setLastSent(int $userId, \DateTimeInterface|string $when): void
    {
        DB::table('users_digests')->insert([
            'userid'   => $userId,
            'mode'     => 'events',
            'lastsent' => $when,
        ]);
    }

    public function test_smoke_no_events(): void
    {
        Mail::fake();

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_there_are_no_upcoming_events(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        // No events created

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_one_email_per_user_with_events_enabled(): void
    {
        Mail::fake();

        $this->createEvent();

        $member1 = $this->createTestUser();
        DB::table('users')->where('id', $member1->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $member2 = $this->createTestUser();
        DB::table('users')->where('id', $member2->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 2 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_spool_failure_for_one_user_does_not_abort_digest(): void
    {
        // The spooler throws on the first recipient (e.g. a transient MJML render
        // error, which spool() re-throws). The per-user loop must skip that
        // recipient and keep going — not let the exception abort the whole run.
        $this->createEvent();

        $member1 = $this->createTestUser();
        DB::table('users')->where('id', $member1->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $member2 = $this->createTestUser();
        DB::table('users')->where('id', $member2->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $calls = 0;
        $spooler = \Mockery::mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andReturnUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('simulated transient MJML render failure');
            }
            return 'spooled-id';
        });
        $this->app->instance(EmailSpoolerService::class, $spooler);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        $this->assertSame(2, $calls, 'Both users should have been attempted');
    }

    public function test_skips_members_with_events_disabled(): void
    {
        Mail::fake();

        $this->createEvent();

        $memberOptedIn = $this->createTestUser();
        DB::table('users')->where('id', $memberOptedIn->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $memberOptedOut = $this->createTestUser();
        DB::table('users')->where('id', $memberOptedOut->id)->update(['eventsallowed' => 0, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_members_with_email_frequency_zero(): void
    {
        Mail::fake();

        $this->createEvent();

        $memberActive = $this->createTestUser();
        DB::table('users')->where('id', $memberActive->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $memberOptedOut = $this->createTestUser();
        DB::table('users')->where('id', $memberOptedOut->id)->update(['eventsallowed' => 1, 'emailfrequency' => 0]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_deleted_users(): void
    {
        Mail::fake();

        $this->createEvent();

        $deletedUser = $this->createTestUser(['deleted' => now()]);
        DB::table('users')->where('id', $deletedUser->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_user_recently_sent(): void
    {
        Mail::fake();

        $this->createEvent();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        // Sent only 1 day ago (< 3-day threshold) — must be skipped.
        $this->setLastSent($member->id, now()->subDays(1));

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_user_not_sent_in_3_days(): void
    {
        Mail::fake();

        $this->createEvent();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        // Last sent 4 days ago (>= 3-day threshold) — must be processed.
        $this->setLastSent($member->id, now()->subDays(4));

        $this->artisan('mail:events-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_records_lastsent_per_user_after_sending(): void
    {
        Mail::fake();

        $this->createEvent();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        $this->assertNotNull(
            DB::table('users_digests')
                ->where('userid', $member->id)
                ->where('mode', 'events')
                ->value('lastsent')
        );
    }

    public function test_skips_events_more_than_30_days_away(): void
    {
        Mail::fake();

        $this->createEvent('Far Future Event', 35);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_past_events(): void
    {
        Mail::fake();

        $this->createEvent('Past Event', -1);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_or_record(): void
    {
        Mail::fake();

        $this->createEvent();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:events-digest', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send 1 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        // Dry-run must not record a per-user lastsent.
        $this->assertNull(
            DB::table('users_digests')->where('userid', $member->id)->where('mode', 'events')->value('lastsent')
        );
    }

    public function test_event_with_image_populates_image_url(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent('Photo Event');
        $this->addEventImage($eventId, 'https://example.com/photo.jpg');

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['imageUrl'] === 'https://example.com/photo.jpg';
        });
    }

    public function test_local_event_image_uses_image_domain_thumbnail(): void
    {
        // A locally-uploaded event image (no externalmods url, no external uid) must
        // resolve to the image domain's community-event thumbnail route. The old code
        // pointed at {userSite}/communityevent/{id}/image/{imgid}, which is not a real
        // route and 404'd, so event-digest photos never rendered.
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent('Local Photo Event');
        $imageId = $this->addEventImage($eventId);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        $expected = config('freegle.images.domain') . "/tcimg_{$imageId}.jpg";
        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) use ($expected) {
            $url = $mail->events[0]['imageUrl'];
            return $url === $expected && ! str_contains($url, '/communityevent/');
        });
    }

    public function test_tus_uploaded_event_image_uses_delivery_proxy(): void
    {
        // A TUS-uploaded image (externaluid contains freegletusd-) routes the raw file
        // through the delivery/resize proxy, exactly as volunteering does.
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent('Tus Photo Event');
        DB::table('communityevents_images')->insert([
            'eventid'     => $eventId,
            'contenttype' => 'image/jpeg',
            'archived'    => 0,
            'externaluid' => 'freegletusd-abc123',
        ]);

        $this->artisan('mail:events-digest')->assertExitCode(0);

        $source   = config('freegle.tus_uploader') . '/abc123';
        $expected = config('freegle.delivery.base_url') . '?url=' . urlencode($source) . '&w=400';
        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) use ($expected) {
            return $mail->events[0]['imageUrl'] === $expected;
        });
    }

    public function test_event_without_image_has_null_image_url(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent('No Photo Event');

        $this->artisan('mail:events-digest')->assertExitCode(0);

        Mail::assertSent(EventsDigestMail::class, function (EventsDigestMail $mail) {
            return $mail->events[0]['imageUrl'] === null;
        });
    }

    public function test_archived_image_is_excluded(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent('Archived Image Event');
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

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent('Contactable Event', 7, [
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

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent('Minimal Event');

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

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $this->createEvent('Described Event', 7, [
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

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = $this->createEvent('Multi-image Event');
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

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['eventsallowed' => 1, 'emailfrequency' => 24]);

        $eventId = DB::table('communityevents')->insertGetId([
            'title'    => 'No End Time Event',
            'location' => 'Somewhere',
            'pending'  => 0,
            'deleted'  => 0,
            'added'    => now(),
        ]);
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
