<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\Membership;
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
        foreach (['volunteering_images', 'volunteering_groups', 'volunteering', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
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
}
