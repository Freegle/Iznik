<?php

namespace Tests\Unit\Services;

use App\Models\ChatImage;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\EmailTracking;
use App\Models\EmailTrackingClick;
use App\Models\EmailTrackingImage;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\PurgeService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeServiceTest extends TestCase
{
    protected PurgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurgeService();
    }

    public function test_purge_spam_chat_messages(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);

        $room = ChatRoom::create([
            'name' => 'Test Room',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
        ]);

        // Create a spam message older than 7 days.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'message' => 'Spam',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(10),
            'reviewrejected' => 1,
        ]);

        // Create a normal message.
        ChatMessage::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'message' => 'Normal',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays(10),
            'reviewrejected' => 0,
        ]);

        $count = $this->service->purgeSpamChatMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_empty_chat_rooms(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);
        $this->createMembership($user3, $group);

        // Create an empty room.
        $emptyRoom = ChatRoom::create([
            'name' => 'Empty Room',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
        ]);

        // Create a room with messages (different users to avoid unique constraint).
        $roomWithMessages = ChatRoom::create([
            'name' => 'Room With Messages',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user3->id,
        ]);

        ChatMessage::create([
            'chatid' => $roomWithMessages->id,
            'userid' => $user1->id,
            'message' => 'Hello',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
        ]);

        $count = $this->service->purgeEmptyChatRooms();

        // In parallel tests, other tests may create empty rooms too, so check at least our room was purged.
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('chat_rooms', ['id' => $emptyRoom->id]);
        $this->assertDatabaseHas('chat_rooms', ['id' => $roomWithMessages->id]);
    }

    public function test_purge_orphaned_chat_images(): void
    {
        // Create orphaned image.
        $orphanedImage = ChatImage::create([
            'chatmsgid' => null,
        ]);

        $count = $this->service->purgeOrphanedChatImages();

        // In parallel tests, other tests may create orphaned images too, so check at least our image was purged.
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('chat_images', ['id' => $orphanedImage->id]);
    }

    public function test_purge_old_messages_history(): void
    {
        // Create old history entries.
        DB::table('messages_history')->insert([
            'arrival' => now()->subDays(100),
        ]);

        // Create recent history entry.
        DB::table('messages_history')->insert([
            'arrival' => now()->subDays(10),
        ]);

        $count = $this->service->purgeOldMessagesHistory();

        $this->assertEquals(1, $count);
    }

    public function test_purge_old_messages_history_default_is_31_days(): void
    {
        // V1 uses MessageCollection::RECENTPOSTS = "Midnight 31 days ago".
        $oldId = DB::table('messages_history')->insertGetId(['arrival' => now()->subDays(32)]);
        $recentId = DB::table('messages_history')->insertGetId(['arrival' => now()->subDays(30)]);

        $this->service->purgeOldMessagesHistory();

        $this->assertDatabaseMissing('messages_history', ['id' => $oldId]);
        $this->assertDatabaseHas('messages_history', ['id' => $recentId]);
    }

    public function test_purge_pending_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set to pending and old.
        MessageGroup::where('msgid', $message->id)
            ->update([
                'collection' => MessageGroup::COLLECTION_PENDING,
                'arrival' => now()->subDays(100),
            ]);

        $count = $this->service->purgePendingMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_old_drafts(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Create old draft entry.
        DB::table('messages_drafts')->insert([
            'msgid' => $message->id,
            'timestamp' => now()->subDays(100),
        ]);

        $count = $this->service->purgeOldDrafts();

        $this->assertEquals(1, $count);
    }

    public function test_purge_deleted_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Mark as deleted.
        $message->update([
            'deleted' => now()->subDays(5),
            'date' => now()->subDays(30),
        ]);

        $count = $this->service->purgeDeletedMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_unvalidated_emails(): void
    {
        // Create old unvalidated email.
        DB::table('users_emails')->insert([
            'email' => $this->uniqueEmail('unvalidated'),
            'userid' => null,
            'added' => now()->subDays(10),
        ]);

        // Create recent unvalidated email.
        DB::table('users_emails')->insert([
            'email' => $this->uniqueEmail('recent'),
            'userid' => null,
            'added' => now()->subDays(1),
        ]);

        $count = $this->service->purgeUnvalidatedEmails();

        $this->assertEquals(1, $count);
    }

    public function test_purge_users_nearby(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user1, $group);
        $this->createMembership($user2, $group);

        $message1 = $this->createTestMessage($user1, $group);
        $message2 = $this->createTestMessage($user2, $group);

        // Create old entry.
        DB::table('users_nearby')->insert([
            'userid' => $user1->id,
            'msgid' => $message1->id,
            'timestamp' => now()->subDays(100),
        ]);

        // Create recent entry.
        DB::table('users_nearby')->insert([
            'userid' => $user2->id,
            'msgid' => $message2->id,
            'timestamp' => now()->subDays(10),
        ]);

        $count = $this->service->purgeUsersNearby();

        $this->assertEquals(1, $count);
    }

    public function test_purge_orphaned_isochrones(): void
    {
        // Create orphaned isochrone.
        $isochroneId = DB::table('isochrones')->insertGetId([
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))')"),
        ]);

        $count = $this->service->purgeOrphanedIsochrones();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('isochrones', ['id' => $isochroneId]);
    }

    public function test_run_all(): void
    {
        $results = $this->service->runAll();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('spam_chat_messages', $results);
        $this->assertArrayHasKey('empty_chat_rooms', $results);
        $this->assertArrayHasKey('orphaned_chat_images', $results);
        $this->assertArrayHasKey('messages_history', $results);
        $this->assertArrayHasKey('pending_messages', $results);
        $this->assertArrayHasKey('old_drafts', $results);
        $this->assertArrayHasKey('non_freegle_messages', $results);
        $this->assertArrayHasKey('deleted_messages', $results);
        $this->assertArrayHasKey('stranded_messages', $results);
        $this->assertArrayHasKey('html_body', $results);
        $this->assertArrayHasKey('unvalidated_emails', $results);
        $this->assertArrayHasKey('users_nearby', $results);
        $this->assertArrayHasKey('orphaned_isochrones', $results);
        $this->assertArrayHasKey('completed_admins', $results);
        $this->assertArrayHasKey('email_tracking', $results);
        $this->assertArrayHasKey('sessions', $results);
        $this->assertArrayHasKey('login_links', $results);
    }

    public function test_purge_email_tracking(): void
    {
        // Create old tracking record.
        $oldTracking = EmailTracking::create([
            'tracking_id' => EmailTracking::generateTrackingId(),
            'email_type' => 'Test',
            'recipient_email' => $this->uniqueEmail('old'),
            'sent_at' => now()->subDays(100),
        ]);

        // Create associated click and image records.
        EmailTrackingClick::create([
            'email_tracking_id' => $oldTracking->id,
            'link_url' => 'https://example.com',
            'clicked_at' => now()->subDays(100),
        ]);

        EmailTrackingImage::create([
            'email_tracking_id' => $oldTracking->id,
            'image_position' => 'header',
            'loaded_at' => now()->subDays(100),
        ]);

        // Create recent tracking record.
        $recentTracking = EmailTracking::create([
            'tracking_id' => EmailTracking::generateTrackingId(),
            'email_type' => 'Test',
            'recipient_email' => $this->uniqueEmail('recent'),
            'sent_at' => now()->subDays(10),
        ]);

        $count = $this->service->purgeEmailTracking();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('email_tracking', ['id' => $oldTracking->id]);
        $this->assertDatabaseHas('email_tracking', ['id' => $recentTracking->id]);
    }

    public function test_purge_html_body(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Set htmlbody and old arrival.
        $message->update([
            'htmlbody' => '<html><body>Test</body></html>',
            'arrival' => now()->subDays(30),
        ]);

        $count = $this->service->purgeHtmlBody();

        $this->assertEquals(1, $count);
        $message->refresh();
        $this->assertNull($message->htmlbody);
    }

    public function test_purge_stranded_messages(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group);

        // Remove from all groups (making it stranded).
        MessageGroup::where('msgid', $message->id)->delete();

        // Make it old enough.
        $message->update(['arrival' => now()->subDays(5)]);

        $count = $this->service->purgeStrandedMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_non_freegle_messages(): void
    {
        $user = $this->createTestUser();

        // Create non-Freegle group.
        $group = $this->createTestGroup();
        $group->update(['type' => 'Reuse']);

        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Make it old.
        MessageGroup::where('msgid', $message->id)
            ->update(['arrival' => now()->subDays(100)]);

        $count = $this->service->purgeNonFreegleMessages();

        $this->assertEquals(1, $count);
    }

    public function test_purge_completed_admins(): void
    {
        $user = $this->createTestUser();

        // Create completed admin.
        $adminId = DB::table('admins')->insertGetId([
            'complete' => now()->subDays(100),
            'created' => now()->subDays(200),
        ]);

        // Create admin_users entries.
        DB::table('admins_users')->insert([
            'adminid' => $adminId,
            'userid' => $user->id,
        ]);

        $count = $this->service->purgeCompletedAdmins();

        $this->assertEquals(1, $count);
        $this->assertDatabaseMissing('admins_users', ['adminid' => $adminId]);
    }

    // =========================================================================
    // Log purge tests (migrated from purge_logs.php)
    // =========================================================================

    public function test_purge_old_likes(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Old like.
        DB::table('messages_likes')->insert([
            'msgid' => $message->id,
            'userid' => $user->id,
            'type' => 'View',
            'timestamp' => now()->subDays(400),
        ]);

        $count = $this->service->purgeOldLikes();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('messages_likes', [
            'msgid' => $message->id,
            'userid' => $user->id,
        ]);
    }

    public function test_purge_old_likes_keeps_recent(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);

        // Recent like.
        DB::table('messages_likes')->insert([
            'msgid' => $message->id,
            'userid' => $user->id,
            'type' => 'View',
            'timestamp' => now()->subDays(30),
        ]);

        $this->service->purgeOldLikes();

        $this->assertDatabaseHas('messages_likes', [
            'msgid' => $message->id,
            'userid' => $user->id,
        ]);
    }

    public function test_purge_login_logout_logs(): void
    {
        // Old login log.
        $loginId = DB::table('logs')->insertGetId([
            'type' => 'User',
            'subtype' => 'Login',
            'timestamp' => now()->subDays(400),
        ]);

        // Old logout log.
        $logoutId = DB::table('logs')->insertGetId([
            'type' => 'User',
            'subtype' => 'Logout',
            'timestamp' => now()->subDays(400),
        ]);

        $count = $this->service->purgeLoginLogoutLogs();

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertDatabaseMissing('logs', ['id' => $loginId]);
        $this->assertDatabaseMissing('logs', ['id' => $logoutId]);
    }

    public function test_purge_user_deletion_logs(): void
    {
        $logId = DB::table('logs')->insertGetId([
            'type' => 'User',
            'subtype' => 'Deleted',
            'timestamp' => now()->subDays(60),
        ]);

        $count = $this->service->purgeUserDeletionLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs', ['id' => $logId]);
    }

    public function test_purge_user_creation_logs(): void
    {
        $logId = DB::table('logs')->insertGetId([
            'type' => 'User',
            'subtype' => 'Created',
            'timestamp' => now()->subDays(60),
        ]);

        $count = $this->service->purgeUserCreationLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs', ['id' => $logId]);
    }

    public function test_purge_blank_subtype_logs(): void
    {
        $logId = DB::table('logs')->insertGetId([
            'type' => 'User',
            'subtype' => '',
            'timestamp' => now()->subDays(60),
        ]);

        $count = $this->service->purgeBlankSubtypeLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs', ['id' => $logId]);
    }

    public function test_purge_bounce_logs(): void
    {
        $logId = DB::table('logs')->insertGetId([
            'type' => 'User',
            'subtype' => 'Bounce',
            'timestamp' => now()->subDays(120),
        ]);

        $count = $this->service->purgeBounceLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs', ['id' => $logId]);
    }

    public function test_purge_old_bounce_emails(): void
    {
        $user = $this->createTestUser();

        // Need an email for the foreign key.
        $emailId = DB::table('users_emails')->insertGetId([
            'email' => $this->uniqueEmail('bounce'),
            'userid' => $user->id,
            'added' => now(),
        ]);

        $bounceId = DB::table('bounces_emails')->insertGetId([
            'emailid' => $emailId,
            'date' => now()->subDays(60),
        ]);

        $count = $this->service->purgeOldBounceEmails();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('bounces_emails', ['id' => $bounceId]);
    }

    public function test_purge_email_logs(): void
    {
        $logId = DB::table('logs_emails')->insertGetId([
            'timestamp' => now()->subDays(3),
        ]);

        $count = $this->service->purgeEmailLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs_emails', ['id' => $logId]);
    }

    public function test_purge_non_freegle_group_logs(): void
    {
        // Create non-Freegle group.
        $group = $this->createTestGroup();
        $group->update(['type' => 'Reuse']);

        $logId = DB::table('logs')->insertGetId([
            'type' => 'Group',
            'groupid' => $group->id,
            'timestamp' => now()->subDays(60),
        ]);

        $count = $this->service->purgeNonFreegleGroupLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs', ['id' => $logId]);
    }

    public function test_purge_src_logs(): void
    {
        $logId = DB::table('logs_src')->insertGetId([
            'src' => 'test',
            'date' => now()->subDays(400),
        ]);

        $count = $this->service->purgeSrcLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs_src', ['id' => $logId]);
    }

    public function test_purge_js_error_logs(): void
    {
        $logId = DB::table('logs_errors')->insertGetId([
            'text' => 'Test error',
            'date' => now()->subDays(60),
        ]);

        $count = $this->service->purgeJsErrorLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs_errors', ['id' => $logId]);
    }

    public function test_purge_plugin_logs(): void
    {
        $logId = DB::table('logs')->insertGetId([
            'type' => 'Plugin',
            'timestamp' => now()->subDays(3),
        ]);

        $count = $this->service->purgePluginLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs', ['id' => $logId]);
    }

    public function test_purge_sql_logs(): void
    {
        $logId = DB::table('logs_sql')->insertGetId([
            'date' => now()->subHours(8),
            'session' => 'test-session',
            'request' => 'SELECT 1',
            'response' => 'OK',
        ]);

        $count = $this->service->purgeSqlLogs();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('logs_sql', ['id' => $logId]);
    }

    public function test_purge_user_activity_logs(): void
    {
        $user = $this->createTestUser();

        DB::table('users_active')->insert([
            'userid' => $user->id,
            'timestamp' => now()->subDays(800),
        ]);

        $count = $this->service->purgeUserActivityLogs();

        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_purge_all_logs_returns_all_keys(): void
    {
        $results = $this->service->purgeAllLogs();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('old_likes', $results);
        $this->assertArrayHasKey('login_logout_logs', $results);
        $this->assertArrayHasKey('user_deletion_logs', $results);
        $this->assertArrayHasKey('user_creation_logs', $results);
        $this->assertArrayHasKey('blank_subtype_logs', $results);
        $this->assertArrayHasKey('bounce_logs', $results);
        $this->assertArrayHasKey('old_bounce_emails', $results);
        $this->assertArrayHasKey('email_logs', $results);
        $this->assertArrayHasKey('non_freegle_group_logs', $results);
        $this->assertArrayHasKey('orphaned_message_logs', $results);
        $this->assertArrayHasKey('src_logs', $results);
        $this->assertArrayHasKey('js_error_logs', $results);
        $this->assertArrayHasKey('plugin_logs', $results);
        $this->assertArrayHasKey('sql_logs', $results);
        $this->assertArrayHasKey('user_activity_logs', $results);
        $this->assertArrayHasKey('orphaned_user_logs', $results);
    }
}
