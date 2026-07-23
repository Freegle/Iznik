<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for GroupPostIngestionService.
 *
 * Verifies that the TN API ingestion path correctly mirrors the email path's
 * routing decisions and writes. All tests use fixture arrays (the same format
 * as tests/fixtures/tn_sync/posts_page_1.json) so no live TN API is needed.
 */
class GroupPostIngestionServiceTest extends TestCase
{
    private LokiService $loki;
    private ItemService $itemService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loki = app(LokiService::class);
        $this->itemService = app(ItemService::class);
    }

    private function makeService(bool $dryRun = true): GroupPostIngestionService
    {
        return new GroupPostIngestionService(
            dryRun: $dryRun,
            loki: $this->loki,
            itemService: $this->itemService,
        );
    }

    private function createTestLocation(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'name' => 'TestLocation_' . uniqid(),
            'type' => 'Postcode',
            'lat'  => 55.9533,
            'lng'  => -3.1883,
        ]);
    }

    private function makePost(array $overrides = []): array
    {
        return array_merge([
            'post_id'   => 'tn-unit-test-' . uniqid(),
            'group_id'  => 'TestGroup',
            'user_id'   => null,
            'title'     => 'Old wooden bookshelf',
            'content'   => 'Good condition, free to collect.',
            'date'      => '2026-07-07T12:00:00Z',
            'type'      => 'offer',
            'outcome'   => null,
            'latitude'  => null,
            'longitude' => null,
            'photos'    => [],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Skip cases
    // -------------------------------------------------------------------------

    public function test_skips_when_user_id_is_null(): void
    {
        $group = $this->createTestGroup();
        $post  = $this->makePost(['user_id' => null]);

        $result = $this->makeService()->ingest($post, $group);

        $this->assertSame('skipped', $result);
    }

    public function test_skips_when_user_not_found_in_dry_run(): void
    {
        // In dry-run mode no DB writes occur, so a missing user cannot be created
        // and the post must be skipped rather than crash.
        $group = $this->createTestGroup();
        $post  = $this->makePost(['user_id' => 999999999]);

        $result = $this->makeService(dryRun: true)->ingest($post, $group);

        $this->assertSame('skipped', $result);
        $this->assertFalse(DB::table('users')->where('id', 999999999)->exists(), 'Dry-run must not create a user row');
    }

    public function test_creates_stub_user_and_ingests_post_when_user_unknown(): void
    {
        // Pick an ID that won't collide with any auto-incremented user created in this test.
        $fdUserId = 98765432;
        $this->assertFalse(DB::table('users')->where('id', $fdUserId)->exists(), 'Pre-condition: user must not exist');

        $group  = $this->createTestGroup();
        $postId = 'tn-stub-user-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $fdUserId]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        // Stub user was created with the correct Freegle id.
        $this->assertTrue(DB::table('users')->where('id', $fdUserId)->exists(), 'Stub user row must be created');
        $this->assertTrue(
            DB::table('users_emails')->where('userid', $fdUserId)->where('email', "tn{$fdUserId}@user.trashnothing.com")->exists(),
            'Synthetic email must be created for stub user',
        );

        // Membership was created so the post could pass the membership check.
        $this->assertTrue(
            DB::table('memberships')->where('userid', $fdUserId)->where('groupid', $group->id)->where('collection', 'Approved')->exists(),
            'Stub membership must be created',
        );

        // Post was ingested (not skipped).
        $this->assertNotSame('skipped', $result, 'Post must not be skipped when stub user is created');
        $this->assertSame(1, Message::where('tnpostid', $postId)->count(), 'Message row must be created');
    }

    public function test_stub_user_creation_is_idempotent_on_second_ingest(): void
    {
        // If the same post arrives again (overlap window), the second call must detect
        // the duplicate and not try to re-insert the user or membership.
        $fdUserId = 98765433;
        $group    = $this->createTestGroup();
        $postId   = 'tn-stub-idem-' . uniqid();
        $post     = $this->makePost(['post_id' => $postId, 'user_id' => $fdUserId]);
        $svc      = $this->makeService(dryRun: false);

        $first  = $svc->ingest($post, $group);
        $second = $svc->ingest($post, $group);

        $this->assertNotSame('skipped', $first);
        $this->assertSame('duplicate', $second);
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
        $this->assertSame(1, DB::table('users')->where('id', $fdUserId)->count(), 'User must not be duplicated');
        $this->assertSame(
            1,
            DB::table('memberships')->where('userid', $fdUserId)->where('groupid', $group->id)->count(),
            'Membership must not be duplicated',
        );
    }

    public function test_skips_when_user_not_a_member(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        // No membership created.
        $post = $this->makePost(['user_id' => $user->id]);

        $result = $this->makeService()->ingest($post, $group);

        $this->assertSame('skipped', $result);
    }

    public function test_returns_duplicate_when_post_already_ingested(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Pre-create a message row that looks like a previously-ingested post.
        $postId  = 'tn-dup-' . uniqid();
        $message = Message::create([
            'type'      => Message::TYPE_OFFER,
            'fromuser'  => $user->id,
            'subject'   => 'OFFER: Already ingested',
            'textbody'  => 'body',
            'source'    => 'TN-API',
            'tnpostid'  => $postId,
            'date'      => now(),
        ]);
        MessageGroup::create([
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival'    => now(),
        ]);

        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $result = $this->makeService()->ingest($post, $group);

        $this->assertSame('duplicate', $result);
    }

    // -------------------------------------------------------------------------
    // Dry-run: no DB writes, correct log output
    // -------------------------------------------------------------------------

    public function test_dry_run_returns_pending_for_unmapped_user(): void
    {
        $user  = $this->createTestUser(); // lastlocation defaults to null
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post   = $this->makePost(['user_id' => $user->id]);
        $result = $this->makeService(dryRun: true)->ingest($post, $group);

        $this->assertSame('pending', $result);
    }

    public function test_dry_run_emits_write_trace_log_and_makes_no_db_writes(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $logLines = [];
        Log::listen(function ($message) use (&$logLines) {
            if (str_contains((string) $message->message, 'TN-SYNC-TRACE [WRITE]')) {
                $logLines[] = (string) $message->message;
            }
        });

        $post   = $this->makePost(['user_id' => $user->id]);
        $result = $this->makeService(dryRun: true)->ingest($post, $group);

        // In dry-run the routing result is still computed correctly.
        $this->assertSame('approved', $result);

        // At least one WRITE trace line must have been emitted.
        $this->assertNotEmpty($logLines, 'Expected TN-SYNC-TRACE [WRITE] log lines in dry-run');

        // No messages row should have been created in the DB.
        $msgCount = DB::table('messages')->where('fromuser', $user->id)->count();
        $this->assertSame(0, $msgCount, 'Dry-run must not write to messages table');
    }

    // -------------------------------------------------------------------------
    // Live writes (dryRun = false)
    // -------------------------------------------------------------------------

    public function test_live_creates_message_and_messages_groups_rows(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-live-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('approved', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Expected a messages row with tnpostid=' . $postId);
        $this->assertSame($user->id, $message->fromuser);
        $this->assertSame(Message::SOURCE_EMAIL, $message->source);
        $this->assertSame(Message::TYPE_OFFER, $message->type);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg, 'Expected a messages_groups row');
        $this->assertSame(MessageGroup::COLLECTION_APPROVED, $mg->collection);
        $this->assertTrue($mg->mod_messaging_allowed, 'mod_messaging_allowed should default to true when not passed');
    }

    public function test_live_persists_mod_messaging_disallowed_when_specified(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-live-nomodmsg-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group, modMessagingAllowed: false);

        $this->assertSame('approved', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg);
        $this->assertFalse($mg->mod_messaging_allowed);
    }

    public function test_live_creates_pending_message_when_group_is_moderated(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['settings' => json_encode(['moderated' => true])]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-live-mod-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg);
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
    }

    public function test_live_is_idempotent_on_second_ingest(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-idem-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $svc    = $this->makeService(dryRun: false);

        $first  = $svc->ingest($post, $group);
        $second = $svc->ingest($post, $group);

        $this->assertSame('approved', $first);
        $this->assertSame('duplicate', $second);

        $count = Message::where('tnpostid', $postId)->count();
        $this->assertSame(1, $count, 'Second ingest must not create a second messages row');
    }

    public function test_overlap_window_does_not_duplicate_posts(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-overlap-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $svc    = $this->makeService(dryRun: false);

        // Simulates the first sync window: post is created.
        $first = $svc->ingest($post, $group);
        $this->assertSame('approved', $first);
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());

        // Simulates the overlap window re-fetching the same post.
        // postAlreadyExists() must detect the duplicate and return early without
        // inserting a second messages row or messages_groups row.
        $second = $svc->ingest($post, $group);
        $this->assertSame('duplicate', $second);
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
        $this->assertSame(
            1,
            MessageGroup::whereIn('msgid', Message::where('tnpostid', $postId)->pluck('id'))->count(),
            'messages_groups must not acquire a second row for the same post',
        );
    }

    public function test_live_synthesizes_rfc822_blob_in_messages_message(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-rfc-' . uniqid();
        $post   = $this->makePost([
            'post_id'  => $postId,
            'user_id'  => $user->id,
            'title'    => 'Bicycle',
            'content'  => 'Blue bike, collect from porch.',
        ]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertNotEmpty($message->message, 'messages.message (RFC822 blob) must not be empty');
        $this->assertStringContainsString('X-Trashnothing-Post-Id: ' . $postId, $message->message);
        $this->assertStringContainsString('OFFER: Bicycle', $message->message);
    }
}
