<?php

namespace Tests\Feature\Mail;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\Group;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VolunteeringDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // VolunteeringDigestService queries volunteering / groups / users globally.
        // Rows from parallel test classes can slip through DatabaseTransactions
        // isolation. Delete inside the current transaction so leaked rows are
        // hidden without affecting other test classes (the DELETE rolls back with
        // this test's transaction).
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['volunteering_dates', 'volunteering_images', 'volunteering_groups', 'volunteering', 'users_digests', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function createVolunteering(?int $groupId = null, string $title = 'Test Volunteering'): int
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

    private function linkVolunteeringToGroup(int $volId, int $groupId): void
    {
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volId,
            'groupid' => $groupId,
        ]);
    }

    private function setLastSent(int $userId, \DateTimeInterface|string $when): void
    {
        DB::table('users_digests')->insert([
            'userid'   => $userId,
            'mode'     => 'volunteering',
            'lastsent' => $when,
        ]);
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
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_one_email_per_user_with_volunteering_enabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member1 = $this->createTestUser();
        $this->createMembership($member1, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $member2 = $this->createTestUser();
        $this->createMembership($member2, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 2 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_one_combined_email_covers_all_a_users_groups(): void
    {
        // A user in two volunteering-enabled groups, each with its own
        // opportunity, gets ONE email containing BOTH — not one email per group.
        Mail::fake();

        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $this->createVolunteering($group1->id, 'Group One Opp');
        $this->createVolunteering($group2->id, 'Group Two Opp');

        $user = $this->createTestUser();
        $this->createMembership($user, $group1, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);
        $this->createMembership($user, $group2, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) {
            return count($mail->volunteerings) === 2;
        });
    }

    public function test_opportunity_cross_posted_to_several_of_users_groups_appears_once(): void
    {
        // The same opportunity shared with two of the user's groups must appear
        // ONCE (deduplicated by id), annotated with both group names.
        Mail::fake();

        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $user = $this->createTestUser();
        $this->createMembership($user, $group1, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);
        $this->createMembership($user, $group2, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $volId = $this->createVolunteering($group1->id, 'Cross-posted Opp');
        $this->linkVolunteeringToGroup($volId, $group2->id);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) use ($group1, $group2) {
            if (count($mail->volunteerings) !== 1) {
                return false;
            }
            // Each group is a ['name' => , 'url' => ] pair for the "Posted on
            // <group>" byline: the friendly name plus its /explore link.
            $groups = $mail->volunteerings[0]['groups'] ?? [];
            $names = array_column($groups, 'name');
            sort($names);
            $expectedNames = [$group1->namefull, $group2->namefull];
            sort($expectedNames);
            if ($names !== $expectedNames) {
                return false;
            }

            $urls = array_column($groups, 'url');
            return collect([$group1, $group2])->every(
                fn ($g) => collect($urls)->contains(fn ($u) => str_contains($u, '/explore/' . $g->nameshort))
            );
        });
    }

    public function test_spool_failure_for_one_user_does_not_abort_digest(): void
    {
        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member1 = $this->createTestUser();
        $this->createMembership($member1, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $member2 = $this->createTestUser();
        $this->createMembership($member2, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

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

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $memberOptedIn = $this->createTestUser();
        $this->createMembership($memberOptedIn, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $memberOptedOut = $this->createTestUser();
        $this->createMembership($memberOptedOut, $group, ['volunteeringallowed' => 0, 'emailfrequency' => 24]);

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
        $this->createMembership($memberActive, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $memberNoEmail = $this->createTestUser();
        $this->createMembership($memberNoEmail, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 0]);

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
        $this->createMembership($deletedUser, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 0 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_user_recently_sent(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        // Sent only 1 day ago (< 3-day threshold) — must be skipped.
        $this->setLastSent($member->id, now()->subDays(1));

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_user_not_sent_in_3_days(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

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

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        $this->assertNotNull(
            DB::table('users_digests')
                ->where('userid', $member->id)
                ->where('mode', 'volunteering')
                ->value('lastsent')
        );
    }

    public function test_includes_global_volunteerings_with_no_group(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        // Global opportunity — no group assigned, shown to all eligible users.
        $this->createVolunteering(null, 'Global Opportunity');

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_expired_volunteerings(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

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
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_non_freegle_group(): void
    {
        Mail::fake();

        $group = $this->createTestGroup(['type' => Group::TYPE_OTHER]);
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_or_record(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would send 1 email(s)')
            ->assertExitCode(0);

        Mail::assertNothingSent();

        $this->assertNull(
            DB::table('users_digests')->where('userid', $member->id)->where('mode', 'volunteering')->value('lastsent')
        );
    }

    public function test_skips_group_with_volunteering_setting_disabled(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)
            ->update(['settings' => json_encode(['volunteering' => false])]);

        $this->createVolunteering($group->id);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')->assertExitCode(0);

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
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

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

        $group = $this->createTestGroup();
        $volId = $this->createVolunteering($group->id);

        DB::table('volunteering_dates')->insert([
            'volunteeringid' => $volId,
            'applyby' => '2026-06-30 00:00:00',
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

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
        // No volunteering_groups row → global opportunity, shown for all users

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

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

        $group = $this->createTestGroup();
        $volId = $this->createVolunteering($group->id);

        DB::table('volunteering_images')->insert([
            'opportunityid' => $volId,
            'contenttype' => 'image/jpeg',
            'externaluid' => null,
            'externalmods' => json_encode(['url' => 'https://cdn.example.com/image.jpg']),
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['volunteeringallowed' => 1, 'emailfrequency' => 24]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSent(VolunteeringDigestMail::class, function (VolunteeringDigestMail $mail) {
            return $mail->volunteerings[0]['photo_thumb'] === 'https://cdn.example.com/image.jpg';
        });
    }
}
