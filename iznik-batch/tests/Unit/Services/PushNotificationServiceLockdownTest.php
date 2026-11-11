<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Services\Lockdown\LockdownService;
use App\Services\PushNotificationService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PushNotificationService under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.4): "entry points return early while held,
 * counted `push`". Ordinary (no lockdown) behaviour is covered by
 * PushNotificationServiceTest and DailyPostsPushTest; this file is only the lockdown
 * branch, on the four entry points that actually reach FCM: notify(), notifyUser(),
 * notifyChatMessage() and notifyDailyNewPosts(). notifyTest() is a fifth entry point,
 * deliberately left ungated (admin diagnostic tool, not member-triggered content) but
 * counted `leaked:push` while held, section 11.6 point 4.
 *
 * There is no hold/release bookkeeping for push, unlike chat/posts/chitchat: a missed
 * push has nothing to catch up, because the badge and the in-app notification it points
 * at are still there next time the app polls.
 */
class PushNotificationServiceLockdownTest extends TestCase
{
    private PushNotificationService $service;

    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->service = new PushNotificationService();
        $this->lockdown = new LockdownService();
    }

    private function pushCounter(): int
    {
        $row = DB::table('lockdown_counters')
            ->where('kind', 'push')
            ->first();

        return $row ? (int) $row->count : 0;
    }

    private function fakeMessaging(): object
    {
        $fake = new class {
            public array $sent = [];

            public function validate($message): void {}

            public function send($message): void
            {
                $this->sent[] = $message;
            }
        };

        $prop = new \ReflectionProperty($this->service, 'messaging');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $fake);

        return $fake;
    }

    private function registerDevice(int $userId, string $apptype, string $label): void
    {
        DB::table('users_push_notifications')->insert([
            'userid' => $userId,
            'type' => 'FCMIOS',
            'subscription' => $label.'-'.uniqid('', true),
            'apptype' => $apptype,
            'added' => now(),
            'lastsent' => null,
        ]);
    }

    private function createPendingMessage(Group $group): Message
    {
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
        ]);

        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => 'Pending',
            'arrival' => now(),
            'deleted' => 0,
        ]);

        return $message;
    }

    // --- notify() (ModTools work summary) ---

    public function test_notify_held_while_push_locked_down(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createPendingMessage($group);
        $this->registerDevice($mod->id, 'ModTools', 'tok-notify-held');
        $fake = $this->fakeMessaging();

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->service->notify($mod->id, true);

        $this->assertSame(0, $result, 'push must not send while the push surface is held');
        $this->assertCount(0, $fake->sent);
        $this->assertSame(1, $this->pushCounter(), 'a held push is counted');
    }

    public function test_notify_sends_again_once_push_is_lifted(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, ['role' => Membership::ROLE_MODERATOR]);
        $this->createPendingMessage($group);
        $this->registerDevice($mod->id, 'ModTools', 'tok-notify-lifted');
        $fake = $this->fakeMessaging();

        $this->lockdown->press(null, 'test lockdown');
        $held = $this->service->notify($mod->id, true);

        $this->lockdown->setSurfaces(['push' => false], null);
        $lifted = $this->service->notify($mod->id, true);

        $this->assertSame(0, $held);
        $this->assertSame(1, $lifted, 'once push is lifted the same work must still be pushed');
        $this->assertCount(1, $fake->sent);
    }

    // --- notifyUser() (FD user badge) ---

    public function test_notify_user_held_while_push_locked_down(): void
    {
        $user = $this->createTestUser();
        $this->registerDevice($user->id, 'User', 'tok-user-held');
        $fake = $this->fakeMessaging();

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->service->notifyUser($user->id);

        $this->assertSame(0, $result);
        $this->assertCount(0, $fake->sent);
        $this->assertSame(1, $this->pushCounter());
    }

    // --- notifyChatMessage() (U2U/U2M chat push) ---

    public function test_notify_chat_message_held_while_push_locked_down(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);
        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender);
        $this->registerDevice($recipient->id, 'User', 'tok-chat-held');
        $fake = $this->fakeMessaging();

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->service->notifyChatMessage($msg->id);

        $this->assertSame(0, $result, 'a chat push must not send while push is held');
        $this->assertCount(0, $fake->sent);
        $this->assertSame(1, $this->pushCounter());
    }

    public function test_notify_chat_message_sends_once_push_is_lifted(): void
    {
        $sender = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($sender, $group);
        $this->createMembership($recipient, $group);
        $room = $this->createTestChatRoom($sender, $recipient);
        $msg = $this->createTestChatMessage($room, $sender);
        $this->registerDevice($recipient->id, 'User', 'tok-chat-lifted');
        $fake = $this->fakeMessaging();

        $this->lockdown->press(null, 'test lockdown');
        $held = $this->service->notifyChatMessage($msg->id);

        $this->lockdown->setSurfaces(['push' => false], null);
        $lifted = $this->service->notifyChatMessage($msg->id);

        $this->assertSame(0, $held);
        $this->assertSame(1, $lifted);
        $this->assertCount(1, $fake->sent);
    }

    // --- notifyDailyNewPosts() ---

    public function test_notify_daily_new_posts_held_while_push_locked_down(): void
    {
        $user = $this->createTestUser();
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (Location)',
            'type' => Message::TYPE_OFFER,
        ]);
        $this->registerDevice($user->id, 'User', 'tok-daily-held');
        $fake = $this->fakeMessaging();

        $msg->groupid = $group->id;
        $posts = app(UnifiedDigestService::class)->deduplicatePosts(collect([$msg]))->values()->all();

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->service->notifyDailyNewPosts($user->id, $posts);

        $this->assertSame(0, $result);
        $this->assertCount(0, $fake->sent);
        $this->assertSame(1, $this->pushCounter());
    }

    // --- notifyTest() (admin diagnostic tool, deliberately ungated) ---

    public function test_notify_test_still_sends_while_push_locked_down_but_counts_as_leaked(): void
    {
        $user = $this->createTestUser();
        $this->registerDevice($user->id, 'User', 'tok-test-leaked');
        $fake = $this->fakeMessaging();

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->service->notifyTest($user->id, false);

        $this->assertSame(1, $result, 'the test push is an admin tool, not member content, so it is not held');
        $this->assertCount(1, $fake->sent);
        $this->assertSame(
            1,
            DB::table('lockdown_counters')->where('kind', 'leaked:push')->value('count'),
            'a known bypass while held is still counted, so the closing report shows it happened'
        );
    }

    public function test_notify_test_does_not_count_leaked_push_while_not_held(): void
    {
        $user = $this->createTestUser();
        $this->registerDevice($user->id, 'User', 'tok-test-not-held');
        $fake = $this->fakeMessaging();

        $result = $this->service->notifyTest($user->id, false);

        $this->assertSame(1, $result);
        $this->assertCount(1, $fake->sent);
        $this->assertNull(DB::table('lockdown_counters')->where('kind', 'leaked:push')->first());
    }

    // --- Ack (section 11.6 point 3) ---

    public function test_ack_is_written_for_each_push_passing_through(): void
    {
        $user = $this->createTestUser();
        $this->registerDevice($user->id, 'User', 'tok-ack');
        $this->fakeMessaging();
        $this->lockdown->press(null, 'test lockdown');

        $this->service->notifyUser($user->id);

        $ack = DB::table('lockdown_acks')->where('loop', 'push')->first();
        $this->assertNotNull($ack, 'push must ack so the presser sees this loop take effect');
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }
}
