<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
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
            // Source post: TN sets group_id only on the per-group COPIES it makes
            // when a post is sent to a group, and those are discarded as
            // crossposts (see ingest()). Tests that want a copy set it.
            'group_id'  => null,
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

    public function test_creates_post_for_non_member_of_resolved_group(): void
    {
        // The group here is resolved from the post's own coordinates
        // (Location::groupsNear), not from membership, so the poster is
        // frequently not a member of the resolved group — that must still
        // succeed, using the same 'DEFAULT' posting status a brand-new
        // member would get, rather than being skipped. Like any unmoderated
        // poster it lands Pending for messages:contentcheck to promote;
        // ContentCheckService::isUserModerated() applies the same DEFAULT
        // fallback for a TN post whose poster has no membership, so nothing
        // strands it there.
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        // Deliberately no membership created.

        $postId = 'tn-non-member-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Expected a messages row even though the poster is not a group member');

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg, 'Expected a messages_groups row for the non-member post');
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
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
        $this->assertSame('pending', $result);

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

        // An unmoderated poster starts Pending for the content check — see
        // test_unmoderated_poster_starts_pending_for_the_content_check().
        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Expected a messages row with tnpostid=' . $postId);
        $this->assertSame($user->id, $message->fromuser);
        $this->assertSame(Message::SOURCE_EMAIL, $message->source);
        $this->assertSame(Message::TYPE_OFFER, $message->type);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg, 'Expected a messages_groups row');
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
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

        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg);
        $this->assertFalse($mg->mod_messaging_allowed);
    }

    /**
     * The email path stopped approving unmoderated posters on arrival - they start
     * Pending so messages:contentcheck can gate them - and this path must do the
     * same. It previously mapped DEFAULT/UNMODERATED straight to approved, which
     * let a TN post that a content rule would block go live unchecked.
     *
     * Pending here must NOT notify mods or index the post: both belong to the
     * content-check job, so a clean post creates no mod work when it is promoted a
     * minute later, and a flagged one is never visible in the meantime.
     */
    public function test_unmoderated_poster_starts_pending_for_the_content_check(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'UNMODERATED']);

        $notified = false;
        Log::listen(function ($message) use (&$notified) {
            if (str_contains((string) $message->message, 'notified group mods')) {
                $notified = true;
            }
        });

        $postId = 'tn-awaiting-check-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->id,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $this->assertSame('pending', $this->makeService(dryRun: false)->ingest($post, $group));

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
        $this->assertNull($mg->approvedat, 'A post awaiting the content check has not been approved');
        // Left for the content check to do on promotion.
        $this->assertNull($mg->contentcheck_checked_at, 'Ingestion must not stamp the content check itself');
        $this->assertFalse($notified, 'Mods must not be notified for a post that is only awaiting the content check');
        $this->assertSame(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'A post awaiting the content check must not be in the spatial index',
        );
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

        $this->assertSame('pending', $first);
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
        $this->assertSame('pending', $first);
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

    /**
     * The 'TN-' prefix on messages.sourceheader is load-bearing: the monthly
     * LoveJunk invoice splits revenue on `sourceheader LIKE 'TN-%'`
     * (LoveJunkInvoiceService), LoveJunkService attributes the post's source by it,
     * and ProcessBackgroundTasksCommand uses it to skip creating freebie alerts for
     * TN posts (TN syndicates those itself). A post ingested by the API must
     * therefore be recognisable as TN, exactly as the email-path one is.
     */
    public function test_live_stamps_a_tn_sourceheader(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-src-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertSame(GroupPostIngestionService::SOURCEHEADER, $message->sourceheader);
        $this->assertStringStartsWith('TN-', (string) $message->sourceheader);
        $this->assertStringContainsString('X-Trash-Nothing-Source: API', $message->message);
    }

    // -------------------------------------------------------------------------
    // Worry words. Mirrors the email-path tests in IncomingMailServiceTest
    // (test_contains_worry_words_*): both paths must read concern_keywords, so
    // that identical content is routed identically however it arrives.
    //
    // Every unmoderated poster's post is now Pending on arrival (the content check
    // promotes the clean ones), so 'pending' alone no longer says whether the worry
    // words bit. The reason on the collection=Pending trace line does: 'worry words'
    // when held, 'posting-status' when the post is merely awaiting the content check.
    // -------------------------------------------------------------------------

    /**
     * Ingest live and return the reason from the collection=Pending trace line,
     * or null if the post was not routed to Pending at all.
     */
    private function ingestAndCapturePendingReason(array $post, $group): ?string
    {
        $reasons = [];
        Log::listen(function ($message) use (&$reasons) {
            $text = (string) $message->message;
            if (str_contains($text, 'set=collection=Pending reason=')) {
                $reasons[] = substr($text, strpos($text, 'reason=') + strlen('reason='));
            }
        });

        $this->makeService(dryRun: false)->ingest($post, $group);

        return $reasons[0] ?? null;
    }

    public function test_worry_words_hold_post_for_concern_keyword(): void
    {
        // Sanity check bounding the whitelist tests below: a genuine
        // (non-whitelisted) concern keyword must still hold the post.
        DB::table('concern_keywords')->insert([
            'keyword'  => 'cash',
            'category' => 'review',
            'action'   => 'flag',
        ]);

        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post = $this->makePost([
            'post_id' => 'tn-worry-' . uniqid(),
            'user_id' => $user->id,
            'title'   => 'Sofa, cash on collection',
            'content' => 'Collection only, please bring a van.',
        ]);

        $this->assertSame('worry words', $this->ingestAndCapturePendingReason($post, $group));
    }

    public function test_worry_words_ignore_stale_legacy_worrywords_table(): void
    {
        // Regression guard (Discourse #9944/7): subjectContainsWorryWords() used to
        // read the legacy 'worrywords' table, a one-time migration snapshot that is
        // never written to again (see MigrateConcernKeywordsCommand). A row inserted
        // only there must NOT be able to hold a post - if it does, this method is
        // checking the wrong table again.
        DB::table('worrywords')->insert([
            'keyword' => 'puppy',
            'type'    => 'Review',
        ]);

        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post = $this->makePost([
            'post_id' => 'tn-worry-legacy-' . uniqid(),
            'user_id' => $user->id,
            'title'   => 'Free puppy',
            'content' => 'Adorable puppy needs a good home.',
        ]);

        // Pending only because it is awaiting the content check, NOT because the
        // legacy row held it.
        $this->assertSame('posting-status', $this->ingestAndCapturePendingReason($post, $group));
    }

    public function test_worry_words_respect_whitelisted_phrase_despite_contained_keyword(): void
    {
        // Discourse #9944/7: 'Cashes Green' was whitelisted via the concern_keywords
        // 'allowed' category (the current admin UI), but the TN API path kept holding
        // such posts because it read the legacy 'worrywords' table, which never
        // received the whitelist row - so identical content was held here while being
        // let through by email.
        //
        // The single-word check is EXACT match only (levenshtein < 1), so this uses a
        // keyword that is a whole word inside the whitelisted phrase ('green' inside
        // 'Cashes Green'). The legacy table is seeded with the same keyword (but NOT
        // the whitelist row, which only ever existed in concern_keywords) so the test
        // genuinely fails against the pre-fix code rather than passing because the
        // legacy table had nothing to match on.
        DB::table('worrywords')->insert([
            'keyword' => 'green',
            'type'    => 'Review',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'  => 'green',
            'category' => 'review',
            'action'   => 'flag',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'  => 'Cashes Green',
            'category' => 'allowed',
            'action'   => 'flag',
        ]);

        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post = $this->makePost([
            'post_id' => 'tn-worry-allowed-' . uniqid(),
            'user_id' => $user->id,
            'title'   => 'Sofa near Cashes Green (Stroud)',
            'content' => 'Collection only, please bring a van.',
        ]);

        // Whitelisted phrase must suppress the contained 'green' match, leaving the
        // post Pending only for the content check rather than held for worry words.
        $this->assertSame('posting-status', $this->ingestAndCapturePendingReason($post, $group));
    }

    // -------------------------------------------------------------------------
    // Reposts vs crossposts. TN keeps a SOURCE post (no group_id) plus one COPY
    // per group it was sent to (group_id set), so each group's mods can moderate
    // their own copy. Copies are crossposts and are discarded — Freegle does its
    // own cross-posting via rippling. A repost is a new source post, so it has no
    // group_id and is kept as its own message, matching the email path.
    // -------------------------------------------------------------------------

    public function test_repost_creates_a_second_message_rather_than_bumping_the_original(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $original = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Old wooden bookshelf',
            'lat'     => 55.9533,
            'lng'     => -3.1883,
            'arrival' => now()->subMinutes(10),
        ]);
        $originalArrival = MessageGroup::where('msgid', $original->id)->where('groupid', $group->id)->first()->arrival;

        // Same poster, same item, same spot, new TN post_id and no group_id —
        // a repost (a new source post), which is kept, matching the email path.
        $postId = 'tn-repost-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->id,
            'title'     => 'Old wooden bookshelf',
            'latitude'  => 55.9534,
            'longitude' => -3.1882,
            'date'      => now()->addMinute()->toIso8601String(),
        ]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);

        $reposted = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($reposted, 'A repost must create its own new message');
        $this->assertNotSame($original->id, $reposted->id, 'The repost must be a separate row, not the original re-pointed');

        // The original is left entirely alone — no bump, no Repost log.
        $mg = MessageGroup::where('msgid', $original->id)->where('groupid', $group->id)->first();
        $this->assertSame(0, $mg->autoreposts);
        $this->assertEquals($originalArrival, $mg->arrival);
        $this->assertSame(0, DB::table('logs')->where('msgid', $original->id)->where('subtype', 'Repost')->count());
        $this->assertSame(0, DB::table('messages_postings')->where('msgid', $original->id)->where('repost', 1)->count());
    }

    public function test_post_with_a_tn_group_id_is_discarded_as_a_crosspost(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-crosspost-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'group_id'  => '8444', // TN's per-group copy — a crosspost.
            'user_id'   => $user->id,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('crosspost', $result);
        $this->assertNull(Message::where('tnpostid', $postId)->first(), 'A per-group copy must not create a message');
    }

    public function test_source_post_without_a_group_id_is_ingested(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-source-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'group_id'  => null,
            'user_id'   => $user->id,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);
        $this->assertNotNull(Message::where('tnpostid', $postId)->first());
    }

    public function test_empty_string_group_id_counts_as_a_source_post(): void
    {
        // TN has been seen returning '' rather than null for "no group"; that is
        // still a source post, not a copy, and must not be silently discarded.
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-source-empty-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'group_id'  => '',
            'user_id'   => $user->id,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $this->assertSame('pending', $this->makeService(dryRun: false)->ingest($post, $group));
        $this->assertNotNull(Message::where('tnpostid', $postId)->first());
    }

    public function test_the_same_tn_post_id_is_still_skipped_as_a_duplicate_on_the_same_group(): void
    {
        // The one de-duplication that remains: (tnpostid, groupid) idempotency,
        // so an overlapping sync window doesn't ingest the same TN post twice.
        $locationId = $this->createTestLocation();
        $user  = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-same-postid-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->id,
            'title'     => 'Old wooden bookshelf',
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $service = $this->makeService(dryRun: false);
        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame('duplicate', $service->ingest($post, $group));
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
    }

    // -------------------------------------------------------------------------
    // Routing context — the input to the caller's single Loki entry.
    //
    // This service does not log to Loki itself, exactly as IncomingMailService
    // doesn't: it accumulates context that PostSyncer turns into one entry. So
    // these tests assert getLastRoutingContext(), the same seam the email path
    // exposes. End-to-end payload/label shape is covered by TnApiLokiParityTest.
    // See plans/tn-api-post-ingestion.md section I.
    // -------------------------------------------------------------------------

    public function test_ingested_post_sets_full_routing_context(): void
    {
        $locationId = $this->createTestLocation();
        $user = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $postId = 'tn-ctx-ingested-'.uniqid();
        $post = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::PENDING, GroupPostIngestionService::outcomeFor('pending'));
        // The 'approved' mapping is still exercised: the write branch is retained for
        // any future caller, mirroring the same retained branch on the email path.
        $this->assertSame(RoutingResult::APPROVED, GroupPostIngestionService::outcomeFor('approved'));

        $context = $service->getLastRoutingContext();
        $this->assertSame($group->id, $context['group_id']);
        $this->assertSame($group->nameshort, $context['group_name']);
        $this->assertSame($user->id, $context['user_id']);
        $this->assertArrayNotHasKey('routing_reason', $context);
        // The join key back to messages.tnpostid for the email-side comparison.
        $this->assertSame(Message::where('tnpostid', $postId)->first()->id, $context['message_id']);
    }

    public function test_pending_context_omits_the_pending_reason(): void
    {
        // The email path computes the same reason and keeps it out of its Loki
        // context (case 9), so including it here would read as a difference
        // between the paths where none exists.
        $user = $this->createTestUser(['lastlocation' => null]);  // unmapped -> pending
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $post = $this->makePost(['post_id' => 'tn-ctx-pending-'.uniqid(), 'user_id' => $user->id]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::PENDING, GroupPostIngestionService::outcomeFor('pending'));
        $this->assertArrayNotHasKey('routing_reason', $service->getLastRoutingContext());
    }

    public function test_duplicate_sets_a_duplicate_reason_and_nothing_else(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $postId = 'tn-ctx-dup-'.uniqid();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Already ingested',
            'textbody' => 'body',
            'source' => 'TN-API',
            'tnpostid' => $postId,
            'date' => now(),
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        $post = $this->makePost(['post_id' => $postId, 'user_id' => $user->id]);
        $service = $this->makeService();

        $this->assertSame('duplicate', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::DROPPED, GroupPostIngestionService::outcomeFor('duplicate'));
        // dropped() REPLACES the context, so an early drop carries the reason
        // and nothing else — exactly as the email path's early drops do.
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_DUPLICATE],
            $service->getLastRoutingContext(),
        );
    }

    public function test_unknown_user_sets_the_email_paths_own_reason_wording(): void
    {
        // Mirrors email-path case 2, including its omission of group context.
        $group = $this->createTestGroup();
        $post = $this->makePost(['post_id' => 'tn-ctx-unknown-'.uniqid(), 'user_id' => 999999999]);
        $service = $this->makeService(dryRun: true);

        $this->assertSame('skipped', $service->ingest($post, $group));
        $this->assertSame(
            ['routing_reason' => 'Post from unknown user'],
            $service->getLastRoutingContext(),
        );
    }

    public function test_prohibited_keeps_group_context_but_sets_no_reason(): void
    {
        // Mirrors email-path case 6, which returns DROPPED without going through
        // dropped() and so carries group/user context and no routing_reason.
        $locationId = $this->createTestLocation();
        $user = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'PROHIBITED']);

        $post = $this->makePost(['post_id' => 'tn-ctx-prohibited-'.uniqid(), 'user_id' => $user->id]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('dropped', $service->ingest($post, $group));

        $context = $service->getLastRoutingContext();
        $this->assertArrayNotHasKey('routing_reason', $context);
        $this->assertSame($group->id, $context['group_id']);
        $this->assertSame($user->id, $context['user_id']);
        $this->assertArrayNotHasKey('message_id', $context);
    }

    public function test_crosspost_sets_a_crosspost_reason_explaining_the_divergence(): void
    {
        // A TN per-group COPY is discarded (Freegle ripples instead), whereas
        // the email path ingests one message per group copy. That volume
        // difference is intended, so it must be explained in the stream rather
        // than looking like posts going missing.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $post = $this->makePost([
            'post_id' => 'tn-ctx-crosspost-'.uniqid(),
            'user_id' => $user->id,
            'group_id' => '8444',  // TN's per-group copy.
        ]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('crosspost', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::DROPPED, GroupPostIngestionService::outcomeFor('crosspost'));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_CROSSPOST],
            $service->getLastRoutingContext(),
        );
    }

    public function test_context_is_reset_between_posts(): void
    {
        // Mirrors IncomingMailService::route() clearing the context at entry:
        // without it, a drop would inherit the previous post's group/user.
        $locationId = $this->createTestLocation();
        $user = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $service = $this->makeService(dryRun: false);

        $service->ingest($this->makePost(['post_id' => 'tn-ctx-first-'.uniqid(), 'user_id' => $user->id]), $group);
        $this->assertArrayHasKey('message_id', $service->getLastRoutingContext());

        // A crosspost drops before any group/user context is set.
        $service->ingest($this->makePost([
            'post_id' => 'tn-ctx-second-'.uniqid(),
            'user_id' => $user->id,
            'group_id' => '8444',
        ]), $group);

        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_CROSSPOST],
            $service->getLastRoutingContext(),
            'Context from the previous post must not leak into this one',
        );
    }

    public function test_repost_routes_like_any_other_source_post(): void
    {
        // Reposts are no longer de-duplicated (they match the email path: a
        // repost is a new source post and creates its own message), so there is
        // no special repost outcome — it routes, and is logged, exactly like a
        // first-time post. This is what closed the old "what subtype should
        // `reposted` use?" question.
        $locationId = $this->createTestLocation();
        $user = $this->createTestUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $original = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Electric sander',
            'textbody' => 'body',
            'source' => 'TN-API',
            'tnpostid' => 'tn-ctx-orig-'.uniqid(),
            'date' => now()->subDays(2),
            'lat' => 55.9533,
            'lng' => -3.1883,
        ]);
        MessageGroup::create([
            'msgid' => $original->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(2),
        ]);

        $postId = 'tn-ctx-repost-'.uniqid();
        $post = $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->id,
            'title' => 'Electric sander',
            'type' => 'offer',
            'latitude' => 55.9533,
            'longitude' => -3.1883,
            'date' => now()->toIso8601String(),
        ]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('pending', $service->ingest($post, $group));

        $context = $service->getLastRoutingContext();
        $this->assertArrayNotHasKey('routing_reason', $context);
        // Its own new message, not the original.
        $this->assertSame(Message::where('tnpostid', $postId)->first()->id, $context['message_id']);
        $this->assertNotSame($original->id, $context['message_id']);
    }

    // -------------------------------------------------------------------------
    // Photo selection
    // -------------------------------------------------------------------------

    /**
     * @return string|null the URL bestPhotoUrl() would download for $photo
     */
    private function bestPhotoUrl(mixed $photo): ?string
    {
        $method = new \ReflectionMethod(GroupPostIngestionService::class, 'bestPhotoUrl');

        return $method->invoke($this->makeService(), $photo);
    }

    public function test_best_photo_url_takes_the_largest_image_not_the_smallest(): void
    {
        // TN documents photos[].images as ordered SMALLEST to largest (see
        // PublicApi/docs/Model/Photo.md), so images[0] is a thumbnail. Taking
        // it ingested a 220x294 image live where the email path, which scrapes
        // the post body, got the 1200x900 original for the same post.
        $photo = [
            'url' => 'https://trashnothing.com/img/large.jpg',
            'images' => [
                ['url' => 'https://trashnothing.com/img/photo.220x294.jpg', 'width' => 220, 'height' => 294],
                ['url' => 'https://trashnothing.com/img/photo.600x450.jpg', 'width' => 600, 'height' => 450],
                ['url' => 'https://trashnothing.com/img/photo.1200x900.jpg', 'width' => 1200, 'height' => 900],
            ],
        ];

        $this->assertSame('https://trashnothing.com/img/photo.1200x900.jpg', $this->bestPhotoUrl($photo));
    }

    public function test_best_photo_url_falls_back_to_the_url_field_when_there_are_no_images(): void
    {
        $photo = ['url' => 'https://trashnothing.com/img/large.jpg', 'images' => []];

        $this->assertSame('https://trashnothing.com/img/large.jpg', $this->bestPhotoUrl($photo));
    }

    public function test_best_photo_url_falls_back_when_the_largest_image_carries_no_url(): void
    {
        // Every field on TN's Photo/PhotoImagesInner models is optional, so a
        // malformed last entry must not lose the photo entirely.
        $photo = [
            'url' => 'https://trashnothing.com/img/large.jpg',
            'images' => [
                ['url' => 'https://trashnothing.com/img/photo.220x294.jpg', 'width' => 220],
                ['width' => 1200],
            ],
        ];

        $this->assertSame('https://trashnothing.com/img/large.jpg', $this->bestPhotoUrl($photo));
    }

    public function test_best_photo_url_returns_null_when_the_photo_has_nothing_usable(): void
    {
        $this->assertNull($this->bestPhotoUrl(['images' => []]));
    }
}
