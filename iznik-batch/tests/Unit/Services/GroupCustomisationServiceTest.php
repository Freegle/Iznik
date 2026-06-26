<?php

namespace Tests\Unit\Services;

use App\Mail\Group\CustomisationReminderMail;
use App\Models\Group;
use App\Models\Membership;
use App\Services\GroupCustomisationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers GroupCustomisationService::sendReminders() and its private helpers.
 *
 * Branch map:
 *  sendReminders() —
 *    dry-run: returns counts without sending mail
 *    group fully customised: skipped
 *    group missing attrs but no mods: skipped + logged
 *    group missing attrs with mods: mails sent (one per mod + mentors)
 *    multiple missing attrs: missing string contains all descriptions
 *
 *  buildMissingList() — exercised via sendReminders():
 *    each of the four attrs (profile/tagline/welcomemail/description) individually missing
 *    all missing at once
 *    all present → empty string → group skipped
 *
 *  getModEmails() — exercised via sendReminders():
 *    owners and moderators included
 *    members excluded
 *    non-preferred emails excluded
 *    pending memberships excluded
 *    system-mod (modtools@modtools.org) excluded when it exists
 */
class GroupCustomisationServiceTest extends TestCase
{
    private GroupCustomisationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent pre-existing DB groups from matching the service query.
        // (Same pattern as RemindCustomisationCommandTest.)
        DB::table('groups')->update(['onhere' => 0]);

        Mail::fake();

        $this->service = new GroupCustomisationService();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Insert a groups_images row and return its ID.
     * Required because groups.profile is a FK → groups_images.id.
     */
    private function createGroupImageId(Group $group): int
    {
        return DB::table('groups_images')->insertGetId([
            'groupid'     => $group->id,
            'contenttype' => 'image/jpeg',
        ]);
    }

    /** Create a group that is missing all four customisation attributes. */
    private function groupMissingAll(array $extra = []): Group
    {
        return $this->createTestGroup(array_merge([
            'type'        => Group::TYPE_FREEGLE,
            'publish'     => 1,
            'onhere'      => 1,
            'profile'     => null,
            'tagline'     => null,
            'welcomemail' => null,
            'description' => null,
        ], $extra));
    }

