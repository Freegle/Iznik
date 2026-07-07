<?php

namespace Tests\Feature\Group;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Services\ModWelfareService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ModActiveWelfareCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeOwner(Group $group, array $membershipAttrs = []): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $group, array_merge([
            'role' => Membership::ROLE_OWNER,
            'added' => now()->subMonths(3),
        ], $membershipAttrs));
        return $user;
    }

    private function makeMod(Group $group, array $membershipAttrs = []): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $group, array_merge([
            'role' => Membership::ROLE_MODERATOR,
            'added' => now()->subMonths(3),
        ], $membershipAttrs));
        return $user;
    }

    private function recordApproval(int $userId, int $groupId, string $arrival = null): void
    {
        // messages_groups.msgid must be unique per group; use a random large id to avoid collisions
        $msgId = DB::table('messages')->insertGetId([
            'type' => Message::TYPE_OFFER,
            'source' => Message::SOURCE_PLATFORM,
            'subject' => 'Test offer',
            'textbody' => 'test',
            'arrival' => $arrival ?? now()->subDays(5)->toDateString(),
        ]);

        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $groupId,
            'approvedby' => $userId,
            'arrival' => $arrival ?? now()->subDays(5)->toDateString(),
            'collection' => Membership::COLLECTION_APPROVED,
        ]);
    }

    private function makeModbotUser(): User
    {
        return $this->createTestUser([
            'email_preferred' => config('freegle.mail.moderator_email'),
        ]);
    }

    // ── Basic smoke tests ────────────────────────────────────────────────────

    public function test_command_runs_with_no_data(): void
    {
        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);
    }

    public function test_dry_run_shows_prefix(): void
    {
        $this->artisan('groups:check-mod-welfare', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);
    }

    // ── Group activity filter ─────────────────────────────────────────────────

    public function test_skips_group_with_no_recent_approvals(): void
    {
        $group = $this->createTestGroup();
        $mod = $this->makeMod($group);

        // Group has NO recent approval activity → skip
        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_processes_group_with_recent_approval_activity(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        // Group has recent approval activity
        $this->recordApproval($owner->id, $group->id);

        // Owner has recent activity — used as the notifier; mod has no approval → gets flagged
        // Since owner approved recently, they are the notify target.
        // Mod has no approvals → inactive → email sent.
        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        // At least one mail should be sent (to the owner about the inactive mod)
        Mail::assertSentCount(1);
    }

    // ── No active owner → notify mentors ──────────────────────────────────────

    public function test_notifies_mentors_when_group_has_no_active_owner(): void
    {
        $group = $this->createTestGroup();

        // Group has recent approval activity (by a user with no ownership membership)
        $anyUser = $this->createTestUser();
        $this->recordApproval($anyUser->id, $group->id);

        // Group has NO owners at all → getModsToNotify returns mentors address → "no active owner" mail
        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Modbot skip ───────────────────────────────────────────────────────────

    public function test_skips_modbot_user(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $modbot = $this->makeModbotUser();

        // Register modbot as a moderator on this group
        $this->createMembership($modbot, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'added' => now()->subMonths(3),
        ]);

        // Record owner approval so group passes the activity check and owner is the notifier
        $this->recordApproval($owner->id, $group->id);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        // Modbot should be skipped — no mail should be sent
        Mail::assertNothingSent();
    }

    // ── Recently warned skip ──────────────────────────────────────────────────

    public function test_skips_mod_warned_within_rewarn_interval(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        // Record a recent warning for the mod on this group
        DB::table('groups_mods_welfare')->insert([
            'groupid' => $group->id,
            'modid' => $mod->id,
            'warnedat' => now()->subDays(15),
        ]);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_warns_mod_after_rewarn_interval_has_passed(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        // Warning was > 31 days ago → should warn again
        DB::table('groups_mods_welfare')->insert([
            'groupid' => $group->id,
            'modid' => $mod->id,
            'warnedat' => now()->subDays(40),
        ]);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    // ── Ignore state skip ─────────────────────────────────────────────────────

    public function test_skips_mod_with_ignore_state(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        DB::table('groups_mods_welfare')->insert([
            'groupid' => $group->id,
            'modid' => $mod->id,
            'state' => 'Ignore',
            'warnedat' => now()->subYears(2),
        ]);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Backup mod skip ───────────────────────────────────────────────────────

    public function test_skips_backup_mod_with_active_false_in_settings(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);

        // Mod has settings.active = false → backup mod, not expected to be active
        $backupMod = $this->makeMod($group, [
            'settings' => ['active' => false],
        ]);

        $this->recordApproval($owner->id, $group->id);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Inactive mod detection ────────────────────────────────────────────────

    public function test_sends_email_for_mod_with_no_approval_activity(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        // Owner has recent approval (so group passes activity filter and owner is notifier)
        $this->recordApproval($owner->id, $group->id);

        // Mod has NO approval history → should be flagged

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_sends_email_for_mod_inactive_over_limit(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        // Owner has recent approval
        $this->recordApproval($owner->id, $group->id);

        // Mod last approved 400 days ago — over the 365-day limit
        $this->recordApproval(
            $mod->id,
            $group->id,
            now()->subDays(400)->toDateString()
        );

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertSentCount(1);
    }

    public function test_no_email_for_recently_active_mod(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        // Mod approved a message recently — should NOT be flagged
        $this->recordApproval($mod->id, $group->id, now()->subDays(30)->toDateString());

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    // ── Welfare table recording ───────────────────────────────────────────────

    public function test_records_warning_in_welfare_table(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        $this->assertDatabaseHas('groups_mods_welfare', [
            'groupid' => $group->id,
            'modid' => $mod->id,
        ]);
    }

    public function test_updates_warnedat_on_upsert(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        // Pre-existing old record
        DB::table('groups_mods_welfare')->insert([
            'groupid' => $group->id,
            'modid' => $mod->id,
            'warnedat' => now()->subDays(40),
        ]);

        $before = now()->subSeconds(1);

        $this->artisan('groups:check-mod-welfare')
            ->assertExitCode(0);

        $record = DB::table('groups_mods_welfare')
            ->where('groupid', $group->id)
            ->where('modid', $mod->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertGreaterThan($before, \Carbon\Carbon::parse($record->warnedat));
    }

    // ── Dry-run ───────────────────────────────────────────────────────────────

    public function test_dry_run_sends_no_email(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        $this->artisan('groups:check-mod-welfare', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_write_welfare_record(): void
    {
        $group = $this->createTestGroup();
        $owner = $this->makeOwner($group);
        $mod = $this->makeMod($group);

        $this->recordApproval($owner->id, $group->id);

        $this->artisan('groups:check-mod-welfare', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('groups_mods_welfare', [
            'groupid' => $group->id,
            'modid' => $mod->id,
        ]);
    }
}
