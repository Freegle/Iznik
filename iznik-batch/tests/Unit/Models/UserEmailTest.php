<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for User::addEmail() and User::removeEmail() — ported from
 * the legacy V1 PHP userAPITest::testAddEmail() and iznik-server-go
 * TestPostUserAddEmail / TestPostUserRemoveEmail.
 */
class UserEmailTest extends TestCase
{
    public function test_add_email_creates_record(): void
    {
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
        $user = $this->createTestUser();
        $email = $this->uniqueEmail('canon');

        $user->addEmail($email);

        $record = DB::table('users_emails')
            ->where('userid', $user->id)
            ->where('email', $email)
            ->first();

        $this->assertNotNull($record->canon);
        $this->assertNotNull($record->backwards);

        // backwards is the reverse of the CANON, not of the address. V1 User::addEmail
        // writes strrev(canonMail($email)) at both its insert sites, and canonMail drops
        // the Trash Nothing -gNNNN suffix and the dots in the domain on purpose. This
        // assertion used to reverse the address, which is a different string for any
        // address with a dot in its domain, and that is how a second and incompatible
        // form got into the column. See .claude/rules/mail-and-data.md.
        $this->assertEquals(User::canonMail(strtolower($record->email)), $record->canon);
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

    /**
     * The cross-stack TN canon table. The SAME pairs are asserted in Go by
     * iznik-server-go/user/partner_canon_test.go against CanonicalizePartnerEmail,
     * so a change to either side that the other does not make fails a test.
     * Keep the two tables identical.
     *
     * Inputs are lowercased first, as the UserEmail hook and
     * users:backfill-email-canon do; Go lowercases inside TNAliasIdentity.
     */
    public static function tnCanonTable(): array
    {
        return [
            'suffixed alias' => ['alice-g123@user.trashnothing.com', 'alice@usertrashnothingcom'],
            'bare dotted' => ['tricia.hayes@user.trashnothing.com', 'tricia.hayes@usertrashnothingcom'],
            'suffixed dotted' => ['tricia.hayes-g298@user.trashnothing.com', 'tricia.hayes@usertrashnothingcom'],
            'bare hyphenated' => ['mary-jane@user.trashnothing.com', 'mary-jane@usertrashnothingcom'],
            'suffixed hyphenated' => ['mary-jane-g12@user.trashnothing.com', 'mary-jane@usertrashnothingcom'],
            'bare hyphen-g word' => ['mary-grace@user.trashnothing.com', 'mary-grace@usertrashnothingcom'],
            'bare short prefix' => ['bibiana@user.trashnothing.com', 'bibiana@usertrashnothingcom'],
            'suffixed longer name' => ['bibiana-gomes-g4840@user.trashnothing.com', 'bibiana-gomes@usertrashnothingcom'],
            'only the last suffix' => ['ann-g12-g34@user.trashnothing.com', 'ann-g12@usertrashnothingcom'],
            'mixed case' => ['Mary-Jane-G12@User.TrashNothing.com', 'mary-jane@usertrashnothingcom'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tnCanonTable')]
    public function test_canon_mail_matches_the_cross_stack_tn_table(string $email, string $canon): void
    {
        $this->assertSame($canon, User::canonMail(strtolower($email)));
    }

    /**
     * The old rule stripped everything after the LAST hyphen, so a bare
     * "mary-jane@" shared a canon with another member's "mary-g12@" and the
     * canon fallback in findUserByEmail could hand one member's mail to the other.
     */
    public function test_canon_mail_keeps_bare_and_suffixed_members_apart(): void
    {
        $this->assertNotSame(
            User::canonMail('mary-jane@user.trashnothing.com'),
            User::canonMail('mary-g12@user.trashnothing.com')
        );
        $this->assertNotSame(
            User::canonMail('bibiana@user.trashnothing.com'),
            User::canonMail('bibiana-gomes-g4840@user.trashnothing.com')
        );
        $this->assertSame(
            User::canonMail('mary-jane@user.trashnothing.com'),
            User::canonMail('mary-jane-g12@user.trashnothing.com'),
            'a bare address and an alias of the same member are one canon'
        );
    }

    public function test_canon_mail_leaves_hyphens_in_other_domains_alone(): void
    {
        $this->assertSame('mary-jane@examplecom', User::canonMail('mary-jane@example.com'));
        $this->assertSame('alice-g123@examplecom', User::canonMail('alice-g123@example.com'));
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
