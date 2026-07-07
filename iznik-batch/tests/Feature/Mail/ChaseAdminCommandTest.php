<?php

namespace Tests\Feature\Mail;

use App\Mail\Admin\ChaseAdminMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChaseAdminCommandTest extends TestCase
{
    /**
     * Create a pending centralized admin copy for a group.
     */
    private function createPendingAdmin(Group $group, array $overrides = []): int
    {
        // Create parent (suggested) admin first.
        $parentId = DB::table('admins')->insertGetId([
            'subject' => 'Test Suggested Admin',
            'text' => 'Test suggested admin text.',
            'pending' => 0,
            'essential' => true,
            'activeonly' => false,
            'created' => now()->subDays(3),
            'complete' => now()->subDays(3),
        ]);

        return DB::table('admins')->insertGetId(array_merge([
            'createdby' => null,
            'groupid' => $group->id,
            'created' => now()->subHours(72),
            'subject' => 'Test Admin Email',
            'text' => 'This is a test admin message.',
            'pending' => 1,
            'essential' => true,
            'activeonly' => false,
            'parentid' => $parentId,
        ], $overrides));
    }

    /**
     * Test: Pending admin older than 48h sends chase to moderators.
     */
    public function test_chases_pending_admin_after_48h(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $adminId = $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertSent(ChaseAdminMail::class, function (ChaseAdminMail $mail) use ($mod) {
            return $mail->user->id === $mod->id;
        });
    }

    /**
     * Test: Admin pending less than 48h is NOT chased.
     */
    public function test_does_not_chase_before_48h(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['created' => now()->subHours(24)]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Admin pending more than 7 days is NOT chased.
     */
    public function test_does_not_chase_after_7_days(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['created' => now()->subDays(8)]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Approved admin (pending=0) is NOT chased.
     */
    public function test_does_not_chase_approved_admin(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['pending' => 0]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Held admin (heldby IS NOT NULL) is NOT chased.
     */
    public function test_does_not_chase_held_admin(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['heldby' => $mod->id, 'heldat' => now()]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Completed admin is NOT chased.
     */
    public function test_does_not_chase_completed_admin(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['complete' => now()]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Admin without parentid (not centralized) is NOT chased.
     */
    public function test_does_not_chase_non_centralized_admin(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        DB::table('admins')->insert([
            'groupid' => $group->id,
            'created' => now()->subHours(72),
            'subject' => 'Non-centralized Admin',
            'text' => 'This is a local admin.',
            'pending' => 1,
            'essential' => true,
            'activeonly' => false,
            'parentid' => null,
        ]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Does not chase again within 24 hours of last chase.
     */
    public function test_respects_24h_chase_interval(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['lastchaseup' => now()->subHours(12)]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Chases again after 24 hours since last chase.
     */
    public function test_chases_again_after_24h(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group, ['lastchaseup' => now()->subHours(25)]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertSent(ChaseAdminMail::class, 1);
    }

    /**
     * Test: Sends to backup moderators too.
     */
    public function test_sends_to_backup_moderators(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

        // Active moderator.
        $activeMod = $this->createTestUser(['lastaccess' => now(), 'fullname' => 'Active Mod']);
        $this->createMembership($activeMod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        // Backup moderator (settings['active'] = false).
        $backupMod = $this->createTestUser(['lastaccess' => now(), 'fullname' => 'Backup Mod']);
        $backupMembership = $this->createMembership($backupMod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => false],
        ]);

        $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        // Both active and backup moderators should receive chase emails.
        Mail::assertSent(ChaseAdminMail::class, 2);
    }

    /**
     * Test: Sends to owners as well.
     */
    public function test_sends_to_owners(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $owner = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($owner, $group, [
            'role' => Membership::ROLE_OWNER,
        ]);

        $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertSent(ChaseAdminMail::class, 1);
    }

    /**
     * Test: Does not send to regular members.
     */
    public function test_does_not_send_to_members(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();

        // Only a regular member, no moderators.
        $member = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($member, $group, [
            'role' => Membership::ROLE_MEMBER,
        ]);

        $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Updates lastchaseup after sending.
     */
    public function test_updates_lastchaseup(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $adminId = $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNotNull($admin->lastchaseup, 'Admin lastchaseup should be set after chase.');
    }

    /**
     * Test: Dry run does not send emails or update lastchaseup.
     */
    public function test_dry_run_does_not_send(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $adminId = $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase', ['--dry-run' => true])
            ->assertSuccessful();

        Mail::assertNothingSent();

        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNull($admin->lastchaseup, 'Dry run should not update lastchaseup.');
    }

    /**
     * Test: Deleted moderators are skipped.
     */
    public function test_deleted_moderator_skipped(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser([
            'lastaccess' => now(),
            'deleted' => now(),
        ]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        $this->createPendingAdmin($group);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Chase email contains correct pending time text.
     */
    public function test_chase_email_contains_pending_time(): void
    {
        Mail::fake();

        $group = $this->createTestGroup();
        $mod = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
        ]);

        // 72 hours = 3 days.
        $this->createPendingAdmin($group, ['created' => now()->subHours(72)]);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertSent(ChaseAdminMail::class, function (ChaseAdminMail $mail) {
            return str_starts_with($mail->pendingTimeText, '3 days');
        });
    }

    /**
     * Test: Multiple pending admins for different groups are all chased.
     */
    public function test_chases_multiple_admins(): void
    {
        Mail::fake();

        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $mod1 = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod1, $group1, ['role' => Membership::ROLE_MODERATOR]);

        $mod2 = $this->createTestUser(['lastaccess' => now()]);
        $this->createMembership($mod2, $group2, ['role' => Membership::ROLE_MODERATOR]);

        $this->createPendingAdmin($group1);
        $this->createPendingAdmin($group2);

        $this->artisan('mail:admin:chase')
            ->assertSuccessful();

        Mail::assertSent(ChaseAdminMail::class, 2);
    }
}
