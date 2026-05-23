<?php

namespace Tests\Feature\User;

use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MembershipCommandsTest extends TestCase
{
    public function test_add_membership_creates_membership_for_user(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:add-membership --email={$email} --group={$group->nameshort}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('memberships', [
            'userid' => $user->id,
            'groupid' => $group->id,
        ]);
    }

    public function test_add_membership_defaults_to_member_role(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:add-membership --email={$email} --group={$group->nameshort}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('memberships', [
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => 'Member',
        ]);
    }

    public function test_add_membership_with_moderator_role(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:add-membership --email={$email} --group={$group->nameshort} --role=Moderator")
            ->assertExitCode(0);

        $this->assertDatabaseHas('memberships', [
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
        ]);
    }

    public function test_add_membership_fails_if_user_not_found(): void
    {
        $group = $this->createTestGroup();

        $this->artisan("user:add-membership --email=notexists@example.com --group={$group->nameshort}")
            ->assertExitCode(1);
    }

    public function test_add_membership_fails_if_group_not_found(): void
    {
        $user = $this->createTestUser();
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:add-membership --email={$email} --group=NonExistentGroup999")
            ->assertExitCode(1);
    }

    public function test_add_membership_skips_if_already_member(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $email = DB::table('users_emails')->where('userid', $user->id)->value('email');

        $this->artisan("user:add-membership --email={$email} --group={$group->nameshort}")
            ->expectsOutputToContain('already a member')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->count());
    }

    public function test_remove_membership_removes_existing_membership(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $this->artisan("user:remove-membership --user-id={$user->id} --group-id={$group->id}")
            ->assertExitCode(0);

        $this->assertDatabaseMissing('memberships', [
            'userid' => $user->id,
            'groupid' => $group->id,
        ]);
    }

    public function test_remove_membership_exits_cleanly_if_not_member(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->artisan("user:remove-membership --user-id={$user->id} --group-id={$group->id}")
            ->assertExitCode(0);
    }

    public function test_remove_membership_fails_if_user_id_not_provided(): void
    {
        $group = $this->createTestGroup();

        $this->artisan("user:remove-membership --group-id={$group->id}")
            ->assertExitCode(1);
    }

    public function test_remove_membership_fails_if_group_id_not_provided(): void
    {
        $user = $this->createTestUser();

        $this->artisan("user:remove-membership --user-id={$user->id}")
            ->assertExitCode(1);
    }
}
