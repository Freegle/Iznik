<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RemindClosedGroupsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createModForGroup(Group $group): void
    {
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
    }

    private function createClosedGroup(): Group
    {
        return $this->createTestGroup(['settings' => ['closed' => 1]]);
    }

    public function test_smoke_no_closed_groups(): void
    {
        $this->artisan('groups:remind-closed')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_reminder_to_closed_group_mods(): void
    {
        $group = $this->createClosedGroup();
        $this->createModForGroup($group);

        $this->artisan('groups:remind-closed')
            ->assertExitCode(0);

        Mail::assertSentCount(2); // 1 to mod + 1 to mentors
    }

    public function test_skips_open_group(): void
    {
        // Open group (no settings or closed=0)
        $group = $this->createTestGroup(['settings' => ['closed' => 0]]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-closed')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_with_no_settings(): void
    {
        $group = $this->createTestGroup(['settings' => null]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-closed')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_closed_group_with_no_mods(): void
    {
        $group  = $this->createClosedGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createTestUserEmail($member, ['preferred' => 1]);

        $this->artisan('groups:remind-closed')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_email(): void
    {
        $group = $this->createClosedGroup();
        $this->createModForGroup($group);

        $this->artisan('groups:remind-closed', ['--dry-run' => true])
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_one_email_per_closed_group(): void
    {
        $group1 = $this->createClosedGroup();
        $this->createModForGroup($group1);

        $group2 = $this->createClosedGroup();
        $this->createModForGroup($group2);

        $this->artisan('groups:remind-closed')
            ->assertExitCode(0);

        Mail::assertSentCount(4); // 1 mod + 1 mentors per group × 2 groups
    }
}
