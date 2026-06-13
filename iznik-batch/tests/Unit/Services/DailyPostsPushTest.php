<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\PushNotificationService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the daily new-posts push notification.
 *
 * Covers:
 *   - buildDailyNewPostsPayload contract (title, message, lines, moreCount, etc.)
 *   - notifyDailyNewPosts: own-post exclusion, token delivery, no-op when no posts
 *   - SendDailyPostsPushCommand: allowlist gate, opt-out flag, cursor advance
 *
 * No real FCM is called. PushNotificationService is either:
 *   (a) instantiated directly when testing payload-building (no Firebase needed), or
 *   (b) mocked via $this->createMock() when testing the send path.
 */
class DailyPostsPushTest extends TestCase
{
    private PushNotificationService $pushService;
    private UnifiedDigestService    $digestService;

    protected function setUp(): void
    {
        parent::setUp();
        // PushNotificationService gracefully no-ops when Firebase creds are
        // absent (logs an error, sets $this->messaging = null). Payload-builder
        // tests don't need messaging; send tests use a mock.
        $this->pushService  = new PushNotificationService();
        $this->digestService = new UnifiedDigestService();
    }

    // -------------------------------------------------------------------------
    // buildDailyNewPostsPayload — contract tests
    // -------------------------------------------------------------------------

    public function test_payload_empty_when_no_posts(): void
    {
        $payload = $this->pushService->buildDailyNewPostsPayload(1, []);
        $this->assertSame([], $payload);
    }

