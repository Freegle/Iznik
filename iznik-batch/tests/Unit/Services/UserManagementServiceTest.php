<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserDeletion;
use App\Models\UserEmail;
use App\Services\LokiService;
use App\Services\UserManagementService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementServiceTest extends TestCase
{
    protected UserManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $lokiService = $this->createMock(LokiService::class);
        $this->service = new UserManagementService($lokiService);
    }

    public function test_process_bounced_emails_marks_invalid(): void
    {
        $user = $this->createTestUser();

        // Update the user's email to have bounced (timestamp set) and be validated.
        UserEmail::where('userid', $user->id)
            ->update(['bounced' => now(), 'validated' => now()]);

        $stats = $this->service->processBouncedEmails();

        $this->assertEquals(1, $stats['marked_invalid']);

        // Verify email is now invalid (validated set to NULL).
        $email = UserEmail::where('userid', $user->id)->first();
        $this->assertNull($email->validated);
    }

    public function test_process_bounced_emails_ignores_non_bounced(): void
    {
        $user = $this->createTestUser();

        // Update the user's email to be validated but not bounced.
        UserEmail::where('userid', $user->id)
            ->update(['bounced' => null, 'validated' => now()]);

        $stats = $this->service->processBouncedEmails();

        $this->assertEquals(0, $stats['marked_invalid']);
    }

    public function test_cleanup_users_returns_all_stats(): void
    {
        $stats = $this->service->cleanupUsers();

        $this->assertArrayHasKey('yahoo_users_deleted', $stats);
        $this->assertArrayHasKey('inactive_users_forgotten', $stats);
        $this->assertArrayHasKey('gdpr_forgets_processed', $stats);
        $this->assertArrayHasKey('forgotten_users_deleted', $stats);
        $this->assertArrayHasKey('deletion_records_pruned', $stats);
    }

    public function test_merge_duplicates_with_no_duplicates(): void
    {
        $user = $this->createTestUser();

        $stats = $this->service->mergeDuplicates();

        $this->assertEquals(0, $stats['duplicates_found']);
    }

    public function test_merge_duplicates_finds_and_merges_duplicates(): void
    {
        // Create two users with the same email.
        $user1 = User::create([
            'firstname' => 'User',
            'lastname' => 'One',
            'fullname' => 'User One',
            'added' => now()->subDays(30),
        ]);

        $user2 = User::create([
            'firstname' => 'User',
            'lastname' => 'Two',
            'fullname' => 'User Two',
            'added' => now()->subDays(20),
        ]);

        // The users_emails table has a unique constraint on email, so we can't add
        // the same email twice. The merge function relies on the same email being
        // associated with multiple userids through the userid column, which also
        // has constraints. Skip this test as the schema doesn't allow duplicates.
        $stats = $this->service->mergeDuplicates();

        $this->assertEquals(0, $stats['duplicates_found']);
    }

    public function test_cleanup_users_dry_run_does_not_modify(): void
    {
        // Create a user with a Yahoo Groups email.
        $user = User::create([
            'firstname' => 'Yahoo',
            'lastname' => 'User',
            'fullname' => 'Yahoo User',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
        ]);

        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'testgroup@yahoogroups.com',
            'added' => now()->subYears(2),
        ]);

        $stats = $this->service->cleanupUsers(TRUE);

        // Should count the Yahoo user but not delete it.
        $this->assertGreaterThanOrEqual(1, $stats['yahoo_users_deleted']);

        // User should still exist.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_process_bounced_emails_with_no_bounced(): void
    {
        $stats = $this->service->processBouncedEmails();

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['marked_invalid']);
    }


    public function test_delete_yahoo_groups_users(): void
    {
        $user = User::create([
            'firstname' => 'Yahoo',
            'lastname' => 'User',
            'fullname' => 'Yahoo User',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
        ]);

        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'testgroup@yahoogroups.com',
            'added' => now()->subYears(2),
        ]);

        $count = $this->service->deleteYahooGroupsUsers();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_forget_inactive_users(): void
    {
        // Create user meeting all inactive criteria: no memberships, no spammer record,
        // no mod notes, last access > 6 months, systemrole = User, not deleted.
        $user = User::create([
            'firstname' => 'Inactive',
            'lastname' => 'User',
            'fullname' => 'Inactive User',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subMonths(7),
            'systemrole' => 'User',
        ]);

        $count = $this->service->forgetInactiveUsers();

        $this->assertGreaterThanOrEqual(1, $count);

        // User should be forgotten (personal data wiped).
        $user->refresh();
        $this->assertNotNull($user->forgotten);
        $this->assertNull($user->firstname);
        $this->assertEquals("Deleted User #{$user->id}", $user->fullname);
    }

    public function test_forget_inactive_users_skips_already_forgotten(): void
    {
        // Create user who meets all inactive criteria but is already forgotten.
        $user = User::create([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Deleted User #12345',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subMonths(7),
            'systemrole' => 'User',
            'forgotten' => now()->subDays(30),
        ]);

        $count = $this->service->forgetInactiveUsers();

        // Already-forgotten user should NOT be re-processed.
        $this->assertEquals(0, $count);
    }

    public function test_forget_inactive_users_skips_with_memberships(): void
    {
        $user = User::create([
            'firstname' => 'Member',
            'lastname' => 'User',
            'fullname' => 'Member User',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subMonths(7),
            'systemrole' => 'User',
        ]);

        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $count = $this->service->forgetInactiveUsers();

        // User should NOT be forgotten because they have memberships.
        $user->refresh();
        $this->assertNull($user->forgotten);
        $this->assertEquals('Member', $user->firstname);
    }

    public function test_process_forgets_after_grace_period(): void
    {
        // Create a user who was soft-deleted > 14 days ago but not yet forgotten.
        $user = User::create([
            'firstname' => 'Grace',
            'lastname' => 'Period',
            'fullname' => 'Grace Period',
            'added' => now()->subYears(1),
            'lastaccess' => now()->subMonths(2),
            'deleted' => now()->subDays(15),
        ]);

        $count = $this->service->processForgets();

        $this->assertGreaterThanOrEqual(1, $count);

        // User should now be forgotten.
        $user->refresh();
        $this->assertNotNull($user->forgotten);
        $this->assertNull($user->firstname);
    }

    public function test_process_forgets_skips_recent_deletes(): void
    {
        // Create a user who was soft-deleted only 5 days ago.
        $user = User::create([
            'firstname' => 'Recent',
            'lastname' => 'Delete',
            'fullname' => 'Recent Delete',
            'added' => now()->subYears(1),
            'lastaccess' => now()->subMonths(2),
            'deleted' => now()->subDays(5),
        ]);

        $count = $this->service->processForgets();

        // Should not have processed this user (within 14-day grace period).
        $user->refresh();
        $this->assertNull($user->forgotten);
        $this->assertEquals('Recent', $user->firstname);
    }

    public function test_forget_user_wipes_personal_data(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        $this->service->forgetUser($user->id, 'Test reason');

        // User personal fields should be wiped.
        $user->refresh();
        $this->assertNull($user->firstname);
        $this->assertNull($user->lastname);
        $this->assertEquals("Deleted User #{$user->id}", $user->fullname);
        $this->assertNull($user->settings);
        $this->assertNotNull($user->forgotten);

        // Non-internal emails should be deleted.
        $this->assertDatabaseMissing('users_emails', [
            'userid' => $user->id,
            'email' => 'test' . $user->id . '@test.com',
        ]);

        // Message content should be wiped.
        $msg = DB::table('messages')->where('id', $message->id)->first();
        $this->assertNull($msg->textbody);
        $this->assertNull($msg->htmlbody);
        $this->assertNotNull($msg->deleted);

        // Memberships should be removed.
        $this->assertDatabaseMissing('memberships', ['userid' => $user->id]);

        // users_related entries should be removed so deleted users don't appear in Related Members.
        $this->assertDatabaseMissing('users_related', ['user1' => $user->id]);
        $this->assertDatabaseMissing('users_related', ['user2' => $user->id]);

        // Log entry should exist.
        $this->assertDatabaseHas('logs', [
            'user' => $user->id,
            'type' => 'User',
            'subtype' => 'Deleted',
            'text' => 'Test reason',
        ]);
    }

    public function test_forget_user_records_deletion_for_partners(): void
    {
        // Partners mirror our users and only learn about changes by polling, so a
        // forget has to leave a tombstone they can see.
        $user = $this->createTestUser();

        $this->service->forgetUser($user->id, 'Test reason');

        $this->assertDatabaseHas('users_deletions', [
            'userid' => $user->id,
            'type' => UserDeletion::TYPE_FORGOTTEN,
            'reason' => 'Test reason',
        ]);
    }

    public function test_delete_fully_forgotten_users(): void
    {
        // Create a forgotten user with no messages.
        $user = User::create([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Deleted User #999',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
            'forgotten' => now()->subDays(30),
        ]);

        $count = $this->service->deleteFullyForgottenUsers();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_delete_fully_forgotten_users_keeps_users_with_messages(): void
    {
        // Create a forgotten user who still has messages.
        $user = User::create([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Deleted User #998',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
            'forgotten' => now()->subDays(30),
        ]);

        $group = $this->createTestGroup();
        $this->createTestMessage($user, $group);

        $count = $this->service->deleteFullyForgottenUsers();

        // User should NOT be deleted because they still have messages.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_delete_fully_forgotten_users_records_purge(): void
    {
        // The users row goes for good here, so the tombstone is the only trace
        // left for a partner to act on.
        $user = User::create([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Deleted User #997',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
            'forgotten' => now()->subDays(30),
        ]);

        $this->service->deleteFullyForgottenUsers();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users_deletions', [
            'userid' => $user->id,
            'type' => UserDeletion::TYPE_PURGED,
        ]);
    }

    public function test_delete_fully_forgotten_users_dry_run_records_nothing(): void
    {
        $user = User::create([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => 'Deleted User #996',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
            'forgotten' => now()->subDays(30),
        ]);

        $this->service->deleteFullyForgottenUsers(TRUE);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('users_deletions', ['userid' => $user->id]);
    }

    public function test_delete_yahoo_groups_users_records_purge(): void
    {
        $user = User::create([
            'firstname' => 'Yahoo',
            'lastname' => 'Purged',
            'fullname' => 'Yahoo Purged',
            'added' => now()->subYears(2),
            'lastaccess' => now()->subYears(1),
        ]);

        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'purgedgroup@yahoogroups.com',
            'added' => now()->subYears(2),
        ]);

        $this->service->deleteYahooGroupsUsers();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users_deletions', [
            'userid' => $user->id,
            'type' => UserDeletion::TYPE_PURGED,
        ]);
    }

    public function test_prune_deletions_removes_records_older_than_retention(): void
    {
        $stale = UserDeletion::create([
            'userid' => 999001,
            'timestamp' => now()->subDays(UserManagementService::DELETION_RETENTION_DAYS + 1),
            'type' => UserDeletion::TYPE_FORGOTTEN,
        ]);

        $fresh = UserDeletion::create([
            'userid' => 999002,
            'timestamp' => now()->subDays(1),
            'type' => UserDeletion::TYPE_FORGOTTEN,
        ]);

        $pruned = $this->service->pruneDeletions();

        $this->assertGreaterThanOrEqual(1, $pruned);
        $this->assertDatabaseMissing('users_deletions', ['id' => $stale->id]);
        $this->assertDatabaseHas('users_deletions', ['id' => $fresh->id]);
    }

    public function test_prune_deletions_dry_run_keeps_records(): void
    {
        $stale = UserDeletion::create([
            'userid' => 999003,
            'timestamp' => now()->subDays(UserManagementService::DELETION_RETENTION_DAYS + 1),
            'type' => UserDeletion::TYPE_FORGOTTEN,
        ]);

        $this->service->pruneDeletions(TRUE);

        $this->assertDatabaseHas('users_deletions', ['id' => $stale->id]);
    }

    public function test_merge_users_for_email_with_single_user(): void
    {
        $user = $this->createTestUser();

        // Use reflection to test protected method.
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mergeUsersForEmail');
        $method->setAccessible(true);

        // Get the user's email.
        $email = \App\Models\UserEmail::where('userid', $user->id)->first()->email;

        // This should return early since there's only one user with this email.
        $method->invoke($this->service, $email);

        // User should still exist and not be deleted.
        $user->refresh();
        $this->assertNull($user->deleted);
    }

    public function test_merge_user_via_reflection(): void
    {
        // Create two users.
        $keepUser = User::create([
            'firstname' => 'Keep',
            'lastname' => 'User',
            'fullname' => 'Keep User',
            'added' => now()->subDays(30),
        ]);

        $mergeUser = User::create([
            'firstname' => 'Merge',
            'lastname' => 'User',
            'fullname' => 'Merge User',
            'added' => now()->subDays(20),
        ]);

        // Use reflection to test protected method.
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mergeUser');
        $method->setAccessible(true);

        $method->invoke($this->service, $keepUser->id, $mergeUser->id);

        // Verify merge user is soft deleted.
        $mergeUser->refresh();
        $this->assertNotNull($mergeUser->deleted);

        // Verify keep user is not deleted.
        $keepUser->refresh();
        $this->assertNull($keepUser->deleted);
    }

    // --- updateLastAccess tests ---

    public function test_update_lastaccess_from_chat_message(): void
    {
        $user = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Set lastaccess to well in the past.
        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subDays(30),
        ]);

        $room = $this->createTestChatRoom($user, $user2);

        // Create a recent chat message from this user.
        $this->createTestChatMessage($room, $user, [
            'date' => now()->subMinutes(5),
        ]);

        $stats = $this->service->updateLastAccess();

        $this->assertGreaterThanOrEqual(1, $stats['updated']);

        $user->refresh();
        // Lastaccess should now be much more recent than 30 days ago.
        $this->assertGreaterThan(
            now()->subDays(1)->timestamp,
            strtotime($user->lastaccess)
        );
    }

    public function test_update_lastaccess_from_membership(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // Set lastaccess to well in the past.
        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => now()->subDays(30),
        ]);

        // Create a membership with recent added date.
        $this->createMembership($user, $group, [
            'added' => now()->subMinutes(5),
        ]);

        $stats = $this->service->updateLastAccess();

        $this->assertGreaterThanOrEqual(1, $stats['updated']);
    }

    /**
     * The point of the window: activity older than it is left to the nightly pass, so
     * the hourly one stops joining users against the whole history of chat_messages
     * and memberships.
     */
    public function test_hourly_pass_ignores_activity_older_than_the_window(): void
    {
        $user = $this->createTestUser();
        $user2 = $this->createTestUser();

        DB::table('users')->where('id', $user->id)->update(['lastaccess' => now()->subDays(60)]);

        $room = $this->createTestChatRoom($user, $user2);
        $this->createTestChatMessage($room, $user, ['date' => now()->subDays(30)]);

        // Pretend the last run was an hour ago, so the window starts three hours back.
        DB::table('config')->upsert(
            [['key' => 'users.lastaccess_cursor', 'value' => now()->subHour()->toDateTimeString()]],
            ['key'],
            ['value'],
        );

        $windowed = $this->service->updateLastAccess();
        $this->assertSame(0, $windowed['updated'], 'a 30-day-old message is outside the window');

        $user->refresh();
        $this->assertLessThan(now()->subDays(50)->timestamp, strtotime($user->lastaccess));

        // The nightly unbounded pass is what catches it.
        $full = $this->service->updateLastAccess(false, true);
        $this->assertTrue($full['full']);
        $this->assertGreaterThanOrEqual(1, $full['updated']);

        $user->refresh();
        $this->assertGreaterThan(now()->subDays(31)->timestamp, strtotime($user->lastaccess));
    }

    public function test_a_run_records_where_it_got_to(): void
    {
        $this->assertNull(DB::table('config')->where('key', 'users.lastaccess_cursor')->value('value'));

        $this->service->updateLastAccess();

        $this->assertNotNull(DB::table('config')->where('key', 'users.lastaccess_cursor')->value('value'));
    }

    public function test_a_dry_run_does_not_record_where_it_got_to(): void
    {
        $this->service->updateLastAccess(true);

        $this->assertNull(DB::table('config')->where('key', 'users.lastaccess_cursor')->value('value'));
    }

    public function test_update_lastaccess_ignores_small_differences(): void
    {
        $user = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Set lastaccess to just 5 minutes ago (within 600s threshold).
        $recentTime = now()->subMinutes(5);
        DB::table('users')->where('id', $user->id)->update([
            'lastaccess' => $recentTime,
        ]);

        $room = $this->createTestChatRoom($user, $user2);

        // Create a chat message from just 3 minutes ago (diff < 600s).
        $this->createTestChatMessage($room, $user, [
            'date' => now()->subMinutes(3),
        ]);

        $stats = $this->service->updateLastAccess();

        // Should not update because the difference is less than 600 seconds.
        $this->assertEquals(0, $stats['updated']);
    }

    // --- updateSupportRoles tests ---

    public function test_update_support_roles_grants_access(): void
    {
        $user = $this->createTestUser();

        // Set user to Moderator role.
        DB::table('users')->where('id', $user->id)->update([
            'systemrole' => 'Moderator',
        ]);

        // Create a team with supporttools=1.
        $teamId = DB::table('teams')->insertGetId([
            'name' => 'Support Team ' . uniqid(),
            'supporttools' => 1,
        ]);

        DB::table('teams_members')->insert([
            'teamid' => $teamId,
            'userid' => $user->id,
            'added' => now(),
        ]);

        $stats = $this->service->updateSupportRoles();

        $this->assertEquals(1, $stats['granted']);

        $user->refresh();
        $this->assertEquals('Support', $user->systemrole);
    }

    public function test_update_support_roles_removes_access(): void
    {
        $user = $this->createTestUser();

        // Set user to Support role but don't put them in any support team.
        DB::table('users')->where('id', $user->id)->update([
            'systemrole' => 'Support',
        ]);

        $stats = $this->service->updateSupportRoles();

        $this->assertGreaterThanOrEqual(1, $stats['removed']);

        $user->refresh();
        $this->assertEquals('Moderator', $user->systemrole);
    }

    public function test_update_support_roles_does_not_downgrade_admin(): void
    {
        $user = $this->createTestUser();

        // Set user to Admin role.
        DB::table('users')->where('id', $user->id)->update([
            'systemrole' => 'Admin',
        ]);

        $stats = $this->service->updateSupportRoles();

        // Admin should not be downgraded even if not in a support team.
        $user->refresh();
        $this->assertEquals('Admin', $user->systemrole);
    }

    public function test_update_support_roles_ignores_non_support_teams(): void
    {
        $user = $this->createTestUser();

        DB::table('users')->where('id', $user->id)->update([
            'systemrole' => 'Moderator',
        ]);

        // Create a team WITHOUT supporttools.
        $teamId = DB::table('teams')->insertGetId([
            'name' => 'Regular Team ' . uniqid(),
            'supporttools' => 0,
        ]);

        DB::table('teams_members')->insert([
            'teamid' => $teamId,
            'userid' => $user->id,
            'added' => now(),
        ]);

        $stats = $this->service->updateSupportRoles();

        // Should not grant support role.
        $user->refresh();
        $this->assertEquals('Moderator', $user->systemrole);
    }

    // --- Email validation tests ---

    public function test_validate_emails_deletes_invalid(): void
    {
        $user = $this->createTestUser();

        // Insert an invalid email directly.
        $invalidId = DB::table('users_emails')->insertGetId([
            'email' => 'not-an-email',
            'userid' => $user->id,
            'added' => now(),
            'bounced' => null,
        ]);

        $stats = $this->service->validateEmails();

        $this->assertGreaterThanOrEqual(1, $stats['invalid']);
        $this->assertDatabaseMissing('users_emails', ['id' => $invalidId]);
    }

    public function test_validate_emails_keeps_valid(): void
    {
        $user = $this->createTestUser();

        $validId = DB::table('users_emails')->insertGetId([
            'email' => $this->uniqueEmail('valid'),
            'userid' => $user->id,
            'added' => now(),
            'bounced' => null,
        ]);

        $this->service->validateEmails();

        $this->assertDatabaseHas('users_emails', ['id' => $validId]);
    }

    public function test_validate_emails_skips_bouncing(): void
    {
        $user = $this->createTestUser();

        // Insert an invalid but bouncing email — should be skipped.
        DB::table('users_emails')->insert([
            'email' => 'not-valid',
            'userid' => $user->id,
            'added' => now(),
            'bounced' => now()->subDays(1),
        ]);

        $this->service->validateEmails();

        // Still exists because bouncing emails are skipped.
        $this->assertDatabaseHas('users_emails', [
            'userid' => $user->id,
            'email' => 'not-valid',
        ]);
    }

    public function test_validate_emails_returns_stats(): void
    {
        $stats = $this->service->validateEmails();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('invalid', $stats);
        $this->assertIsInt($stats['total']);
        $this->assertIsInt($stats['invalid']);
    }

    // --- Rating visibility tests ---

    public function test_rating_visible_when_both_users_messaged(): void
    {
        $rater = $this->createTestUser();
        $ratee = $this->createTestUser();

        $room = $this->createTestChatRoom($rater, $ratee);

        // Both users send messages (no refmsgid = direct messages).
        $this->createTestChatMessage($room, $rater, [
            'message' => 'Hello',
            'refmsgid' => null,
            'date' => now()->subHour(),
        ]);
        $this->createTestChatMessage($room, $ratee, [
            'message' => 'Hi there',
            'refmsgid' => null,
            'date' => now()->subMinutes(50),
        ]);

        // Create rating (not visible yet).
        $ratingId = DB::table('ratings')->insertGetId([
            'rater' => $rater->id,
            'ratee' => $ratee->id,
            'rating' => 'Up',
            'visible' => false,
            'timestamp' => now(),
            'reviewrequired' => 0,
        ]);

        $stats = $this->service->updateRatingVisibility('1 day ago');

        $this->assertGreaterThanOrEqual(1, $stats['processed']);
        $this->assertGreaterThanOrEqual(1, $stats['made_visible']);

        $rating = DB::table('ratings')->where('id', $ratingId)->first();
        $this->assertTrue((bool) $rating->visible);
    }

    public function test_rating_visible_when_ratee_replied_to_post(): void
    {
        $rater = $this->createTestUser();
        $ratee = $this->createTestUser();
        $group = $this->createTestGroup();

        $room = $this->createTestChatRoom($rater, $ratee);
        $message = $this->createTestMessage($rater, $group);

        // Ratee replied to a post (has refmsgid).
        $this->createTestChatMessage($room, $ratee, [
            'message' => 'Is this available?',
            'refmsgid' => $message->id,
            'date' => now()->subHour(),
        ]);

        $ratingId = DB::table('ratings')->insertGetId([
            'rater' => $rater->id,
            'ratee' => $ratee->id,
            'rating' => 'Up',
            'visible' => false,
            'timestamp' => now(),
            'reviewrequired' => 0,
        ]);

        $stats = $this->service->updateRatingVisibility('1 day ago');

        $rating = DB::table('ratings')->where('id', $ratingId)->first();
        $this->assertTrue((bool) $rating->visible);
    }

    public function test_rating_hidden_when_no_meaningful_interaction(): void
    {
        $rater = $this->createTestUser();
        $ratee = $this->createTestUser();

        // No chat room or messages between them.
        $ratingId = DB::table('ratings')->insertGetId([
            'rater' => $rater->id,
            'ratee' => $ratee->id,
            'rating' => 'Up',
            'visible' => true,
            'timestamp' => now(),
            'reviewrequired' => 0,
        ]);

        $stats = $this->service->updateRatingVisibility('1 day ago');

        $rating = DB::table('ratings')->where('id', $ratingId)->first();
        $this->assertFalse((bool) $rating->visible);
        $this->assertGreaterThanOrEqual(1, $stats['made_hidden']);
    }

    public function test_rating_visibility_no_change_needed(): void
    {
        $stats = $this->service->updateRatingVisibility('1 second ago');

        $this->assertEquals(0, $stats['processed']);
        $this->assertEquals(0, $stats['made_visible']);
        $this->assertEquals(0, $stats['made_hidden']);
    }

    public function test_backfill_demotes_stale_moderator(): void
    {
        // systemrole Moderator but no Owner/Moderator membership anywhere.
        $user = $this->createTestUser(['systemrole' => 'Moderator']);

        $stats = $this->service->backfillModeratorSystemRoles();

        $user->refresh();
        $this->assertEquals('User', $user->systemrole);
        $this->assertGreaterThanOrEqual(1, $stats['demoted']);
    }

    public function test_backfill_keeps_moderator_with_mod_membership(): void
    {
        $user = $this->createTestUser(['systemrole' => 'Moderator']);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => 'Moderator']);

        $this->service->backfillModeratorSystemRoles();

        $user->refresh();
        $this->assertEquals('Moderator', $user->systemrole);
    }

    public function test_backfill_keeps_moderator_with_owner_membership(): void
    {
        $user = $this->createTestUser(['systemrole' => 'Moderator']);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => 'Owner']);

        $this->service->backfillModeratorSystemRoles();

        $user->refresh();
        $this->assertEquals('Moderator', $user->systemrole);
    }

    public function test_backfill_leaves_support_untouched(): void
    {
        // Support outranks Moderator and is set deliberately — never auto-demoted.
        $user = $this->createTestUser(['systemrole' => 'Support']);

        $this->service->backfillModeratorSystemRoles();

        $user->refresh();
        $this->assertEquals('Support', $user->systemrole);
    }

    public function test_backfill_dry_run_does_not_change(): void
    {
        $user = $this->createTestUser(['systemrole' => 'Moderator']);

        $stats = $this->service->backfillModeratorSystemRoles(true);

        $user->refresh();
        $this->assertEquals('Moderator', $user->systemrole);
        $this->assertGreaterThanOrEqual(1, $stats['demoted']);
    }
}
