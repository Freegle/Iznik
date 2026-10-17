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

    private const SPAM_WATERMARK_KEY = 'purge_chats_spam_last_id';

    private function spamWatermark(): int
    {
        return (int) (DB::table('config')->where('key', self::SPAM_WATERMARK_KEY)->value('value') ?? 0);
    }

    private function setSpamWatermark(int $id): void
    {
        DB::table('config')->upsert(['key' => self::SPAM_WATERMARK_KEY, 'value' => (string) $id], ['key'], ['value']);
    }

    /** @return array{0: int, 1: int} [user id, chat room id] */
    private function spamTestRoom(): array
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $room = ChatRoom::create([
            'name' => 'Spam Room',
            'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id,
            'user2' => $user2->id,
        ]);

        return [$user1->id, $room->id];
    }

    private function spamMessage(int $userid, int $chatid, int $ageDays, bool $rejected): int
    {
        return ChatMessage::create([
            'chatid' => $chatid,
            'userid' => $userid,
            'message' => 'Spam',
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now()->subDays($ageDays),
            'reviewrejected' => $rejected ? 1 : 0,
        ])->id;
    }

    public function test_spam_purge_advances_watermark_to_trail_boundary(): void
    {
        [$user, $room] = $this->spamTestRoom();
        $this->setSpamWatermark(0);

        $veryOld = $this->spamMessage($user, $room, 30, false);
        $oldEnough = $this->spamMessage($user, $room, 20, false);
        $inTrail = $this->spamMessage($user, $room, 10, false);
        $recent = $this->spamMessage($user, $room, 1, false);

        $this->service->purgeSpamChatMessages();

        // Advances over everything older than cutoff + trail (7 + 7 days), and not a row further.
        $this->assertGreaterThanOrEqual($oldEnough, $this->spamWatermark());
        $this->assertLessThan($inTrail, $this->spamWatermark());
        $this->assertLessThan($recent, $this->spamWatermark());
        $this->assertGreaterThanOrEqual($veryOld, $this->spamWatermark());
    }

    public function test_spam_purge_does_not_rescan_below_the_watermark(): void
    {
        [$user, $room] = $this->spamTestRoom();

        $below = $this->spamMessage($user, $room, 30, true);
        $above = $this->spamMessage($user, $room, 30, true);
        $this->setSpamWatermark($below);

        $count = $this->service->purgeSpamChatMessages();

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('chat_messages', ['id' => $below]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $above]);
    }

    public function test_spam_purge_still_purges_a_message_rejected_late_inside_the_trail(): void
    {
        [$user, $room] = $this->spamTestRoom();
        $this->setSpamWatermark(0);

        // Older than the 7 day cutoff but inside the trail: not yet rejected on the first night.
        $late = $this->spamMessage($user, $room, 9, false);
        $this->service->purgeSpamChatMessages();

        $this->assertLessThan($late, $this->spamWatermark());
        $this->assertDatabaseHas('chat_messages', ['id' => $late]);

        // The auto-reject of stale review items lands after the watermark pass.
        DB::table('chat_messages')->where('id', $late)->update(['reviewrejected' => 1]);

        $this->assertEquals(1, $this->service->purgeSpamChatMessages());
        $this->assertDatabaseMissing('chat_messages', ['id' => $late]);
    }

    public function test_spam_purge_also_clears_rejected_messages_of_spammers_below_the_watermark(): void
    {
        [$user, $room] = $this->spamTestRoom();

        // Marking a user as a spammer rejects ALL their messages, whatever their age.
        $old = $this->spamMessage($user, $room, 40, true);
        DB::table('spam_users')->insert([
            'userid' => $user,
            'collection' => 'Spammer',
            'added' => now(),
        ]);
        $this->setSpamWatermark($old + 1000000);

        $this->assertEquals(1, $this->service->purgeSpamChatMessages());
        $this->assertDatabaseMissing('chat_messages', ['id' => $old]);
    }

    public function test_spam_purge_stops_when_a_chunk_comes_back_short(): void
    {
        [$user, $room] = $this->spamTestRoom();
        $this->setSpamWatermark(0);
        $this->spamMessage($user, $room, 30, true);
        $this->spamMessage($user, $room, 30, true);

        $deletes = 0;
        DB::listen(function ($query) use (&$deletes) {
            if (stripos($query->sql, 'delete from `chat_messages`') === 0) {
                $deletes++;
            }
        });

        $this->assertEquals(2, $this->service->purgeSpamChatMessages());

        // Two rows fit in one chunk, so one DELETE; the old loop issued a second, empty one.
        $this->assertEquals(1, $deletes);
    }

    public function test_spam_purge_walks_several_windows_and_chunks(): void
    {
        [$user, $room] = $this->spamTestRoom();
        $this->setSpamWatermark(0);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->spamMessage($user, $room, 30, true);
            $this->spamMessage($user, $room, 30, false);
        }

        $service = new class extends PurgeService {
            public function __construct()
            {
                $this->chunkSize = 2;
                $this->spamScanWindow = 3;
            }
        };

        $this->assertEquals(5, $service->purgeSpamChatMessages());
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('chat_messages', ['id' => $id]);
        }
    }

    public function test_spam_purge_dry_run_counts_without_deleting_or_moving_the_watermark(): void
    {
        [$user, $room] = $this->spamTestRoom();
        $this->setSpamWatermark(0);
        $id = $this->spamMessage($user, $room, 30, true);

        $this->assertEquals(1, $this->service->purgeSpamChatMessages(7, true));
        $this->assertDatabaseHas('chat_messages', ['id' => $id]);
        $this->assertEquals(0, $this->spamWatermark());
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

    public function test_purge_all_logs_purges_email_tracking_at_30_days(): void
    {
        // email_tracking is now purged by the daily log purge (purge:logs ->
        // purgeAllLogs). Previously it was only in the unscheduled purge:all, so
        // the table grew unbounded and the stats endpoints timed out. Retention
        // is 30 days.
        $old = EmailTracking::create([
            'tracking_id' => EmailTracking::generateTrackingId(),
            'email_type' => 'Test',
            'recipient_email' => $this->uniqueEmail('alog-old'),
            'sent_at' => now()->subDays(35),
        ]);
        $recent = EmailTracking::create([
            'tracking_id' => EmailTracking::generateTrackingId(),
            'email_type' => 'Test',
            'recipient_email' => $this->uniqueEmail('alog-recent'),
            'sent_at' => now()->subDays(25),
        ]);

        $results = $this->service->purgeAllLogs();

        $this->assertArrayHasKey('email_tracking', $results);
        $this->assertDatabaseMissing('email_tracking', ['id' => $old->id]);
        $this->assertDatabaseHas('email_tracking', ['id' => $recent->id]);
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

    /**
     * Set the service's chunk size for a test. Reflection rather than a setter so the
     * production class does not grow an API that only tests use.
     */
    private function setChunkSize(int $size): void
    {
        $prop = new \ReflectionProperty(PurgeService::class, 'chunkSize');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $size);
    }

    /**
     * Record the SQL of every chunk SELECT the given callback runs against `logs`.
     *
     * @return array<int, array{sql: string, bindings: array}>
     */
    private function captureChunkSelects(string $needle, callable $fn): array
    {
        $seen = [];
        DB::listen(function ($query) use (&$seen, $needle) {
            if (stripos($query->sql, 'select') === 0 && stripos($query->sql, $needle) !== false) {
                $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $fn();

        return $seen;
    }

    /**
     * The orphan-log purge must resume from the last id it saw rather than re-running the
     * whole anti-join for every chunk. Without a watermark each chunk rescans the table from
     * the start, so the run is O(chunks x table) and gets slower as it nears completion -
     * measured on production at 2.9M rows read per row deleted.
     */
    public function test_purge_orphaned_user_logs_resumes_from_a_watermark(): void
    {
        $missingUser = 2147483000;

        $orphans = [];
        for ($i = 0; $i < 3; $i++) {
            $orphans[] = DB::table('logs')->insertGetId([
                'type' => 'User',
                'subtype' => 'Login',
                'user' => $missingUser + $i,
                'timestamp' => now()->subDays(400),
            ]);
        }

        $this->setChunkSize(1);

        $selects = $this->captureChunkSelects('left join users', function () {
            $this->service->purgeOrphanedUserLogs();
        });

        $this->assertGreaterThanOrEqual(2, count($selects), 'expected one chunk SELECT per orphan plus a terminating one');

        $watermarks = [];
        foreach ($selects as $select) {
            $this->assertMatchesRegularExpression(
                '/logs\.id\s*>\s*\?/i',
                $select['sql'],
                'each chunk must be bounded by an id watermark so it resumes instead of rescanning'
            );

            $numeric = array_values(array_filter($select['bindings'], 'is_int'));
            $this->assertNotEmpty($numeric, 'the watermark binding must be present');
            $watermarks[] = $numeric[0];
        }

        $sorted = $watermarks;
        sort($sorted);
        $this->assertSame($sorted, $watermarks, 'the watermark must not go backwards');
        $this->assertSame(count(array_unique($watermarks)), count($watermarks), 'each chunk must start after the previous one ended');

        foreach ($orphans as $orphan) {
            $this->assertDatabaseMissing('logs', ['id' => $orphan]);
        }
    }

    /**
     * The watermark must not skip work: orphans separated by rows that are not orphans must
     * all still be deleted, and the rows in between must survive.
     */
    public function test_purge_orphaned_user_logs_deletes_orphans_interleaved_with_live_rows(): void
    {
        $user = $this->createTestUser();
        $missingUser = 2147483100;

        $first = DB::table('logs')->insertGetId([
            'type' => 'User', 'subtype' => 'Login', 'user' => $missingUser,
            'timestamp' => now()->subDays(400),
        ]);
        $keep = DB::table('logs')->insertGetId([
            'type' => 'User', 'subtype' => 'Login', 'user' => $user->id,
            'timestamp' => now()->subDays(400),
        ]);
        $second = DB::table('logs')->insertGetId([
            'type' => 'User', 'subtype' => 'Login', 'user' => $missingUser + 1,
            'timestamp' => now()->subDays(400),
        ]);

        $this->setChunkSize(1);
        $this->service->purgeOrphanedUserLogs();

        $this->assertDatabaseMissing('logs', ['id' => $first]);
        $this->assertDatabaseMissing('logs', ['id' => $second]);
        $this->assertDatabaseHas('logs', ['id' => $keep]);
    }

    /**
     * Recent orphans must be left alone - the cutoff is what keeps the purge to old rows.
     */
    public function test_purge_orphaned_user_logs_leaves_recent_orphans(): void
    {
        $recent = DB::table('logs')->insertGetId([
            'type' => 'User', 'subtype' => 'Login', 'user' => 2147483200,
            'timestamp' => now()->subDays(1),
        ]);

        $this->service->purgeOrphanedUserLogs();

        $this->assertDatabaseHas('logs', ['id' => $recent]);
    }

    /**
     * Same defect as the orphan-log purge: the empty-room anti-join drives a 3.8M-row range
     * scan on `typelatest`, and without a watermark every chunk repeats it from the start.
     */
    public function test_purge_empty_chat_rooms_resumes_from_a_watermark(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        ChatRoom::create([
            'name' => 'Empty A', 'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id, 'user2' => $user2->id,
        ]);
        ChatRoom::create([
            'name' => 'Empty B', 'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id, 'user2' => $user3->id,
        ]);

        $this->setChunkSize(1);

        $selects = $this->captureChunkSelects('chat_messages', function () {
            $this->service->purgeEmptyChatRooms();
        });

        $chunkSelects = array_values(array_filter($selects, fn ($q) => stripos($q['sql'], 'left join') !== false));
        $this->assertGreaterThanOrEqual(2, count($chunkSelects));

        $watermarks = [];
        foreach ($chunkSelects as $select) {
            $this->assertMatchesRegularExpression(
                '/`?chat_rooms`?\.`?id`?\s*>\s*\?/i',
                $select['sql'],
                'each chunk must be bounded by an id watermark so it resumes instead of rescanning'
            );

            $numeric = array_values(array_filter($select['bindings'], 'is_int'));
            $this->assertNotEmpty($numeric, 'the watermark binding must be present');
            $watermarks[] = end($numeric);
        }

        $sorted = $watermarks;
        sort($sorted);
        $this->assertSame($sorted, $watermarks, 'the watermark must not go backwards');
        $this->assertSame(count(array_unique($watermarks)), count($watermarks), 'each chunk must start after the previous one ended');
    }

    /**
     * The watermark must not skip empty rooms that sit after a room which has messages.
     */
    public function test_purge_empty_chat_rooms_deletes_rooms_interleaved_with_busy_rooms(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();
        $user4 = $this->createTestUser();

        $firstEmpty = ChatRoom::create([
            'name' => 'Empty first', 'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id, 'user2' => $user2->id,
        ]);

        $busy = ChatRoom::create([
            'name' => 'Busy', 'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id, 'user2' => $user3->id,
        ]);
        ChatMessage::create([
            'chatid' => $busy->id, 'userid' => $user1->id, 'message' => 'Hello',
            'type' => ChatMessage::TYPE_DEFAULT, 'date' => now(),
        ]);

        $lastEmpty = ChatRoom::create([
            'name' => 'Empty last', 'chattype' => ChatRoom::TYPE_USER2USER,
            'user1' => $user1->id, 'user2' => $user4->id,
        ]);

        $this->setChunkSize(1);
        $this->service->purgeEmptyChatRooms();

        $this->assertDatabaseMissing('chat_rooms', ['id' => $firstEmpty->id]);
        $this->assertDatabaseMissing('chat_rooms', ['id' => $lastEmpty->id]);
        $this->assertDatabaseHas('chat_rooms', ['id' => $busy->id]);
    }

    public function test_purge_assistant_instances_removes_only_idle_ones(): void
    {
        $stale = DB::table('assistant_instances')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'owner' => 'a:stale',
            'workflow' => 'freegle',
            'state' => 'HUB',
            'status' => 'active',
            'context' => '{}',
            'history' => '[]',
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);
        $fresh = DB::table('assistant_instances')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'owner' => 'a:fresh',
            'workflow' => 'freegle',
            'state' => 'GIVE_ITEM',
            'status' => 'active',
            'context' => '{}',
            'history' => '[]',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->assertGreaterThanOrEqual(1, $this->service->purgeAssistantInstances(30, dryRun: true));
        $this->assertDatabaseHas('assistant_instances', ['id' => $stale]);

        $count = $this->service->purgeAssistantInstances(30);

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertDatabaseMissing('assistant_instances', ['id' => $stale]);
        $this->assertDatabaseHas('assistant_instances', ['id' => $fresh]);
    }
}
