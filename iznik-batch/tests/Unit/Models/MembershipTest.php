<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    public function test_is_active_mod_returns_true_for_members(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'added' => now(),
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_true_for_mod_with_null_settings(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => null,
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_true_for_mod_without_active_key(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['someOtherSetting' => true],
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_true_for_explicitly_active_mod(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => true],
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_false_for_backup_mod(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        $this->assertFalse($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_false_for_backup_owner(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        $this->assertFalse($membership->isActiveMod());
    }

    public function test_active_moderators_scope_excludes_backup_mods(): void
    {
        $group = $this->createTestGroup();

        // Create an active mod (null settings).
        $activeMod1 = $this->createTestUser();
        Membership::create([
            'userid' => $activeMod1->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => null,
        ]);

        // Create an active mod (explicitly active).
        $activeMod2 = $this->createTestUser();
        Membership::create([
            'userid' => $activeMod2->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'added' => now(),
            'settings' => ['active' => true],
        ]);

        // Create a backup mod.
        $backupMod = $this->createTestUser();
        Membership::create([
            'userid' => $backupMod->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        // Create a regular member (should not be included).
        $member = $this->createTestUser();
        Membership::create([
            'userid' => $member->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'added' => now(),
        ]);

        $activeMods = $group->memberships()->activeModerators()->get();

        $this->assertCount(2, $activeMods);
        $this->assertTrue($activeMods->contains('userid', $activeMod1->id));
        $this->assertTrue($activeMods->contains('userid', $activeMod2->id));
        $this->assertFalse($activeMods->contains('userid', $backupMod->id));
        $this->assertFalse($activeMods->contains('userid', $member->id));
    }

    public function test_active_moderators_scope_matches_integer_one_as_active(): void
    {
        // The database may store active:1 (integer) rather than active:true (boolean).
        // The scope must match both.
        $group = $this->createTestGroup();

        // Create a mod with active as integer 1 (as stored in real database).
        $activeMod = $this->createTestUser();
        $membership = Membership::create([
            'userid' => $activeMod->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'added' => now(),
        ]);

        // Directly set JSON with integer 1 to simulate real database values.
        \DB::table('memberships')
            ->where('id', $membership->id)
            ->update(['settings' => json_encode(['active' => 1])]);

        $activeMods = $group->memberships()->activeModerators()->get();

        $this->assertCount(1, $activeMods);
        $this->assertTrue($activeMods->contains('userid', $activeMod->id));
    }

    public function test_active_moderators_scope_excludes_integer_zero_as_inactive(): void
    {
        // Ensure active:0 (integer) is treated as inactive.
        $group = $this->createTestGroup();

        $backupMod = $this->createTestUser();
        $membership = Membership::create([
            'userid' => $backupMod->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
        ]);

        // Directly set JSON with integer 0 to simulate inactive mod.
        \DB::table('memberships')
            ->where('id', $membership->id)
            ->update(['settings' => json_encode(['active' => 0])]);

        $activeMods = $group->memberships()->activeModerators()->get();

        $this->assertCount(0, $activeMods);
    }

    public function test_is_active_mod_falls_back_to_legacy_showmessages_false(): void
    {
        // V1 User::activeModForGroup falls back to the legacy showmessages
        // flag when 'active' is not set. Old memberships may still have this.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['showmessages' => false],
        ]);

        $this->assertFalse($membership->isActiveMod());
    }

    public function test_is_active_mod_falls_back_to_legacy_showmessages_true(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['showmessages' => true],
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_prefers_active_over_legacy_showmessages(): void
    {
        // If both keys are present, 'active' wins — V1 only consults
        // 'showmessages' when 'active' is absent.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => true, 'showmessages' => false],
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_active_moderators_scope_excludes_mod_with_showmessages_false(): void
    {
        $group = $this->createTestGroup();

        $backupMod = $this->createTestUser();
        $membership = Membership::create([
            'userid' => $backupMod->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['showmessages' => false],
        ]);

        $activeMod = $this->createTestUser();
        Membership::create([
            'userid' => $activeMod->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['showmessages' => true],
        ]);

        $activeMods = $group->memberships()->activeModerators()->get();

        $this->assertTrue($activeMods->contains('userid', $activeMod->id));
        $this->assertFalse($activeMods->contains('userid', $backupMod->id));
    }
}
