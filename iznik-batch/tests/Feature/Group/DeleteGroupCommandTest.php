<?php

namespace Tests\Feature\Group;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteGroupCommandTest extends TestCase
{
    public function test_deletes_group_with_force_flag(): void
    {
        $group = $this->createTestGroup();
        $gid = $group->id;

        $this->artisan("group:delete --group={$group->nameshort} --force")
            ->assertExitCode(0);

        $this->assertDatabaseMissing('groups', ['id' => $gid]);
    }

    public function test_dry_run_shows_counts_without_deleting(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $this->artisan("group:delete --group={$group->nameshort} --dry-run")
            ->expectsOutputToContain('memberships')
            ->assertExitCode(0);

        $this->assertDatabaseHas('groups', ['id' => $group->id]);
    }

    public function test_fails_if_group_not_found(): void
    {
        $this->artisan('group:delete --group=NonExistentGroup999 --force')
            ->assertExitCode(1);
    }

    public function test_fails_if_group_not_provided(): void
    {
        $this->artisan('group:delete --force')
            ->assertExitCode(1);
    }

    public function test_removes_memberships_before_deleting_group(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $this->artisan("group:delete --group={$group->nameshort} --force")
            ->assertExitCode(0);

        $this->assertDatabaseMissing('memberships', ['groupid' => $group->id]);
        $this->assertDatabaseMissing('groups', ['id' => $group->id]);
    }
}
