<?php

namespace Tests\Feature\Membership;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * membership:remove-banned - clears memberships held by members banned from that group,
 * the rows subscribe-by-email left behind before it checked users_banned.
 */
class RemoveBannedCommandTest extends TestCase
{
    private function banAndRejoin(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $mod = $this->createTestUser();

        DB::table('users_banned')->insert([
            'userid' => $user->id,
            'groupid' => $group->id,
            'byuser' => $mod->id,
            'date' => now()->subDay(),
        ]);

        $this->createMembership($user, $group, ['added' => now()]);

        return [$user->id, $group->id, $mod->id];
    }

    public function test_dry_run_reports_without_removing(): void
    {
        [$userid, $groupid] = $this->banAndRejoin();

        $this->artisan('membership:remove-banned', ['--user' => $userid])
            ->expectsOutputToContain('Would remove 1 membership')
            ->assertExitCode(0);

        $this->assertTrue(
            DB::table('memberships')->where('userid', $userid)->where('groupid', $groupid)->exists(),
            'dry run must not remove anything'
        );
    }

    public function test_removes_the_membership_and_logs_it(): void
    {
        [$userid, $groupid, $modid] = $this->banAndRejoin();

        $this->artisan('membership:remove-banned', ['--user' => $userid, '--commit' => true])
            ->expectsOutputToContain('Removed 1 membership')
            ->assertExitCode(0);

        $this->assertFalse(
            DB::table('memberships')->where('userid', $userid)->where('groupid', $groupid)->exists()
        );

        $log = DB::table('logs')
            ->where('type', 'Group')
            ->where('subtype', 'Left')
            ->where('user', $userid)
            ->where('groupid', $groupid)
            ->first();

        $this->assertNotNull($log, 'removal must be visible in the modlog');
        $this->assertEquals('via ban', $log->text);
        $this->assertEquals($modid, $log->byuser);
    }

    public function test_leaves_memberships_on_groups_the_member_is_not_banned_from(): void
    {
        [$userid, $bannedGroupid] = $this->banAndRejoin();

        $user = \App\Models\User::find($userid);
        $otherGroup = $this->createTestGroup();
        $this->createMembership($user, $otherGroup);

        $this->artisan('membership:remove-banned', ['--user' => $userid, '--commit' => true])
            ->assertExitCode(0);

        $this->assertFalse(
            DB::table('memberships')->where('userid', $userid)->where('groupid', $bannedGroupid)->exists()
        );
        $this->assertTrue(
            DB::table('memberships')->where('userid', $userid)->where('groupid', $otherGroup->id)->exists()
        );
    }
}
