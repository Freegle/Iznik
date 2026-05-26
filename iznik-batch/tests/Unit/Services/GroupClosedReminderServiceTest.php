<?php

namespace Tests\Unit\Services;

use App\Mail\Group\ClosedGroupReminderMail;
use App\Models\Group;
use App\Models\Membership;
use App\Services\GroupClosedReminderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers GroupClosedReminderService::sendReminders() and its private helpers.
 *
 * Branch map:
 *  sendReminders() —
 *    dry-run: returns counts without sending mail
 *    closed group with mods: mails sent (one per mod + mentors)
 *    closed group with no mods: skipped
 *    open groups: not included in query
 *    group with settings.closed = 0: not included in query
 *
 *  getModEmails() — exercised via sendReminders():
 *    owners and moderators included
 *    plain members excluded
 *    pending memberships excluded
 *    non-preferred emails excluded
 *    system-mod (modtools@modtools.org) excluded
 */
class GroupClosedReminderServiceTest extends TestCase
{
    private GroupClosedReminderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent pre-existing DB groups from matching the service's closed-group query
        // by clearing their settings. Groups without settings.closed=1 are excluded.
        DB::table('groups')->update(['settings' => null]);

        Mail::fake();

        $this->service = new GroupClosedReminderService();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Create a published Freegle group marked as closed via settings JSON. */
    private function createClosedGroup(): Group
    {
        return $this->createTestGroup([
            'type'    => Group::TYPE_FREEGLE,
            'publish' => 1,
            'onhere'  => 1,
            'settings' => ['closed' => 1],
        ]);
    }

