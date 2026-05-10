<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RemindCustomisationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function createGroupImageId(Group $group): int
    {
        return DB::table('groups_images')->insertGetId([
            'groupid'     => $group->id,
            'contenttype' => 'image/jpeg',
        ]);
    }

    private function createModForGroup(Group $group): void
    {
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createTestUserEmail($mod, ['preferred' => 1]);
    }

    public function test_smoke_runs_with_no_matching_groups(): void
    {
        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_reminder_when_all_attrs_missing(): void
    {
        $group = $this->createTestGroup();
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_group_with_all_attrs_set(): void
    {
        $group   = $this->createTestGroup([
            'tagline'     => 'We give away stuff!',
            'welcomemail' => 'Welcome to our group.',
            'description' => 'A friendly local group.',
        ]);
        $group->update(['profile' => $this->createGroupImageId($group)]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_when_some_attrs_missing(): void
    {
        $group = $this->createTestGroup([
            'tagline' => 'We give away stuff!',
            // profile, welcomemail and description are null
        ]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_skips_non_freegle_group(): void
    {
        $group = $this->createTestGroup(['type' => Group::TYPE_OTHER]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_unpublished_group(): void
    {
        $group = $this->createTestGroup(['publish' => 0]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_not_on_here(): void
    {
        $group = $this->createTestGroup(['onhere' => 0]);
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_with_no_mods(): void
    {
        // Create group but add only a regular member, no moderators.
        $group  = $this->createTestGroup();
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);
        $this->createTestUserEmail($member, ['preferred' => 1]);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_email(): void
    {
        $group = $this->createTestGroup();
        $this->createModForGroup($group);

        $this->artisan('groups:remind-customisation', ['--dry-run' => true])
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_one_email_per_qualifying_group(): void
    {
        $group1 = $this->createTestGroup();
        $this->createModForGroup($group1);

        $group2 = $this->createTestGroup();
        $this->createModForGroup($group2);

        $this->artisan('groups:remind-customisation')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }
}