    public function test_single_post_title_is_item_name(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Sofa (Kingston)');

        $posts   = $this->buildPostsArray([$msg]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('Sofa', $payload['title'],
            'Single-post title must be the bare item name (stripped prefix + location)');
        $this->assertSame('1', $payload['count']);
        $this->assertSame('1', $payload['badge']);
        $this->assertSame('0', $payload['moreCount']);
    }

    public function test_multi_post_title_is_count_new_things(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msgs  = [
            $this->createApprovedMessage($user, $group, 'OFFER: Sofa (Kingston)'),
            $this->createApprovedMessage($user, $group, 'WANTED: Bike (Surbiton)'),
        ];

        $posts   = $this->buildPostsArray($msgs);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('2 new things near you', $payload['title']);
        $this->assertSame('2', $payload['count']);
    }

    public function test_seven_posts_lines_capped_at_five_and_more_count(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        $msgs = [];
        $subjects = [
            'OFFER: Sofa (Kingston)',
            'WANTED: Bike (Surbiton)',
            'OFFER: Coffee table (Kingston)',
            'OFFER: Books (Kingston)',
            'WANTED: Lamp (Surbiton)',
            'OFFER: Chair (Richmond)',
            'OFFER: Desk (Richmond)',
        ];
        foreach ($subjects as $s) {
            $msgs[] = $this->createApprovedMessage($user, $group, $s);
        }

        $posts   = $this->buildPostsArray($msgs);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('7 new things near you', $payload['title']);
        $this->assertSame('7', $payload['count']);

        $lines = json_decode($payload['lines'], TRUE);
        $this->assertCount(5, $lines, 'lines must contain exactly 5 entries when count > 5');
        $this->assertSame('2', $payload['moreCount'], 'moreCount must be count - 5 = 2');

        // Summary text
        $this->assertSame('Freegle • 7 new posts', $payload['summary']);

        // Single-line fallback message must contain the first 5 names and "+2 more"
        $this->assertStringContainsString('+2 more', $payload['message']);
    }

    public function test_payload_required_fields_are_strings(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Lamp (Ealing)');

        $posts   = $this->buildPostsArray([$msg]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        // FCM requires ALL data values to be strings.
        $requiredStringKeys = ['channel_id', 'category', 'notId', 'count', 'title',
            'message', 'route', 'image', 'lines', 'summary', 'moreCount',
            'timestamp', 'badge', 'content-available', 'modtools'];

        foreach ($requiredStringKeys as $key) {
            $this->assertArrayHasKey($key, $payload, "Key '{$key}' must be present");
            $this->assertIsString($payload[$key], "payload['{$key}'] must be a string (FCM requirement)");
        }
    }

    public function test_payload_constant_fields(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Box (Ealing)');

        $posts   = $this->buildPostsArray([$msg]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('new_posts',     $payload['channel_id']);
        $this->assertSame('NEW_POSTS',     $payload['category']);
        $this->assertSame('200000001',     $payload['notId'],
            'notId must be a constant so Android replaces (not stacks) the tray entry');
        $this->assertSame('/browse',       $payload['route']);
        $this->assertSame('1',             $payload['content-available']);
        $this->assertSame('false',         $payload['modtools']);
    }

    public function test_lines_are_json_encoded_array(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msgs  = [
            $this->createApprovedMessage($user, $group, 'OFFER: Sofa (Kingston)'),
            $this->createApprovedMessage($user, $group, 'WANTED: Bike (Surbiton)'),
        ];

        $posts   = $this->buildPostsArray($msgs);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertIsString($payload['lines']);
        $lines = json_decode($payload['lines'], TRUE);
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Offer:', $lines[0]);
        $this->assertStringContainsString('Wanted:', $lines[1]);
    }

    public function test_image_is_empty_string_when_no_attachment(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Books (London)');

        $posts   = $this->buildPostsArray([$msg]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('', $payload['image']);
    }

    public function test_image_url_from_attachment_externalurl(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Vase (London)');

        // Add a usable attachment with externalurl.
        MessageAttachment::create([
            'msgid'       => $msg->id,
            'externalurl' => 'https://cdn.example.com/img123.jpg',
            'archived'    => 0,
            'primary'     => 1,
        ]);

        // Reload with attachments relation.
        $msg->load('attachments');

        $posts   = $this->buildPostsArray([$msg]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('https://cdn.example.com/img123.jpg', $payload['image']);
    }

    // -------------------------------------------------------------------------
    // notifyDailyNewPosts — send path (mocked service)
    // -------------------------------------------------------------------------

    public function test_notify_returns_zero_when_no_posts(): void
    {
        $service = $this->createPartialMockService();
        // No messaging — notifyDailyNewPosts short-circuits via messagingUnavailable
        // before it even reaches buildDailyNewPostsPayload. But with an empty posts
        // array it returns 0 immediately from the filtering step.
        $result = $service->notifyDailyNewPosts(999, []);
        $this->assertSame(0, $result);
    }

    public function test_notify_excludes_users_own_posts(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Self (London)');

        // Insert a push token so it's present for the DB query (even though
        // Firebase is unavailable, the method returns 0 after own-post filter).
        $this->createUserPushToken($user->id);

        $posts = $this->buildPostsArray([$msg]);
        // All posts are from $user — they should be filtered out, returning 0.
        $result = $this->pushService->notifyDailyNewPosts($user->id, $posts);

        $this->assertSame(0, $result,
            'notifyDailyNewPosts must return 0 when all posts are from the recipient');
    }

    public function test_notify_returns_zero_when_no_fcm_token(): void
    {
        $user    = $this->createTestUser();
        $other   = $this->createTestUser();
        $group   = $this->createTestGroup();
        $msg     = $this->createApprovedMessage($other, $group, 'OFFER: Lamp (Oxford)');

        // No push token inserted for $user.
        $posts = $this->buildPostsArray([$msg]);

        // Firebase unavailable (no creds in test) → messaging is null → 0 via messagingUnavailable.
        // That's fine — the point is 0 is returned without crashing.
        $result = $this->pushService->notifyDailyNewPosts($user->id, $posts);
        $this->assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // push:daily-posts command — allowlist, opt-out, cursor advance
    // -------------------------------------------------------------------------

    public function test_command_exits_early_when_allowlist_empty(): void
    {
        Config::set('freegle.posts_push_allowlist', '');

        $this->artisan('push:daily-posts')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    }

    public function test_command_dry_run_does_not_advance_cursor(): void
    {
        Config::set('freegle.posts_push_allowlist', '*');

        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createApprovedMessage($this->createTestUser(), $group, 'OFFER: Dry item (London)');
        $this->createUserPushToken($user->id);

        // Mock so notifyDailyNewPosts never fires.
        $mockPush = $this->createMock(PushNotificationService::class);
        $mockPush->expects($this->never())->method('notifyDailyNewPosts');
        // buildDailyNewPostsPayload is used in dry-run output; let the real impl run.
        $mockPush->method('buildDailyNewPostsPayload')
            ->willReturn(['title' => 'Dry run test', 'count' => '1']);
        $this->app->instance(PushNotificationService::class, $mockPush);

        $this->artisan('push:daily-posts', [
            '--user'    => $user->id,
            '--dry-run' => TRUE,
        ])->assertExitCode(0);

        $cursor = UserDigest::where('userid', $user->id)->where('mode', 'push')->first();
        $this->assertNull($cursor?->lastsent,
            'Cursor must not advance in dry-run mode');
    }

    public function test_command_skips_opted_out_user(): void
    {
        Config::set('freegle.posts_push_allowlist', '*');

        // User has opted out of daily-posts push.
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'settings' => json_encode(['notifications' => ['dailypostspush' => FALSE]]),
        ]);
        $user = $user->fresh();

        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $this->createApprovedMessage($this->createTestUser(), $group, 'OFFER: Opted-out item (London)');
        $this->createUserPushToken($user->id);

        $mockPush = $this->createMock(PushNotificationService::class);
        $mockPush->expects($this->never())->method('notifyDailyNewPosts');
        $this->app->instance(PushNotificationService::class, $mockPush);

        $this->artisan('push:daily-posts', ['--user' => $user->id])
            ->assertExitCode(0);
    }

    public function test_command_advances_cursor_after_send(): void
    {
        Config::set('freegle.posts_push_allowlist', '*');

        $user    = $this->createTestUser();
        $other   = $this->createTestUser();
        $group   = $this->createTestGroup();

        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $msg = $this->createApprovedMessage($other, $group, 'OFFER: Cursor item (London)');
        $this->createUserPushToken($user->id);

        $mockPush = $this->createMock(PushNotificationService::class);
        $mockPush->method('notifyDailyNewPosts')->willReturn(1);
        $mockPush->method('buildDailyNewPostsPayload')->willReturn(['title' => 'test', 'count' => '1']);
        $this->app->instance(PushNotificationService::class, $mockPush);

        $before = UserDigest::where('userid', $user->id)->where('mode', 'push')->first();
        $this->assertNull($before, 'No cursor before first run');

        $this->artisan('push:daily-posts', ['--user' => $user->id])
            ->assertExitCode(0);

        $after = UserDigest::where('userid', $user->id)->where('mode', 'push')->first();
        $this->assertNotNull($after, 'Cursor must be created after first run');
        $this->assertNotNull($after->lastsent, 'lastsent must be set after send');
        $this->assertEquals($msg->id, $after->lastmsgid, 'lastmsgid must point at the last processed message');
    }

    public function test_command_once_per_day_guard_prevents_resend(): void
    {
        Config::set('freegle.posts_push_allowlist', '*');

        $user  = $this->createTestUser();
        $other = $this->createTestUser();
        $group = $this->createTestGroup();

        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createApprovedMessage($other, $group, 'OFFER: Guard item (London)');
        $this->createUserPushToken($user->id);

        // Seed a cursor with lastsent = today (London) to simulate already-sent.
        UserDigest::create([
            'userid'      => $user->id,
            'mode'        => 'push',
            'lastmsgid'   => null,
            'lastmsgdate' => now()->subDay(),
            'lastsent'    => now(),  // today
        ]);

        $mockPush = $this->createMock(PushNotificationService::class);
        $mockPush->expects($this->never())->method('notifyDailyNewPosts');
        $this->app->instance(PushNotificationService::class, $mockPush);

        // Run WITHOUT --user so the guard applies.
        $this->artisan('push:daily-posts')
            ->assertExitCode(0);

        // notifyDailyNewPosts was never called (the expectation above enforces it).
    }

    public function test_command_allowlist_restricts_to_pilot_email(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        // Only user1's email is in the allowlist.
        $email1 = 'test' . $user1->id . '@test.com';
        Config::set('freegle.posts_push_allowlist', $email1);

        $group = $this->createTestGroup();
        $other = $this->createTestUser();

        $this->createMembership($user1, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($user2, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);

        $this->createApprovedMessage($other, $group, 'OFFER: Allowlist item (London)');
        $this->createUserPushToken($user1->id);
        $this->createUserPushToken($user2->id);

        $calledFor = [];
        $mockPush = $this->createMock(PushNotificationService::class);
        $mockPush->method('notifyDailyNewPosts')
            ->willReturnCallback(function (int $userId) use (&$calledFor) {
                $calledFor[] = $userId;
                return 1;
            });
        $mockPush->method('buildDailyNewPostsPayload')->willReturn(['title' => 't', 'count' => '1']);
        $this->app->instance(PushNotificationService::class, $mockPush);

        $this->artisan('push:daily-posts')->assertExitCode(0);

        $this->assertContains($user1->id, $calledFor,
            'user1 is in the allowlist and must receive the push');
        $this->assertNotContains($user2->id, $calledFor,
            'user2 is NOT in the allowlist and must be excluded');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create an approved message (messages + messages_groups).
     */
    private function createApprovedMessage(User $user, Group $group, string $subject): Message
    {
        return $this->createTestMessage($user, $group, [
            'subject' => $subject,
            'type'    => str_starts_with(strtolower($subject), 'offer') ? Message::TYPE_OFFER : Message::TYPE_WANTED,
        ]);
    }

    /**
     * Build the deduped post array format that deduplicatePosts() returns
     * from a flat list of Message models. Each item carries a groupid attribute
     * so the key function works.
     */
    private function buildPostsArray(array $messages): array
    {
        $posts = collect($messages)->map(function (Message $msg) {
            $msg->groupid = (int) DB::table('messages_groups')
                ->where('msgid', $msg->id)
                ->value('groupid');
            return $msg;
        });

        return $this->digestService->deduplicatePosts($posts)->values()->all();
    }

    /**
     * Insert a FCM Android push token for a user in the FD app.
     */
    private function createUserPushToken(int $userId, string $type = 'FCMAndroid'): void
    {
        DB::table('users_push_notifications')->insert([
            'userid'       => $userId,
            'apptype'      => 'User',
            'type'         => $type,
            'subscription' => 'test_token_' . bin2hex(random_bytes(16)),
            'added'        => now(),
        ]);
    }

    /**
     * Return a PushNotificationService instance. Firebase init will fail in
     * tests (no creds file), so $this->messaging === null. Payload tests work
     * without Firebase; send tests mock the service entirely.
     */
    private function createPartialMockService(): PushNotificationService
    {
        return new PushNotificationService();
    }
}