    /** Add a moderator/owner to a group and return their preferred email. */
    private function addMod(Group $group, string $role = Membership::ROLE_MODERATOR): string
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $group, ['role' => $role]);
        return DB::table('users_emails')->where('userid', $user->id)->where('preferred', 1)->value('email');
    }

    // ------------------------------------------------------------------
    // Dry-run
    // ------------------------------------------------------------------

    public function test_dry_run_returns_counts_without_sending_mail(): void
    {
        $group = $this->createClosedGroup();
        $this->addMod($group);

        $result = $this->service->sendReminders(dryRun: true);

        Mail::assertNothingSent();
        $this->assertArrayHasKey('groups_checked', $result);
        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertGreaterThanOrEqual(1, $result['sent']);
    }

    // ------------------------------------------------------------------
    // Closed group with mods → sends mails
    // ------------------------------------------------------------------

    public function test_sends_mail_to_each_mod_and_mentors_for_closed_group(): void
    {
        $group = $this->createClosedGroup();
        $mod1Email = $this->addMod($group, Membership::ROLE_OWNER);
        $mod2Email = $this->addMod($group, Membership::ROLE_MODERATOR);

        config(['freegle.mail.mentors_addr' => 'mentors@ilovefreegle.org']);

        $result = $this->service->sendReminders();

        // mod1 + mod2 + mentors = 3 mails
        Mail::assertSent(ClosedGroupReminderMail::class, 3);
        Mail::assertSent(ClosedGroupReminderMail::class, fn ($m) => $m->recipientEmail === $mod1Email);
        Mail::assertSent(ClosedGroupReminderMail::class, fn ($m) => $m->recipientEmail === $mod2Email);
        Mail::assertSent(ClosedGroupReminderMail::class, fn ($m) => $m->recipientEmail === 'mentors@ilovefreegle.org');
        $this->assertGreaterThanOrEqual(1, $result['sent']);
    }

    public function test_mail_carries_correct_group_name(): void
    {
        $group = $this->createClosedGroup();
        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(ClosedGroupReminderMail::class, fn (ClosedGroupReminderMail $m) => $m->groupName === $group->nameshort);
    }

    public function test_config_mentors_addr_used_for_mentors_copy(): void
    {
        config(['freegle.mail.mentors_addr' => 'my-mentors@example.com']);

        $group = $this->createClosedGroup();
        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(ClosedGroupReminderMail::class, fn ($m) => $m->recipientEmail === 'my-mentors@example.com');
    }

    // ------------------------------------------------------------------
    // Closed group with no mods → skipped
    // ------------------------------------------------------------------

    public function test_closed_group_with_no_mods_is_skipped(): void
    {
        $this->createClosedGroup(); // no mods

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    // ------------------------------------------------------------------
    // Open groups are not included in the query
    // ------------------------------------------------------------------

    public function test_open_group_without_settings_is_not_included(): void
    {
        $group = $this->createTestGroup([
            'type'    => Group::TYPE_FREEGLE,
            'publish' => 1,
            'onhere'  => 1,
        ]);
        // settings is null by default — not matched
        $this->addMod($group);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertEquals(0, $result['groups_checked']);
    }

    public function test_open_group_with_closed_zero_is_not_included(): void
    {
        $group = $this->createTestGroup([
            'type'     => Group::TYPE_FREEGLE,
            'publish'  => 1,
            'onhere'   => 1,
            'settings' => ['closed' => 0],
        ]);
        $this->addMod($group);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertEquals(0, $result['groups_checked']);
    }

    public function test_open_group_with_closed_false_is_not_included(): void
    {
        $group = $this->createTestGroup([
            'type'     => Group::TYPE_FREEGLE,
            'publish'  => 1,
            'onhere'   => 1,
            'settings' => ['closed' => false],
        ]);
        $this->addMod($group);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertEquals(0, $result['groups_checked']);
    }

    // ------------------------------------------------------------------
    // getModEmails — role and collection filtering
    // ------------------------------------------------------------------

    public function test_plain_members_are_not_included_in_mod_emails(): void
    {
        $group = $this->createClosedGroup();

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    public function test_pending_moderator_is_excluded(): void
    {
        $group = $this->createClosedGroup();

        $user = $this->createTestUser();
        $this->createMembership($user, $group, [
            'role'       => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_PENDING,
        ]);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    public function test_non_preferred_external_email_used_when_preferred_is_internal_alias(): void
    {
        // V1 parity: when a user's preferred=1 email is one of our internal
        // per-user-alias domains, fall back to a non-preferred external email
        // rather than silently dropping them.
        $group = $this->createClosedGroup();

        $user = $this->createTestUser();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_OWNER]);

        DB::table('users_emails')->where('userid', $user->id)->update(['preferred' => 0]);
        DB::table('users_emails')->insert([
            'userid'    => $user->id,
            'email'     => "user{$user->id}-alias@users.ilovefreegle.org",
            'preferred' => 1,
            'added'     => now(),
        ]);

        $result = $this->service->sendReminders();

        Mail::assertSent(ClosedGroupReminderMail::class);
        $this->assertGreaterThanOrEqual(1, $result['sent']);
    }

    public function test_user_with_only_internal_alias_email_is_skipped(): void
    {
        $group = $this->createClosedGroup();

        $user = $this->createTestUser();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_OWNER]);

        DB::table('users_emails')->where('userid', $user->id)->delete();
        DB::table('users_emails')->insert([
            'userid'    => $user->id,
            'email'     => "user{$user->id}-alias@users.ilovefreegle.org",
            'preferred' => 1,
            'added'     => now(),
        ]);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    public function test_system_modtools_user_is_excluded(): void
    {
        $group = $this->createClosedGroup();

        $sysUser = $this->createTestUser(['email_preferred' => 'modtools@modtools.org']);
        $this->createMembership($sysUser, $group, ['role' => Membership::ROLE_MODERATOR]);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    public function test_system_mod_excluded_but_real_mods_still_receive_mail(): void
    {
        $group = $this->createClosedGroup();

        $sysUser = $this->createTestUser(['email_preferred' => 'modtools@modtools.org']);
        $this->createMembership($sysUser, $group, ['role' => Membership::ROLE_MODERATOR]);

        $realModEmail = $this->addMod($group, Membership::ROLE_OWNER);

        $this->service->sendReminders();

        Mail::assertSent(ClosedGroupReminderMail::class, fn ($m) => $m->recipientEmail === $realModEmail);
        Mail::assertNotSent(ClosedGroupReminderMail::class, fn ($m) => $m->recipientEmail === 'modtools@modtools.org');
    }

    // ------------------------------------------------------------------
    // Return structure
    // ------------------------------------------------------------------

    public function test_return_structure_keys_are_present(): void
    {
        $result = $this->service->sendReminders();

        $this->assertArrayHasKey('groups_checked', $result);
        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('skipped', $result);
    }

    public function test_sent_plus_skipped_equals_groups_checked(): void
    {
        $closedWithMod = $this->createClosedGroup();
        $this->addMod($closedWithMod);

        $closedNoMod = $this->createClosedGroup();

        $result = $this->service->sendReminders();

        $this->assertEquals($result['groups_checked'], $result['sent'] + $result['skipped']);
    }
}
