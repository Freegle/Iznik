<?php

namespace Tests\Feature\Mail;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VolunteeringDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // VolunteeringDigestService queries volunteering / users globally.
        // Rows from parallel test classes can slip through DatabaseTransactions
        // isolation. Delete inside the current transaction so leaked rows are
        // hidden without affecting other test classes (the DELETE rolls back with
        // this test's transaction).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['volunteering_dates', 'volunteering_images', 'volunteering', 'users_digests', 'users_emails', 'users'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createVolunteering(string $title = 'Test Volunteering'): int
    {
        $volId = DB::table('volunteering')->insertGetId([
            'title' => $title,
            'location' => 'Community Centre',
            'description' => 'Help needed at local centre.',
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'added' => now(),
        ]);

        return $volId;
    }

    private function setLastSent(int $userId, \DateTimeInterface|string $when): void
    {
        DB::table('users_digests')->insert([
            'userid'   => $userId,
            'mode'     => 'volunteering',
            'lastsent' => $when,
        ]);
    }

    public function test_smoke_no_opportunities(): void
    {
        Mail::fake();

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_when_there_are_no_active_volunteerings(): void
    {
        Mail::fake();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_one_email_per_user_with_volunteering_enabled(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $member1 = $this->createTestUser();
        DB::table('users')->where('id', $member1->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $member2 = $this->createTestUser();
        DB::table('users')->where('id', $member2->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 2 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_spool_failure_for_one_user_does_not_abort_digest(): void
    {
        $this->createVolunteering();

        $member1 = $this->createTestUser();
        DB::table('users')->where('id', $member1->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $member2 = $this->createTestUser();
        DB::table('users')->where('id', $member2->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

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

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        $this->assertSame(2, $calls, 'Both users should have been attempted');
    }

    public function test_skips_members_with_volunteering_disabled(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $memberOptedIn = $this->createTestUser();
        DB::table('users')->where('id', $memberOptedIn->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $memberOptedOut = $this->createTestUser();
        DB::table('users')->where('id', $memberOptedOut->id)->update(['volunteeringallowed' => 0, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_members_with_email_frequency_zero(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $memberActive = $this->createTestUser();
        DB::table('users')->where('id', $memberActive->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $memberNoEmail = $this->createTestUser();
        DB::table('users')->where('id', $memberNoEmail->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 0]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_deleted_users(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $deletedUser = $this->createTestUser(['deleted' => now()]);
        DB::table('users')->where('id', $deletedUser->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_user_recently_sent(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        // Sent only 1 day ago (< 3-day threshold) — must be skipped.
        $this->setLastSent($member->id, now()->subDays(1));

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_user_not_sent_in_3_days(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        // Last sent 4 days ago (>= 3-day threshold) — must be processed.
        $this->setLastSent($member->id, now()->subDays(4));

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_records_lastsent_per_user_after_sending(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        $this->assertNotNull(
            DB::table('users_digests')
                ->where('userid', $member->id)
                ->where('mode', 'volunteering')
                ->value('lastsent')
        );
    }

    public function test_includes_an_opportunity_for_every_eligible_user(): void
    {
        Mail::fake();

        $this->createVolunteering('Global Opportunity');

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_expired_volunteerings(): void
    {
        Mail::fake();

        $expiredId = DB::table('volunteering')->insertGetId([
            'title' => 'Expired Opportunity',
            'location' => 'Somewhere',
            'pending' => 0,
            'deleted' => 0,
            'expired' => 1,
            'added' => now(),
        ]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_or_record(): void
    {
        Mail::fake();

        $this->createVolunteering();

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send 1 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        $this->assertNull(
            DB::table('users_digests')->where('userid', $member->id)->where('mode', 'volunteering')->value('lastsent')
        );
    }

    public function test_online_field_is_passed_through(): void
    {
        Mail::fake();

        $volId = DB::table('volunteering')->insertGetId([
            'title' => 'Online Helper',
            'location' => 'Anywhere',
            'description' => 'Remote volunteering.',
            'online' => 1,
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'added' => now(),
        ]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) {
            return $mail->volunteerings[0]['online'] === true;
        });
    }

    public function test_applyby_deadline_is_formatted(): void
    {
        Mail::fake();

        $volId = $this->createVolunteering();

        DB::table('volunteering_dates')->insert([
            'volunteeringid' => $volId,
            'applyby' => '2026-06-30 00:00:00',
        ]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) {
            return !empty($mail->volunteerings[0]['applyby']);
        });
    }

    public function test_contact_fields_are_passed_through(): void
    {
        Mail::fake();

        DB::table('volunteering')->insertGetId([
            'title' => 'Contact Test',
            'location' => 'Town Hall',
            'description' => 'Contact details test.',
            'contactname' => 'Jane Smith',
            'contactphone' => '01234 567890',
            'contactemail' => 'jane@example.org',
            'contacturl' => 'https://example.org/volunteer',
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'added' => now(),
        ]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) {
            $v = $mail->volunteerings[0];
            return $v['contactname'] === 'Jane Smith'
                && $v['contactemail'] === 'jane@example.org';
        });
    }

    public function test_photo_thumb_from_externalmods_url(): void
    {
        Mail::fake();

        $volId = $this->createVolunteering();

        DB::table('volunteering_images')->insert([
            'opportunityid' => $volId,
            'contenttype' => 'image/jpeg',
            'externaluid' => null,
            'externalmods' => json_encode(['url' => 'https://cdn.example.com/image.jpg']),
        ]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) {
            return $mail->volunteerings[0]['photo_thumb'] === 'https://cdn.example.com/image.jpg';
        });
    }
}