    /** Create a group with all four attributes populated. */
    private function groupFullyCustomised(): Group
    {
        $group = $this->createTestGroup([
            'type'        => Group::TYPE_FREEGLE,
            'publish'     => 1,
            'onhere'      => 1,
            'tagline'     => 'A tagline',
            'welcomemail' => 'Welcome!',
            'description' => 'A description',
        ]);

        // profile is a FK to groups_images — insert a row and reference it.
        $imageId = $this->createGroupImageId($group);
        DB::table('groups')->where('id', $group->id)->update(['profile' => $imageId]);

        return $group->fresh();
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
        $group = $this->groupMissingAll();
        $this->addMod($group);

        $result = $this->service->sendReminders(dryRun: true);

        $this->assertArrayHasKey('groups_checked', $result);
        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('skipped', $result);
        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['sent']);
    }

    // ------------------------------------------------------------------
    // Fully-customised group is skipped
    // ------------------------------------------------------------------

    public function test_fully_customised_group_is_skipped(): void
    {
        $this->groupFullyCustomised();

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    // ------------------------------------------------------------------
    // No mods → skipped
    // ------------------------------------------------------------------

    public function test_group_with_missing_attrs_but_no_mods_is_skipped(): void
    {
        $this->groupMissingAll();

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    // ------------------------------------------------------------------
    // Mails sent when attrs are missing
    // ------------------------------------------------------------------

    public function test_sends_mail_to_each_mod_and_mentors_when_attrs_missing(): void
    {
        $group = $this->groupMissingAll();
        $mod1Email = $this->addMod($group, Membership::ROLE_OWNER);
        $mod2Email = $this->addMod($group, Membership::ROLE_MODERATOR);

        config(['freegle.mail.mentors_addr' => 'mentors@ilovefreegle.org']);

        $result = $this->service->sendReminders();

        // mod1 + mod2 + mentors = 3 mails
        Mail::assertSent(CustomisationReminderMail::class, 3);
        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => $m->recipientEmail === $mod1Email);
        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => $m->recipientEmail === $mod2Email);
        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => $m->recipientEmail === 'mentors@ilovefreegle.org');
        $this->assertGreaterThanOrEqual(1, $result['sent']);
    }

    public function test_mail_subject_contains_group_name(): void
    {
        $group = $this->groupMissingAll();
        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, function (CustomisationReminderMail $mail) use ($group) {
            return $mail->groupName === $group->nameshort;
        });
    }

    // ------------------------------------------------------------------
    // buildMissingList — each attribute individually
    // ------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('missingAttributeProvider')]
    public function test_missing_attribute_appears_in_mail_body(string $attr, string $expectedSnippet, mixed $presentValue): void
    {
        // Start with all attrs present, then clear one.
        $group = $this->groupFullyCustomised();
        DB::table('groups')->where('id', $group->id)->update([$attr => null]);

        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, function (CustomisationReminderMail $mail) use ($expectedSnippet) {
            return str_contains($mail->missing, $expectedSnippet);
        });
    }

    public static function missingAttributeProvider(): array
    {
        return [
            'tagline missing'     => ['tagline',      'Tagline',      'A tagline'],
            'welcomemail missing' => ['welcomemail',  'Welcome Mail', 'Welcome!'],
            'description missing' => ['description',  'Description',  'A description'],
        ];
    }

    public function test_missing_profile_appears_in_mail_body(): void
    {
        // Profile is a FK — test separately, starting from all-missing.
        $group = $this->groupMissingAll();
        // Set the other three so only profile is missing.
        DB::table('groups')->where('id', $group->id)->update([
            'tagline'     => 'A tagline',
            'welcomemail' => 'Welcome!',
            'description' => 'A description',
        ]);

        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => str_contains($m->missing, 'Profile picture'));
    }

    public function test_all_four_missing_attributes_appear_in_mail_body(): void
    {
        $group = $this->groupMissingAll();
        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, function (CustomisationReminderMail $mail) {
            return str_contains($mail->missing, 'Profile picture')
                && str_contains($mail->missing, 'Tagline')
                && str_contains($mail->missing, 'Welcome Mail')
                && str_contains($mail->missing, 'Description');
        });
    }

    public function test_empty_string_attribute_treated_as_missing(): void
    {
        $group = $this->groupMissingAll(['tagline' => '']);
        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => str_contains($m->missing, 'Tagline'));
    }

    // ------------------------------------------------------------------
    // getModEmails — role and collection filtering
    // ------------------------------------------------------------------

    public function test_plain_members_are_not_included_in_mod_emails(): void
    {
        $group = $this->groupMissingAll();

        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['role' => Membership::ROLE_MEMBER]);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    public function test_pending_memberships_are_excluded(): void
    {
        $group = $this->groupMissingAll();

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
        // V1 parity: fall back to non-preferred external when preferred=1 is an internal alias.
        $group = $this->groupMissingAll();

        $user = $this->createTestUser();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);

        DB::table('users_emails')->where('userid', $user->id)->update(['preferred' => 0]);
        DB::table('users_emails')->insert([
            'userid'    => $user->id,
            'email'     => "user{$user->id}-alias@users.ilovefreegle.org",
            'preferred' => 1,
            'added'     => now(),
        ]);

        $result = $this->service->sendReminders();

        Mail::assertSent(\App\Mail\Group\CustomisationReminderMail::class);
        $this->assertGreaterThanOrEqual(1, $result['sent']);
    }

    public function test_user_with_only_internal_alias_email_is_skipped(): void
    {
        $group = $this->groupMissingAll();

        $user = $this->createTestUser();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);

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

    public function test_system_modtools_user_excluded_from_mod_emails(): void
    {
        $group = $this->groupMissingAll();

        $sysUser = $this->createTestUser(['email_preferred' => 'modtools@modtools.org']);
        $this->createMembership($sysUser, $group, ['role' => Membership::ROLE_MODERATOR]);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    public function test_system_mod_excluded_but_real_mods_still_notified(): void
    {
        $group = $this->groupMissingAll();

        $sysUser = $this->createTestUser(['email_preferred' => 'modtools@modtools.org']);
        $this->createMembership($sysUser, $group, ['role' => Membership::ROLE_MODERATOR]);

        $realModEmail = $this->addMod($group, Membership::ROLE_OWNER);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => $m->recipientEmail === $realModEmail);
        Mail::assertNotSent(CustomisationReminderMail::class, fn ($m) => $m->recipientEmail === 'modtools@modtools.org');
    }

    // ------------------------------------------------------------------
    // Groups outside the query filters are not included
    // ------------------------------------------------------------------

    public function test_unpublished_group_is_not_included(): void
    {
        // setUp already sets onhere=0 on all pre-existing groups.
        // Now create one with publish=0 (onhere defaults to 1 in createTestGroup).
        $group = $this->groupMissingAll(['publish' => 0]);
        $this->addMod($group);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertEquals(0, $result['groups_checked']);
    }

    public function test_group_not_on_here_is_not_included(): void
    {
        $group = $this->groupMissingAll(['onhere' => 0]);
        $this->addMod($group);

        $result = $this->service->sendReminders();

        Mail::assertNothingSent();
        $this->assertEquals(0, $result['groups_checked']);
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
        $groupWithMod = $this->groupMissingAll();
        $this->addMod($groupWithMod);

        $groupFullyCustom = $this->groupFullyCustomised();
        $groupNoMods      = $this->groupMissingAll();

        $result = $this->service->sendReminders();

        $this->assertEquals($result['groups_checked'], $result['sent'] + $result['skipped']);
    }

    public function test_config_mentors_addr_used_for_mentors_copy(): void
    {
        config(['freegle.mail.mentors_addr' => 'custom-mentors@example.com']);

        $group = $this->groupMissingAll();
        $this->addMod($group);

        $this->service->sendReminders();

        Mail::assertSent(CustomisationReminderMail::class, fn ($m) => $m->recipientEmail === 'custom-mentors@example.com');
    }
}
