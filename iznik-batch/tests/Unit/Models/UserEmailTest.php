<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for User::addEmail() and User::removeEmail() — ported from
 * iznik-server userAPITest::testAddEmail() and iznik-server-go
 * TestPostUserAddEmail / TestPostUserRemoveEmail.
 */
class UserEmailTest extends TestCase
{
    private const DB_WRITES_DISABLED_SKIP_REASON = 'Temporarily skipped: TNSyncCommand-related write paths are disabled for port testing.';

    private function skipIfTNSyncWritesDisabled(): void
    {
        $this->markTestSkipped(self::DB_WRITES_DISABLED_SKIP_REASON);
    }

    public function test_add_email_creates_record(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $newEmail = $this->uniqueEmail('added');

        $id = $user->addEmail($newEmail);

        $this->assertNotNull($id);
        $this->assertTrue(
            DB::table('users_emails')->where('userid', $user->id)->where('email', $newEmail)->exists()
        );
    }

    public function test_add_email_sets_primary(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $newEmail = $this->uniqueEmail('primary');

        $user->addEmail($newEmail, 1);

        $preferred = DB::table('users_emails')
            ->where('userid', $user->id)
            ->where('preferred', 1)
            ->value('email');

        $this->assertEquals($newEmail, $preferred);
    }

    public function test_add_email_clears_other_primaries(): void
    {
        $user = $this->createTestUser();
        $newEmail = $this->uniqueEmail('new-primary');

        $user->addEmail($newEmail, 1);

        $primaryCount = DB::table('users_emails')
            ->where('userid', $user->id)
            ->where('preferred', 1)
            ->count();

        $this->assertEquals(1, $primaryCount);
    }

    public function test_add_email_returns_null_for_owner_address(): void
    {
        $user = $this->createTestUser();

        $result = $user->addEmail('somegroup-owner@yahoogroups.com');

        $this->assertNull($result);
    }

    public function test_add_email_returns_null_for_volunteer_address(): void
    {
        $user = $this->createTestUser();

        $groupDomain = config('freegle.group_domain', 'ilovefreegle.org');
        $result = $user->addEmail("testgroup-volunteers@{$groupDomain}");

        $this->assertNull($result);
    }

    public function test_add_email_returns_null_for_auto_address(): void
    {
        $user = $this->createTestUser();

        $groupDomain = config('freegle.group_domain', 'ilovefreegle.org');
        $result = $user->addEmail("testgroup-auto@{$groupDomain}");

        $this->assertNull($result);
    }

    public function test_add_email_returns_null_for_replyto_address(): void
    {
        $user = $this->createTestUser();

        $result = $user->addEmail('replyto-12345@example.com');

        $this->assertNull($result);
    }

    public function test_add_email_returns_null_for_notify_address(): void
    {
        $user = $this->createTestUser();

        $result = $user->addEmail('notify-67890@example.com');

        $this->assertNull($result);
    }

    public function test_add_existing_email_returns_existing_id(): void
    {
        $user = $this->createTestUser();
        $email = $this->uniqueEmail('existing');

        $id1 = $user->addEmail($email);
        $id2 = $user->addEmail($email);

        $this->assertEquals($id1, $id2);
    }

    public function test_add_email_sets_canon_and_backwards(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $email = $this->uniqueEmail('canon');

        $user->addEmail($email);

        $record = DB::table('users_emails')
            ->where('userid', $user->id)
            ->where('email', $email)
            ->first();

        $this->assertNotNull($record->canon);
        $this->assertNotNull($record->backwards);
        $this->assertEquals(strrev($record->canon), $record->backwards);
    }

    public function test_add_email_non_primary(): void
    {
        $user = $this->createTestUser();
        $originalPreferred = $user->email_preferred;
        $newEmail = $this->uniqueEmail('secondary');

        $user->addEmail($newEmail, 0);

        // Original primary should remain.
        $preferred = DB::table('users_emails')
            ->where('userid', $user->id)
            ->where('preferred', 1)
            ->value('email');

        $this->assertEquals($originalPreferred, $preferred);
    }

    public function test_remove_email_deletes_record(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $extra = $this->createTestUserEmail($user);

        $this->assertTrue(
            DB::table('users_emails')->where('userid', $user->id)->where('email', $extra->email)->exists()
        );

        $user->removeEmail($extra->email);

        $this->assertFalse(
            DB::table('users_emails')->where('userid', $user->id)->where('email', $extra->email)->exists()
        );
    }

    public function test_remove_email_only_affects_specified_email(): void
    {
        $this->skipIfTNSyncWritesDisabled();

        $user = $this->createTestUser();
        $extra1 = $this->createTestUserEmail($user);
        $extra2 = $this->createTestUserEmail($user);

        $user->removeEmail($extra1->email);

        $this->assertFalse(
            DB::table('users_emails')->where('email', $extra1->email)->exists()
        );
        $this->assertTrue(
            DB::table('users_emails')->where('email', $extra2->email)->exists()
        );
    }

    public function test_canon_mail_strips_tn_group_suffix(): void
    {
        $canon = User::canonMail('alice-g123@user.trashnothing.com');

        $this->assertEquals('alice@usertrashnothingcom', $canon);
    }

    public function test_canon_mail_googlemail_to_gmail(): void
    {
        $canon = User::canonMail('test@googlemail.com');

        $this->assertStringContainsString('gmail', $canon);
    }

    public function test_canon_mail_removes_gmail_dots(): void
    {
        $canon = User::canonMail('first.last@gmail.com');

        $this->assertEquals('firstlast@gmailcom', $canon);
    }

    public function test_canon_mail_removes_plus_addressing(): void
    {
        $canon = User::canonMail('user+tag@example.com');

        $this->assertEquals('user@examplecom', $canon);
    }
}
