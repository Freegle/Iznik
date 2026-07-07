<?php

namespace Tests\Feature\Group;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupAdminCommandsTest extends TestCase
{
    public function test_set_closed_closes_a_group(): void
    {
        $group = $this->createTestGroup(['settings' => ['closed' => 0]]);

        $this->artisan("group:set-closed --group={$group->nameshort} --closed=1")
            ->assertExitCode(0);

        $settings = json_decode(DB::table('groups')->where('id', $group->id)->value('settings'), true);
        $this->assertSame(1, $settings['closed']);
    }

    public function test_set_closed_opens_a_group(): void
    {
        $group = $this->createTestGroup(['settings' => ['closed' => 1]]);

        $this->artisan("group:set-closed --group={$group->nameshort} --closed=0")
            ->assertExitCode(0);

        $settings = json_decode(DB::table('groups')->where('id', $group->id)->value('settings'), true);
        $this->assertSame(0, $settings['closed']);
    }

    public function test_set_closed_fails_if_group_not_found(): void
    {
        $this->artisan('group:set-closed --group=NonExistentGroup999 --closed=1')
            ->assertExitCode(1);
    }

    public function test_set_closed_fails_if_group_not_provided(): void
    {
        $this->artisan('group:set-closed --closed=1')
            ->assertExitCode(1);
    }

    public function test_copy_group_creates_new_unpublished_group(): void
    {
        $source = $this->createTestGroup([
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['closed' => 0],
            'tagline' => 'Test tagline',
        ]);
        $destName = 'CopyDest_' . uniqid();

        $this->artisan("group:copy --from={$source->nameshort} --to={$destName}")
            ->assertExitCode(0);

        $dest = DB::table('groups')->where('nameshort', $destName)->first();
        $this->assertNotNull($dest);
        $this->assertSame(0, (int) $dest->publish);
        $this->assertSame(0, (int) $dest->onmap);
    }

    public function test_copy_group_fails_if_source_not_found(): void
    {
        $this->artisan('group:copy --from=NonExistentGroup999 --to=NewGroupName')
            ->assertExitCode(1);
    }

    public function test_copy_group_fails_if_destination_already_exists(): void
    {
        $source = $this->createTestGroup();
        $dest = $this->createTestGroup();

        $this->artisan("group:copy --from={$source->nameshort} --to={$dest->nameshort}")
            ->assertExitCode(1);
    }

    public function test_move_members_transfers_memberships(): void
    {
        $from = $this->createTestGroup();
        $to = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $from);

        $this->artisan("group:move-members --from={$from->nameshort} --to={$to->nameshort}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('memberships', ['userid' => $user->id, 'groupid' => $to->id]);
        $this->assertDatabaseMissing('memberships', ['userid' => $user->id, 'groupid' => $from->id]);
    }

    public function test_move_members_skips_already_member_of_destination(): void
    {
        $from = $this->createTestGroup();
        $to = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $from);
        $this->createMembership($user, $to);

        $this->artisan("group:move-members --from={$from->nameshort} --to={$to->nameshort}")
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $to->id)
            ->count());
    }

    public function test_move_members_fails_if_from_group_not_found(): void
    {
        $to = $this->createTestGroup();

        $this->artisan("group:move-members --from=NonExistentGroup999 --to={$to->nameshort}")
            ->assertExitCode(1);
    }

    public function test_move_members_fails_if_to_group_not_found(): void
    {
        $from = $this->createTestGroup();

        $this->artisan("group:move-members --from={$from->nameshort} --to=NonExistentGroup999")
            ->assertExitCode(1);
    }
}
