<?php

namespace Tests\Unit\Services;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for PushNotificationService::getBadgeCount().
 *
 * Each test creates its own mod + group + membership and only queries that mod's count.
 * Test isolation is provided by DatabaseTransactions in the base TestCase (each test
 * rolls back all DB changes). No shared state between tests.
 */
class PushNotificationServiceTest extends TestCase
{
    protected PushNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PushNotificationService();
    }

    /**
     * Held pending messages must NOT count towards the badge.
     *
     * Regression for Discourse #9547: mods reported a badge count of 1 but no
     * actionable work. Root cause: held pending messages were counted in the
     * badge but session.go's work total excludes heldby IS NOT NULL messages.
     */
    public function test_held_pending_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => $mod->id,  // held — must not count
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Held pending messages must not inflate badge count');
    }

    /**
     * Unheld pending messages DO count towards the badge.
     */
    public function test_unheld_pending_messages_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,  // not held — must count
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(1, $count, 'Unheld pending messages must count towards badge');
    }

    /**
     * Spam collection messages count towards the badge.
     *
     * Session.go uses COLLECTION_SPAM, not spamtype in Pending.
     */
    public function test_spam_collection_messages_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Spam (Location)',
            'textbody' => 'Spam',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_SPAM,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(1, $count, 'Spam collection messages must count towards badge');
    }

    /**
     * Deleted pending messages must NOT count towards the badge.
     */
    public function test_deleted_pending_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Deleted (Location)',
            'textbody' => 'Deleted',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 1,  // deleted — must not count
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Deleted messages must not inflate badge count');
    }

    /**
     * Messages with null fromuser must NOT count towards the badge.
     */
    public function test_null_fromuser_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $message = Message::create([
            'fromuser' => null,  // no sender — must not count
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: System (Location)',
            'textbody' => 'System',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Messages with null fromuser must not inflate badge count');
    }

    /**
     * Pending messages in inactive groups must NOT count towards the badge.
     *
     * Session.go excludes inactive group work from `total` (it goes to pendingother/blue).
     * A mod can set themselves inactive via membership settings.active=0.
     */
    public function test_inactive_group_pending_messages_do_not_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => 0],  // inactive — must not count (Membership model has array cast)
        ]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test (Location)',
            'textbody' => 'Test',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Inactive group pending messages must not inflate badge count');
    }

    public function test_pending_volunteering_ops_count_towards_badge(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Create a pending volunteering op linked to this group.
        $volunteeringId = DB::table('volunteering')->insertGetId([
            'pending' => 1,
            'deleted' => 0,
            'expired' => 0,
            'title' => 'Test volunteer op',
            'userid' => $mod->id,
        ]);
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volunteeringId,
            'groupid' => $group->id,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(1, $count, 'Pending volunteering op must count towards badge');
    }

    public function test_non_pending_volunteering_ops_do_not_count(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Approved (non-pending) volunteering op — should not inflate badge.
        $volunteeringId = DB::table('volunteering')->insertGetId([
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'title' => 'Approved op',
            'userid' => $mod->id,
        ]);
        DB::table('volunteering_groups')->insert([
            'volunteeringid' => $volunteeringId,
            'groupid' => $group->id,
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count, 'Non-pending volunteering op must not count towards badge');
    }

    /**
     * ModTools Android pushes must include a `notification` block.
     *
     * Without it, FCM hands a data-only push to the app's listener and the
     * notification never appears in the system tray — the bug that made
     * real mod-work pushes invisible while the forceVisible test push worked.
     */
    public function test_buildAndroidFcmMessage_includes_notification_block_for_modtools(): void
    {
        $payload = [
            'title' => '3 messages pending',
            'message' => 'Open ModTools to review',
            'channel_id' => 'modtools',
        ];

        $arr = $this->invokeBuildAndroidFcmMessage('tok-mt', $payload, false);

        $this->assertArrayHasKey('notification', $arr,
            'ModTools Android push must include a notification block so Android raises it in the tray');
        $this->assertSame('3 messages pending', $arr['notification']['title']);
        $this->assertSame('Open ModTools to review', $arr['notification']['body']);
        $this->assertSame('tok-mt', $arr['token']);
        $this->assertSame($payload, $arr['data'],
            'Existing data payload (channel_id, badge, etc.) must still be present');
    }

    /**
     * Non-modtools Android pushes without forceVisible stay data-only.
     *
     * This protects the user-app chat path (notifyIndividualMessages) which
     * relies on data-only messages with action buttons built by the app.
     */
    public function test_buildAndroidFcmMessage_omits_notification_block_for_non_modtools(): void
    {
        $payload = [
            'title' => 'New chat message',
            'message' => 'Hello',
            'channel_id' => 'chat_messages',
        ];

        $arr = $this->invokeBuildAndroidFcmMessage('tok-chat', $payload, false);

        $this->assertArrayNotHasKey('notification', $arr,
            'Non-modtools push (no forceVisible) must remain data-only');
    }

    /**
     * forceVisible (used by the test-push command) always adds the block,
     * regardless of channel.
     */
    public function test_buildAndroidFcmMessage_forceVisible_adds_notification_block(): void
    {
        $payload = [
            'title' => 'Test',
            'message' => 'Hello',
            'channel_id' => 'chat_messages',
        ];

        $arr = $this->invokeBuildAndroidFcmMessage('tok', $payload, true);

        $this->assertArrayHasKey('notification', $arr);
    }

    /**
     * Empty-title payload (e.g. zero-count modtools push to clear the badge)
     * must NOT add a notification block — we don't want an empty tray entry.
     */
    public function test_buildAndroidFcmMessage_skips_notification_block_when_title_empty(): void
    {
        $payload = [
            'title' => '',
            'message' => '',
            'channel_id' => 'modtools',
        ];

        $arr = $this->invokeBuildAndroidFcmMessage('tok', $payload, false);

        $this->assertArrayNotHasKey('notification', $arr,
            'Zero-count clear-badge pushes have empty title and must not show in the tray');
    }

    /**
     * Chitchat notifications (CommentOnYourPost in users_notifications) must never
     * inflate the modtools badge count.
     *
     * V1 Bug #9676: PushNotifications::notify($uid, TRUE) fired unconditionally when
     * $modtools=TRUE, and getNotificationPayload(TRUE) included ALL notification types
     * in $total, so a chitchat comment triggered a spurious "1 pending" modtools push.
     *
     * V2 fix: getBadgeCount() queries only messages_groups (pending/spam) and
     * volunteering — it never touches users_notifications. Chitchat activity therefore
     * cannot inflate the modtools badge.
     */
    public function test_getBadgeCount_not_inflated_by_chitchat_notification(): void
    {
        $mod = $this->createTestUser();
        $sender = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Insert a CommentOnYourPost notification (chitchat) for the mod — simulates a
        // newsfeed comment arriving while the mod has no pending modtools work.
        DB::table('users_notifications')->insert([
            'fromuser' => $sender->id,
            'touser' => $mod->id,
            'type' => 'CommentOnYourPost',
            'seen' => 0,
            'timestamp' => now(),
        ]);

        $count = $this->service->getBadgeCount($mod->id);

        $this->assertEquals(0, $count,
            'Chitchat (CommentOnYourPost) must not inflate the modtools badge — V2 fix for Discourse #9676');
    }

    /**
     * When no modtools work is pending, buildModToolsPayload returns a zero-count
     * badge-clearing payload with an empty title — no visible notification is raised
     * in the Android system tray (guarded by test_buildAndroidFcmMessage_skips_notification_block_when_title_empty).
     *
     * This confirms the full V2 chain: chitchat-only activity → badge=0 → title=''
     * → no notification block → no tray entry in ModTools app.
     */
    public function test_buildModToolsPayload_returns_zero_badge_when_no_modtools_work(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $method = new \ReflectionMethod($this->service, 'buildModToolsPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($this->service, $mod->id);

        $this->assertNotNull($payload, 'Payload must not be null (zero-count clears the badge)');
        $this->assertSame('0', $payload['badge'], 'Badge must be 0 when no modtools work pending');
        $this->assertSame('0', $payload['count'], 'Count must be 0 when no modtools work pending');
        $this->assertSame('', $payload['title'], 'Title must be empty so no tray notification is raised');
    }

    /**
     * Visible ModTools push: priority high and notification.tag set so the
     * latest "N pending" entry replaces the previous one in the tray.
     */
    public function test_buildAndroidConfig_visible_modtools_gets_high_priority_and_tag(): void
    {
        $payload = [
            'title' => '3 messages pending',
            'message' => 'Open ModTools to review',
            'channel_id' => 'modtools',
        ];

        $cfg = $this->invokeBuildAndroidConfig(123, $payload, false);

        $this->assertSame('high', $cfg['priority']);
        $this->assertSame(['tag' => 'modtools-123'], $cfg['notification']);
    }

    /**
     * Zero-work ModTools push (empty title) must be truly silent: data-only,
     * normal priority, no AndroidConfig.notification. Setting
     * AndroidConfig.notification on a data-only payload promotes it to a
     * notification message on some devices/Capacitor builds and surfaces an
     * empty tray entry — the bug we're fixing.
     */
    public function test_buildAndroidConfig_zero_count_modtools_is_silent(): void
    {
        $payload = [
            'title' => '',
            'message' => '',
            'channel_id' => 'modtools',
        ];

        $cfg = $this->invokeBuildAndroidConfig(123, $payload, false);

        $this->assertSame('normal', $cfg['priority'],
            'Silent badge-clear pushes should not wake the device with high priority');
        $this->assertArrayNotHasKey('notification', $cfg,
            'AndroidConfig.notification must be absent for data-only clear-badge pushes');
    }

    /**
     * forceVisible (test-push command) always rides high priority even for
     * non-modtools channels, but never gets the modtools tag.
     */
    public function test_buildAndroidConfig_forceVisible_high_priority_no_tag_for_non_modtools(): void
    {
        $payload = [
            'title' => 'Test',
            'message' => 'Hello',
            'channel_id' => 'chat_messages',
        ];

        $cfg = $this->invokeBuildAndroidConfig(123, $payload, true);

        $this->assertSame('high', $cfg['priority']);
        $this->assertArrayNotHasKey('notification', $cfg);
    }

    /**
     * Regression for Discourse #9692/10: when only spam (no pending) messages exist,
     * the notification must route to /modtools (the dashboard) and must NOT say "pending".
     */
    public function test_buildModToolsPayload_spam_only_routes_to_dashboard(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $sender = $this->createTestUser();
        $message = Message::create([
            'fromuser' => $sender->id,
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Spam Item (Location)',
            'textbody' => 'Spam',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
            'heldby' => null,
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_SPAM,
            'arrival' => now(),
            'deleted' => 0,
        ]);

        $method = new \ReflectionMethod($this->service, 'buildModToolsPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($this->service, $mod->id);

        // Badge must still reflect total work items
        $this->assertSame('1', $payload['badge'], 'Badge must count spam items');
        // CORRECT: no pending messages → must route to dashboard, not /messages/pending
        $this->assertSame('/modtools', $payload['route'],
            'When only spam exists (no pending messages), route must be /modtools not /messages/pending');
        $this->assertStringNotContainsString('pending', $payload['title'],
            'When only spam exists, title must not say "pending" — Discourse #9692');
    }

    private function invokeBuildAndroidFcmMessage(string $token, array $payload, bool $forceVisible): array
    {
        $method = new \ReflectionMethod($this->service, 'buildAndroidFcmMessage');
        $method->setAccessible(true);
        return $method->invoke($this->service, $token, $payload, $forceVisible);
    }

    private function invokeBuildAndroidConfig(int $userId, array $payload, bool $forceVisible): array
    {
        $method = new \ReflectionMethod($this->service, 'buildAndroidConfig');
        $method->setAccessible(true);
        return $method->invoke($this->service, $userId, $payload, $forceVisible);
    }

    // --- Chat message recipient computation (V1 parity tests) ---
    //
    // These pin the V1 ChatRoom::notifyMembers() target table from
    // iznik-server/include/chat/ChatRoom.php:1458-1521. Each test corresponds
    // to a cell in that table or an invariant ($excludeuser, getMemberships()>0).
    //
    // Tests target the new getChatMessageRecipients(messageId) method which
    // returns ['fd' => int[], 'mt' => int[]] — the FD-app and MT-app push
    // recipient sets respectively.

    public function test_u2u_returns_both_users_in_fd_recipients(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEqualsCanonicalizing([$recipient->id], $result['fd'],
            'U2U FD recipients = both users minus sender');
        $this->assertEquals([], $result['mt'],
            'U2U MT recipients empty when message not held for review');
    }

    public function test_u2u_excludes_sender_from_fd_recipients(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertNotContains($sender->id, $result['fd'],
            'Sender must never push to themselves (V1 $excludeuser)');
    }

    public function test_u2m_returns_user1_in_fd_and_active_mods_in_mt(): void
    {
        $member = $this->createTestUser();
        $modA = $this->createTestUser();
        $modB = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group);
        $this->createMembership($modA, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($modB, $group, ['role' => Membership::ROLE_OWNER]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $msg = $this->createTestChatMessage($room, $member);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEquals([], $result['fd'],
            'U2M FD recipients exclude the sender (member here)');
        $this->assertEqualsCanonicalizing([$modA->id, $modB->id], $result['mt'],
            'U2M MT recipients = all active group mods minus sender');
    }

    public function test_u2m_mod_sender_excluded_from_mt_recipients(): void
    {
        // Mod sends a message to a member in their own group → mod shouldn't
        // get a push notification about their own outgoing message.
        $member = $this->createTestUser();
        $modSender = $this->createTestUser();
        $modOther = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group);
        $this->createMembership($modSender, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($modOther, $group, ['role' => Membership::ROLE_MODERATOR]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $msg = $this->createTestChatMessage($room, $modSender);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEqualsCanonicalizing([$member->id], $result['fd'],
            'U2M FD recipient = member (user1) when mod is sender');
        $this->assertEqualsCanonicalizing([$modOther->id], $result['mt'],
            'Sender mod must be excluded from MT recipients (V1 $excludeuser)');
    }

    public function test_u2m_excludes_inactive_mods_from_mt(): void
    {
        $member = $this->createTestUser();
        $activeMod = $this->createTestUser();
        $inactiveMod = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($member, $group);
        $this->createMembership($activeMod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => 1],
        ]);
        $this->createMembership($inactiveMod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'settings' => ['active' => 0],
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $msg = $this->createTestChatMessage($room, $member);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEqualsCanonicalizing([$activeMod->id], $result['mt'],
            'Inactive mods (settings.active=0) must not receive MT push (V1 parity)');
    }

    public function test_recipient_who_blocked_chat_is_excluded(): void
    {
        // V1 notifyIndividualMessages filter: chat_roster.status = 'Blocked'
        // means the recipient blocked the conversation — no push for them.
        $sender = $this->createTestUser();
        $blocker = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($blocker, $group);

        $room = $this->createTestChatRoom($sender, $blocker);

        DB::table('chat_roster')->insert([
            'chatid' => $room->id,
            'userid' => $blocker->id,
            'status' => 'Blocked',
            'date' => now(),
        ]);

        $msg = $this->createTestChatMessage($room, $sender);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEquals([], $result['fd'],
            'Users who blocked this chat must not receive push (V1 parity)');
    }

    public function test_recipient_with_zero_memberships_is_excluded(): void
    {
        // V1: pokeMembers/notify only fires for users with getMemberships() > 0.
        // Stops ex-members and never-joined users from getting pushes.
        $sender = $this->createTestUser();
        $exMember = $this->createTestUser();  // no createMembership

        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);

        $room = $this->createTestChatRoom($sender, $exMember);
        $msg = $this->createTestChatMessage($room, $sender);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEquals([], $result['fd'],
            'Users with zero memberships must not be pushed (V1 invariant)');
    }

    public function test_held_for_review_message_returns_empty_recipients(): void
    {
        // V1: !$review gate. Even if enqueued by mistake, the handler must
        // double-check and refuse to push for reviewed content.
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender, ['reviewrequired' => 1]);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEquals([], $result['fd']);
        $this->assertEquals([], $result['mt']);
    }

    public function test_rejected_message_returns_empty_recipients(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);

        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender, ['reviewrejected' => 1]);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEquals([], $result['fd']);
        $this->assertEquals([], $result['mt']);
    }

    public function test_missing_message_returns_empty_recipients(): void
    {
        $result = $this->service->getChatMessageRecipients(9999999);

        $this->assertEquals([], $result['fd']);
        $this->assertEquals([], $result['mt']);
    }

    public function test_mod2mod_returns_no_fd_or_mt_recipients(): void
    {
        // V1 notifyMembers() has no case for Mod2Mod — only pokeMembers does.
        // Out of scope here; push side returns empty.
        $mod1 = $this->createTestUser();
        $mod2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod1, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createMembership($mod2, $group, ['role' => Membership::ROLE_MODERATOR]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'user1' => $mod1->id,
            'user2' => $mod2->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $msg = $this->createTestChatMessage($room, $mod1);

        $result = $this->service->getChatMessageRecipients($msg->id);

        $this->assertEquals([], $result['fd']);
        $this->assertEquals([], $result['mt']);
    }

    // --- Chat message payload structure (V1 parity tests) ---
    //
    // Pin the FCM payload shape that the mobile app expects, from V1
    // PushNotifications::notifyIndividualMessages (chat path) and
    // useMobileStore.handleNotification (iznik-nuxt3/stores/mobile.js).

    public function test_fd_chat_payload_uses_chat_messages_channel(): void
    {
        $sender = $this->createTestUser(['fullname' => 'Alice']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender, ['message' => 'Hello there']);

        $payload = $this->service->buildChatMessagePayload($msg->id, $recipient->id, FALSE);

        $this->assertEquals('chat_messages', $payload['channel_id'],
            'FD chat payload must use the chat_messages Android channel');
        $this->assertEquals('0', (string) $payload['modtools']);
    }

    public function test_mt_chat_payload_uses_modtools_channel(): void
    {
        $sender = $this->createTestUser(['fullname' => 'Alice']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender, ['message' => 'Hi']);

        $payload = $this->service->buildChatMessagePayload($msg->id, $recipient->id, TRUE);

        $this->assertEquals('modtools', $payload['channel_id']);
        $this->assertEquals('1', (string) $payload['modtools']);
    }

    public function test_chat_payload_uses_chatid_as_notid(): void
    {
        // V1: notId = chatid so a second message in the same chat REPLACES
        // the previous notification instead of stacking — prevents flooding.
        $sender = $this->createTestUser(['fullname' => 'Alice']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender, ['message' => 'Hi']);

        $payload = $this->service->buildChatMessagePayload($msg->id, $recipient->id, FALSE);

        $this->assertEquals((string) $room->id, (string) $payload['notId']);
    }

    public function test_chat_payload_route_targets_specific_chat(): void
    {
        $sender = $this->createTestUser(['fullname' => 'Alice']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender, ['message' => 'Hi']);

        $payload = $this->service->buildChatMessagePayload($msg->id, $recipient->id, FALSE);

        $this->assertEquals('/chats/' . $room->id, $payload['route']);
        $this->assertEquals((string) $room->id, (string) $payload['chatid']);
        $this->assertEquals((string) $room->id, (string) $payload['chatids']);
    }

    public function test_chat_payload_truncates_long_message(): void
    {
        $sender = $this->createTestUser(['fullname' => 'Alice']);
        $recipient = $this->createTestUser();
        $room = $this->createTestChatRoom($sender, $recipient);
        $long = str_repeat('A', 500);
        $msg = $this->createTestChatMessage($room, $sender, ['message' => $long]);

        $payload = $this->service->buildChatMessagePayload($msg->id, $recipient->id, FALSE);

        $this->assertLessThanOrEqual(260, strlen($payload['message']),
            'Payload message should be truncated for push display (~256 chars)');
    }

    public function test_u2m_mod_to_member_payload_uses_group_volunteers_title(): void
    {
        // V1 hides individual mod identity from members: when a mod replies in
        // a User2Mod chat, the push title shows "{GroupName} Volunteers", not
        // the mod's personal display name. Matches the email notification.
        $member = $this->createTestUser(['fullname' => 'MemberAlice']);
        $mod = $this->createTestUser(['fullname' => 'ModBob']);
        $group = $this->createTestGroup();
        $this->createMembership($member, $group);
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $msg = $this->createTestChatMessage($room, $mod, ['message' => 'Hello from the team']);

        $payload = $this->service->buildChatMessagePayload($msg->id, $member->id, FALSE);

        $this->assertStringContainsString('Volunteers', $payload['title'],
            'Mod sender to member in U2M must show "{Group} Volunteers" as title');
        $this->assertStringNotContainsString('ModBob', $payload['title'],
            'Individual mod name must NOT leak to the member in push title');
    }
}
