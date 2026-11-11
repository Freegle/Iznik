<?php

namespace Tests\Feature\Monitor;

use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tests for monitor:chat-roster.
 *
 * The chat mailer only emails participants who have a chat_roster row, so the
 * command raises a Sentry error for any live participant of a recently active
 * member chat without one.
 *
 * The clock is moved to June 2037 so that only this test's messages fall inside the
 * command's window: other tests leave chats with recent messages and no roster
 * rows, which would otherwise be reported here.
 */
class ChatRosterCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.chat_roster_monitor.window_hours', 48);
        Carbon::setTestNow('2037-06-15 05:45:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Records what reaches Sentry; checked after the test in assertPostConditions(). */
    private ?object $sentry = null;

    private array $sentryExpected = [0, ''];

    private function expectSentry(int $times, string $contains = ''): void
    {
        // A recorder rather than a mock: a mock whose arguments do not match
        // throws inside the command, the console turns that into exit code 1,
        // and a test expecting 1 then passes for the wrong reason.
        $this->sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = [(string) $message, (string) $severity];
            }
        };
        $this->sentryExpected = [$times, $contains];
        $this->app->instance('sentry', $this->sentry);
    }

    protected function assertPostConditions(): void
    {
        if ($this->sentry !== null) {
            [$times, $contains] = $this->sentryExpected;
            $this->assertCount($times, $this->sentry->calls, 'Sentry received: '.json_encode($this->sentry->calls));
            foreach ($this->sentry->calls as [$message, $severity]) {
                $this->assertStringContainsString($contains, $message);
                $this->assertSame((string) \Sentry\Severity::error(), $severity);
            }
        }

        parent::assertPostConditions();
    }

    private function roster(ChatRoom $room, User ...$users): void
    {
        foreach ($users as $user) {
            ChatRoster::create(['chatid' => $room->id, 'userid' => $user->id, 'status' => ChatRoster::STATUS_ONLINE]);
        }
    }

    /** A User2User chat between two new members with one message, $hoursAgo old. */
    private function activeChat(int $hoursAgo = 1): array
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $room = $this->createTestChatRoom($a, $b);
        $this->createTestChatMessage($room, $a, ['date' => now()->subHours($hoursAgo)]);

        return [$room, $a, $b];
    }

    public function test_every_participant_on_the_roster_passes(): void
    {
        [$room, $a, $b] = $this->activeChat();
        $this->roster($room, $a, $b);
        $this->expectSentry(0);

        $this->artisan('monitor:chat-roster')
            ->expectsOutputToContain('Chat roster OK: all 2 participants of 1 chats active in the last 48 hours')
            ->assertExitCode(0);
    }

    public function test_a_participant_missing_from_the_roster_raises_sentry_error(): void
    {
        // The account-merge shape: the room names the surviving account, the only
        // roster row on that side is still the old one's.
        [$room, $a, $b] = $this->activeChat();
        $this->roster($room, $a);
        $this->expectSentry(1, "1 live participant in chats active in the last 48 hours has no chat_roster row, so the chat mailer never emails them: chat {$room->id} (User2User) user {$b->id}");

        $this->artisan('monitor:chat-roster')->assertExitCode(1);
    }

    public function test_user1_missing_is_reported_too(): void
    {
        [$room, $a, $b] = $this->activeChat();
        $this->roster($room, $b);
        $this->expectSentry(1, "chat {$room->id} (User2User) user {$a->id}");

        $this->artisan('monitor:chat-roster')->assertExitCode(1);
    }

    public function test_user2mod_member_missing_is_reported(): void
    {
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $this->createTestChatMessage($room, $member);
        $this->expectSentry(1, "chat {$room->id} (User2Mod) user {$member->id}");

        $this->artisan('monitor:chat-roster')->assertExitCode(1);
    }

    public function test_user2mod_moderators_are_not_required_on_the_roster(): void
    {
        // Moderators are added to a User2Mod roster when they are mailed, so
        // only the member is checked.
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $room = ChatRoom::create([
            'chattype' => ChatRoom::TYPE_USER2MOD,
            'user1' => $member->id,
            'groupid' => $group->id,
            'created' => now(),
        ]);
        $this->roster($room, $member);
        $this->createTestChatMessage($room, $member);
        $this->expectSentry(0);

        $this->artisan('monitor:chat-roster')->assertExitCode(0);
    }

    public function test_a_deleted_participant_is_not_reported(): void
    {
        // A deleted account is not mailed whatever its roster says.
        [$room, $a, $b] = $this->activeChat();
        $this->roster($room, $a);
        $b->deleted = now();
        $b->save();
        $this->expectSentry(0);

        $this->artisan('monitor:chat-roster')
            ->expectsOutputToContain('all 1 participants of 1 chats')
            ->assertExitCode(0);
    }

    public function test_chats_quiet_for_longer_than_the_window_are_not_checked(): void
    {
        [$quiet] = $this->activeChat(72);
        [$room, $a, $b] = $this->activeChat();
        $this->roster($room, $a, $b);
        $this->expectSentry(0);

        $this->artisan('monitor:chat-roster')
            ->expectsOutputToContain('of 1 chats')
            ->assertExitCode(0);
    }

    public function test_messages_dated_in_the_future_are_not_checked(): void
    {
        // Not recent, whatever else they are; and other tests leave rows with
        // far-future dates that would otherwise land in this window.
        [$future] = $this->activeChat(-24);
        [$room, $a, $b] = $this->activeChat();
        $this->roster($room, $a, $b);
        $this->expectSentry(0);

        $this->artisan('monitor:chat-roster')
            ->expectsOutputToContain('of 1 chats')
            ->assertExitCode(0);
    }

    public function test_no_recent_chats_is_an_error_not_a_pass(): void
    {
        $this->expectSentry(1, 'so the roster check could not run');

        $this->artisan('monitor:chat-roster')->assertExitCode(1);
    }

    public function test_names_at_most_ten_and_counts_them_all(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->activeChat();
        }
        $this->expectSentry(1, '12 live participants in chats active in the last 48 hours have no chat_roster row');

        $this->artisan('monitor:chat-roster')
            ->expectsOutputToContain(', ...')
            ->assertExitCode(1);
    }
}
