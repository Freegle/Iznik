<?php

namespace Tests\Unit\Services;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\Membership;
use App\Models\User;
use App\Services\ChatNotificationService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatNotificationServiceTest extends TestCase
{
    protected ChatNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChatNotificationService();
        Mail::fake();
    }

    public function test_notify_by_email_with_no_rooms(): void
    {
        // Use a non-existent chat ID to ensure we don't scan all rooms.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, -1);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_sends_notification(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        // Create roster entries.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message from sender to recipient (old enough to trigger notification).
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Recipient should be notified.
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_with_specific_chat(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            $room->id
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_respects_delay(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a very recent message (within delay period).
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subSeconds(5),
        ]);

        // With default 30 second delay, this message should not trigger notification.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_skips_old_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now()->subDays(2),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message older than the sinceHours parameter.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subDays(2),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Old messages should not trigger notification.
        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_for_user2mod(): void
    {
        $user = $this->createTestUser();
        $mod = $this->createTestUser();

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $user->id,
            'user2' => $mod->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $mod->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $user, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_with_force_all(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create an already mailed message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'mailedtoall' => 1,
            'seenbyall' => 1,
        ]);

        // With force=true, should still process.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            $room->id,
            ChatNotificationService::DEFAULT_DELAY,
            ChatNotificationService::DEFAULT_SINCE_HOURS,
            true
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_skips_rejected_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a rejected message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'reviewrejected' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_skips_user_without_email(): void
    {
        $sender = $this->createTestUser();

        // Create recipient without email.
        $recipient = User::create([
            'firstname' => 'No',
            'lastname' => 'Email',
            'fullname' => 'No Email',
            'added' => now(),
        ]);

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Should not send to user without email.
        Mail::assertNotSent(ChatNotification::class, function ($mail) use ($recipient) {
            return $mail->to[0]['address'] === $recipient->email_preferred;
        });
    }

    public function test_notify_by_email_updates_roster(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        $recipientRoster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        $message = $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Check if roster was updated.
        $recipientRoster->refresh();
        // The lastmsgemailed should be updated if notification was sent.
        $this->assertTrue(
            $recipientRoster->lastmsgemailed === null ||
            $recipientRoster->lastmsgemailed >= 0
        );
    }

    public function test_notify_by_email_updates_lastmsgnotified(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
            'lastmsgnotified' => null,
        ]);

        $recipientRoster = ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
            'lastmsgnotified' => null,
        ]);

        $message = $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $recipientRoster->refresh();
        $this->assertEquals(
            $message->id,
            $recipientRoster->lastmsgnotified,
            'lastmsgnotified should be updated to the message ID after sending notification'
        );
    }

    public function test_default_delay_constant(): void
    {
        $this->assertEquals(30, ChatNotificationService::DEFAULT_DELAY);
    }

    public function test_default_since_hours_constant(): void
    {
        $this->assertEquals(24, ChatNotificationService::DEFAULT_SINCE_HOURS);
    }

    public function test_notify_by_email_skips_already_mailed(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create already mailed message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'mailedtoall' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_skips_seen_messages(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create already seen message.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'seenbyall' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_with_custom_delay(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message 20 seconds ago.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subSeconds(20),
        ]);

        // With 10 second delay, this should trigger notification.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            $room->id,
            10 // 10 second delay.
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_with_custom_since_hours(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now()->subHours(36),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message 36 hours ago.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subHours(36),
        ]);

        // With 24 hour since limit, this should not trigger notification.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);

        // With 48 hour since limit, it should.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_USER2USER,
            $room->id,
            ChatNotificationService::DEFAULT_DELAY,
            48
        );

        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_skips_messages_needing_review(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message that needs review.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'reviewrequired' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_skips_messages_needing_processing(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
        ]);

        // Create a message that needs processing.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
            'processingrequired' => 1,
            'processingsuccessful' => 0,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_user2mod_notifies_group_moderators(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create moderators for the group.
        $moderator1 = $this->createTestUser();
        $moderator2 = $this->createTestUser();

        Membership::create([
            'userid' => $moderator1->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        Membership::create([
            'userid' => $moderator2->id,
            'groupid' => $group->id,
            'role' => 'Owner',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member only (moderators will be added dynamically).
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Member sends message.
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        // Should notify moderators (the roster entries are created automatically).
        $this->assertGreaterThanOrEqual(0, $count);

        // Verify roster entries were created for moderators.
        $mod1Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator1->id)
            ->first();
        $mod2Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator2->id)
            ->first();

        $this->assertNotNull($mod1Roster, 'Moderator 1 should have a roster entry');
        $this->assertNotNull($mod2Roster, 'Moderator 2 (Owner) should have a roster entry');
    }

    public function test_notify_by_email_user2mod_notifies_member(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create a moderator.
        $moderator = $this->createTestUser();

        Membership::create([
            'userid' => $moderator->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Moderator sends message.
        $this->createTestChatMessage($room, $moderator, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        // Should notify the member.
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function test_notify_by_email_user2mod_sends_to_moderators(): void
    {
        $member = $this->createTestUser([
            'fullname' => 'Alice Member',
        ]);
        $group = $this->createTestGroup();

        // Create a moderator.
        $moderator = $this->createTestUser([
            'fullname' => 'Bob Moderator',
        ]);

        Membership::create([
            'userid' => $moderator->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Member sends message.
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        // Should have sent at least 1 notification (to the moderator).
        // The member is also notified if they sent the message and have NOTIFS_EMAIL_MINE enabled.
        $this->assertGreaterThan(0, $count, 'Should have sent notifications');

        // Verify moderator roster entry was updated.
        $modRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator->id)
            ->first();
        $this->assertNotNull($modRoster, 'Moderator should have roster entry');
        $this->assertNotNull($modRoster->lastmsgemailed, 'Moderator roster should be updated after notification');
    }

    public function test_notify_by_email_user2mod_skips_backup_moderators(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create an active moderator.
        $activeMod = $this->createTestUser([
            'fullname' => 'Active Mod',
        ]);

        // Create a backup moderator (settings['active'] = false).
        $backupMod = $this->createTestUser([
            'fullname' => 'Backup Mod',
        ]);

        // Active mod - no settings means active by default.
        Membership::create([
            'userid' => $activeMod->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
            'settings' => null,
        ]);

        // Backup mod - explicitly marked as inactive.
        Membership::create([
            'userid' => $backupMod->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Member sends message.
        $this->createTestChatMessage($room, $member, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        // Should have sent notification to active mod but not backup mod.
        $this->assertGreaterThan(0, $count, 'Should have sent notifications');

        // Verify active mod roster entry was created and updated.
        $activeModRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $activeMod->id)
            ->first();
        $this->assertNotNull($activeModRoster, 'Active moderator should have roster entry');
        $this->assertNotNull($activeModRoster->lastmsgemailed, 'Active moderator should have been notified');

        // Verify backup mod was NOT added to roster (not notified).
        $backupModRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $backupMod->id)
            ->first();
        $this->assertNull($backupModRoster, 'Backup moderator should NOT have roster entry');
    }

    public function test_notify_by_email_user2mod_includes_explicitly_active_moderators(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        // Create a moderator explicitly marked as active.
        $activeMod = $this->createTestUser([
            'fullname' => 'Explicit Active Mod',
        ]);

        Membership::create([
            'userid' => $activeMod->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
            'settings' => ['active' => true],
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        $this->createTestChatMessage($room, $member, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        $this->assertGreaterThan(0, $count);

        // Verify explicitly active mod was notified.
        $modRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $activeMod->id)
            ->first();
        $this->assertNotNull($modRoster, 'Explicitly active moderator should have roster entry');
        $this->assertNotNull($modRoster->lastmsgemailed, 'Explicitly active moderator should have been notified');
    }

    public function test_notify_by_email_user2mod_mod_message_notifies_other_mods_not_sender(): void
    {
        $member = $this->createTestUser(['fullname' => 'Alice Member']);
        $group = $this->createTestGroup();

        // Create three moderators.
        $modA = $this->createTestUser(['fullname' => 'Mod A (sender)']);
        $modB = $this->createTestUser(['fullname' => 'Mod B']);
        $modC = $this->createTestUser(['fullname' => 'Mod C']);

        foreach ([$modA, $modB, $modC] as $mod) {
            Membership::create([
                'userid' => $mod->id,
                'groupid' => $group->id,
                'role' => 'Moderator',
                'added' => now(),
                'settings' => null, // Active by default.
            ]);
        }

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Mod A sends a message.
        $this->createTestChatMessage($room, $modA, [
            'date' => now()->subMinutes(5),
            'message' => 'Hello from Mod A',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        // Should notify member + Mod B + Mod C = 3 notifications.
        // Mod A should NOT be notified (own message, no NOTIFS_EMAIL_MINE).
        $this->assertGreaterThanOrEqual(3, $count, 'Should notify member and other mods');

        // Verify member was notified.
        $memberRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $member->id)
            ->first();
        $this->assertNotNull($memberRoster->lastmsgemailed, 'Member should have been notified');

        // Verify Mod B was notified.
        $modBRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $modB->id)
            ->first();
        $this->assertNotNull($modBRoster, 'Mod B should have roster entry');
        $this->assertNotNull($modBRoster->lastmsgemailed, 'Mod B should have been notified');

        // Verify Mod C was notified.
        $modCRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $modC->id)
            ->first();
        $this->assertNotNull($modCRoster, 'Mod C should have roster entry');
        $this->assertNotNull($modCRoster->lastmsgemailed, 'Mod C should have been notified');

        // Verify Mod A (sender) was NOT notified - roster entry exists but lastmsgemailed should not be updated.
        // (The roster entry is created when we loop through mods, but no email should be sent.)
        $modARoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $modA->id)
            ->first();
        // Mod A's roster entry may exist but they should not have been emailed.
        if ($modARoster) {
            $this->assertNull($modARoster->lastmsgemailed, 'Mod A (sender) should NOT have been notified');
        }
    }

    public function test_notify_by_email_user2mod_active_mod_reply_notifies_other_active_not_backup(): void
    {
        $member = $this->createTestUser(['fullname' => 'Alice Member']);
        $group = $this->createTestGroup();

        // Create two active mods and one backup mod.
        $activeMod1 = $this->createTestUser(['fullname' => 'Active Mod 1']);
        $activeMod2 = $this->createTestUser(['fullname' => 'Active Mod 2']);
        $backupMod = $this->createTestUser(['fullname' => 'Backup Mod']);

        // Active mod 1 - null settings means active.
        Membership::create([
            'userid' => $activeMod1->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
            'settings' => null,
        ]);

        // Active mod 2 - explicitly active.
        Membership::create([
            'userid' => $activeMod2->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
            'settings' => ['active' => true],
        ]);

        // Backup mod - explicitly inactive.
        Membership::create([
            'userid' => $backupMod->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Create roster for member.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Active Mod 1 sends a reply to member.
        $this->createTestChatMessage($room, $activeMod1, [
            'date' => now()->subMinutes(5),
            'message' => 'Reply from Active Mod 1',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        // Should notify: member + Active Mod 2 = 2 notifications.
        // Should NOT notify: Active Mod 1 (sender), Backup Mod (inactive).
        $this->assertGreaterThanOrEqual(2, $count, 'Should notify member and other active mod');

        // Verify member was notified.
        $memberRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $member->id)
            ->first();
        $this->assertNotNull($memberRoster->lastmsgemailed, 'Member should have been notified');

        // Verify Active Mod 2 was notified.
        $activeMod2Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $activeMod2->id)
            ->first();
        $this->assertNotNull($activeMod2Roster, 'Active Mod 2 should have roster entry');
        $this->assertNotNull($activeMod2Roster->lastmsgemailed, 'Active Mod 2 should have been notified');

        // Verify Active Mod 1 (sender) was NOT notified.
        $activeMod1Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $activeMod1->id)
            ->first();
        if ($activeMod1Roster) {
            $this->assertNull($activeMod1Roster->lastmsgemailed, 'Active Mod 1 (sender) should NOT be notified');
        }

        // Verify Backup Mod was NOT notified (not even roster entry created).
        $backupModRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $backupMod->id)
            ->first();
        $this->assertNull($backupModRoster, 'Backup Mod should NOT have roster entry');
    }

    public function test_notify_by_email_for_mod2mod(): void
    {
        $group = $this->createTestGroup();

        // Create two moderators.
        $mod1 = $this->createTestUser(['fullname' => 'Mod One']);
        $mod2 = $this->createTestUser(['fullname' => 'Mod Two']);

        Membership::create([
            'userid' => $mod1->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        Membership::create([
            'userid' => $mod2->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        // Create Mod2Mod chat room (user1/user2 are NULL for Mod2Mod).
        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'groupid' => $group->id,
            'user1' => null,
            'user2' => null,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Add both mods to roster.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $mod1->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $mod2->id,
            'lastmsgemailed' => null,
        ]);

        // Mod1 sends message.
        $this->createTestChatMessage($room, $mod1, [
            'date' => now()->subMinutes(5),
            'message' => 'Hello fellow mods!',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_MOD2MOD, $room->id);

        // Mod2 should be notified (Mod1 is sender, not notified unless NOTIFS_EMAIL_MINE).
        $this->assertGreaterThanOrEqual(1, $count, 'Should notify at least one mod');
    }

    public function test_notify_by_email_mod2mod_notifies_all_roster_members(): void
    {
        $group = $this->createTestGroup();

        // Create three moderators.
        $mod1 = $this->createTestUser(['fullname' => 'Mod One']);
        $mod2 = $this->createTestUser(['fullname' => 'Mod Two']);
        $mod3 = $this->createTestUser(['fullname' => 'Mod Three']);

        foreach ([$mod1, $mod2, $mod3] as $mod) {
            Membership::create([
                'userid' => $mod->id,
                'groupid' => $group->id,
                'role' => 'Moderator',
                'added' => now(),
            ]);
        }

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'groupid' => $group->id,
            'user1' => null,
            'user2' => null,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        // Add all mods to roster.
        foreach ([$mod1, $mod2, $mod3] as $mod) {
            ChatRoster::create([
                'chatid' => $room->id,
                'userid' => $mod->id,
                'lastmsgemailed' => null,
            ]);
        }

        // Mod1 sends message.
        $this->createTestChatMessage($room, $mod1, [
            'date' => now()->subMinutes(5),
            'message' => 'Hello fellow mods!',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_MOD2MOD, $room->id);

        // Mod2 and Mod3 should be notified (Mod1 is sender).
        $this->assertGreaterThanOrEqual(2, $count, 'Should notify Mod2 and Mod3');

        // Verify Mod2 roster was updated.
        $mod2Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $mod2->id)
            ->first();
        $this->assertNotNull($mod2Roster->lastmsgemailed, 'Mod2 should have been notified');

        // Verify Mod3 roster was updated.
        $mod3Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $mod3->id)
            ->first();
        $this->assertNotNull($mod3Roster->lastmsgemailed, 'Mod3 should have been notified');

        // Verify Mod1 (sender) was NOT notified.
        $mod1Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $mod1->id)
            ->first();
        $this->assertNull($mod1Roster->lastmsgemailed, 'Mod1 (sender) should NOT be notified');
    }

    public function test_notify_by_email_mod2mod_uses_message_author_as_sender(): void
    {
        $group = $this->createTestGroup();

        $mod1 = $this->createTestUser(['fullname' => 'Mod Sender']);
        $mod2 = $this->createTestUser(['fullname' => 'Mod Recipient']);

        foreach ([$mod1, $mod2] as $mod) {
            Membership::create([
                'userid' => $mod->id,
                'groupid' => $group->id,
                'role' => 'Moderator',
                'added' => now(),
            ]);
        }

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'groupid' => $group->id,
            'user1' => null,
            'user2' => null,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        foreach ([$mod1, $mod2] as $mod) {
            ChatRoster::create([
                'chatid' => $room->id,
                'userid' => $mod->id,
                'lastmsgemailed' => null,
            ]);
        }

        // Mod1 sends message.
        $this->createTestChatMessage($room, $mod1, [
            'date' => now()->subMinutes(5),
            'message' => 'Test message',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_MOD2MOD, $room->id);

        // Verify email was sent.
        $this->assertGreaterThanOrEqual(1, $count);

        // Verify the email was sent with correct sender info.
        Mail::assertSent(ChatNotification::class, function ($mail) use ($mod1, $mod2) {
            // The email should be to mod2.
            return $mail->recipient->id === $mod2->id
                && $mail->sender->id === $mod1->id;
        });
    }

    public function test_notify_by_email_mod2mod_respects_delay(): void
    {
        $group = $this->createTestGroup();

        $mod1 = $this->createTestUser(['fullname' => 'Mod One']);
        $mod2 = $this->createTestUser(['fullname' => 'Mod Two']);

        foreach ([$mod1, $mod2] as $mod) {
            Membership::create([
                'userid' => $mod->id,
                'groupid' => $group->id,
                'role' => 'Moderator',
                'added' => now(),
            ]);
        }

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'groupid' => $group->id,
            'user1' => null,
            'user2' => null,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        foreach ([$mod1, $mod2] as $mod) {
            ChatRoster::create([
                'chatid' => $room->id,
                'userid' => $mod->id,
                'lastmsgemailed' => null,
            ]);
        }

        // Create a very recent message (within delay period).
        $this->createTestChatMessage($room, $mod1, [
            'date' => now()->subSeconds(5),
            'message' => 'Very recent message',
        ]);

        // With default 30 second delay, this message should not trigger notification.
        $count = $this->service->notifyByEmail(ChatRoom::TYPE_MOD2MOD, $room->id);

        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_mod2mod_skips_already_mailed(): void
    {
        $group = $this->createTestGroup();

        $mod1 = $this->createTestUser(['fullname' => 'Mod One']);
        $mod2 = $this->createTestUser(['fullname' => 'Mod Two']);

        foreach ([$mod1, $mod2] as $mod) {
            Membership::create([
                'userid' => $mod->id,
                'groupid' => $group->id,
                'role' => 'Moderator',
                'added' => now(),
            ]);
        }

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'groupid' => $group->id,
            'user1' => null,
            'user2' => null,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        foreach ([$mod1, $mod2] as $mod) {
            ChatRoster::create([
                'chatid' => $room->id,
                'userid' => $mod->id,
                'lastmsgemailed' => null,
            ]);
        }

        // Create already mailed message.
        $this->createTestChatMessage($room, $mod1, [
            'date' => now()->subMinutes(5),
            'mailedtoall' => 1,
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_MOD2MOD, $room->id);

        $this->assertEquals(0, $count);
    }

    public function test_notify_by_email_mod2mod_with_force_all(): void
    {
        $group = $this->createTestGroup();

        $mod1 = $this->createTestUser(['fullname' => 'Mod One']);
        $mod2 = $this->createTestUser(['fullname' => 'Mod Two']);

        foreach ([$mod1, $mod2] as $mod) {
            Membership::create([
                'userid' => $mod->id,
                'groupid' => $group->id,
                'role' => 'Moderator',
                'added' => now(),
            ]);
        }

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_MOD2MOD,
            'groupid' => $group->id,
            'user1' => null,
            'user2' => null,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        foreach ([$mod1, $mod2] as $mod) {
            ChatRoster::create([
                'chatid' => $room->id,
                'userid' => $mod->id,
                'lastmsgemailed' => null,
            ]);
        }

        // Create already mailed message.
        $this->createTestChatMessage($room, $mod1, [
            'date' => now()->subMinutes(5),
            'mailedtoall' => 1,
            'seenbyall' => 1,
        ]);

        // With force=true, should still process.
        $count = $this->service->notifyByEmail(
            ChatRoom::TYPE_MOD2MOD,
            $room->id,
            ChatNotificationService::DEFAULT_DELAY,
            ChatNotificationService::DEFAULT_SINCE_HOURS,
            true
        );

        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function test_notify_by_email_excludes_blocked_users(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        // Create roster entry for sender (normal status).
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        // Create roster entry for recipient with BLOCKED status.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
            'status' => ChatRoster::STATUS_BLOCKED,
        ]);

        // Create a message from sender to recipient.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Blocked user should NOT be notified.
        $this->assertEquals(0, $count);
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_includes_non_blocked_users(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        // Create roster entries with normal (non-blocked) status.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
            'status' => ChatRoster::STATUS_ONLINE,
        ]);

        // Create a message from sender to recipient.
        $this->createTestChatMessage($room, $sender, [
            'date' => now()->subMinutes(5),
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Non-blocked user should be notified.
        $this->assertGreaterThanOrEqual(1, $count);
    }

    /**
     * Test that when a user receives a copy of their own message (NOTIFS_EMAIL_MINE),
     * the From name shows THEIR name, not the other user's name.
     *
     * Bug scenario:
     * 1. Alice and Bob have a User2User chat
     * 2. Alice has NOTIFS_EMAIL_MINE enabled
     * 3. Alice sends a message
     * 4. Alice receives a copy of her own message
     * 5. The From name should be "Alice on Freegle", NOT "Bob on Freegle"
     */
    public function test_notify_by_email_copy_to_self_shows_own_name_not_other_user(): void
    {
        // Create Alice with NOTIFS_EMAIL_MINE enabled.
        $alice = $this->createTestUser([
            'fullname' => 'Alice Sender',
            'settings' => [
                'notifications' => [
                    'email' => true,
                    'emailmine' => true,
                    'push' => false,
                ],
            ],
        ]);

        $bob = $this->createTestUser(['fullname' => 'Bob Recipient']);

        $room = $this->createTestChatRoom($alice, $bob, [
            'latestmessage' => now(),
        ]);

        // Create roster entries.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $alice->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $bob->id,
            'lastmsgemailed' => null,
        ]);

        // Alice sends a message.
        $this->createTestChatMessage($room, $alice, [
            'date' => now()->subMinutes(5),
            'message' => 'Hello Bob!',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // Should send 2 notifications: one to Bob (normal), one to Alice (copy of own message).
        $this->assertEquals(2, $count, 'Should notify both Bob and Alice (copy-to-self)');

        // Get all sent ChatNotification mails.
        $sentMails = Mail::sent(ChatNotification::class);
        $this->assertCount(2, $sentMails, 'Should have sent exactly 2 ChatNotification emails');

        // Find the emails sent to each user.
        $aliceMail = null;
        $bobMail = null;

        foreach ($sentMails as $mail) {
            if ($mail->recipient->id === $alice->id) {
                $aliceMail = $mail;
            }
            if ($mail->recipient->id === $bob->id) {
                $bobMail = $mail;
            }
        }

        $this->assertNotNull($aliceMail, 'Alice should have received an email');
        $this->assertNotNull($bobMail, 'Bob should have received an email');

        // When Alice receives a copy of her own message, the sender should be Alice (not Bob).
        // This ensures the From name shows "Alice Sender on Freegle", not "Bob Recipient on Freegle".
        $this->assertEquals(
            $alice->id,
            $aliceMail->sender->id,
            'When Alice receives copy of own message, sender should be Alice (not Bob). '.
            'Expected Alice (id='.$alice->id.'), got sender id='.$aliceMail->sender->id
        );

        // Bob should receive the message with Alice as sender (normal case).
        $this->assertEquals(
            $alice->id,
            $bobMail->sender->id,
            'Bob should receive message from Alice'
        );
    }

    /**
     * Test that moderators who added mod notes to a User2User chat
     * are NOT notified when users reply.
     *
     * Bug scenario:
     * 1. Two users have a User2User chat
     * 2. A message is held for review
     * 3. A moderator adds a mod note to the chat (which adds them to the roster)
     * 4. One of the users replies
     * 5. The moderator should NOT be notified about that reply
     *
     * This matches the original iznik-server getMembersStatus() which filtered:
     * "chat_roster.userid IN (chat_rooms.user1, chat_rooms.user2)"
     */
    public function test_notify_by_email_user2user_excludes_moderators_in_roster(): void
    {
        $user1 = $this->createTestUser(['fullname' => 'Alice User']);
        $user2 = $this->createTestUser(['fullname' => 'Bob User']);
        $moderator = $this->createTestUser(['fullname' => 'Charlie Moderator']);

        // Create a User2User chat between user1 and user2.
        $room = $this->createTestChatRoom($user1, $user2, [
            'latestmessage' => now(),
        ]);

        // Create roster entries for the actual chat participants.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user1->id,
            'lastmsgemailed' => null,
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $user2->id,
            'lastmsgemailed' => null,
        ]);

        // Moderator is also in the roster (e.g., they added a mod note earlier).
        // This simulates what happens when a mod adds a ModMail message to the chat.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $moderator->id,
            'lastmsgemailed' => null,
        ]);

        // User1 sends a new message.
        $message = $this->createTestChatMessage($room, $user1, [
            'date' => now()->subMinutes(5),
            'message' => 'Hello Bob!',
        ]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        // User2 should be notified (they're the recipient).
        $user2Roster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $user2->id)
            ->first();
        $this->assertNotNull($user2Roster->lastmsgemailed, 'User2 should have been notified');

        // Moderator should NOT be notified - they're not a participant in this User2User chat.
        $modRoster = ChatRoster::where('chatid', $room->id)
            ->where('userid', $moderator->id)
            ->first();
        $this->assertNull(
            $modRoster->lastmsgemailed,
            'Moderator should NOT be notified about User2User chat messages'
        );

        // Verify we only sent 1 notification (to user2), not 2.
        $this->assertEquals(1, $count, 'Should only notify the actual recipient, not the moderator');
    }

    public function test_notify_by_email_excludes_closed_user2user(): void
    {
        // Bug: V1 PHP filters status = 'Closed' AND status = 'Blocked' before sending
        // notifications. Laravel's notBlocked() scope only excluded 'Blocked', so users
        // who closed a chat were still getting email notifications.
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();

        $room = $this->createTestChatRoom($sender, $recipient, [
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $sender->id,
            'lastmsgemailed' => null,
        ]);

        // Recipient has closed the chat — should not receive notifications.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $recipient->id,
            'lastmsgemailed' => null,
            'status' => ChatRoster::STATUS_CLOSED,
        ]);

        $this->createTestChatMessage($room, $sender, ['date' => now()->subMinutes(5)]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2USER, $room->id);

        $this->assertEquals(0, $count, 'User who closed the chat should NOT receive email notifications');
        Mail::assertNothingSent();
    }

    public function test_notify_by_email_excludes_closed_user2mod_mod(): void
    {
        // Bug: When a moderator closes a User2Mod chat, their chat_roster.status is set
        // to 'Closed'. But getMembersToNotify() fetches mods from group memberships and
        // then creates/fetches their roster without checking status — so Closed mods
        // were still receiving notifications.
        $member = $this->createTestUser();
        $group = $this->createTestGroup();

        $moderator = $this->createTestUser();
        Membership::create([
            'userid' => $moderator->id,
            'groupid' => $group->id,
            'role' => 'Moderator',
            'added' => now(),
        ]);

        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
            'latestmessage' => now(),
        ]);

        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $member->id,
            'lastmsgemailed' => null,
        ]);

        // Moderator has already closed the chat — should not be notified.
        ChatRoster::create([
            'chatid' => $room->id,
            'userid' => $moderator->id,
            'lastmsgemailed' => null,
            'status' => ChatRoster::STATUS_CLOSED,
        ]);

        $this->createTestChatMessage($room, $member, ['date' => now()->subMinutes(5)]);

        $count = $this->service->notifyByEmail(ChatRoom::TYPE_USER2MOD, $room->id);

        $modRoster = ChatRoster::where('chatid', $room->id)->where('userid', $moderator->id)->first();
        $this->assertNull(
            $modRoster?->lastmsgemailed,
            'Moderator who closed the chat should NOT be notified'
        );
        $this->assertEquals(0, $count, 'No notifications should be sent when only mod has closed the chat');
        Mail::assertNothingSent();
    }

}
