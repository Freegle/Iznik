<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UnifiedDigestServiceTest extends TestCase
{
    protected UnifiedDigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnifiedDigestService();
        Mail::fake();
        // Rippling ships dark; enable it so the reach-coordination ledger path is exercised.
        config(['freegle.ripple.enabled' => true]);
    }

    public function test_deduplication_with_tnpostid(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Create two messages with same tnpostid (cross-posted via TN).
        $message1 = $this->createTestMessage($user, $group1, [
            'tnpostid' => 'TN12345',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'tnpostid' => 'TN12345',
            'subject' => $message1->subject,
        ]);

        // Add groupid attribute for deduplication test.
        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
    }

    public function test_completed_came_and_went_posts_are_deduplicated_like_live(): void
    {
        // The same item, cross-posted to two groups as two separate messages
        // (distinct ids, shared tnpostid) — and both Taken/Received, so they
        // land in the greyed daily "came and went" section.
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $message1 = $this->createTestMessage($user, $group1, [
            'tnpostid' => 'TN54321',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'tnpostid' => 'TN54321',
            'subject' => $message1->subject,
        ]);
        $message1->load('groups');
        $message2->load('groups');
        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $completed = collect([$message1, $message2]);

        // The old came-and-went path used ->unique('id'), which keeps both
        // because the msgids differ — that's the duplication we're fixing.
        $this->assertCount(2, $completed->unique('id')->values());

        // The fix collapses the cross-post to a single card, exactly like the
        // live section's deduplicatePosts().
        $deduped = $this->service->deduplicateCompletedPosts($completed);
        $this->assertCount(1, $deduped);
        $this->assertEquals($message1->id, $deduped->first()->id);

        // Both groups are folded into the surviving card, so the byline reads
        // "Posted to: A, B" just like the live section — not a single group.
        $this->assertEqualsCanonicalizing(
            [$group1->id, $group2->id],
            $deduped->first()->groups->pluck('id')->all()
        );
    }

    public function test_deduplication_single_message_on_multiple_groups(): void
    {
        // The multi-group model: ONE messages row with two messages_groups
        // rows. The digest query joins messages to messages_groups, so the
        // same messages.id comes back once per group with a different groupid.
        // deduplicatePosts must collapse those into a single digest entry that
        // lists both groups.
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $message = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Single multi-group item (London)',
            'textbody' => 'One physical item, posted to two groups.',
        ]);

        // Second messages_groups row — same msgid, different group.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        // Reproduce the join output: the same message as two rows differing
        // only by groupid.
        $row1 = $message->fresh();
        $row1->groupid = $group1->id;
        $row2 = $message->fresh();
        $row2->groupid = $group2->id;

        $posts = collect([$row1, $row2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated);
        $this->assertEquals($message->id, $deduplicated->first()['message']->id);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
        $this->assertEqualsCanonicalizing(
            [$group1->id, $group2->id],
            $deduplicated->first()['postedToGroups']
        );
    }

    /**
     * Regression (Discourse #9850): a poster reposts one item under the same subject/location with
     * a slightly reworded body, and BOTH reposts ripple into several groups. The two reposts share
     * a dedup key but differ by body. Each repost's own multi-group copies must still collapse into
     * a single card — the earlier code kept only the FIRST post per key as a merge target, so the
     * second repost's copies each failed bodiesMatch against the first and became a separate card
     * (linda_rowlands' bed appeared ~10x). Expect exactly TWO cards (one per repost), each listing
     * all its groups — not one-card-per-group.
     */
    public function test_deduplication_two_reworded_reposts_each_collapse_across_groups(): void
    {
        $user = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        // Two reposts of the same item: same subject + location, slightly different body.
        $repost1 = $this->createTestMessage($user, $groupA, [
            'subject' => 'OFFER: Divan bed frame (London)',
            'textbody' => '5ft wide retail bedframe for queen sized divan. Dismantled.',
        ]);
        $repost2 = $this->createTestMessage($user, $groupA, [
            'subject' => 'OFFER: Divan bed frame (London)',
            'textbody' => '5ft wide retail bedframe only for queen. Dismantled, all fittings.',
        ]);
        $repost2->locationid = $repost1->locationid;
        $repost2->save();

        // Each repost appears on BOTH groups (the join produces one row per (msgid, group)),
        // interleaved as the real query would return them by arrival.
        $mk = function ($msg, $groupid) {
            $row = $msg->fresh();
            $row->locationid = $msg->locationid;
            $row->groupid = $groupid;
            return $row;
        };
        $posts = collect([
            $mk($repost1, $groupA->id), $mk($repost2, $groupA->id),
            $mk($repost1, $groupB->id), $mk($repost2, $groupB->id),
        ]);

        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(2, $deduplicated, 'each reworded repost is one card, not one card per group');
        foreach ($deduplicated as $card) {
            $this->assertCount(2, $card['postedToGroups'], 'each repost card lists both its groups');
            $this->assertEqualsCanonicalizing([$groupA->id, $groupB->id], $card['postedToGroups']);
        }
    }

    public function test_deduplication_without_tnpostid(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Create two similar messages without tnpostid but with same subject/location.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Test Item (London)',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Test Item (London)',
        ]);

        // Set same locationid after creation (nullable, no FK constraint).
        $message1->locationid = $message1->locationid;
        $message2->locationid = $message1->locationid;

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
    }

    public function test_different_items_not_deduplicated(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message1 = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);
        $message2 = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Table (London)',
        ]);

        $message1->groupid = $group->id;
        $message2->groupid = $group->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(2, $deduplicated);
    }

    public function test_user_digest_tracker_created(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        // Set recipient to want daily digests and be active. V1 parity:
        // membership emailfrequency=24 is the authoritative daily selector;
        // simplemail acts only as the join-time default that populated it.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // Create a message from another user (so recipient has something to receive).
        $this->createTestMessage($poster, $group);

        // Run digest - should create tracker and send email.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $tracker = UserDigest::where('userid', $recipient->id)
            ->where('mode', UnifiedDigestService::MODE_DAILY)
            ->first();

        $this->assertNotNull($tracker);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_digest_excludes_posts_with_an_outcome(): void
    {
        // V1 parity (Digest.php:218): a post that already has an outcome
        // (Withdrawn/Taken/Received/...) is no longer available and must not
        // appear in the digest — it was advertising withdrawn items as live.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // The only post in range has been withdrawn.
        $message = $this->createTestMessage($poster, $group);
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => 'Withdrawn',
            'timestamp' => now(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(0, $stats['emails_sent'], 'a withdrawn/taken post must not be digested');
    }

    public function test_daily_digest_flags_already_seen_posts_for_the_recipient(): void
    {
        // A messages_likes 'View' (in-app view, or an opened/clicked digest via
        // mail:digest:mark-seen) marks the post seen for THAT recipient, so the
        // daily digest can sink it below fresh posts (config freegle.digest.seen_penalty).
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $seen = $this->createTestMessage($poster, $group);
        $unseen = $this->createTestMessage($poster, $group);

        DB::table('messages_likes')->insert([
            'msgid' => $seen->id, 'userid' => $recipient->id, 'type' => 'View', 'count' => 0,
        ]);

        $tracker = UserDigest::create([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
        ]);

        $posts = $this->service->getPostsForUser($recipient, $tracker, UnifiedDigestService::MODE_DAILY);
        $byId = $posts->keyBy('id');

        $this->assertNotNull($byId->get($seen->id), 'seen post is a candidate');
        $this->assertTrue((bool) $byId->get($seen->id)->seen_by_user, 'viewed post is flagged seen_by_user');
        $this->assertFalse((bool) $byId->get($unseen->id)->seen_by_user, 'un-viewed post is not flagged');
    }

    public function test_daily_digest_with_available_and_taken_still_sends(): void
    {
        // An available post + a Taken post: the digest still goes (1 email);
        // the available post is the main content and the Taken one feeds the
        // "came and went" section rather than blocking the send.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $this->createTestMessage($poster, $group); // available
        $taken = $this->createTestMessage($poster, $group);
        DB::table('messages_outcomes')->insert([
            'msgid' => $taken->id,
            'outcome' => 'Taken',
            'timestamp' => now(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent'], 'available post still sends; taken feeds came-and-went');
    }

    public function test_daily_digest_skips_user_already_sent_today(): void
    {
        // Bulk daily run (no --user) must not re-send to a user who already
        // got a daily digest earlier the same London day, even though new
        // posts exist — guards against the multi-send seen on 2026-06-11 when
        // the command was run several times in one day. A new London day (the
        // next 08:00 cron) re-includes them.
        config(['freegle.digest.daily_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createTestMessage($poster, $group);

        // Already digested at the start of today's London day, cursor at 0 so
        // there ARE newer posts — only the once-today guard should hold it back.
        UserDigest::create([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
            'lastsent' => \Carbon\Carbon::now('Europe/London')->startOfDay()->setTimezone('UTC'),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);
        $this->assertEquals(0, $stats['emails_sent'], 'must skip a user already digested today');

        // Move the last-sent mark into yesterday (London); now eligible again.
        UserDigest::where('userid', $recipient->id)
            ->where('mode', UnifiedDigestService::MODE_DAILY)
            ->update(['lastsent' => \Carbon\Carbon::now('Europe/London')->startOfDay()->subHours(2)->setTimezone('UTC')]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);
        $this->assertEquals(1, $stats['emails_sent'], 'must send once the last digest was a prior day');
    }

    public function test_daily_digest_streams_most_overdue_first(): void
    {
        // The daily bulk run must process recipients MOST-OVERDUE-FIRST: never-sent users, then
        // oldest lastsent. When the send window can't clear the whole population, id-order
        // (the old lazyById streaming) permanently starves the same high-id tail; overdue-first
        // rotates the lag fairly. Regression for streamDailyOverdueFirst.
        config(['freegle.digest.daily_allowlist' => '*']);

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $this->createTestMessage($poster, $group);

        $mk = function () use ($group) {
            $u = $this->createTestUser();
            $u->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
            $u->lastaccess = now();
            $u->save();
            $this->createMembership($u, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
            return $u->fresh();
        };

        $neverSent = $mk();
        $old = $mk();
        $recent = $mk();

        $prior = fn ($days) => \Carbon\Carbon::now('Europe/London')->startOfDay()->subDays($days)->setTimezone('UTC');
        // never-sent: deliberately NO users_digests row (NULL lastsent → most overdue).
        UserDigest::create(['userid' => $old->id, 'mode' => UnifiedDigestService::MODE_DAILY, 'lastmsgid' => 0, 'lastsent' => $prior(10)]);
        UserDigest::create(['userid' => $recent->id, 'mode' => UnifiedDigestService::MODE_DAILY, 'lastmsgid' => 0, 'lastsent' => $prior(1)]);

        // Invoke the protected streamer directly and capture the yielded order.
        $ref = new \ReflectionMethod($this->service, 'getUsersForDigest');
        $ref->setAccessible(true);
        $stream = $ref->invoke($this->service, UnifiedDigestService::MODE_DAILY);
        $ids = $stream->pluck('id')->all();

        // Other fixture users may share the stream — assert only the RELATIVE order of our three.
        $ourOrder = array_values(array_filter($ids, fn ($id) => in_array($id, [$neverSent->id, $old->id, $recent->id], true)));
        $this->assertEquals(
            [$neverSent->id, $old->id, $recent->id],
            $ourOrder,
            'daily stream must yield never-sent first, then oldest lastsent, then most recent'
        );
    }

    /**
     * Regression for the 2026-07-05 daily-digest flood: a digest whose only content is a
     * pinned post has an EMPTY cursor-post set ($allPosts) but STILL sends an email.
     * updateDigestTracker must stamp lastsent in that case so the once-per-London-day
     * guard skips the user on the next tick — otherwise the digest re-sends every minute.
     */
    public function test_update_tracker_stamps_lastsent_when_email_sent_with_no_cursor_posts(): void
    {
        $user = $this->createTestUser();
        $old = \Carbon\Carbon::now()->subDays(2)->setTimezone('UTC');
        $tracker = UserDigest::create([
            'userid' => $user->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
            'lastsent' => $old,
        ]);

        $ref = new \ReflectionMethod($this->service, 'updateDigestTracker');
        $ref->setAccessible(true);

        $oldRaw = $tracker->fresh()->getRawOriginal('lastsent');

        // Email sent, but no cursor posts (pinned-only digest): lastsent MUST advance.
        $ref->invoke($this->service, $tracker->fresh(), collect(), true);
        $this->assertNotEquals(
            $oldRaw,
            $tracker->fresh()->getRawOriginal('lastsent'),
            'lastsent must be stamped when a daily email was sent with no cursor posts'
        );

        // No email sent and no posts: lastsent must NOT change (never mark unsent users).
        $tracker->update(['lastsent' => $old]);
        $resetRaw = $tracker->fresh()->getRawOriginal('lastsent');
        $ref->invoke($this->service, $tracker->fresh(), collect(), false);
        $this->assertEquals(
            $resetRaw,
            $tracker->fresh()->getRawOriginal('lastsent'),
            'lastsent must not change when no email was sent'
        );
    }

    public function test_format_posted_to_multiple_groups(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $result = $this->service->formatPostedTo([$group1->id, $group2->id]);

        $this->assertStringContainsString('Posted to:', $result);
        $this->assertStringContainsString($group1->nameshort, $result);
        $this->assertStringContainsString($group2->nameshort, $result);
    }

    public function test_format_posted_to_single_group_returns_empty(): void
    {
        $group = $this->createTestGroup();

        $result = $this->service->formatPostedTo([$group->id]);

        $this->assertEmpty($result);
    }

    public function test_digest_includes_users_own_posts(): void
    {
        // V1 parity: the per-group digest selection in the legacy V1 PHP
        // Digest implementation has no fromuser != ? filter, so a user's
        // own posts appear in their own digest. Mirror that here.
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $this->createTestMessage($recipient, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_deduplication_same_subject_different_body_not_deduped(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Two messages with same subject but different body text.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Garden tools (London)',
            'textbody' => 'I have a spade and a fork available for collection.',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Garden tools (London)',
            'textbody' => 'Lawnmower available, needs collecting this weekend.',
        ]);

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should NOT be deduplicated because bodies are different.
        $this->assertCount(2, $deduplicated);
    }

    public function test_deduplication_same_subject_same_body_deduped(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $bodyText = 'I have a lovely sofa available for collection.';

        // Two messages with same subject AND same body.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Sofa (London)',
            'textbody' => $bodyText,
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Sofa (London)',
            'textbody' => $bodyText,
        ]);

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should be deduplicated because both subject and body match.
        $this->assertCount(1, $deduplicated);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
    }

    public function test_deduplication_null_body_treated_as_matching(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Two messages with same subject and both null bodies.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Table (London)',
            'textbody' => null,
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Table (London)',
            'textbody' => null,
        ]);

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should be deduplicated - null bodies both normalize to ''.
        $this->assertCount(1, $deduplicated);
    }

    public function test_sponsors_are_included_and_deduplicated(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Recipient wants daily digests for both groups — V1 parity requires
        // per-group emailfrequency=24 on each membership.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group1);
        $this->createMembership($poster, $group2);
        $this->createMembership($recipient, $group1, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($recipient, $group2, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // Create messages so the digest has content.
        $this->createTestMessage($poster, $group1);
        $this->createTestMessage($poster, $group2);

        // Same sponsor on both groups (Essex-style: one sponsor, many groups).
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group1->id,
            'name' => 'Essex County Council',
            'linkurl' => 'https://essex.gov.uk',
            'imageurl' => 'https://essex.gov.uk/logo.png',
            'tagline' => 'Supporting reuse in Essex',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Test Contact',
            'contactemail' => 'test@essex.gov.uk',
            'amount' => 100,
            'visible' => TRUE,
        ]);
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group2->id,
            'name' => 'Essex County Council',
            'linkurl' => 'https://essex.gov.uk',
            'imageurl' => 'https://essex.gov.uk/logo.png',
            'tagline' => 'Supporting reuse in Essex',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Test Contact',
            'contactemail' => 'test@essex.gov.uk',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        // Different sponsor on group2 only.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group2->id,
            'name' => 'Local Business',
            'linkurl' => 'https://localbiz.example.com',
            'imageurl' => 'https://localbiz.example.com/logo.png',
            'tagline' => null,
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Biz Contact',
            'contactemail' => 'biz@example.com',
            'amount' => 50,
            'visible' => TRUE,
        ]);

        // Get sponsors for this user — should deduplicate Essex across groups.
        $sponsors = $this->service->getSponsorsForUser($recipient);

        // Should have 2 unique sponsors, not 3.
        $this->assertCount(2, $sponsors);

        // Essex should appear once with the highest amount.
        $essex = $sponsors->firstWhere('name', 'Essex County Council');
        $this->assertNotNull($essex);
        $this->assertEquals('https://essex.gov.uk', $essex->linkurl);
        $this->assertEquals('Supporting reuse in Essex', $essex->tagline);

        // Local Business should appear once.
        $localBiz = $sponsors->firstWhere('name', 'Local Business');
        $this->assertNotNull($localBiz);
    }

    public function test_get_sponsors_for_group_returns_only_that_groups_sponsors(): void
    {
        // V1 parity for immediate digests: an email about group A must carry
        // only group A's sponsors, never the union across the recipient's other
        // groups (which getSponsorsForUser returns for the daily digest).
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        DB::table('groups_sponsorship')->insert([
            'groupid' => $groupA->id,
            'name' => 'Group A Sponsor',
            'linkurl' => 'https://a.example.com',
            'imageurl' => 'https://a.example.com/logo.png',
            'tagline' => 'Backs group A',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'A',
            'contactemail' => 'a@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);
        DB::table('groups_sponsorship')->insert([
            'groupid' => $groupB->id,
            'name' => 'Group B Sponsor',
            'linkurl' => 'https://b.example.com',
            'imageurl' => 'https://b.example.com/logo.png',
            'tagline' => 'Backs group B',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'B',
            'contactemail' => 'b@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        $sponsors = $this->service->getSponsorsForGroup($groupA->id);

        $this->assertCount(1, $sponsors);
        $this->assertEquals('Group A Sponsor', $sponsors->first()->name);
        $this->assertNull($sponsors->firstWhere('name', 'Group B Sponsor'));
    }

    public function test_get_sponsors_for_group_excludes_expired_and_invisible(): void
    {
        $group = $this->createTestGroup();

        // Expired.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Expired Sponsor',
            'linkurl' => 'https://x.example.com',
            'imageurl' => null,
            'tagline' => null,
            'startdate' => now()->subMonths(2),
            'enddate' => now()->subMonth(),
            'contactname' => 'X',
            'contactemail' => 'x@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);
        // Hidden.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Hidden Sponsor',
            'linkurl' => 'https://y.example.com',
            'imageurl' => null,
            'tagline' => null,
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Y',
            'contactemail' => 'y@example.com',
            'amount' => 100,
            'visible' => FALSE,
        ]);
        // Active + visible.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Active Sponsor',
            'linkurl' => 'https://z.example.com',
            'imageurl' => null,
            'tagline' => null,
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Z',
            'contactemail' => 'z@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        $sponsors = $this->service->getSponsorsForGroup($group->id);

        $this->assertCount(1, $sponsors);
        $this->assertEquals('Active Sponsor', $sponsors->first()->name);
    }

    public function test_get_sponsors_for_group_returns_empty_for_zero_group(): void
    {
        $this->assertTrue($this->service->getSponsorsForGroup(0)->isEmpty());
    }

    public function test_expired_sponsors_are_excluded(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Expired sponsor.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Old Sponsor',
            'startdate' => now()->subYear(),
            'enddate' => now()->subMonth(),
            'contactname' => 'Old',
            'contactemail' => 'old@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        // Hidden sponsor.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Hidden Sponsor',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Hidden',
            'contactemail' => 'hidden@example.com',
            'amount' => 100,
            'visible' => FALSE,
        ]);

        $sponsors = $this->service->getSponsorsForUser($user);
        $this->assertCount(0, $sponsors);
    }

    // PER-USER ELIGIBILITY TESTS REMOVED — they were based on the prior
    // per-user iteration model. The new V1-parity per-group iteration
    // determines recipients purely by memberships.emailfrequency=-1 within
    // each group it walks; standalone "would THIS user be eligible?" tests
    // don't map cleanly. The new per-group tests at the end of this file
    // cover the equivalent guarantees (fanout, cursor advance, tie-break,
    // allowlist gate, dry-run, --limit, --group, --user).

    // (further per-user immediate-mode tests removed — superseded by the
    // per-group iteration tests near the end of the file.)

    /**
     * Daily mode must NOT fan out — every new post since the previous send
     * is bundled into a single rolled-up email regardless of how many there
     * are.
     */
    public function test_daily_mode_bundles_all_posts_into_one_email(): void
    {
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: C (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    /**
     * Daily defaults to OFF (empty allowlist = nobody) so a deploy can't
     * double-mail the whole userbase alongside V1's still-running daily cron.
     * A regression flipping this default to '*' would do exactly that.
     */
    public function test_daily_mode_default_allowlist_is_empty(): void
    {
        $this->assertSame('', config('freegle.digest.daily_allowlist'));
    }

    /**
     * Create an active daily-digest recipient with one incoming post, at the
     * given per-group cadence. The poster is immediate-only with no lastaccess
     * so it never shows up in the broad daily selection.
     */
    private function makeDailyRecipientWithPost(int $emailfrequency = Membership::EMAIL_FREQUENCY_DAILY): User
    {
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => $emailfrequency]);
        $this->createMembership($poster, $group);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        return $recipient;
    }

    public function test_daily_mode_empty_allowlist_sends_to_nobody(): void
    {
        config(['freegle.digest.daily_allowlist' => '']);
        $this->makeDailyRecipientWithPost();

        // Broad run (no explicit --user): the empty allowlist gates everyone out.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        $this->assertEquals(0, $stats['users_processed']);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_daily_mode_wildcard_allows_everyone(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->makeDailyRecipientWithPost();

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_mode_allowlist_filters_to_specified_addresses(): void
    {
        $allowed = $this->makeDailyRecipientWithPost();
        $this->makeDailyRecipientWithPost(); // a second eligible recipient, not opted in

        $allowedEmail = $allowed->emails()->first()->email;
        config(['freegle.digest.daily_allowlist' => $allowedEmail]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        // Only the opted-in recipient is selected and mailed.
        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_mode_explicit_user_bypasses_empty_allowlist(): void
    {
        // Even with the allowlist OFF, an explicit --user (manual sampling)
        // still sends — the gate only applies to the broad scheduled run.
        config(['freegle.digest.daily_allowlist' => '']);
        $recipient = $this->makeDailyRecipientWithPost();

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_mode_folds_intermediate_cadences(): void
    {
        // With the per-group digest removed, a member on an intermediate
        // cadence (e.g. 8-hourly) has no dedicated sender. Daily must fold
        // every positive cadence in so they aren't silently dropped.
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->makeDailyRecipientWithPost(8);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_empty_allows_everyone(): void
    {
        // Empty config means "no restriction" — both immediate-frequency
        // members get the notification: the recipient AND the poster (V1
        // parity loops a user's own post back to them).
        config(['freegle.digest.immediate_allowlist' => '']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_wildcard_allows_everyone(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        // Both immediate members (recipient + poster) receive it.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_filters_to_specified_addresses(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $allowed = $this->createTestUser();
        $blocked = $this->createTestUser();
        $this->createMembership($poster, $group);
        $this->createMembership($allowed, $group);
        $this->createMembership($blocked, $group);
        $this->seedImmediateCursor($group);

        $allowedEmail = $allowed->emails()->first()->email;
        config(['freegle.digest.immediate_allowlist' => $allowedEmail]);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // Only the allowed-list recipient gets the message; the other
        // immediate-frequency member in the same group is filtered out.
        $this->assertEquals(1, $stats['emails_sent']);
        $this->assertEquals(1, $stats['users_processed']);
    }

    /**
     * The default value checked into config must be '*' so the rolled-out
     * cron emails every eligible user — V1's bulk3 immediate-digest cron was
     * disabled on 2026-05-27 and this Laravel job is now the only source of
     * immediate notifications. A regression that re-pinned the default to a
     * specific address would silently drop all but that user's notifications.
     */
    public function test_immediate_mode_default_is_wildcard(): void
    {
        $this->assertEquals('*', config('freegle.digest.immediate_allowlist'));

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        // Both immediate members (recipient + poster) receive it.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_mail_newly_reached_reach_gates_then_picks_up_later_reached_on_rerun(): void
    {
        // The expander-driven mailer (#0 step 4) mails the post to immediate members the reach
        // NOW covers, ledgers them, and — crucially — on a LATER tick picks up members the reach
        // reaches afterwards (the exact case the cursor-based approach silently dropped).
        config(['freegle.digest.immediate_allowlist' => '*']);

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $memberA = $this->createTestUser();
        $this->createMembership($memberA, $group);
        $memberB = $this->createTestUser();
        $this->createMembership($memberB, $group);
        $this->setMyLocation($memberA, 51.5, -0.1);  // inside reach v1
        $this->setMyLocation($memberB, 51.5, 0.5);   // outside v1, inside v2

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: reach mail (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->mailNewlyReachedForPost($msg->id);

        $ledgered = fn ($uid) => DB::table('rippling_reach_notified')
            ->where('msgid', $msg->id)->where('userid', $uid)->exists();
        $this->assertTrue($ledgered($memberA->id), 'reach-covered member A mailed + ledgered');
        $this->assertFalse($ledgered($memberB->id), 'out-of-reach member B not yet mailed');

        // Reach grows to cover B; the re-run mails B and does NOT re-mail A (ledger dedup).
        DB::statement('UPDATE rippling_reach SET polygon = ST_GeomFromText(?, 3857) WHERE msgid = ?',
            ['POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))', $msg->id]);
        $before = DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count();
        $this->service->mailNewlyReachedForPost($msg->id);
        $this->assertTrue($ledgered($memberB->id), 'newly-reached member B mailed on re-run');
        $this->assertSame(
            $before + 1,
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count(),
            'only B newly notified — A not re-mailed'
        );

        // #0 / §15 instrumentation: both expander mails (A then B) are counted.
        $this->assertSame(2, (int) DB::table('rippling_event_metrics')
            ->where('day', now()->toDateString())->where('event', 'immediate_mailed')->value('count'),
            'immediate mails on expansion are counted');
    }

    public function test_cursor_immediate_digest_excludes_posts_with_a_reach_row(): void
    {
        // A rippling post (has a rippling_reach row) is mailed by the expander, NOT the cursor
        // digest — so the cursor digest must skip it, or members get two immediate mails.
        config(['freegle.digest.immediate_allowlist' => '*']);
        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: rippling (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('b', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent'], 'cursor immediate digest skips a post that has a reach row');
    }

    public function test_cursor_immediate_ledger_is_dark_when_rippling_disabled(): void
    {
        // With the master activation switch off, the cursor immediate digest still mails normally but
        // does NOT touch the rippling reach-coordination ledger (the expander mailer that reads it is
        // inert while off anyway), so the new table stays empty in the dark state.
        config(['freegle.ripple.enabled' => false]);
        config(['freegle.digest.immediate_allowlist' => '*']);
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $member = $this->createTestUser();
        $this->createMembership($member, $group);
        $this->setMyLocation($member, 51.5, -0.1);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: dark (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('d', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedImmediateCursor($group);

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertSame(
            0,
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count(),
            'no reach-coordination ledger rows are written while rippling is off'
        );
    }

    public function test_cursor_and_expander_do_not_double_mail_via_shared_ledger(): void
    {
        // A post is cursor-mailed on arrival (no reach row yet) and the reach row appears minutes
        // later. The cursor send records the ledger, so the expander must NOT re-mail those members.
        config(['freegle.digest.immediate_allowlist' => '*']);
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $member = $this->createTestUser();
        $this->createMembership($member, $group);
        $this->setMyLocation($member, 51.5, -0.1);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: window (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('c', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedImmediateCursor($group);

        // No reach row yet → the cursor immediate digest mails the group and records the ledger.
        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $member->id)->exists(),
            'cursor immediate send is recorded in the ledger'
        );

        // Reach row appears afterwards; the expander must not re-mail the already-mailed member.
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        $before = DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count();
        $sent = $this->service->mailNewlyReachedForPost($msg->id);
        $this->assertSame(0, $sent, 'expander does not re-mail members the cursor digest already mailed');
        $this->assertSame(
            $before,
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count(),
            'no new ledger rows — the shared ledger prevents the double-mail'
        );
    }

    public function test_daily_digest_reach_gates_rippling_posts_by_member_location(): void
    {
        // The daily digest (and the daily-posts push, which shares getPostsForUser) must
        // reach-gate rippling posts by the member's location, just like the immediate path —
        // a daily member is only shown a rippling post once its reach covers them.
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($member, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->setMyLocation($member, 51.5, -0.1);

        // Reach COVERS the member (-0.1, 51.5 is inside this polygon).
        $covered = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: covered (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $covered->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($covered->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        // Reach does NOT cover the member (far to the east).
        $faraway = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: faraway (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $faraway->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($faraway->id, 'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))');

        $tracker = UserDigest::create([
            'userid' => $member->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
        ]);

        $ids = $this->service->getPostsForUser($member, $tracker, UnifiedDigestService::MODE_DAILY)
            ->pluck('id')->all();

        $this->assertContains($covered->id, $ids, 'reach-covered rippling post is included for the daily member');
        $this->assertNotContains($faraway->id, $ids, 'rippling post whose reach does not cover the member is excluded');
    }

    public function test_daily_digest_reach_gate_consults_sandwich_bounds(): void
    {
        // The reach gate must consult the sandwich bounds when they exist
        // (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md): outside outer_bound is an
        // authoritative cheap reject and inside inner_bound an authoritative cheap
        // accept — in both cases the exact polygon is never tested. Prove it with
        // adversarial fixtures whose bounds deliberately contradict their polygon
        // (impossible for verified writer-derived bounds, but the only way to observe
        // which shape the query trusted).
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($member, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->setMyLocation($member, 51.5, -0.1);

        // Post A: polygon COVERS the member, but outer_bound EXCLUDES them → cheap-rejected.
        $cheapReject = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: cheap reject (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $cheapReject->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($cheapReject->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::statement(
            "UPDATE rippling_reach SET outer_bound = ST_GeomFromText('POLYGON((5 5,5.1 5,5.1 5.1,5 5.1,5 5))', 3857),
                    inner_bound = NULL WHERE msgid = ?",
            [$cheapReject->id]
        );

        // Post B: polygon does NOT cover the member, but inner_bound INCLUDES them → cheap-accepted.
        $cheapAccept = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: cheap accept (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $cheapAccept->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($cheapAccept->id, 'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))');
        DB::statement(
            "UPDATE rippling_reach
                SET outer_bound = ST_GeomFromText('POLYGON((-0.3 51.3,0.1 51.3,0.1 51.7,-0.3 51.7,-0.3 51.3))', 3857),
                    inner_bound = ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)
              WHERE msgid = ?",
            [$cheapAccept->id]
        );

        $tracker = UserDigest::create([
            'userid' => $member->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
        ]);

        $ids = $this->service->getPostsForUser($member, $tracker, UnifiedDigestService::MODE_DAILY)
            ->pluck('id')->all();

        $this->assertNotContains($cheapReject->id, $ids, 'a viewer outside outer_bound is cheap-rejected without testing the polygon');
        $this->assertContains($cheapAccept->id, $ids, 'a viewer inside inner_bound is cheap-accepted without testing the polygon');
    }

    public function test_daily_digest_reach_gate_boundary_band_uses_exact_polygon(): void
    {
        // Between the bounds — inside outer_bound but not inside inner_bound (here: NULL
        // inner) — the gate must fall through to the exact polygon test.
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($member, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->setMyLocation($member, 51.5, -0.1);

        $outerWkt = 'POLYGON((-0.3 51.3,0.1 51.3,0.1 51.7,-0.3 51.7,-0.3 51.3))';

        // Band post whose exact polygon covers the member → included.
        $bandIn = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: band in (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $bandIn->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($bandIn->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::statement(
            "UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = NULL WHERE msgid = ?",
            [$outerWkt, $bandIn->id]
        );

        // Band post whose exact polygon does NOT cover the member → excluded.
        $bandOut = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: band out (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $bandOut->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($bandOut->id, 'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))');
        DB::statement(
            "UPDATE rippling_reach SET outer_bound = ST_GeomFromText(?, 3857), inner_bound = NULL WHERE msgid = ?",
            [$outerWkt, $bandOut->id]
        );

        $tracker = UserDigest::create([
            'userid' => $member->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
        ]);

        $ids = $this->service->getPostsForUser($member, $tracker, UnifiedDigestService::MODE_DAILY)
            ->pluck('id')->all();

        $this->assertContains($bandIn->id, $ids, 'boundary band falls back to the exact polygon (covered → included)');
        $this->assertNotContains($bandOut->id, $ids, 'boundary band falls back to the exact polygon (not covered → excluded)');
    }

    public function test_daily_digest_ignores_degraded_bounds_for_came_and_went_posts(): void
    {
        // Completion degrades a post's bounds row to a degenerate point (outer=POINT,
        // inner=NULL) to prune it from the browse candidate set. The digest, however,
        // still shows completed posts ("came and went"), so its reach gate must NOT
        // treat a degraded bounds row as an authoritative reject — it must fall back to
        // the exact polygon (the design doc's "digest came-and-went posts vanish" trap).
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($member, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->setMyLocation($member, 51.5, -0.1);

        $taken = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: came and went (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $taken->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($taken->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('messages_outcomes')->insert(['msgid' => $taken->id, 'outcome' => Message::OUTCOME_TAKEN]);
        // Degraded bounds, as completion pruning writes them.
        DB::statement(
            "UPDATE rippling_reach SET outer_bound = ST_SRID(POINT(-0.1, 51.5), 3857), inner_bound = NULL
              WHERE msgid = ?",
            [$taken->id]
        );

        $tracker = UserDigest::create([
            'userid' => $member->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
        ]);

        $posts = $this->service->getPostsForUser($member, $tracker, UnifiedDigestService::MODE_DAILY);
        $this->assertContains(
            $taken->id,
            $posts->pluck('id')->all(),
            'a completed post with degraded bounds still reaches the digest via its exact polygon'
        );
        $this->assertSame(
            1,
            (int) $posts->firstWhere('id', $taken->id)->has_success,
            'and it is flagged has_success for the came-and-went section'
        );
    }

    public function test_immediate_cursor_advances_past_reach_excluded_posts(): void
    {
        // After full rollout every new post has a reach row, so the cursor digest finds
        // nothing to mail. The cursor must still advance past those posts — otherwise it
        // freezes forever and the scan window grows without bound.
        config(['freegle.digest.immediate_allowlist' => '*']);
        [$group, $poster] = $this->bootstrapImmediateGroup();

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: reach only (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertEquals((int) $msg->id, (int) $cursor->msgid,
            'cursor advances past the reach-excluded post rather than freezing');
    }

    /** Set a user's settings.mylocation point (the canonical first-choice location source). */
    protected function setMyLocation(User $user, float $lat, float $lng): void
    {
        $settings = $user->settings ?? [];
        $settings['mylocation'] = ['lat' => $lat, 'lng' => $lng];
        $user->settings = $settings;
        $user->save();
    }

    /** Seed a rippling_reach row for a post with the given WKT polygon (SRID 3857). */
    protected function seedReach(int $msgid, string $wkt): void
    {
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$msgid, $wkt, $wkt]
        );
    }

    /**
     * Helper: create a group, a poster, a recipient (both at
     * emailfrequency=-1) and seed the immediate cursor. Returns
     * [$group, $poster, $recipient]. Used by the allowlist tests above
     * to keep their setup terse.
     */
    protected function bootstrapImmediateGroup(): array
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group);
        $this->seedImmediateCursor($group);
        return [$group, $poster, $recipient];
    }

    /**
     * Helper: seed/update a groups_digests row at frequency=-1 for one
     * group. The per-group cron walks groups_digests, so a row must exist
     * (V1 INSERT IGNOREs in production; tests need the same shape).
     */
    protected function seedImmediateCursor(Group $group, ?string $msgdate = null, ?int $msgid = null): void
    {
        // msgid has a FK to messages.id (ON DELETE SET NULL), so the value must
        // be either NULL or a real message id. Tests that need a baseline cursor
        // without a real message default to NULL.
        \App\Models\GroupDigest::updateOrCreate(
            [
                'groupid' => $group->id,
                'frequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            ],
            [
                'msgdate' => $msgdate,
                'msgid' => $msgid,
            ]
        );
    }

    /**
     * Push the given message's messages_groups.arrival back past the
     * isImmediateMessageReady() defer deadline so the digest doesn't
     * postpone it waiting for an attachment. Most immediate tests don't
     * care about the defer behaviour; this keeps them terse.
     */
    protected function makeImmediateReady(Message $message): void
    {
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->update([
                'arrival' => now()->subMinutes(UnifiedDigestService::ATTACHMENT_WAIT_DEADLINE_MINUTES + 1),
            ]);
    }

    /**
     * Allowlist must not affect daily mode — that's a separate, already-running
     * flow that we don't want to gate behind this setting.
     */
    public function test_immediate_allowlist_does_not_affect_daily_mode(): void
    {
        // Pin to an address that nobody in this test has, then run daily.
        config(['freegle.digest.immediate_allowlist' => 'nobody-test@example.invalid']);

        $user = $this->createTestUser();
        $user->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $user->lastaccess = now();
        $user->save();
        $user->refresh();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    // ─── V1-PARITY PER-GROUP IMMEDIATE TESTS ────────────────────────────
    // These pin the new sendImmediateDigests behaviour: walk
    // groups_digests at frequency=-1, find messages since the per-group
    // cursor with (arrival, msgid) tuple compare, send to every member
    // at emailfrequency=-1 — including the poster, since V1 (no fromuser
    // filter) loops a user's own posts back to them too — advance the cursor.

    public function test_immediate_sends_to_each_immediate_frequency_member_in_group(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $r1 = $this->createTestUser();
        $r2 = $this->createTestUser();
        $dailyOnly = $this->createTestUser();

        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($r1, $group);
        $this->createMembership($r2, $group);
        $this->createMembership($dailyOnly, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // poster + r1 + r2 are all immediate-frequency; all three receive
        // (V1 parity includes the poster's own post). dailyOnly is excluded
        // by the emailfrequency filter.
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(3, $stats['users_processed']);
        $this->assertEquals(3, $stats['emails_sent']);
    }

    public function test_immediate_includes_poster_own_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Own item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // V1 parity: the per-group selection has no fromuser filter, so a
        // poster's own post loops back to them too. Poster is the only
        // immediate-frequency member, so exactly one email goes out — to them.
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_sends_one_email_per_new_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msgA = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $msgB = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $msgC = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: C (TestLocation)']);
        $this->makeImmediateReady($msgA);
        $this->makeImmediateReady($msgB);
        $this->makeImmediateReady($msgC);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // 3 posts × 2 immediate members (recipient + poster) = 6 emails.
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['users_processed']);
        $this->assertEquals(6, $stats['emails_sent']);
    }

    public function test_immediate_advances_groups_digests_cursor(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $this->makeImmediateReady($msg1);
        $this->makeImmediateReady($msg2);

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();

        $this->assertEquals($msg2->id, $cursor->msgid);
        $this->assertNotNull($cursor->msgdate);

        // Running again with no new posts must not re-send.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_immediate_tuple_tiebreak_catches_messages_with_identical_arrival(): void
    {
        // V1 only uses `arrival > msgdate`; we tighten to (arrival, msgid)
        // tuple compare so a same-arrival collision can't drop a message
        // between two cron ticks. Force two messages_groups rows to share
        // an arrival, run once to send both, then run again to confirm
        // nothing fires again.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();

        // Backdate past the immediate-defer deadline so isImmediateMessageReady
        // doesn't postpone these; same arrival for both messages to force the
        // tuple-tiebreak path we're testing.
        $sharedArrival = now()
            ->subMinutes(UnifiedDigestService::ATTACHMENT_WAIT_DEADLINE_MINUTES + 1)
            ->format('Y-m-d H:i:s.u');
        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg1->id)->update(['arrival' => $sharedArrival]);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg2->id)->update(['arrival' => $sharedArrival]);

        // 2 same-arrival posts × 2 immediate members (recipient + poster) = 4.
        $first = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(4, $first['emails_sent'], 'Both same-arrival messages must be picked up');

        $second = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(0, $second['emails_sent'], 'Cursor msgid must keep same-arrival messages from re-firing');
    }

    public function test_immediate_skips_groups_with_no_immediate_members(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $dailyMember = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($dailyMember, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->seedImmediateCursor($group);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // The whereExists() filter in sendImmediateDigests should skip the
        // group entirely because no membership has emailfrequency=-1.
        $this->assertEquals(0, $stats['groups_processed']);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_immediate_limit_caps_groups_processed_per_run(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        for ($i = 0; $i < 3; $i++) {
            $g = $this->createTestGroup();
            $this->createMembership($poster, $g);
            $this->createMembership($recipient, $g);
            $this->seedImmediateCursor($g);
            $msg = $this->createTestMessage($poster, $g, ['subject' => "OFFER: Item {$i} (TestLocation)"]);
            $this->makeImmediateReady($msg);
        }

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, null, 1);

        // Limit caps it to one group; that group's post goes to both its
        // immediate members (recipient + poster).
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_group_filter_restricts_to_single_group(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        foreach ([$groupA, $groupB] as $g) {
            $this->createMembership($poster, $g);
            $this->createMembership($recipient, $g);
            $this->seedImmediateCursor($g);
            $msg = $this->createTestMessage($poster, $g, ['subject' => 'OFFER: Item (TestLocation)']);
            $this->makeImmediateReady($msg);
        }

        $stats = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, $groupA->id
        );

        // Only group A processed; its post goes to both immediate members
        // (recipient + poster).
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['emails_sent']);

        $cursorB = DB::table('groups_digests')
            ->where('groupid', $groupB->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursorB->msgdate, 'Group B must not have advanced its cursor');
    }

    public function test_immediate_user_filter_restricts_recipients_within_group(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $targeted = $this->createTestUser();
        $other = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($targeted, $group);
        $this->createMembership($other, $group);
        $this->seedImmediateCursor($group);
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $targeted->id);

        // Only the targeted user gets the email.
        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);

        // Cursor must NOT advance when --user is set — the other group
        // member ($other) still needs to receive this message on the next
        // unrestricted run.
        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursor->msgdate, 'Cursor must not advance under a --user-restricted run');
    }

    public function test_immediate_dry_run_does_not_advance_cursor(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, null, null, true);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();

        $this->assertNull($cursor->msgdate, 'Dry run must not advance the cursor');
    }

    public function test_immediate_defers_recent_message_with_no_attachment(): void
    {
        // Newly-posted message that hasn't acquired an AI-generated
        // attachment yet — defer rather than mail with a stock placeholder
        // while generation is still in flight. The cursor must NOT
        // advance past it so the next tick retries.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Recent no-image (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent']);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursor->msgdate, 'Cursor must not advance past a deferred message');
    }

    public function test_immediate_sends_attached_message_even_if_recent(): void
    {
        // Has a usable attachment immediately (user-uploaded photo).
        // No need to wait for AI; mail right away.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: With photo (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id,
            'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'primary' => 1,
            'archived' => 0,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // recipient + poster both receive (immediate members; own post included).
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_sends_unattached_message_after_deadline(): void
    {
        // Older than ATTACHMENT_WAIT_DEADLINE_MINUTES and still no
        // attachment — give up waiting and send with the placeholder
        // rather than holding the notification indefinitely.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Stuck no-image (TestLocation)']);
        $oldArrival = now()->subMinutes(UnifiedDigestService::ATTACHMENT_WAIT_DEADLINE_MINUTES + 1);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => $oldArrival]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // recipient + poster both receive (immediate members; own post included).
        $this->assertEquals(2, $stats['emails_sent']);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNotNull($cursor->msgdate, 'Cursor must advance after we give up waiting');
    }

    public function test_immediate_shard_partitions_groups_by_modulo(): void
    {
        // Each shard must own a disjoint slice of groups (MOD(groupid, N)
        // = shard). Running shard 0 of 4 must process only groups where
        // groupid % 4 == 0; running shard 1 must process only those where
        // % 4 == 1; and so on. Union across all shards = every group.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        // Create 8 groups; force their ids so we know each shard's slice.
        // (createTestGroup doesn't let us pin the id, but we can sample
        // many and just check that the shard filter is applied — we look
        // at WHICH groups each shard sees.)
        $groups = [];
        for ($i = 0; $i < 8; $i++) {
            $g = $this->createTestGroup();
            $this->createMembership($poster, $g);
            $this->createMembership($recipient, $g);
            $this->seedImmediateCursor($g);
            $this->createTestMessage($poster, $g, ['subject' => "OFFER: Item {$i} (TestLocation)"]);
            DB::table('messages_groups')->where('msgid', DB::table('messages')->where('subject','like',"OFFER: Item {$i}%")->max('id'))
                ->update(['arrival' => now()->subMinutes(10)]); // older than the AI-wait deadline so they all send
            $groups[$i] = $g;
        }

        // Run shard 0 of 2: should process exactly the groups with even
        // ids. Shard 1 should process exactly the groups with odd ids.
        $statsShard0 = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, null, 0, 2
        );
        $statsShard1 = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, null, 1, 2
        );

        $evenGroupCount = collect($groups)->filter(fn($g) => $g->id % 2 === 0)->count();
        $oddGroupCount = collect($groups)->filter(fn($g) => $g->id % 2 === 1)->count();

        $this->assertEquals($evenGroupCount, $statsShard0['groups_processed'], 'Shard 0 must process even-id groups');
        $this->assertEquals($oddGroupCount, $statsShard1['groups_processed'], 'Shard 1 must process odd-id groups');
        $this->assertEquals(count($groups), $statsShard0['groups_processed'] + $statsShard1['groups_processed'], 'Shards must partition the group set');
    }

    public function test_immediate_shards_do_not_double_process_same_group(): void
    {
        // Stronger check than the partition test: pick one specific
        // group and verify that exactly one shard (the one matching
        // MOD(groupid, 4)) sees it and the other three don't.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()->subMinutes(10)]);

        $owningShard = $group->id % 4;
        for ($s = 0; $s < 4; $s++) {
            // Reset cursor between shards so each run sees a clean state.
            // msgid must be NULL (not 0) — there's a FK to messages.id.
            DB::table('groups_digests')
                ->where('groupid', $group->id)
                ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
                ->update(['msgid' => null, 'msgdate' => null]);

            $stats = $this->service->sendDigests(
                UnifiedDigestService::MODE_IMMEDIATE,
                null, null, false, null, $s, 4
            );
            if ($s === $owningShard) {
                $this->assertEquals(1, $stats['groups_processed'], "Shard {$s} owns this group");
                // recipient + poster both receive (immediate members; own post included).
                $this->assertEquals(2, $stats['emails_sent']);
            } else {
                $this->assertEquals(0, $stats['groups_processed'], "Shard {$s} must not see this group");
                $this->assertEquals(0, $stats['emails_sent']);
            }
        }
    }

    public function test_immediate_deferral_blocks_later_messages_in_same_group(): void
    {
        // If msg A (older) is deferred, msg B (newer) MUST NOT be sent
        // even if it's ready — otherwise advancing the cursor to B's
        // position would skip A on the next tick.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $now = now();

        // Msg A: arrived now, no attachment → deferred.
        $a = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A no-img (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $a->id)->update(['arrival' => $now]);

        // Msg B: arrived later, has attachment → would be ready, but
        // must wait behind A.
        $b = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B with photo (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $b->id)->update(['arrival' => $now->copy()->addSecond()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $b->id,
            'externaluid' => 'freegletusd-' . str_repeat('b', 32),
            'primary' => 1,
            'archived' => 0,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent'], 'Later ready msg B must wait until A is dealt with');

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursor->msgdate, 'Cursor must not advance past the deferred A');
    }

    public function test_immediate_advances_cursor_even_if_one_recipient_send_throws(): void
    {
        // Regression: if Mail::send threw for one recipient (e.g. bad
        // address), the exception used to escape processGroupImmediate's
        // outer foreach. lastProcessed stayed null → cursor never
        // advanced → next cron tick re-sent the SAME message to every
        // recipient. Observed: Penny Langley received 27 copies of one
        // post in 13 min. SafeMail::sendMailable now catches permanent
        // failures and marks the recipient as bouncing, so the loop
        // keeps going and the cursor advances normally.
        //
        // We can't easily make Mail::fake() throw, so use a User with a
        // syntactically invalid preferred email to drive the permanent-
        // failure path through SmtpFailureClassifier in a real Mail
        // pipeline. This needs the real Symfony mailer (not Mail::fake);
        // tests in CI use a null mailer that swallows everything, so for
        // unit purposes we assert the cursor logic with all-good sends.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $r1 = $this->createTestUser();
        $r2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($r1, $group);
        $this->createMembership($r2, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // Poster + both recipients got mailed (all immediate members; own
        // post loops back to the poster per V1 parity).
        $this->assertEquals(3, $stats['emails_sent']);

        // Cursor advanced (this is the regression check — used to stay
        // at null if any send threw).
        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertEquals($msg->id, $cursor->msgid, 'Cursor must advance after sends complete');

        // Re-running finds nothing to do (proving the cursor stuck).
        $stats2 = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(0, $stats2['emails_sent'], 'No duplicate send after cursor advance');
    }

    /**
     * Long-inactive members and daily members must not get immediate
     * per-post emails (Discourse topic 9728 posts 11-12: member 39318461
     * "no recent activity"; support flooded). processGroupImmediate gates
     * recipients on the same V1-parity inactivity window the daily path uses
     * (Engage::USER_INACTIVE = 365*12*3600 = 182.5 days) and on
     * emailfrequency=-1. It must NOT use a stricter 90-day cutoff: that
     * silently dropped members inactive for 90-182.5 days (member 41020747,
     * lastaccess 111 days) even though V1 and the daily digest still mail them.
     *
     * Five-user group:
     *   poster         emailfrequency=-1, active → receives (V1 parity: own post loops back)
     *   immediateUser  emailfrequency=-1, active → receives
     *   deadZoneUser   emailfrequency=-1, lastaccess 120 days ago → receives (inside 182.5d)
     *   dailyUser      emailfrequency=24, active → excluded (emailfrequency filter)
     *   inactiveUser   emailfrequency=-1, lastaccess 2 years ago → excluded (182.5d gate)
     *
     * So poster + immediateUser + deadZoneUser receive: 3 emails.
     */
    public function test_inactive_and_daily_users_must_not_receive_immediate_individual_emails(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();

        // Active immediate-frequency member — receives (alongside the poster).
        $immediateUser = $this->createTestUser();

        // Immediate-frequency member inactive for 120 days — inside the 182.5-day
        // V1-parity window, so must still receive. A 90-day cutoff would wrongly
        // drop them (the member 41020747 regression).
        $deadZoneUser = $this->createTestUser();
        $deadZoneUser->lastaccess = now()->subDays(120);
        $deadZoneUser->save();

        // Active daily-digest member — correctly excluded by the emailfrequency filter.
        $dailyUser = $this->createTestUser();

        // Long-inactive immediate-frequency member (lastaccess 2 years ago) —
        // beyond the 182.5-day window, correctly excluded.
        $inactiveUser = $this->createTestUser();
        $inactiveUser->lastaccess = now()->subYears(2);
        $inactiveUser->save();

        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($immediateUser, $group);
        $this->createMembership($deadZoneUser, $group);
        $this->createMembership($dailyUser, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($inactiveUser, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // poster + immediateUser + deadZoneUser receive; dailyUser (freq) and
        // inactiveUser (182.5-day gate) are excluded.
        $this->assertEquals(3, $stats['emails_sent']);
        $this->assertEquals(3, $stats['users_processed']);
    }

    // ─── V1-PARITY: per-group emailfrequency is authoritative ────────────
    // Bug case (user 801, Richmond Upon Thames, 2026-05-27): a user with
    // legacy simplemail='Full' who had switched some groups to Daily was
    // being treated as a "Full" user for every group and flooded with
    // immediate emails. V1 (the legacy V1 PHP Digest implementation)
    // ignores simplemail at send time and filters strictly on
    // memberships.emailfrequency. These tests pin that behaviour so the
    // regression cannot recur.

    public function test_daily_includes_user_with_simplemail_full_when_membership_is_daily(): void
    {
        // Emma's case: simplemail='Full' but a specific group is at Daily.
        // The Daily setting must win — she should get a daily digest for
        // that group (and, separately, no immediate spam for it).
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'simplemail=Full must not block per-group Daily delivery');
    }

    public function test_daily_excludes_user_whose_only_memberships_are_immediate(): void
    {
        // simplemail=Basic alone is NOT enough — V1 parity requires at
        // least one approved membership at emailfrequency=24. The old
        // code would have selected this user and then tried to mail
        // their immediate-frequency groups in the daily roll-up.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
        ]);
        $this->createTestMessage($poster, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_daily_excludes_user_with_simplemail_none(): void
    {
        // V1 sendOurMails() opt-out: simplemail='None' silences every
        // mail regardless of per-group settings.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_NONE];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createTestMessage($poster, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_daily_digest_excludes_posts_from_immediate_only_groups(): void
    {
        // Mixed-frequency case: same user, two groups, one Daily and one
        // Immediate. The daily roll-up must contain ONLY the daily
        // group's post — the immediate group's post must not be bundled
        // (that group is the immediate cron's responsibility). The old
        // code skipped the per-group emailfrequency filter whenever
        // simplemail was set and would have included both.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $dailyGroup = $this->createTestGroup();
        $immediateGroup = $this->createTestGroup();
        $this->createMembership($poster, $dailyGroup);
        $this->createMembership($poster, $immediateGroup);
        $this->createMembership($recipient, $dailyGroup, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($recipient, $immediateGroup, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
        ]);

        $dailyMsg = $this->createTestMessage($poster, $dailyGroup, [
            'subject' => 'OFFER: Daily item (TestLocation)',
        ]);
        $immediateMsg = $this->createTestMessage($poster, $immediateGroup, [
            'subject' => 'OFFER: Immediate item (TestLocation)',
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertSent(\App\Mail\Digest\UnifiedDigest::class, function ($mail) use ($dailyMsg, $immediateMsg) {
            // $posts is protected on purpose: making it public would auto-inject
            // the raw collection into the mail views and shadow the prepared
            // posts (breaking the templates), so read it via reflection.
            $prop = new \ReflectionProperty($mail, 'posts');
            $prop->setAccessible(true);
            $subjects = $prop->getValue($mail)->map(fn ($p) => $p['message']->subject)->all();
            return in_array($dailyMsg->subject, $subjects, true)
                && !in_array($immediateMsg->subject, $subjects, true);
        });
    }

    public function test_immediate_excludes_simplemail_full_user_with_daily_only_memberships(): void
    {
        // Re-run of Emma's scenario through getUsersForDigest('immediate').
        // This path is dead in production (sendDigests('immediate') short-
        // circuits to the per-group sendImmediateDigests), but the
        // function is reachable in tests / future callers and must
        // match V1: simplemail='Full' alone never wins; the user needs
        // at least one membership at emailfrequency=-1.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getUsersForDigest');
        $method->setAccessible(true);
        /** @var \Illuminate\Support\LazyCollection $eligible */
        $eligible = $method->invoke($this->service, UnifiedDigestService::MODE_IMMEDIATE, $recipient->id);

        $this->assertCount(0, $eligible->all(), 'simplemail=Full alone must not select a user who has no immediate-frequency memberships');
    }

    // -----------------------------------------------------------------------
    // Task 3: Engagement counts in the post query
    // -----------------------------------------------------------------------

    private function callPrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    public function test_get_posts_for_user_exposes_engagement_counts(): void
    {
        $recipient = $this->createTestUser();
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Counted (TestLocation)',
            'arrival' => now()->subHours(2),
        ]);

        // 3 'View' likes (the count column is SUMmed) and 1 approved 'Interested' reply.
        DB::table('messages_likes')->insert([
            'msgid' => $msg->id, 'userid' => $recipient->id, 'type' => 'View', 'count' => 3,
            'timestamp' => now(),
        ]);
        // chat_messages has a FK on chatid; create a real chat room to satisfy it.
        $room = $this->createTestChatRoom($recipient, $poster);
        DB::table('chat_messages')->insert([
            'refmsgid' => $msg->id, 'userid' => $poster->id, 'chatid' => $room->id,
            'type' => 'Interested', 'message' => 'Interested',
            'reviewrejected' => 0, 'reviewrequired' => 0, 'date' => now(),
            'processingrequired' => 0, 'processingsuccessful' => 1,
            'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
        ]);

        $tracker = UserDigest::create([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgdate' => null,
        ]);

        $posts = $this->service->getPostsForUser(
            $recipient, $tracker, UnifiedDigestService::MODE_DAILY
        );

        $row = $posts->firstWhere('id', $msg->id);
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->views);
        $this->assertSame(1, (int) $row->replies);
    }

    // -----------------------------------------------------------------------
    // Task 4: Per-run reach-radius lookup
    // -----------------------------------------------------------------------

    public function test_reach_radius_falls_back_to_config_default_without_reach_row(): void
    {
        config(['freegle.ripple.score.default_reach_metres' => 12345.0]);
        $svc = app(\App\Services\UnifiedDigestService::class);

        // No rippling_reach row for this msgid => default.
        $r = $this->callPrivate($svc, 'reachRadiusMetres', [999999999]);
        $this->assertEqualsWithDelta(12345.0, $r, 1e-6);
    }

    public function test_reach_radius_is_distance_origin_to_polygon_boundary(): void
    {
        $svc = app(\App\Services\UnifiedDigestService::class);

        // Need a real message row to satisfy rippling_reach FK on msgid.
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($poster, $group);

        // seedReach() seeds the origin at (lat 51.5, lng -0.1). The polygon stores
        // lng/lat DEGREES (tagged SRID 3857 by Freegle convention). This box spans
        // +/-0.1deg in each axis, so all four corners are equidistant from the origin
        // (~13km), and the reach radius is that great-circle corner distance in metres.
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        // Same haversine the implementation uses (mean Earth radius 6371000m).
        $haversine = function (float $lat1, float $lng1, float $lat2, float $lng2): float {
            $R = 6371000.0;
            $dLat = deg2rad($lat2 - $lat1);
            $dLng = deg2rad($lng2 - $lng1);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

            return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
        };
        // Max over all four corners (the southern corners are marginally farther
        // because east-west distance grows with cos(latitude)) — mirrors the
        // implementation, which takes the greatest origin->vertex distance.
        $expected = 0.0;
        foreach ([[-0.2, 51.4], [0.0, 51.4], [0.0, 51.6], [-0.2, 51.6]] as [$lng, $lat]) {
            $expected = max($expected, $haversine(51.5, -0.1, $lat, $lng));
        }

        $r = $this->callPrivate($svc, 'reachRadiusMetres', [$msg->id]);
        $this->assertEqualsWithDelta($expected, $r, 1.0);
        // Sanity: a ~0.1deg box corner from this origin is ~13km — kilometre-scale metres.
        $this->assertGreaterThan(10000, $r);
        $this->assertLessThan(16000, $r);
    }

    // -----------------------------------------------------------------------
    // Task 5: scoreAndSortAvailable (haversine distance + DigestPostScorer)
    // -----------------------------------------------------------------------

    public function test_available_posts_pin_two_nearest_then_score_for_the_rest(): void
    {
        config(['freegle.ripple.score.default_reach_metres' => 40000.0]);
        $latlng = [0.0, 0.0]; // recipient [lat, lng]

        $mk = function (int $id, float $lat, float $lng, int $ageH, int $views) {
            $p = new \stdClass();
            $p->id = $id;
            $p->lat = $lat;
            $p->lng = $lng;
            $p->arrival = now()->subHours($ageH);
            $p->views = $views;
            $p->replies = 0;
            return $p;
        };

        // First two posts are ALWAYS the two nearest (nearest first), regardless of
        // score; the rest then follow the score order. id1 ~55m, id2 ~110m are the two
        // nearest. id3/id4 are both ~1.1km, so the rest are ordered by the budget term:
        // id3 unseen (higher score) before id4 heavily viewed (lower score).
        $nearest  = $mk(1, 0.0005, 0.0, 1, 0);   // ~55m   -> pinned #1
        $second   = $mk(2, 0.0010, 0.0, 1, 0);   // ~110m  -> pinned #2
        $farFresh = $mk(3, 0.0100, 0.0, 1, 0);   // ~1.1km, unseen      -> rest, higher score
        $farBusy  = $mk(4, 0.0100, 0.0, 1, 500); // ~1.1km, 500 views   -> rest, lower score

        $sorted = $this->callPrivate(
            $this->service,
            'scoreAndSortAvailable',
            [collect([$farBusy, $farFresh, $second, $nearest]), $latlng]
        );

        $this->assertSame([1, 2, 3, 4], $sorted->pluck('id')->all());
    }

    // -----------------------------------------------------------------------
    // Task 6: Wire score-sort into daily digest flow
    // -----------------------------------------------------------------------

    public function test_daily_digest_orders_live_posts_by_score(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'mylocation' => ['lat' => 51.5, 'lng' => -0.12],
        ];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.12]);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // near post has NEWER arrival; far post has OLDER arrival.
        // Arrival ASC would put far (older) first => [far, near].
        // Score ordering should put near first => [near, far].
        $near = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Near (TestLocation)',
            'lat' => 51.5, 'lng' => -0.12, 'arrival' => now()->subHours(2),
        ]);
        $far = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far (TestLocation)',
            'lat' => 53.0, 'lng' => -0.12, 'arrival' => now()->subHours(10),
        ]);

        // Spy the spooler so we can read the posts handed to the daily UnifiedDigest.
        $captured = null;
        $spy = \Mockery::mock(\App\Services\EmailSpoolerService::class);
        $spy->shouldReceive('spool')->andReturnUsing(function ($mailable) use (&$captured) {
            $captured = $mailable;
            return 'spooled';
        });
        $this->app->instance(\App\Services\EmailSpoolerService::class, $spy);

        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertNotNull($captured, 'daily digest should have been spooled');
        $ids = $captured->getPosts()->map(fn ($p) => $p['message']->id)->all();
        $this->assertSame([$near->id, $far->id], $ids); // near outranks far on closeness
    }

    // -----------------------------------------------------------------------
    // Pinned posts (paid bulk-offer clearances)
    // -----------------------------------------------------------------------

    public function test_daily_digest_force_includes_pinned_open_post_at_the_top(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'mylocation' => ['lat' => 51.5, 'lng' => -0.12],
        ];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup(['lat' => 51.5, 'lng' => -0.12]);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // A normal, recent, in-window post.
        $normal = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: normal recent (TestLocation)',
            'lat' => 51.5, 'lng' => -0.12, 'arrival' => now()->subHours(1),
        ]);
        // A PINNED post that arrived 10 days ago — OUTSIDE the first-digest 24h window, so
        // getPostsForUser would NOT return it. Pinning must force it in, at the very top.
        $pinnedMsg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: pinned clearance (TestLocation)',
            'lat' => 51.5, 'lng' => -0.12, 'arrival' => now()->subDays(10),
        ]);
        DB::table('messages_pinned')->insert(['msgid' => $pinnedMsg->id]);

        $captured = null;
        $spy = \Mockery::mock(\App\Services\EmailSpoolerService::class);
        $spy->shouldReceive('spool')->andReturnUsing(function ($mailable) use (&$captured) {
            $captured = $mailable;
            return 'spooled';
        });
        $this->app->instance(\App\Services\EmailSpoolerService::class, $spy);

        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertNotNull($captured, 'daily digest should have been spooled');
        $ids = $captured->getPosts()->map(fn ($p) => $p['message']->id)->all();
        $this->assertContains($pinnedMsg->id, $ids, 'a pinned OPEN post is force-included even though it is outside the digest window');
        $this->assertContains($normal->id, $ids, 'the normal in-window post is still included');
        $this->assertSame($pinnedMsg->id, $ids[0], 'the pinned post is at the very top of the digest');
    }

    public function test_daily_digest_does_not_pin_a_closed_post(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // The only post is pinned but has been TAKEN (closed). Pinning applies only while a
        // post is open, so it must NOT be force-included, and nothing should send.
        $pinnedTaken = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: pinned but taken (TestLocation)',
            'arrival' => now()->subDays(10),
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $pinnedTaken->id,
            'outcome' => 'Taken',
            'timestamp' => now(),
        ]);
        DB::table('messages_pinned')->insert(['msgid' => $pinnedTaken->id]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(0, $stats['emails_sent'], 'a pinned but closed (taken) post is not force-included, so nothing sends');
    }

    // ---- Decoupled, sharded reach-mail pass (sendReachDigests) -------------------
    // Reach mail used to run inline in ExpandService's serial Phase-2 loop (~75% of
    // run time). It is now a separate sharded pass over recently-changed reach rows,
    // mirroring the immediate-digest cron's MOD(...,shards) partitioning.

    /** Set up a rippling post whose reach covers one immediate-frequency member. */
    private function setUpRippledPostWithReachableImmediateMember(): array
    {
        config(['freegle.digest.immediate_allowlist' => '*']);
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group, ['added' => now()->subHours(72)]);
        $member = $this->createTestUser();
        $this->createMembership($member, $group, ['added' => now()->subHours(72)]); // immediate by default
        $member->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]];
        $member->save();

        $message = $this->createTestMessage($poster, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->where('groupid', $group->id)->update([
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subHours(1),
        ]);
        // Reach polygon (status 'expanding', just updated) covering the member's location.
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );

        return [$message, $member];
    }

    public function test_send_reach_digests_mails_newly_reached_immediate_member(): void
    {
        [$message, $member] = $this->setUpRippledPostWithReachableImmediateMember();

        $stats = $this->service->sendReachDigests();

        $this->assertGreaterThanOrEqual(1, $stats['emails_sent'], 'a reachable immediate member is mailed');
        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists(),
            'the reach-mail pass records the notification in the ledger'
        );
    }

    public function test_send_reach_digests_is_idempotent_via_ledger(): void
    {
        $this->setUpRippledPostWithReachableImmediateMember();

        $this->service->sendReachDigests();
        $second = $this->service->sendReachDigests();

        $this->assertSame(0, $second['emails_sent'], 'already-notified members are not re-mailed on a second pass');
    }

    public function test_send_reach_digests_partitions_posts_by_msgid_shard(): void
    {
        [$message, $member] = $this->setUpRippledPostWithReachableImmediateMember();

        $shards = 2;
        $ownShard = (int) $message->id % $shards;
        $otherShard = 1 - $ownShard;

        // The shard that does NOT own this msgid must skip it entirely.
        $this->service->sendReachDigests(null, false, $otherShard, $shards);
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->exists(),
            'a shard does not process posts outside its MOD(msgid, shards) partition'
        );

        // The owning shard processes it.
        $this->service->sendReachDigests(null, false, $ownShard, $shards);
        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists(),
            'the owning shard mails the reachable member'
        );
    }

    // ─── DISTANCE-PREFERENCE EMAIL FILTERING ────────────────────────────
    // settings.browseMaxDistance (miles) narrows the daily digest, the
    // immediate cursor path and the reach-mail path — see
    // docs/superpowers/specs/2026-07-01-distance-preference-email-filtering-design.md.
    // London (51.5074, -0.1278) is the default group/message location
    // (createTestGroup/createTestMessage); Edinburgh (55.9533, -3.1889) is
    // ~330 miles away (always "far"); (51.5, 0.4) is ~22.7 miles away (a
    // "medium-far" point used to straddle a 2-mile cap vs a 50-mile cap).

    public function test_daily_digest_filters_out_post_beyond_distance_preference(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'browseMaxDistance' => 2,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent'], 'the far post is filtered out, leaving nothing to send');
    }

    public function test_daily_digest_keeps_post_within_distance_preference(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'browseMaxDistance' => 5,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        // ~0.9 miles from the recipient — inside the 5-mile cap.
        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Near item (London)',
            'lat' => 51.52,
            'lng' => -0.1278,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'a post within the limit is still sent');
    }

    public function test_daily_digest_distance_preference_noop_when_setting_absent(): void
    {
        // Majority-case regression check: no browseMaxDistance at all = unlimited,
        // so a far post is unaffected by the new filter.
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'no browseMaxDistance set means unlimited — unaffected by the new filter');
    }

    public function test_daily_digest_distance_preference_noop_when_setting_is_sentinel(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'browseMaxDistance' => \App\Services\Ripple\DistancePreferenceFilter::DISTANCE_UNLIMITED,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'the sentinel value means unlimited — unaffected by the new filter');
    }

    // ─── OUTBOUND (author-side) distance preference ─────────────────────
    // The SAME setting, read from the POST AUTHOR, also caps who sees their post:
    // a recipient beyond the author's browseMaxDistance of the post is filtered
    // out even when the recipient themselves has no distance limit. (51.5, 0.4) is
    // ~22.7 miles from the London recipient — inside a 50-mile author cap, outside
    // a 2-mile one.

    public function test_daily_digest_filters_out_post_beyond_authors_distance_preference(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        // Recipient has NO distance limit of their own, so any filtering is the author's doing.
        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        // Poster caps how far away their post is shown at 2 miles.
        $poster = $this->createTestUser();
        $poster->settings = ['browseMaxDistance' => 2];
        $poster->save();

        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        // ~22.7 miles from the recipient — outside the poster's 2-mile outbound cap.
        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Local-only item',
            'lat' => 51.5,
            'lng' => 0.4,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent'], "the post is filtered out by the author's 2-mile cap, despite the recipient having no limit");
    }

    public function test_daily_digest_keeps_post_within_authors_distance_preference(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        // Poster's cap (50 miles) comfortably includes the ~22.7-mile recipient.
        $poster = $this->createTestUser();
        $poster->settings = ['browseMaxDistance' => 50];
        $poster->save();

        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Wider-reach item',
            'lat' => 51.5,
            'lng' => 0.4,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], "a recipient within the author's cap still gets the post");
    }

    public function test_daily_digest_own_post_bypasses_distance_preference(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'browseMaxDistance' => 1,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);

        // The recipient's OWN post, far from their resolved location.
        $this->createTestMessage($recipient, $group, [
            'subject' => 'OFFER: My own far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'own post is always included regardless of distance preference');
    }

    public function test_daily_digest_distance_preference_fails_open_without_recipient_location(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipient = $this->createTestUser();
        $recipient->settings = [
            'simplemail' => User::SIMPLE_MAIL_BASIC,
            'browseMaxDistance' => 1,
            // No mylocation and no lastlocation — resolveUserLatLng() returns null.
        ];
        $recipient->lastaccess = now();
        $recipient->save();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'cannot resolve recipient location — fail open, no filtering');
    }

    /**
     * Proves the insertion-point choice actually kept push notifications out of
     * scope: getPostsForUser is shared with SendDailyPostsPushCommand, so it must
     * NOT be distance-filtered — only the email path (sendDigestToUser) is.
     */
    public function test_get_posts_for_user_unaffected_by_distance_preference(): void
    {
        $recipient = $this->createTestUser();
        $recipient->settings = [
            'browseMaxDistance' => 1,
            'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278],
        ];
        $recipient->save();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($poster, $group);

        $far = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $tracker = UserDigest::create([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgdate' => null,
        ]);

        $posts = $this->service->getPostsForUser($recipient, $tracker, UnifiedDigestService::MODE_DAILY);

        $this->assertTrue(
            $posts->pluck('id')->contains($far->id),
            'getPostsForUser (shared with the push command) is not distance-filtered'
        );
    }

    public function test_immediate_cursor_filters_far_post_for_distance_limited_member(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);

        $limited = $this->createTestUser();
        $limited->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278]];
        $limited->save();
        $this->createMembership($limited, $group);

        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // Poster's own post always bypasses the filter; the distance-limited member
        // does not get mailed — the post is ~330 miles away, beyond their 2-mile cap.
        $this->assertEquals(1, $stats['emails_sent']);
        $this->assertEquals(1, $stats['users_processed']);
    }

    public function test_immediate_cursor_does_not_filter_unlimited_member_for_distance_preference(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);

        $unlimited = $this->createTestUser();
        $unlimited->settings = ['mylocation' => ['lat' => 51.5074, 'lng' => -0.1278]]; // no browseMaxDistance
        $unlimited->save();
        $this->createMembership($unlimited, $group);

        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(2, $stats['emails_sent'], 'an unlimited member in the same group still gets the far post');
        $this->assertEquals(2, $stats['users_processed']);
    }

    public function test_immediate_cursor_advances_even_when_every_recipient_is_distance_filtered(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        // The poster is NOT a member of this group, so there is no own-post
        // bypass in play — the only recipient is filtered by distance.
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();

        $limited = $this->createTestUser();
        $limited->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278]];
        $limited->save();
        $this->createMembership($limited, $group);

        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent'], 'the only recipient is filtered out by distance');

        $cursor = DB::table('groups_digests')->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)->first();
        $this->assertEquals(
            (int) $msg->id,
            (int) $cursor->msgid,
            'cursor still advances past the message even though every recipient was distance-filtered'
        );
    }

    public function test_immediate_cursor_own_post_bypasses_distance_preference(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $poster->settings = ['browseMaxDistance' => 1, 'mylocation' => ['lat' => 51.5074, 'lng' => -0.1278]];
        $poster->save();

        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Own far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(1, $stats['emails_sent'], 'own post always bypasses the distance filter');
    }

    public function test_mail_newly_reached_filters_distance_limited_member_inside_reach(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $memberC = $this->createTestUser();
        $this->createMembership($memberC, $group);
        // ~22.7 miles from the post origin — inside the reach polygon below, but
        // beyond memberC's own 2-mile cap.
        $memberC->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $memberC->save();

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: reach distance (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('c', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        // Wide enough to cover memberC's location (lng 0.4 is within -0.2..0.6).
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $memberC->id)->exists(),
            'inside reach but beyond the personal distance preference — not mailed, no ledger write'
        );
    }

    public function test_mail_newly_reached_does_not_ledger_a_distance_filtered_skip_then_mails_after_widening_distance_preference(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $memberC = $this->createTestUser();
        $this->createMembership($memberC, $group);
        $memberC->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $memberC->save();

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: reach distance widen (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('e', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->mailNewlyReachedForPost($msg->id);
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $memberC->id)->exists(),
            'first run: filtered out, no ledger row written'
        );

        // Widen the slider — a re-run within the reach-mail recency window now mails them,
        // because the earlier skip was never ledgered (design's recommended semantics).
        $memberC->settings = array_merge($memberC->settings, ['browseMaxDistance' => 50]);
        $memberC->save();

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $memberC->id)->exists(),
            'second run after widening: mailed and ledgered'
        );
    }

    public function test_mail_newly_reached_own_post_bypasses_distance_preference(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        // Poster's own resolved location differs from the post's origin (e.g. they
        // posted from work) and is beyond their own tight distance preference.
        $poster->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $poster->save();

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: own reach post (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('f', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $poster->id)->exists(),
            'own post is mailed and ledgered despite exceeding the personal distance preference'
        );
    }

    public function test_cross_pipeline_distance_preference_decisions_agree(): void
    {
        // Same recipient location, same post location (~22.7 miles apart), same
        // tight browseMaxDistance=2 miles: all three pipelines must independently
        // reach the SAME "filter this out" decision, since all three call the one
        // shared DistancePreferenceFilter helper.
        config(['freegle.digest.immediate_allowlist' => '*']);
        config(['freegle.digest.daily_allowlist' => '*']);

        $recipientSettings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $farLat = 51.5074;
        $farLng = -0.1278;

        // --- Daily ---
        $dailyGroup = $this->createTestGroup();
        $dailyPoster = $this->createTestUser();
        $dailyRecipient = $this->createTestUser();
        $dailyRecipient->settings = array_merge(['simplemail' => User::SIMPLE_MAIL_BASIC], $recipientSettings);
        $dailyRecipient->lastaccess = now();
        $dailyRecipient->save();
        $this->createMembership($dailyRecipient, $dailyGroup, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->createMembership($dailyPoster, $dailyGroup);
        $this->createTestMessage($dailyPoster, $dailyGroup, [
            'subject' => 'OFFER: Cross-pipeline daily (TestLocation)', 'lat' => $farLat, 'lng' => $farLng,
        ]);
        $dailyStats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $dailyRecipient->id);
        $this->assertEquals(0, $dailyStats['emails_sent'], 'daily digest rejects the out-of-range post');

        // --- Immediate cursor ---
        $cursorGroup = $this->createTestGroup();
        $cursorPoster = $this->createTestUser();
        $cursorRecipient = $this->createTestUser();
        $cursorRecipient->settings = $recipientSettings;
        $cursorRecipient->save();
        $this->createMembership($cursorPoster, $cursorGroup);
        $this->createMembership($cursorRecipient, $cursorGroup);
        $this->seedImmediateCursor($cursorGroup);
        $cursorMsg = $this->createTestMessage($cursorPoster, $cursorGroup, [
            'subject' => 'OFFER: Cross-pipeline cursor (TestLocation)', 'lat' => $farLat, 'lng' => $farLng,
        ]);
        $this->makeImmediateReady($cursorMsg);
        $cursorStats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $cursorRecipient->id);
        $this->assertEquals(0, $cursorStats['emails_sent'], 'cursor immediate rejects the out-of-range post');

        // --- Reach-mail ---
        $reachGroup = $this->createTestGroup();
        $reachPoster = $this->createTestUser();
        $reachRecipient = $this->createTestUser();
        $reachRecipient->settings = $recipientSettings;
        $reachRecipient->save();
        $this->createMembership($reachPoster, $reachGroup);
        $this->createMembership($reachRecipient, $reachGroup);
        $reachMsg = $this->createTestMessage($reachPoster, $reachGroup, [
            'subject' => 'OFFER: Cross-pipeline reach (TestLocation)', 'lat' => $farLat, 'lng' => $farLng,
        ]);
        DB::table('messages_groups')->where('msgid', $reachMsg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $reachMsg->id, 'externaluid' => 'freegletusd-'.str_repeat('g', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        // Polygon wide enough to cover both the post origin and the recipient's location.
        $this->seedReach($reachMsg->id, 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))');
        $this->service->mailNewlyReachedForPost($reachMsg->id);
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $reachMsg->id)->where('userid', $reachRecipient->id)->exists(),
            'reach-mail rejects the out-of-range post, same as the other two pipelines'
        );
    }

    public function test_daily_digest_eager_loads_externalmods_for_ai_photo_detection(): void
    {
        // The daily-posts PUSH collage prefers a real photo over an AI illustration
        // (PushNotificationService::attachmentIsAi reads attachments.externalmods).
        // getPostsForUser's shared eager-load must carry externalmods through, or in
        // production every photo is silently classed as real. The existing AI-preference
        // tests build fully-loaded models and so never exercised the real eager-load.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->lastaccess = now();
        $recipient->save();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);
        MessageAttachment::create([
            'msgid'        => $msg->id,
            'externalurl'  => 'https://cdn.example.com/ai.jpg',
            'archived'     => 0,
            'primary'      => 1,
            'externalmods' => json_encode(['ai' => true]),
        ]);

        $tracker = UserDigest::firstOrCreate(
            ['userid' => $recipient->id, 'mode' => UnifiedDigestService::MODE_DAILY],
            ['lastmsgid' => null, 'lastmsgdate' => null],
        );

        $posts = $this->service->getPostsForUser(
            $recipient,
            $tracker,
            UnifiedDigestService::MODE_DAILY
        );

        $post = $posts->firstWhere('id', $msg->id);
        $this->assertNotNull($post, 'the AI-photo offer should be in the daily digest set');

        $attachment = $post->attachments->first();
        $this->assertNotNull($attachment, 'the attachment should be eager-loaded');
        $this->assertNotNull(
            $attachment->externalmods,
            'externalmods must be eager-loaded so the push can distinguish AI illustrations from real photos'
        );
        $this->assertSame(['ai' => true], json_decode($attachment->externalmods, true));
    }

    /**
     * The rural-access overflow lane, on the mail path.
     *
     * A member whose own density band earns the wider travel budget can sit outside a reach
     * that the audience cap stopped short - the case this lane exists for: measured on live, a
     * post outside Birmingham stopped at 28.0 minutes on exactly 4,000 members while a
     * sparse-band moderator 31.4 minutes away, already at the 45-minute maximum, was shut out.
     *
     * Sets up one member outside the reach polygon but inside the sparse ring, and asserts the
     * SAME setup both ways round the flag - so neither expectation can be satisfied by the
     * member simply never being mailable.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Message}
     */
    private function seedOverflowCase(
        ?string $band,
        string $ringWkt = 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))',
        string $lane = 'rural',
        string $ringKey = 'sparse'
    ): array {
        config(['freegle.digest.immediate_allowlist' => '*']);
        UnifiedDigestService::forgetOverflowColumn();

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $member = $this->createTestUser();
        $this->createMembership($member, $group);
        $settings = [
            'mylocation' => ['lat' => 51.5, 'lng' => 0.4],
            // The sentinel: their own preference must not be what excludes them, or the test
            // would pass for the wrong reason.
            'browseReachMaxDistance' => 9007199254740991,
            'browseMaxMinutes' => 45,
        ];
        if ($band !== null) {
            $settings['browseDensityBand'] = $band;
        }
        $member->settings = $settings;
        $member->save();

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: rural overflow (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('r', 32),
            'primary' => 1, 'archived' => 0,
        ]);

        // The committed reach stops at lng 0.0 - well short of the member at 0.4.
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)
            ->update(['overflow_bounds' => json_encode([$lane => [$ringKey => $ringWkt]])]);

        return [$member, $msg];
    }

    /** Answer the spatial server's batch deprivation lookup with one fifth per point. */
    private function fakeQuintiles(array $quintiles): void
    {
        Http::fake(['*/v1/quintiles' => Http::response(['quintiles' => $quintiles, 'available' => true])]);
    }

    private function wasMailed(int $msgid, int $userid): bool
    {
        return DB::table('rippling_reach_notified')
            ->where('msgid', $msgid)->where('userid', $userid)->exists();
    }

    public function test_rural_overflow_does_not_mail_outside_the_reach_when_the_lane_is_off(): void
    {
        config(['freegle.ripple.rural_access.enabled' => false]);
        [$member, $msg] = $this->seedOverflowCase('sparse');

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse(
            $this->wasMailed($msg->id, $member->id),
            'rings are stored but the lane is off, so the reach polygon alone decides'
        );
    }

    public function test_rural_overflow_mails_a_member_whose_own_band_earns_the_wider_budget(): void
    {
        config(['freegle.ripple.rural_access.enabled' => true]);
        [$member, $msg] = $this->seedOverflowCase('sparse');

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertTrue(
            $this->wasMailed($msg->id, $member->id),
            'outside the capped reach but inside their own band ring - the case the lane exists for'
        );
    }

    public function test_rural_overflow_does_not_mail_a_member_of_a_different_band(): void
    {
        // Inside the sparse ring geographically, but a dense-band member has not earned that
        // budget: the ring belongs to the band, not to the area.
        config(['freegle.ripple.rural_access.enabled' => true]);
        [$member, $msg] = $this->seedOverflowCase('dense');

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse(
            $this->wasMailed($msg->id, $member->id),
            'a dense-band member inside the sparse ring must not be admitted by it'
        );
    }

    /**
     * The fairness lane, on the mail path.
     *
     * The ring is a STRETCHED isochrone, so containment alone would simply widen the reach for
     * everyone inside it - a bigger radius, not fairness. The stretch is earned by the
     * deprivation fifth, and that lives only in the spatial server, so it is asked there for
     * the people the ring adds rather than stored against anybody.
     */
    private function seedFairnessCase(): array
    {
        return $this->seedOverflowCase(null, 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))', 'fairness', '1');
    }

    public function test_fairness_overflow_mails_a_member_in_the_most_deprived_fifth(): void
    {
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        [$member, $msg] = $this->seedFairnessCase();
        $this->fakeQuintiles([1]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertTrue(
            $this->wasMailed($msg->id, $member->id),
            'outside the committed reach, inside the stretched ring, and in the fifth the stretch is for'
        );
    }

    public function test_fairness_overflow_does_not_mail_a_member_outside_the_target_fifth(): void
    {
        // Same geography, same ring, different person: containment got them considered, the
        // fifth is what decides. Without this the lane is just a wider radius for everyone.
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        [$member, $msg] = $this->seedFairnessCase();
        $this->fakeQuintiles([4]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse(
            $this->wasMailed($msg->id, $member->id),
            'inside the stretched ring but not in the fifth it was stretched for'
        );
    }

    public function test_fairness_overflow_drops_the_extra_recipients_when_deprivation_is_unavailable(): void
    {
        // Fail CLOSED. Mailing everyone the stretched ring covers is exactly the
        // widened-radius behaviour the fifth exists to prevent, so an unavailable lookup must
        // cost the lane its extra people rather than hand them all the mail.
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        [$member, $msg] = $this->seedFairnessCase();
        Http::fake(['*/v1/quintiles' => Http::response(null, 500)]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse($this->wasMailed($msg->id, $member->id));
    }

    public function test_fairness_overflow_drops_the_extra_recipients_on_a_misaligned_answer(): void
    {
        // Answers are matched back to people BY POSITION, so a short array would attribute one
        // member's deprivation to another. Refusing the whole answer is the only safe reading.
        config(['freegle.ripple.fairness.enabled' => true, 'freegle.ripple.fairness.max_quintile' => 1]);
        [$member, $msg] = $this->seedFairnessCase();
        $this->fakeQuintiles([]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse($this->wasMailed($msg->id, $member->id));
    }

    public function test_fairness_overflow_sends_nothing_when_the_lane_is_off(): void
    {
        config(['freegle.ripple.fairness.enabled' => false]);
        [$member, $msg] = $this->seedFairnessCase();
        Http::fake(['*/v1/quintiles' => Http::response(['quintiles' => [1], 'available' => true])]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse($this->wasMailed($msg->id, $member->id));
        Http::assertNothingSent();
    }

    public function test_rural_overflow_does_not_mail_a_member_with_no_band_recorded(): void
    {
        // The backfill has not reached them yet. Absent must mean "not eligible" rather than
        // "matches anything", or the lane would widen the mail for the whole membership the
        // moment it was switched on.
        config(['freegle.ripple.rural_access.enabled' => true]);
        [$member, $msg] = $this->seedOverflowCase(null);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertFalse(
            $this->wasMailed($msg->id, $member->id),
            'no band recorded must not be admitted by any ring'
        );
    }
}
