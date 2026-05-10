<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Services\WelcomeReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WelcomeReviewCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeGroupWithWelcome(array $attributes = []): Group
    {
        return $this->createTestGroup(array_merge([
            'welcomemail' => 'Welcome to our group! Please read our rules.',
            'welcomereview' => null,
        ], $attributes));
    }

    private function makeOwner(Group $group, int $emailFrequency = Membership::EMAIL_FREQUENCY_IMMEDIATE): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $group, [
            'role' => Membership::ROLE_OWNER,
            'emailfrequency' => $emailFrequency,
        ]);
        return $user;
    }

    // ── Smoke tests ──────────────────────────────────────────────────────────

    public function test_command_runs_with_no_data(): void
    {
        $this->artisan('groups:welcome-review')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_shows_prefix(): void
    {
        $this->artisan('groups:welcome-review', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);
    }

    // ── Skip logic ───────────────────────────────────────────────────────────

    public function test_skips_group_with_no_welcome_mail(): void
    {
        $group = $this->createTestGroup(['welcomemail' => null]);
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_group_reviewed_within_365_days(): void
    {
        $group = $this->makeGroupWithWelcome([
            'welcomereview' => now()->subDays(100)->toDateString(),
        ]);
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_group_not_yet_reviewed(): void
    {
        $group = $this->makeGroupWithWelcome(['welcomereview' => null]);
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_processes_group_reviewed_over_365_days_ago(): void
    {
        $group = $this->makeGroupWithWelcome([
            'welcomereview' => now()->subDays(400)->toDateString(),
        ]);
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Mod filtering ─────────────────────────────────────────────────────────

    public function test_sends_to_all_owners_and_moderators(): void
    {
        $group = $this->makeGroupWithWelcome();
        $this->makeOwner($group);
        $mod = $this->createTestUser();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
        ]);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertSentCount(2);
    }

    public function test_skips_mod_with_email_frequency_zero(): void
    {
        $group = $this->makeGroupWithWelcome();
        $this->makeOwner($group, emailFrequency: Membership::EMAIL_FREQUENCY_NEVER);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_skips_deleted_mods(): void
    {
        $group = $this->makeGroupWithWelcome();
        $owner = $this->makeOwner($group);

        DB::table('users')->where('id', $owner->id)->update(['deleted' => now()]);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Record update ─────────────────────────────────────────────────────────

    public function test_updates_welcomereview_timestamp(): void
    {
        $group = $this->makeGroupWithWelcome();
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review')->assertExitCode(0);

        // welcomereview is a date column (not datetime), so compare date strings
        $updated = DB::table('groups')->where('id', $group->id)->value('welcomereview');
        $this->assertEquals(now()->toDateString(), $updated);
    }

    // ── Dry-run ───────────────────────────────────────────────────────────────

    public function test_dry_run_no_email_sent(): void
    {
        $group = $this->makeGroupWithWelcome();
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review', ['--dry-run' => true])->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_update_welcomereview(): void
    {
        $group = $this->makeGroupWithWelcome(['welcomereview' => null]);
        $this->makeOwner($group);

        $this->artisan('groups:welcome-review', ['--dry-run' => true])->assertExitCode(0);

        $updated = DB::table('groups')->where('id', $group->id)->value('welcomereview');
        $this->assertNull($updated);
    }

    // ── Batch limit ───────────────────────────────────────────────────────────

    public function test_respects_batch_limit(): void
    {
        // Create more groups than the batch limit
        for ($i = 0; $i < WelcomeReviewService::BATCH_LIMIT + 3; $i++) {
            $group = $this->makeGroupWithWelcome();
            $this->makeOwner($group);
        }

        $result = (new WelcomeReviewService())->sendWelcomeReviews(false);

        $this->assertLessThanOrEqual(WelcomeReviewService::BATCH_LIMIT, $result['groups_processed']);
    }
}
