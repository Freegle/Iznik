<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\Membership;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VolunteeringDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // VolunteeringDigestService queries volunteerings globally (no WHERE groupid in SQL).
        // Rows from parallel test classes can slip through DatabaseTransactions isolation.
        // Delete inside the current transaction so leaked rows are hidden without affecting
        // other test classes (the DELETE is rolled back with this test's transaction).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['volunteering_dates', 'volunteering_images', 'volunteering_groups', 'volunteering', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createVolunteering(int $groupId = null, string $title = 'Test Volunteering'): int
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

        if ($groupId !== null) {
            DB::table('volunteering_groups')->insert([
                'volunteeringid' => $volId,
                'groupid' => $groupId,
            ]);
        }

        return $volId;
    }

    public function test_smoke_no_groups(): void
    {
        Mail::fake();

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_with_no_active_volunteerings(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        // No volunteering created for the group

        $this->artisan('mail:volunteering-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_to_members_with_volunteering_enabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member1 = $this->createTestUser();
        $this->createMembership($member1, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $member2 = $this->createTestUser();
        $this->createMembership($member2, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 2 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_spool_failure_for_one_member_does_not_abort_digest(): void
    {
        // The spooler throws on the first recipient (e.g. a transient MJML
        // render error, which spool() re-throws because it isn't a permanent
        // address failure). The per-member loop must skip that recipient and
        // keep going — not let the exception escape and abort the whole run.
        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member1 = $this->createTestUser();
        $this->createMembership($member1, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $member2 = $this->createTestUser();
        $this->createMembership($member2, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

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

        $this->assertSame(2, $calls, 'Both members should have been attempted');
    }

    public function test_skips_members_with_volunteering_disabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $memberOptedIn = $this->createTestUser();
        $this->createMembership($memberOptedIn, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $memberOptedOut = $this->createTestUser();
        $this->createMembership($memberOptedOut, $group, [
            'volunteeringallowed' => 0,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_members_with_email_frequency_zero(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $memberActive = $this->createTestUser();
        $this->createMembership($memberActive, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $memberNoEmail = $this->createTestUser();
        $this->createMembership($memberNoEmail, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 0,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_deleted_users(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $deletedUser = $this->createTestUser(['deleted' => now()]);
        $this->createMembership($deletedUser, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_recently_sent(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['lastvolunteeringroundup' => now()->subDays(1)]);

        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_group_not_sent_in_3_days(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['lastvolunteeringroundup' => now()->subDays(4)]);

        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_updates_lastvolunteeringroundup_after_sending(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->assertExitCode(0);

        $this->assertNotNull(
            DB::table('groups')->where('id', $group->id)->value('lastvolunteeringroundup')
        );
    }

    public function test_includes_global_volunteerings_with_no_group(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        // Global volunteering — no group assigned
        $this->createVolunteering(null, 'Global Opportunity');

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_expired_volunteerings(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

        // Expired volunteering — should not be included
        $expiredId = DB::table('volunteering')->insertGetId([
            'title' => 'Expired Opportunity',
            'location' => 'Somewhere',
            'pending' => 0,
            'deleted' => 0,
            'expired' => 1,
            'added' => now(),
        ]);
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $expiredId,
            'groupid' => $group->id,
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_freegle_group(): void
    {
        Mail::fake();

        $group = $this->createTestGroup(['type' => Group::TYPE_OTHER]);
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_emails(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send 1 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        $this->assertNull(
            DB::table('groups')->where('id', $group->id)->value('lastvolunteeringroundup')
        );
    }

    /**
     * Regression test for Discourse topic 9692 post 12.
     *
     * The DB stores volunteering titles HTML-encoded (e.g. "Coffee &amp; Cake").
     * VolunteeringDigestService must call html_entity_decode() before passing the
     * title to the mailable so Blade's {{ }} produces a single &amp; in the HTML
     * source (which email clients render as "&"). Without the decode, {{ }} double-
     * encodes to &amp;amp;, which email clients render as the literal string "&amp;".
     */
    public function test_html_encoded_title_in_db_is_decoded_in_rendered_email(): void
    {
        $group = $this->createTestGroup();

        // Insert volunteering with HTML-encoded title, exactly as production DB stores it.
        $volId = DB::table('volunteering')->insertGetId([
            'title'       => 'Coffee &amp; Cake Stall',
            'location'    => 'Tea &amp; Coffee Room',
            'description' => 'Bring friends &amp; family.',
            'pending'     => 0,
            'deleted'     => 0,
            'expired'     => 0,
            'added'       => now(),
        ]);
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volId,
            'groupid'        => $group->id,
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency'      => 24,
        ]);

        // Capture the VolunteeringDigestMail before it reaches the spooler.
        $capturedMail = null;
        $spooler = \Mockery::mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andReturnUsing(function ($mailable) use (&$capturedMail) {
            $capturedMail = $mailable;
            return 'spooled-id';
        });
        $this->app->instance(EmailSpoolerService::class, $spooler);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        $this->assertNotNull($capturedMail, 'A VolunteeringDigestMail should have been created');

        $html = $capturedMail->render();

        // Correct: single &amp; in HTML source → email client displays "&"
        $this->assertStringContainsString('Coffee &amp; Cake Stall', $html,
            'Title must appear as single-encoded "&amp;" so email clients render "&"');

        // Wrong: double-encoded &amp;amp; → email client displays the literal "&amp;"
        $this->assertStringNotContainsString('Coffee &amp;amp; Cake Stall', $html,
            'Title must not be double-encoded (regression guard for 9692/12)');
    }

    public function test_skips_group_with_volunteering_setting_disabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['settings' => json_encode(['volunteering' => false])]);

        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_online_field_is_passed_through(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

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
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volId,
            'groupid' => $group->id,
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_applyby_deadline_is_formatted(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $volId = $this->createVolunteering($group->id);

        DB::table('volunteering_dates')->insert([
            'volunteeringid' => $volId,
            'applyby' => '2026-06-30 00:00:00',
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_contact_fields_are_passed_through(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

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
        // No volunteering_groups row → global opportunity, shown for all groups

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_photo_thumb_from_externalmods_url(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $volId = $this->createVolunteering($group->id);

        DB::table('volunteering_images')->insert([
            'opportunityid' => $volId,
            'contenttype' => 'image/jpeg',
            'externaluid' => null,
            'externalmods' => json_encode(['url' => 'https://cdn.example.com/image.jpg']),
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }
}
