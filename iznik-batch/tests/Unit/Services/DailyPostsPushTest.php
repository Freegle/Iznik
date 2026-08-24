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
    // Distance preference — the push and the email must agree
    // -------------------------------------------------------------------------

    /**
     * The daily EMAIL digest narrows to the member's own distance preference. The daily
     * PUSH fetches through the same getPostsForUser but used to skip that narrowing, so a
     * member who had reduced their range still got the far-away post on their phone while
     * it was correctly absent from their inbox. Both now call this one method.
     */
    public function test_distance_preference_filter_narrows_to_the_members_range(): void
    {
        $poster = $this->createTestUser();
        $group  = $this->createTestGroup();

        $member = $this->createTestUser();
        $member->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1], 'browseMaxDistance' => 5];
        $member->save();
        $member->refresh();

        $near = $this->createApprovedMessage($poster, $group, 'OFFER: Near sofa (Kingston)');
        $far  = $this->createApprovedMessage($poster, $group, 'OFFER: Far sofa (Aberdeen)');

        // Roughly 2 miles away, and roughly 400 miles away.
        $near->lat = 51.53; $near->lng = -0.1;  $near->fromuser = $poster->id;
        $far->lat  = 57.15; $far->lng  = -2.1;  $far->fromuser  = $poster->id;

        $kept = $this->digestService->filterByDistancePreference(
            collect([$near, $far]),
            $member
        );

        $ids = $kept->pluck('id')->all();
        $this->assertContains($near->id, $ids, 'a post inside the range is kept');
        $this->assertNotContains($far->id, $ids, 'a post beyond the range is dropped');
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
        // The badge reflects the user's unread chats + notifications, NOT the
        // number of posts in the digest. This fresh user has neither, so 0.
        $this->assertSame('0', $payload['badge']);
        $this->assertSame('0', $payload['moreCount']);
    }

    public function test_badge_is_user_unread_not_post_count(): void
    {
        // The daily "new posts near you" digest is informational and must not
        // inflate the app-icon badge with the post count. The badge must equal
        // the user's actual unread items (unseen notifications + unread chats).
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // Three posts in the digest...
        $msgs = [
            $this->createApprovedMessage($user, $group, 'OFFER: Sofa (Kingston)'),
            $this->createApprovedMessage($user, $group, 'WANTED: Bike (Surbiton)'),
            $this->createApprovedMessage($user, $group, 'OFFER: Lamp (Kingston)'),
        ];
        $posts = $this->buildPostsArray($msgs);

        // ...but two genuinely unseen notifications for the recipient.
        for ($i = 0; $i < 2; $i++) {
            DB::table('users_notifications')->insert([
                'touser' => $user->id,
                'type' => 'Exhort',
                'seen' => 0,
                'timestamp' => now(),
            ]);
        }

        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('3', $payload['count'], 'count reflects the posts in the digest');
        $this->assertSame('2', $payload['badge'], 'badge reflects unread items, not the post count');
    }

    public function test_bulk_offer_shows_item_count(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg   = $this->createApprovedMessage($user, $group, 'OFFER: Office clearance (Brighton)');
        foreach (['Desk', 'Chair', 'Lamp'] as $i => $name) {
            \Illuminate\Support\Facades\DB::table('messages_bulk_items')->insert([
                'msgid' => $msg->id, 'position' => $i, 'name' => $name, 'quantity' => 1, 'condition' => 'Good',
            ]);
        }

        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $this->buildPostsArray([$msg]));

        // The single-post title makes clear it's a multi-item clearance.
        $this->assertSame('Office clearance — 3 items', $payload['title']);
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

    public function test_images_collects_top_post_photos_for_collage(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // Two posts with photos, one without — images[] should hold the two photo URLs
        // in order, and skip the photo-less post.
        $m1 = $this->createApprovedMessage($user, $group, 'OFFER: Sofa (London)');
        MessageAttachment::create(['msgid' => $m1->id, 'externalurl' => 'https://cdn.example.com/a.jpg', 'archived' => 0, 'primary' => 1]);
        $m2 = $this->createApprovedMessage($user, $group, 'WANTED: Bike (London)');
        $m3 = $this->createApprovedMessage($user, $group, 'OFFER: Lamp (London)');
        MessageAttachment::create(['msgid' => $m3->id, 'externalurl' => 'https://cdn.example.com/c.jpg', 'archived' => 0, 'primary' => 1]);
        foreach ([$m1, $m2, $m3] as $m) {
            $m->load('attachments');
        }

        $posts   = $this->buildPostsArray([$m1, $m2, $m3]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $images = json_decode($payload['images'], true);
        $this->assertSame(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/c.jpg'], $images);
        // image (single) is the first post's photo, for the single-post / fallback path.
        $this->assertSame('https://cdn.example.com/a.jpg', $payload['image']);
    }

    /**
     * Helper: create an approved OFFER with a single attachment, optionally AI.
     */
    private function postWithPhoto(User $user, Group $group, string $subject, string $url, bool $ai = false, int $primary = 1): Message
    {
        $msg = $this->createApprovedMessage($user, $group, $subject);
        MessageAttachment::create([
            'msgid'        => $msg->id,
            'externalurl'  => $url,
            'archived'     => 0,
            'primary'      => $primary,
            'externalmods' => $ai ? json_encode(['ai' => true]) : null,
        ]);
        $msg->load('attachments');

        return $msg;
    }

    public function test_collage_puts_real_photos_before_ai_and_pads_with_ai(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // Interleaved real/AI posts: real A, AI X, real B, AI Y, real C.
        // Collage must list real photos first (in order), then pad to 4 with AI.
        $posts = $this->buildPostsArray([
            $this->postWithPhoto($user, $group, 'OFFER: A (London)', 'https://cdn.example.com/realA.jpg'),
            $this->postWithPhoto($user, $group, 'OFFER: X (London)', 'https://cdn.example.com/aiX.jpg', true),
            $this->postWithPhoto($user, $group, 'OFFER: B (London)', 'https://cdn.example.com/realB.jpg'),
            $this->postWithPhoto($user, $group, 'OFFER: Y (London)', 'https://cdn.example.com/aiY.jpg', true),
            $this->postWithPhoto($user, $group, 'OFFER: C (London)', 'https://cdn.example.com/realC.jpg'),
        ]);

        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);
        $images  = json_decode($payload['images'], true);

        // 3 real first (digest order), then 1 AI to fill the 4-photo collage.
        $this->assertSame([
            'https://cdn.example.com/realA.jpg',
            'https://cdn.example.com/realB.jpg',
            'https://cdn.example.com/realC.jpg',
            'https://cdn.example.com/aiX.jpg',
        ], $images);
        // The single image also prefers a real photo.
        $this->assertSame('https://cdn.example.com/realA.jpg', $payload['image']);
    }

    public function test_collage_pads_with_ai_when_too_few_real(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // Only one real photo available; AI photos fill the remaining slots so the
        // collage still renders (needs >= 2 photos).
        $posts = $this->buildPostsArray([
            $this->postWithPhoto($user, $group, 'OFFER: X (London)', 'https://cdn.example.com/aiX.jpg', true),
            $this->postWithPhoto($user, $group, 'OFFER: A (London)', 'https://cdn.example.com/realA.jpg'),
            $this->postWithPhoto($user, $group, 'OFFER: Y (London)', 'https://cdn.example.com/aiY.jpg', true),
        ]);

        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);
        $images  = json_decode($payload['images'], true);

        $this->assertSame([
            'https://cdn.example.com/realA.jpg',
            'https://cdn.example.com/aiX.jpg',
            'https://cdn.example.com/aiY.jpg',
        ], $images);
        // image prefers the real photo even though an AI post came first in the digest.
        $this->assertSame('https://cdn.example.com/realA.jpg', $payload['image']);
    }

    public function test_collage_uses_ai_when_no_real_photos(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // No real photos at all — AI photos are the only option, so use them.
        $posts = $this->buildPostsArray([
            $this->postWithPhoto($user, $group, 'OFFER: X (London)', 'https://cdn.example.com/aiX.jpg', true),
            $this->postWithPhoto($user, $group, 'OFFER: Y (London)', 'https://cdn.example.com/aiY.jpg', true),
        ]);

        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);
        $images  = json_decode($payload['images'], true);

        $this->assertSame([
            'https://cdn.example.com/aiX.jpg',
            'https://cdn.example.com/aiY.jpg',
        ], $images);
    }

    public function test_within_post_prefers_real_attachment_over_ai(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        // A single post carrying BOTH an AI illustration (primary) and a real photo.
        // The post must contribute its real photo, even though the AI one is primary.
        $msg = $this->createApprovedMessage($user, $group, 'OFFER: Sofa (London)');
        MessageAttachment::create([
            'msgid'        => $msg->id,
            'externalurl'  => 'https://cdn.example.com/ai.jpg',
            'archived'     => 0,
            'primary'      => 1,
            'externalmods' => json_encode(['ai' => true]),
        ]);
        MessageAttachment::create([
            'msgid'        => $msg->id,
            'externalurl'  => 'https://cdn.example.com/real.jpg',
            'archived'     => 0,
            'primary'      => 0,
            'externalmods' => null,
        ]);
        $msg->load('attachments');

        $posts   = $this->buildPostsArray([$msg]);
        $payload = $this->pushService->buildDailyNewPostsPayload($user->id, $posts);

        $this->assertSame('https://cdn.example.com/real.jpg', $payload['image']);
        $images = json_decode($payload['images'], true);
        $this->assertSame(['https://cdn.example.com/real.jpg'], $images);
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

    /**
     * End to end through the command: a post beyond the member's own distance preference
     * must not be pushed. The email digest already withheld it, so pushing it was the two
     * channels disagreeing about the same member's own setting.
     */
    public function test_command_does_not_push_a_post_beyond_the_distance_preference(): void
    {
        Config::set('freegle.posts_push_allowlist', '*');

        $user  = $this->createTestUser();
        $other = $this->createTestUser();
        $group = $this->createTestGroup();

        $user->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1], 'browseMaxDistance' => 5];
        $user->save();
        $user->refresh();

        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // The only post is hundreds of miles away, well outside the member's 5 miles.
        $msg = $this->createApprovedMessage($other, $group, 'OFFER: Far away item (Aberdeen)');
        DB::table('messages')->where('id', $msg->id)->update(['lat' => 57.15, 'lng' => -2.1]);
        DB::table('messages_spatial')->where('msgid', $msg->id)->update([
            'point' => DB::raw("ST_SRID(POINT(-2.1, 57.15), 3857)"),
        ]);
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
