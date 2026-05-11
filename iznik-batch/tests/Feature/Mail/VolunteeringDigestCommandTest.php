<?php

namespace Tests\Feature\Mail;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\Group;
use App\Models\Membership;
use App\Services\VolunteeringDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VolunteeringDigestCommandTest extends TestCase
{
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

    public function test_debug_global_volunteering_intermediate_state(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $volId = DB::table('volunteering')->insertGetId([
            'title'       => 'Global Opportunity',
            'location'    => 'Community Centre',
            'description' => 'Help needed.',
            'pending'     => 0,
            'deleted'     => 0,
            'expired'     => 0,
            'added'       => now(),
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency'      => 24,
        ]);

        // Step 1: confirm no volunteering_groups row for our volunteering
        $vgCount = (int) DB::table('volunteering_groups')->where('volunteeringid', $volId)->count();
        $this->assertSame(0, $vgCount, "volunteering_groups should have no row for global volunteering");

        // Step 2: groups query
        $groups = DB::table('groups')
            ->where('type', Group::TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->where(function ($q) {
                $q->whereNull('lastvolunteeringroundup')
                    ->orWhereRaw('DATEDIFF(NOW(), lastvolunteeringroundup) >= ?', [VolunteeringDigestService::MIN_INTERVAL_DAYS]);
            })
            ->whereRaw("nameshort NOT LIKE '%playground%'")
            ->select(['id', 'nameshort', 'settings'])
            ->get();

        $groupIds = $groups->pluck('id')->toArray();
        $this->assertContains($group->id, $groupIds, "Groups query must include our test group. Found IDs: ".implode(',', $groupIds));

        // Step 3: volunteerings query for our group
        $groupRow = $groups->firstWhere('id', $group->id);
        $volunteerings = DB::table('volunteering')
            ->leftJoin('volunteering_images', 'volunteering_images.opportunityid', '=', 'volunteering.id')
            ->where('volunteering.pending', 0)
            ->where('volunteering.deleted', 0)
            ->where('volunteering.expired', 0)
            ->where(function ($q) use ($groupRow) {
                $q->whereExists(function ($sub) use ($groupRow) {
                    $sub->select(DB::raw(1))
                        ->from('volunteering_groups')
                        ->whereColumn('volunteering_groups.volunteeringid', 'volunteering.id')
                        ->where('volunteering_groups.groupid', $groupRow->id);
                })->orWhereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('volunteering_groups')
                        ->whereColumn('volunteering_groups.volunteeringid', 'volunteering.id');
                });
            })
            ->select(['volunteering.id', 'volunteering.title'])
            ->get();

        $volIds = $volunteerings->pluck('id')->toArray();
        $this->assertContains($volId, $volIds, "Volunteering query must include our global volunteering. Found IDs: ".implode(',', $volIds));

        // Step 4: members query
        $members = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->join('users_emails', function ($join) {
                $join->on('users_emails.userid', '=', 'memberships.userid')
                    ->where('users_emails.preferred', '=', 1);
            })
            ->where('memberships.groupid', $group->id)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('memberships.volunteeringallowed', 1)
            ->where('memberships.emailfrequency', '!=', 0)
            ->whereNull('users.deleted')
            ->whereNotNull('users_emails.email')
            ->get();

        $this->assertCount(1, $members, "Members query must find exactly 1 member");

        // Step 5: full service call
        $result = (new VolunteeringDigestService())->sendVolunteeringDigests(false);
        $this->assertSame(1, $result['groups_processed'], "Service must process 1 group");
        $this->assertSame(1, $result['sent'], "Service must send 1 email");
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

    public function test_includes_photo_thumb_when_image_exists(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $volId = $this->createVolunteering($group->id);

        DB::table('volunteering_images')->insert([
            'opportunityid' => $volId,
            'contenttype'   => 'image/jpeg',
            'archived'      => 0,
        ]);

        $member = $this->createTestUser();
        $this->createMembership($member, $group, [
            'volunteeringallowed' => 1,
            'emailfrequency' => 24,
        ]);

        $this->artisan('mail:volunteering-digest')
            ->expectsOutputToContain('Sent 1 email(s)')
            ->assertExitCode(0);

        Mail::assertSent(VolunteeringDigestMail::class, function ($mail) {
            return !empty($mail->volunteerings[0]['photo_thumb']);
        });
    }
}
