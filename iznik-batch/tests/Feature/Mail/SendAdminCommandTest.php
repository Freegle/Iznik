<?php

namespace Tests\Feature\Mail;

use App\Mail\Admin\AdminMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendAdminCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // An admin goes to every member, so the fixture members are taken out of the way
        // and each test counts only the members it creates. The transaction undoes this.
        DB::table('users')->whereNull('deleted')->update(['deleted' => now()]);
    }

    /**
     * Create an approved admin.
     */
    private function createAdmin(array $overrides = []): int
    {
        return DB::table('admins')->insertGetId(array_merge([
            'createdby' => null,
            'created' => now(),
            'subject' => 'Test Admin Email',
            'text' => 'This is a test admin message.',
            'ctalink' => 'https://www.ilovefreegle.org/donate',
            'ctatext' => 'Donate',
            'pending' => 0,
            'essential' => true,
            'activeonly' => false,
        ], $overrides));
    }

    /**
     * Test: Approved admin sends to members.
     * Mirrors V1 testBasic.
     */
    public function test_approved_admin_sends_to_members(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, function (AdminMail $mail) use ($user) {
            return $mail->user->id === $user->id;
        });

        // Admin should be marked complete.
        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNotNull($admin->complete);
    }

    /**
     * Test: Pending admin sends 0 emails.
     * Mirrors V1 testBasic.
     */
    public function test_pending_admin_sends_nothing(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin(['pending' => 1]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Users with no preferred email are skipped.
     */
    public function test_user_without_email_skipped(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // Create user with no email records.
        $user = User::create([
            'firstname' => 'No',
            'lastname' => 'Email',
            'fullname' => 'No Email',
            'added' => now(),
            'lastaccess' => now(),
        ]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Admin marked complete after processing.
     */
    public function test_admin_marked_complete(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNotNull($admin->complete, 'Admin should be marked complete after sending.');
    }

    /**
     * Test: activeonly=1 skips users with very old lastaccess (> 2 years ago).
     * These users are considered inactive and should not receive activeonly admins.
     */
    public function test_active_only_skips_very_old_lastaccess(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // User who last accessed over 2 years ago.
        $user = $this->createTestUser(['lastaccess' => now()->subYears(2)]);

        $adminId = $this->createAdmin(['activeonly' => true]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: activeonly=1 skips inactive users.
     * Mirrors V1 testActiveOnly.
     */
    public function test_active_only_skips_inactive(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // User with old lastaccess.
        $inactiveUser = $this->createTestUser(['lastaccess' => now()->subYear()]);

        $adminId = $this->createAdmin(['activeonly' => true]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: activeonly=1 sends to active users.
     */
    public function test_active_only_sends_to_active(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $activeUser = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin(['activeonly' => true]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);
    }

    /**
     * Test: activeonly=0 sends to inactive users.
     */
    public function test_not_active_only_sends_to_inactive(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $inactiveUser = $this->createTestUser(['lastaccess' => now()->subYear()]);

        $adminId = $this->createAdmin(['activeonly' => false]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);
    }

    /**
     * Test: essential=0 + relevantallowed=0 → skipped (for non-mod members).
     * Mirrors V1 testNonessential.
     */
    public function test_nonessential_skips_opted_out_member(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 0]);

        $adminId = $this->createAdmin(['essential' => false]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: essential=0 + moderator → always sent.
     * Mirrors V1 testNonessential.
     */
    public function test_nonessential_always_sends_to_moderator(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $mod = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 0]);
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $adminId = $this->createAdmin(['essential' => false]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);
    }

    /**
     * Test: essential=1 sends regardless of relevantallowed.
     * Mirrors V1 testNonessential.
     */
    public function test_essential_ignores_relevantallowed(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 0]);

        $adminId = $this->createAdmin(['essential' => true]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);
    }

    /**
     * Test: --dry-run counts without sending.
     */
    public function test_dry_run_counts_without_sending(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId, '--dry-run' => true])
            ->assertSuccessful();

        Mail::assertNothingSent();

        // Admin should NOT be marked complete on dry run.
        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNull($admin->complete, 'Admin should not be marked complete on dry run.');
    }

    /**
     * Test: --limit stops after N emails.
     */
    public function test_limit_stops_after_n_emails(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // Create 3 users.
        for ($i = 0; $i < 3; $i++) {
            $user = $this->createTestUser(['lastaccess' => now()]);
        }

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId, '--limit' => 1])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);
    }

    /**
     * Test: Feature flag disabled → no-op.
     */
    public function test_feature_flag_disabled_noop(): void
    {
        config(['freegle.mail.enabled_types' => '']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Deleted users are skipped.
     */
    public function test_deleted_user_skipped(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser([
            'lastaccess' => now(),
            'deleted' => now(),
        ]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: sendafter in the future → not sent yet.
     */
    public function test_sendafter_future_not_sent(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin(['sendafter' => now()->addDay()]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: sendafter in the past → sent.
     */
    public function test_sendafter_past_is_sent(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin(['sendafter' => now()->subHour()]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);
    }

    /**
     * Test: simplemail=None skipped for non-essential admins.
     */
    public function test_simplemail_none_skipped_for_nonessential(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser([
            'lastaccess' => now(),
            'settings' => ['simplemail' => 'None'],
        ]);

        $adminId = $this->createAdmin(['essential' => false]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: --limit should NOT mark admin as complete (so remaining members can be sent next run).
     */
    public function test_limit_does_not_mark_admin_complete(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // Create 3 users.
        for ($i = 0; $i < 3; $i++) {
            $user = $this->createTestUser(['lastaccess' => now()]);
        }

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId, '--limit' => 1])
            ->assertSuccessful();

        // Admin should NOT be marked complete since we hit the limit before processing all members.
        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNull($admin->complete, 'Admin should not be marked complete when limit stops processing early.');
    }

    /**
     * Test: Admin with no members should complete without errors.
     */
    public function test_admin_with_no_members_completes(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // No members added.

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();

        // Admin should still be marked complete.
        $admin = DB::table('admins')->where('id', $adminId)->first();
        $this->assertNotNull($admin->complete, 'Admin with no members should still be marked complete.');
    }

    /**
     * Test: TN (TrashNothing) users are skipped.
     */
    public function test_tn_user_skipped(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // Create a TN user with @user.trashnothing.com email.
        $tnUser = $this->createTestUser([
            'lastaccess' => now(),
            'email_preferred' => 'tnuser_' . uniqid() . '@user.trashnothing.com',
        ]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Regular (non-suggested) admin records in admins_users for dedup on retry.
     */
    public function test_regular_admin_dedup_on_retry(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $adminId = $this->createAdmin();

        // First run — user should receive email.
        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, 1);

        // Check admins_users record uses admin's own ID (no parent).
        $this->assertDatabaseHas('admins_users', [
            'userid' => $user->id,
            'adminid' => $adminId,
        ]);

        // Reset admin to incomplete for retry simulation.
        DB::table('admins')->where('id', $adminId)->update(['complete' => null]);
        Mail::fake();

        // Second run — user should be skipped via dedup.
        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: AdminMail receives volunteers from the command.
     */
    public function test_volunteers_passed_to_admin_mail(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // Create a regular member who will receive the admin email.
        $user = $this->createTestUser(['lastaccess' => now()]);

        // Create a moderator who hasn't opted out via "Show me as a volunteer"
        // (should appear in the volunteer list). users.settings.showmod defaults
        // to true when the JSON key is missing, so leaving settings unset is
        // exactly the same path as a real mod who never touched the toggle.
        $mod = $this->createTestUser([
            'lastaccess' => now(),
            'fullname' => 'Jane Smith',
        ]);
        DB::table('users')->where('id', $mod->id)->update(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, function (AdminMail $mail) {
            return !empty($mail->volunteers) && $mail->volunteers[0]['firstname'] === 'Jane';
        });
    }

    /**
     * Test: a moderator who has toggled "Show me as a volunteer" OFF
     * (users.settings.showmod = false) is excluded from the volunteer list.
     * Regression cover for the previous publishconsent=1 filter which used
     * the wrong consent column (see BirthdayService::getActiveVolunteers
     * for the full rationale and the V1→V2 parity history).
     */
    public function test_moderator_with_showmod_false_not_passed_to_admin_mail(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        // Recipient.
        $user = $this->createTestUser(['lastaccess' => now()]);

        // Mod who opted out via "Show me as a volunteer": settings.showmod = false.
        $optedOut = $this->createTestUser([
            'lastaccess' => now(),
            'fullname'   => 'Hidden Mod',
        ]);
        DB::table('users')
            ->where('id', $optedOut->id)
            ->update(['settings' => json_encode(['showmod' => false])]);
        DB::table('users')->where('id', $optedOut->id)->update(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $adminId = $this->createAdmin();

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertSent(AdminMail::class, function (AdminMail $mail) {
            $firstnames = array_column($mail->volunteers ?? [], 'firstname');
            return ! in_array('Hidden', $firstnames, true);
        });
    }

    /**
     * Test: Very old admin (>7 days) is not picked up.
     */
    public function test_old_admin_not_processed(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        // Create admin that was created 10 days ago (older than 7-day cutoff).
        $adminId = $this->createAdmin(['created' => now()->subDays(10)]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    /**
     * Test: Moderator guidance stored on an admin is never sent to members.
     */
    public function test_moderator_guidance_is_not_sent_to_members(): void
    {
        config(['freegle.mail.enabled_types' => 'Admin']);
        Mail::fake();

        $user = $this->createTestUser(['lastaccess' => now()]);

        $guidance = 'GUIDANCE-FOR-MODS-ONLY adapt this for your area';
        $adminId = $this->createAdmin(['modguidance' => $guidance]);

        $this->artisan('mail:admin:send', ['--id' => $adminId])->assertSuccessful();

        $checked = false;
        Mail::assertSent(AdminMail::class, function (AdminMail $mail) use ($guidance, &$checked) {
            $html = $mail->render();
            $text = view('emails.text.admin.admin', $mail->buildViewData())->render();

            $this->assertStringContainsString('This is a test admin message.', $html);
            $this->assertStringNotContainsString($guidance, $html);
            $this->assertStringNotContainsString($guidance, $text);
            $this->assertStringNotContainsString($guidance, $mail->adminText);
            $checked = true;

            return true;
        });
        $this->assertTrue($checked);

        // Sending must not touch the guidance column.
        $this->assertEquals($guidance, DB::table('admins')->where('id', $adminId)->value('modguidance'));
    }
}
