<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\Ripple\ReachMemberQueueService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesRingIndex;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

class UnifiedDigestServiceTest extends TestCase
{
    use FakesRingIndex;
    use SeedsReachCells;

    protected UnifiedDigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnifiedDigestService();
        Mail::fake();
        // Rippling ships dark; enable it so the reach-coordination ledger path is exercised.
        config(['freegle.ripple.enabled' => true]);
        $this->fakeRingIndex();

        // Digests are not scoped to a community, so fixture posts would be candidates for every
        // recipient. Age them out so each test digests only the posts it creates.
        DB::table('messages')->update(['arrival' => now()->subYear()]);
        // Recipients are sitewide too, so fixture members are taken off digests.
        DB::table('users')->update(['emailfrequency' => 0]);
    }

    public function test_completed_came_and_went_posts_are_deduplicated_like_live(): void
    {
        // The same item posted twice as two separate messages
        // (distinct ids, shared tnpostid) — and both Taken/Received, so they
        // land in the greyed daily "came and went" section.
        $user = $this->createTestUser();

        $message1 = $this->createTestMessage($user, [
            'tnpostid' => 'TN54321',
        ]);
        $message2 = $this->createTestMessage($user, [
            'tnpostid' => 'TN54321',
            'subject' => $message1->subject,
        ]);

        $completed = collect([$message1, $message2]);

        // The old came-and-went path used ->unique('id'), which keeps both
        // because the msgids differ — that's the duplication we're fixing.
        $this->assertCount(2, $completed->unique('id')->values());

        // The fix collapses the cross-post to a single card, exactly like the
        // live section's deduplicatePosts().
        $deduped = $this->service->deduplicateCompletedPosts($completed);
        $this->assertCount(1, $deduped);
        $this->assertEquals($message1->id, $deduped->first()->id);
    }

    public function test_different_items_not_deduplicated(): void
    {
        $user = $this->createTestUser();

        $message1 = $this->createTestMessage($user, [
            'subject' => 'OFFER: Sofa (London)',
        ]);
        $message2 = $this->createTestMessage($user, [
            'subject' => 'OFFER: Table (London)',
        ]);

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(2, $deduplicated);
    }

    public function test_user_digest_tracker_created(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        // Set recipient to want daily digests and be active. V1 parity:
        // membership emailfrequency=24 is the authoritative daily selector;
        // simplemail acts only as the join-time default that populated it.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        // Create a message from another user (so recipient has something to receive).
        $this->createTestMessage($poster);

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

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        // The only post in range has been withdrawn.
        $message = $this->createTestMessage($poster);
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => 'Withdrawn',
            'timestamp' => now(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(0, $stats['emails_sent'], 'a withdrawn/taken post must not be digested');
    }

    public function test_daily_digest_excludes_a_post_whose_reach_is_frozen(): void
    {
        // A frozen reach (status 'held') means the origin copy has been pulled back for
        // moderation. Browse, the badge and search hide the post, and nothing ever clears
        // 'held', so the digest carrying it would leave the mail as the one surface still
        // pushing a post that is under review.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $message = $this->createTestMessage($poster);

        // A reach that DOES cover the recipient, so only the frozen status can exclude it.
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status, arrival)
             VALUES (?, 51.5, -0.1, ?,
                ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))', 3857)),
                'held', NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            [$message->id, $this->reachCellsFor('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))')]
        );

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(0, $stats['emails_sent'], 'a post under moderation must not be digested');
    }

    public function test_daily_digest_drops_a_post_whose_stored_label_says_out(): void
    {
        // Labels-truth: the stored road-network label is the deciding record.
        // The cell grid covers this recipient (over-coverage - the far bank of
        // an estuary), but the label knows they cannot drive there within the
        // post's current budget, so the digest must not mail it - the same
        // narrowing browse applies, so mail can never carry what browse hides.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();
        $this->setMyLocation($recipient, 51.5, -0.1);

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $message = $this->createTestMessage($poster);

        // Cells that DO cover the recipient at (51.5, -0.1).
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status, arrival)
             VALUES (?, 51.5, -0.1, ?,
                ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))', 3857)),
                'expanding', NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            [$message->id, $this->reachCellsFor('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))')]
        );

        Http::fake(['*/v1/reach-eval*' => Http::response([
            'results' => [['msgid' => $message->id, 'verdict' => 'out']],
        ])]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(0, $stats['emails_sent'], 'an OUT label verdict must override in-reach cells');
    }

    public function test_daily_digest_keeps_a_post_with_no_stored_label(): void
    {
        // Not backfilled yet (or the routing server predates labels): the
        // cell-grid verdict stands unchanged.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();
        $this->setMyLocation($recipient, 51.5, -0.1);

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $message = $this->createTestMessage($poster);

        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status, arrival)
             VALUES (?, 51.5, -0.1, ?,
                ST_Envelope(ST_GeomFromText('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))', 3857)),
                'expanding', NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            [$message->id, $this->reachCellsFor('POLYGON((-0.3 51.3, 0.1 51.3, 0.1 51.7, -0.3 51.7, -0.3 51.3))')]
        );

        Http::fake(['*/v1/reach-eval*' => Http::response([
            'results' => [['msgid' => $message->id, 'verdict' => 'nolabels']],
        ])]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent'], 'with no stored label the cell verdict decides');
    }

    public function test_daily_digest_discovers_a_labelled_post_the_grid_missed(): void
    {
        // The under-coverage band: the post's cell grid does NOT cover this
        // recipient, so the grid gate alone would exclude it - but its stored
        // label admits them by road, and the discover arm re-admits it, the
        // same union the browse feed applies.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();
        $this->setMyLocation($recipient, 51.5, -0.1);

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $message = $this->createTestMessage($poster);

        // Cells well away from the recipient at (51.5, -0.1).
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, status, arrival)
             VALUES (?, 51.5, 0.7, ?,
                ST_Envelope(ST_GeomFromText('POLYGON((0.5 51.3, 0.9 51.3, 0.9 51.7, 0.5 51.7, 0.5 51.3))', 3857)),
                'expanding', NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            [$message->id, $this->reachCellsFor('POLYGON((0.5 51.3, 0.9 51.3, 0.9 51.7, 0.5 51.7, 0.5 51.3))')]
        );

        Http::fake(['*/v1/reach-eval*' => Http::response([
            'results' => [],
            'discovered' => [['msgid' => $message->id, 'verdict' => 'in']],
        ])]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent'], 'a label-admitted post the grid missed must still be mailed');
    }

    public function test_daily_digest_flags_already_seen_posts_for_the_recipient(): void
    {
        // A messages_likes 'View' (in-app view, or an opened/clicked digest via
        // mail:digest:mark-seen) marks the post seen for THAT recipient, so the
        // daily digest can sink it below fresh posts (config freegle.digest.seen_penalty).
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $seen = $this->createTestMessage($poster);
        $unseen = $this->createTestMessage($poster);

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

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $this->createTestMessage($poster); // available
        $taken = $this->createTestMessage($poster);
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

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        $this->createTestMessage($poster);

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

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $this->createTestMessage($poster);

        $mk = function () {
            $u = $this->createTestUser();
            $u->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
            $u->lastaccess = now();
            $u->save();
            DB::table('users')->where('id', $u->id)->update(['emailfrequency' => 24]);
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

    public function test_digest_includes_users_own_posts(): void
    {
        // V1 parity: the per-group digest selection in the legacy V1 PHP
        // Digest implementation has no fromuser != ? filter, so a user's
        // own posts appear in their own digest. Mirror that here.
        $recipient = $this->createTestUser();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $this->createTestMessage($recipient);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_deduplication_same_subject_different_body_not_deduped(): void
    {
        $user = $this->createTestUser();

        // Two messages with same subject but different body text.
        $message1 = $this->createTestMessage($user, [
            'subject' => 'OFFER: Garden tools (London)',
            'textbody' => 'I have a spade and a fork available for collection.',
        ]);
        $message2 = $this->createTestMessage($user, [
            'subject' => 'OFFER: Garden tools (London)',
            'textbody' => 'Lawnmower available, needs collecting this weekend.',
        ]);

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should NOT be deduplicated because bodies are different.
        $this->assertCount(2, $deduplicated);
    }

    public function test_deduplication_null_body_treated_as_matching(): void
    {
        $user = $this->createTestUser();

        // Two messages with same subject and both null bodies.
        $message1 = $this->createTestMessage($user, [
            'subject' => 'OFFER: Table (London)',
            'textbody' => null,
        ]);
        $message2 = $this->createTestMessage($user, [
            'subject' => 'OFFER: Table (London)',
            'textbody' => null,
        ]);

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should be deduplicated - null bodies both normalize to ''.
        $this->assertCount(1, $deduplicated);
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $this->createTestMessage($poster, ['subject' => 'OFFER: A (TestLocation)']);
        $this->createTestMessage($poster, ['subject' => 'OFFER: B (TestLocation)']);
        $this->createTestMessage($poster, ['subject' => 'OFFER: C (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_digest_carries_posts_left_out_at_the_cap_to_the_next_run(): void
    {
        // Discourse 10029/17: a post that was eligible but did not fit under the post cap
        // used to be lost for good, because the cursor moved past it. The digest now
        // records what it left out and offers those posts again in the next run.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;

        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();
        $poster = $this->createTestUser();
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        for ($i = 1; $i <= $cap + 2; $i++) {
            $this->createTestMessage($poster, ['subject' => "OFFER: Carry{$i}Zq (TestLocation)"]);
        }

        Mail::fake();
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent']);
        $sent = [];
        Mail::assertSent(\App\Mail\Digest\UnifiedDigest::class, function ($m) use (&$sent) {
            $sent[] = $m;
            return true;
        });
        $this->assertCount(1, $sent);
        $dropped = $sent[0]->droppedPostIds();
        $this->assertCount(2, $dropped, 'two posts did not fit under the cap');

        $tracker = UserDigest::where('userid', $recipient->id)
            ->where('mode', UnifiedDigestService::MODE_DAILY)
            ->first();
        $this->assertEqualsCanonicalizing($dropped, $tracker->carryover);

        // Next day: nothing new has arrived, but the two carried posts are offered again,
        // and once shown they are not carried any further.
        $tracker->update(['lastsent' => now()->subDay()]);
        Mail::fake();
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent']);
        $sent = [];
        Mail::assertSent(\App\Mail\Digest\UnifiedDigest::class, function ($m) use (&$sent) {
            $sent[] = $m;
            return true;
        });
        $shown = array_map(fn ($p) => (int) $p['msgid'], $sent[0]->mailDescriptor()['posts']);
        $this->assertEqualsCanonicalizing($dropped, $shown);
        $this->assertSame([], $sent[0]->droppedPostIds());
        $this->assertNull($tracker->fresh()->carryover);
    }

    public function test_carried_posts_sit_below_the_new_ones_in_the_next_digest(): void
    {
        // A carried post is older than the cursor by construction, so letting the score
        // interleave it gives the member a digest whose dates jump around - the exact thing
        // the roll-up exists to avoid. The carried half sinks below the new posts.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;
        [$recipient, $poster] = $this->dailyDigestMembers();

        for ($i = 1; $i <= $cap + 2; $i++) {
            $this->createTestMessage($poster, [
                'subject' => "OFFER: Below{$i}Zq (TestLocation)",
                'arrival' => now()->subHours(2),
            ]);
        }

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $carried = $this->digestTrackerFor($recipient)->carryover;
        $this->assertCount(2, $carried, 'two posts did not fit under the cap');

        // A quiet next day: two new posts arrive, so there is room for everything.
        $fresh = [];
        foreach ([1, 2] as $i) {
            $fresh[] = $this->createTestMessage($poster, [
                'subject' => "OFFER: Fresh{$i}Zq (TestLocation)",
            ])->id;
        }
        $this->digestTrackerFor($recipient)->update(['lastsent' => now()->subDay()]);

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $shown = array_map(
            fn ($p) => (int) $p['msgid'],
            $this->lastDailyDigest()->mailDescriptor()['posts']
        );

        $this->assertCount(4, $shown, 'all four fit under the cap');
        $this->assertEqualsCanonicalizing($fresh, array_slice($shown, 0, 2), 'the new posts lead');
        $this->assertEqualsCanonicalizing($carried, array_slice($shown, 2), 'the carried ones follow');
    }

    public function test_a_carried_post_stops_being_carried_once_it_is_too_old(): void
    {
        // Nothing removes an id from the carryover except being shown, or the post getting an
        // outcome or being deleted - and a member whose daily volume is over the cap has no
        // room to show one. Without the age bound they accumulate a permanent block of old
        // posts at the head of every window.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;
        [$recipient, $poster] = $this->dailyDigestMembers();

        for ($i = 1; $i <= $cap + 2; $i++) {
            $this->createTestMessage($poster, [
                'subject' => "OFFER: Aged{$i}Zq (TestLocation)",
                'arrival' => now()->subHours(2),
            ]);
        }

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        [$stale, $recent] = $this->digestTrackerFor($recipient)->carryover;

        // Age one of the two past the bound, on the clock the digest window uses.
        $aged = now()->subDays(UnifiedDigestService::CARRYOVER_MAX_AGE_DAYS + 1);
        DB::table('messages')->where('id', $stale)->update(['arrival' => $aged]);
        DB::table('messages')->where('id', $stale)->update(['arrival' => $aged]);

        // Next day, a full digest's worth of new posts, so neither carried post has room.
        for ($i = 1; $i <= $cap; $i++) {
            $this->createTestMessage($poster, ['subject' => "OFFER: Next{$i}Zq (TestLocation)"]);
        }
        $this->digestTrackerFor($recipient)->update(['lastsent' => now()->subDay()]);

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $dropped = $this->lastDailyDigest()->droppedPostIds();
        $this->assertContains($stale, $dropped, 'the aged post was still a candidate the cap cut');
        $this->assertContains($recent, $dropped, 'so was the one still within the bound');

        $this->assertSame([$recent], $this->digestTrackerFor($recipient)->carryover);
    }

    public function test_a_carried_post_the_member_has_since_seen_is_not_carried_again(): void
    {
        // scoreAndSortAvailable sinks a seen post by seen_penalty, so it can never win a slot
        // against anything unseen. Carrying it spends a DIGEST_LOAD_CAP slot - at the FRONT of
        // the window, since carried posts are older than the cursor - on a post that will
        // never be shown.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;
        [$recipient, $poster] = $this->dailyDigestMembers();

        for ($i = 1; $i <= $cap + 2; $i++) {
            $this->createTestMessage($poster, [
                'subject' => "OFFER: Seen{$i}Zq (TestLocation)",
                'arrival' => now()->subHours(2),
            ]);
        }

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        [$viewed, $unviewed] = $this->digestTrackerFor($recipient)->carryover;

        // The member found one of them on the website in the meantime.
        DB::table('messages_likes')->insert([
            'msgid' => $viewed, 'userid' => $recipient->id, 'type' => 'View', 'count' => 1,
        ]);

        for ($i = 1; $i <= $cap; $i++) {
            $this->createTestMessage($poster, ['subject' => "OFFER: After{$i}Zq (TestLocation)"]);
        }
        $this->digestTrackerFor($recipient)->update(['lastsent' => now()->subDay()]);

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $dropped = $this->lastDailyDigest()->droppedPostIds();
        $this->assertContains($viewed, $dropped, 'the seen post was still a candidate the cap cut');

        $this->assertSame([$unviewed], $this->digestTrackerFor($recipient)->carryover);
    }

    public function test_the_carryover_never_holds_more_than_one_digest_worth(): void
    {
        // The age bound does not bound the SIZE: a member busy enough to drop hundreds fills
        // the list with three days of recent posts. Size is what costs them, because the
        // window query is oldest-first under DIGEST_LOAD_CAP. One digest's worth is also the
        // most that could ever be shown, and droppedPostIds() is in the digest's own priority
        // order, so the head is the part with a real chance.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;
        [$recipient, $poster] = $this->dailyDigestMembers();

        for ($i = 1; $i <= 2 * $cap + 3; $i++) {
            $this->createTestMessage($poster, [
                'subject' => "OFFER: Many{$i}Zq (TestLocation)",
                'arrival' => now()->subHours(2),
            ]);
        }

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $dropped = $this->lastDailyDigest()->droppedPostIds();
        $this->assertCount($cap + 3, $dropped, 'more than a digest\'s worth did not fit');

        $carried = $this->digestTrackerFor($recipient)->carryover;
        $this->assertCount($cap, $carried, 'the stored list is capped');
        $this->assertSame(array_slice($dropped, 0, $cap), $carried, 'and it keeps the head');
    }

    public function test_the_carryover_is_not_ORed_into_the_window_query(): void
    {
        // The carryover list is on messages.id; the window's range column is
        // messages.arrival. ORing them inside the range means MySQL cannot index-merge, and
        // — because the query is
        // ORDER BY arrival ASC LIMIT — walks the arrival index from the oldest row of
        // 11M forward instead. Measured on production 2026-09-17: 60-74s against 0.29s
        // for the same query with the carryover arm removed, which is what pinned db2 at
        // 98% of its cores and stopped the daily digest finishing inside its window.
        // The two arms must therefore be two queries, not one.
        [$recipient, $poster] = $this->dailyDigestMembers();

        $carried = $this->createTestMessage($poster, [
            'subject' => 'OFFER: SplitCarriedZq (TestLocation)',
            'arrival' => now()->subDays(2),
        ]);
        $this->createTestMessage($poster, [
            'subject' => 'OFFER: SplitFreshZq (TestLocation)',
            'arrival' => now()->subHour(),
        ]);

        $tracker = $this->trackerWithCarryover($recipient, [$carried->id], now()->subDay());

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->service->getPostsForUser($recipient, $tracker, UnifiedDigestService::MODE_DAILY);

        // Exact, not approximate: the window arm carries the arrival range and the carryover
        // arm carries the id list. Only the ORed form has both in one statement.
        $isWindow = fn ($sql) => str_contains($sql, '`messages`.`arrival` >');
        $hasIdList = fn ($sql) => (bool) preg_match('/`messages`\.`id`\s+in\s*\(/i', $sql);

        foreach ($queries as $sql) {
            $this->assertFalse(
                $isWindow($sql) && $hasIdList($sql),
                "the carryover is ORed into the windowed query, which costs the arrival index:\n" . $sql
            );
        }

        // ...and both arms did run, so this is not passing because nothing was queried.
        $this->assertTrue(collect($queries)->contains($isWindow), 'the window arm did not run');
        $this->assertTrue(collect($queries)->contains($hasIdList), 'the carryover arm did not run');
    }

    public function test_a_carryover_only_run_does_not_move_the_cursor_backwards(): void
    {
        // The cursor is taken from $posts->last() in arrival-ascending order. Carried posts
        // are older than the cursor by construction, so when a run's window has nothing new
        // and the carryover is all that comes back, last() is an OLD post and lastmsgdate
        // regresses. The next run then re-opens a window the member has already been sent,
        // which both re-offers posts and widens the scan this change exists to narrow.
        $cap = \App\Mail\Digest\DigestStyle::DIGEST_POST_CAP;
        [$recipient, $poster] = $this->dailyDigestMembers();

        // Staggered arrivals, so "backwards" is observable: the cap keeps the newest and
        // drops the oldest, so everything carried is strictly older than the cursor. Minutes,
        // not hours - a fresh tracker's window is arrival >= now()-1 day, and DIGEST_POST_CAP
        // is 65, so hour-spacing would push most of these outside the first run's window.
        for ($i = 1; $i <= $cap + 2; $i++) {
            $this->createTestMessage($poster, [
                'subject' => "OFFER: Cursor{$i}Zq (TestLocation)",
                'arrival' => now()->subMinutes($cap + 3 - $i),
            ]);
        }

        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $tracker = $this->digestTrackerFor($recipient);
        $cursorAfterFirstRun = $tracker->lastmsgdate;
        $this->assertNotNull($cursorAfterFirstRun, 'the first run set a cursor');
        $this->assertNotEmpty($tracker->carryover, 'the cap left something to carry');

        // A quiet day: nothing new has arrived, so only the carried posts come back.
        $tracker->update(['lastsent' => now()->subDay()]);
        Mail::fake();
        $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $cursorAfterSecondRun = $this->digestTrackerFor($recipient)->lastmsgdate;
        $this->assertNotNull($cursorAfterSecondRun);
        $this->assertTrue(
            $cursorAfterSecondRun->greaterThanOrEqualTo($cursorAfterFirstRun),
            "a carryover-only run dragged the cursor back into a window already sent: "
                . "{$cursorAfterFirstRun} -> {$cursorAfterSecondRun}"
        );
    }

    /**
     * A daily tracker with a carryover list and a cursor, as a run that hit the cap leaves it.
     *
     * @param int[] $carryover
     */
    private function trackerWithCarryover(User $recipient, array $carryover, \DateTimeInterface $cursor): UserDigest
    {
        $tracker = UserDigest::firstOrCreate(
            ['userid' => $recipient->id, 'mode' => UnifiedDigestService::MODE_DAILY],
        );
        $tracker->update([
            'carryover' => $carryover,
            'lastmsgdate' => $cursor,
            'lastsent' => now()->subDay(),
        ]);

        return $tracker->fresh();
    }

    /**
     * A recipient on daily and someone to post.
     *
     * @return array{0: User, 1: User}
     */
    private function dailyDigestMembers(): array
    {
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        return [$recipient, $poster];
    }

    private function digestTrackerFor(User $recipient): UserDigest
    {
        return UserDigest::where('userid', $recipient->id)
            ->where('mode', UnifiedDigestService::MODE_DAILY)
            ->firstOrFail();
    }

    /**
     * The digest the run under test just spooled. Mail::assertSent also fails the test when
     * nothing was sent, which is the assertion every caller here wants anyway.
     */
    private function lastDailyDigest(): \App\Mail\Digest\UnifiedDigest
    {
        $sent = [];
        Mail::assertSent(\App\Mail\Digest\UnifiedDigest::class, function ($m) use (&$sent) {
            $sent[] = $m;
            return true;
        });

        return end($sent);
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
    private function makeDailyRecipientWithPost(int $emailfrequency = 24): User
    {
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => $emailfrequency]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $this->createTestMessage($poster, ['subject' => 'OFFER: Item (TestLocation)']);

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

    public function test_mail_newly_reached_reach_gates_then_picks_up_later_reached_on_rerun(): void
    {
        // The expander-driven mailer (#0 step 4) mails the post to immediate members the reach
        // NOW covers, ledgers them, and — crucially — on a LATER tick picks up members the reach
        // reaches afterwards (the exact case the cursor-based approach silently dropped).
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $memberA = $this->createTestUser();
        DB::table('users')->where('id', $memberA->id)->update(['emailfrequency' => -1]);
        $memberB = $this->createTestUser();
        DB::table('users')->where('id', $memberB->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($memberA, 51.5, -0.1);  // inside reach v1
        $this->setMyLocation($memberB, 51.5, 0.5);   // outside v1, inside v2

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: reach mail (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)->update(['reach_labels' => 'label-bytes']);

        // The stored label decides who is reached: at first only A's point
        // (seedReach's box ends at lng 0.0, so A at -0.1 is in, B at 0.5 out).
        $this->service->mailNewlyReachedForPost($msg->id);

        $ledgered = fn ($uid) => DB::table('rippling_reach_notified')
            ->where('msgid', $msg->id)->where('userid', $uid)->exists();
        $this->assertTrue($ledgered($memberA->id), 'reach-covered member A mailed + ledgered');
        $this->assertFalse($ledgered($memberB->id), 'out-of-reach member B not yet mailed');

        // The reach grows to cover B (the label now admits B's point; the
        // outer bound grows as every writer keeps it growing). The re-run
        // mails B and does NOT re-mail A (ledger dedup).
        DB::statement('UPDATE rippling_reach SET outer_bound = ST_Envelope(ST_GeomFromText(?, 3857)) WHERE msgid = ?',
            ['POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))', $msg->id]);
        $this->reachArrivalBox = $this->wktBounds('POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))');
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

    public function test_daily_digest_reach_gates_rippling_posts_by_member_location(): void
    {
        // The daily digest (and the daily-posts push, which shares getPostsForUser) must
        // reach-gate rippling posts by the member's location, just like the immediate path —
        // a daily member is only shown a rippling post once its reach covers them.
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => 24]);
        $this->setMyLocation($member, 51.5, -0.1);

        // Reach COVERS the member (-0.1, 51.5 is inside this polygon).
        $covered = $this->createTestMessage($poster, ['subject' => 'OFFER: covered (TestLocation)']);
        DB::table('messages')->where('id', $covered->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($covered->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        // Reach does NOT cover the member (far to the east).
        $faraway = $this->createTestMessage($poster, ['subject' => 'OFFER: faraway (TestLocation)']);
        DB::table('messages')->where('id', $faraway->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
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

    public function test_daily_digest_ignores_degraded_bounds_for_came_and_went_posts(): void
    {
        // Completion degrades a post's bounds row to a degenerate point (outer=POINT,
        // inner=NULL) to prune it from the browse candidate set. The digest, however,
        // still shows completed posts ("came and went"), so its reach gate must NOT
        // treat a degraded bounds row as an authoritative reject - the containment
        // universe comes from the stored cells via the reach index, which still
        // carries the post (the design doc's "digest came-and-went posts vanish" trap).
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => 24]);
        $this->setMyLocation($member, 51.5, -0.1);

        $taken = $this->createTestMessage($poster, ['subject' => 'OFFER: came and went (TestLocation)']);
        DB::table('messages')->where('id', $taken->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
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
            'a completed post with degraded bounds still reaches the digest via its stored cells'
        );
        $this->assertSame(
            1,
            (int) $posts->firstWhere('id', $taken->id)->has_success,
            'and it is flagged has_success for the came-and-went section'
        );
    }

    /** Set a user's settings.mylocation point (the canonical first-choice location source). */
    protected function setMyLocation(User $user, float $lat, float $lng): void
    {
        $settings = $user->settings ?? [];
        $settings['mylocation'] = ['lat' => $lat, 'lng' => $lng];
        $user->settings = $settings;
        $user->save();
    }

    /** Seed a rippling_reach row for a post whose reach is the given rectangle WKT. */
    protected function seedReach(int $msgid, string $wkt): void
    {
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$msgid, $this->reachCellsFor($wkt), $wkt]
        );
        DB::table('rippling_reach')->where('msgid', $msgid)->update(['reach_labels' => 'label-bytes']);

        // The newly-reached mail evaluates the stored label per candidate
        // point (reach-arrival); fake it to admit points inside the seeded
        // box. Http::fake merges first-match-wins (null falls through), so
        // ONE callback is installed and it reads $this->reachArrivalBox -
        // a test that grows the reach updates the property, not the fake.
        $this->reachArrivalBox = $this->wktBounds($wkt);
        if (!$this->reachArrivalFakeInstalled) {
            $this->reachArrivalFakeInstalled = true;
            Http::fake(function ($request) {
                if (!str_contains($request->url(), 'reach-arrival')) {
                    return null;
                }
                [$minLng, $minLat, $maxLng, $maxLat] = $this->reachArrivalBox;
                $results = [];
                foreach ($request['points'] ?? [] as $pt) {
                    $lat = (float) ($pt['lat'] ?? 0);
                    $lng = (float) ($pt['lng'] ?? 0);
                    $in = $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
                    $results[] = ['arrival' => $in ? 100 : null, 'in' => $in];
                }

                return Http::response(['results' => $results]);
            });
        }
    }

    /** The box the faked reach-arrival admits; seedReach sets it. */
    private array $reachArrivalBox = [0.0, 0.0, 0.0, 0.0];

    private bool $reachArrivalFakeInstalled = false;

    /** [minLng, minLat, maxLng, maxLat] of a simple WKT polygon. */
    private function wktBounds(string $wkt): array
    {
        preg_match_all('/(-?\d+\.?\d*) (-?\d+\.?\d*)/', $wkt, $m, PREG_SET_ORDER);
        $lngs = array_map(fn ($p) => (float) $p[1], $m);
        $lats = array_map(fn ($p) => (float) $p[2], $m);

        return [min($lngs), min($lats), max($lngs), max($lats)];
    }

    /**
     * Push the given message's messages_groups.arrival back past the
     * isImmediateMessageReady() defer deadline so the digest doesn't
     * postpone it waiting for an attachment. Most immediate tests don't
     * care about the defer behaviour; this keeps them terse.
     */
    protected function makeImmediateReady(Message $message): void
    {
        DB::table('messages')
            ->where('id', $message->id)
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
        DB::table('users')->where('id', $user->id)->update(['emailfrequency' => 24]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    // ─── V1-PARITY PER-GROUP IMMEDIATE TESTS ────────────────────────────
    // These pin the new sendImmediateDigests behaviour: walk
    // groups_digests at frequency=-1, find messages since the per-group
    // cursor with (arrival, msgid) tuple compare, send to every member
    // at emailfrequency=-1 — including the poster, since V1 (no fromuser
    // filter) loops a user's own posts back to them too — advance the cursor.

    /**
     * The reach mailer is the other immediate path (rippling posts go through it, not the
     * cursor). A member already mailed about one copy must not be mailed about its twin when
     * the twin's own reach grows over them.
     */
    public function test_reach_mailer_does_not_mail_a_duplicate_item_to_an_already_mailed_member(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => 24]);
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($member, 51.5, -0.1);

        $ids = [];
        foreach (['c', 'd'] as $copy) {
            $msg = $this->createTestMessage($poster, [
                'subject' => 'OFFER: Rippled Twice Bike (TestLocation)',
                'textbody' => 'A bike, one owner.',
            ]);
            DB::table('messages')->where('id', $msg->id)
                ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
            DB::table('messages_attachments')->insert([
                'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat($copy, 32),
                'primary' => 1, 'archived' => 0,
            ]);
            $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
            $ids[] = (int) $msg->id;
        }

        $this->assertSame(1, $this->service->mailNewlyReachedForPost($ids[0]), 'first copy mails the member');
        $this->assertSame(
            0,
            $this->service->mailNewlyReachedForPost($ids[1]),
            'the duplicate must not mail a member who already had the first'
        );
    }

    /**
     * The grouping rule itself, in one place: copies of an item group, different items do not,
     * and another member's identical post is never mine.
     */
    public function test_item_siblings_group_copies_and_keep_other_things_apart(): void
    {
        $poster = $this->createTestUser();
        $other = $this->createTestUser();

        $copyOne = $this->createTestMessage($poster, [
            'subject' => 'OFFER: Sibling Kettle (TestLocation)', 'textbody' => 'A kettle.',
        ]);
        $copyTwo = $this->createTestMessage($poster, [
            'subject' => 'OFFER: Sibling Kettle (TestLocation)', 'textbody' => 'A kettle.',
        ]);
        $different = $this->createTestMessage($poster, [
            'subject' => 'OFFER: Sibling Kettle (TestLocation)', 'textbody' => 'Actually a toaster.',
        ]);
        $someoneElse = $this->createTestMessage($other, [
            'subject' => 'OFFER: Sibling Kettle (TestLocation)', 'textbody' => 'A kettle.',
        ]);

        $siblings = $this->service->itemSiblingMsgids([$copyOne->id]);

        $this->assertContains((int) $copyTwo->id, $siblings[$copyOne->id], 'the identical copy is the same item');
        $this->assertNotContains((int) $different->id, $siblings[$copyOne->id], 'a different body is a different item');
        $this->assertNotContains((int) $someoneElse->id, $siblings[$copyOne->id], "another member's post is never mine");

        $this->assertSame(
            (int) $copyOne->id,
            $this->service->itemIdsForMsgids([$copyTwo->id])[$copyTwo->id],
            'both copies resolve to the same item id, whichever one is asked about'
        );
    }

    /**
     * The daily digest collapses copies that land in ONE digest. A copy that lands in the NEXT
     * one is the same item to the member, and is how one thing reached people on four days
     * running (Discourse 9808).
     */
    public function test_daily_digest_does_not_resend_an_item_sent_in_an_earlier_digest(): void
    {
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $member->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $member->lastaccess = now();
        $member->save();
        $member->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => 24]);

        $this->createTestMessage($poster, [
            'subject' => 'OFFER: Repeated Shelf (TestLocation)',
            'textbody' => 'A pine shelf.',
            'arrival' => now()->subHours(2),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $member->id);
        $this->assertEquals(1, $stats['emails_sent'], 'the first digest carries the item');

        $this->createTestMessage($poster, [
            'subject' => 'OFFER: Repeated Shelf (TestLocation)',
            'textbody' => 'A pine shelf.',
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $member->id);
        $this->assertEquals(
            0,
            $stats['emails_sent'],
            'a copy of something already sent is not a second digest'
        );
    }

    /**
     * ...but a member on their FIRST digest has been sent nothing, so nothing may be withheld
     * from them on the grounds that an older copy exists.
     */
    public function test_daily_digest_first_run_withholds_nothing_on_a_null_cursor(): void
    {
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $member->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $member->lastaccess = now();
        $member->save();
        $member->refresh();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => 24]);

        // Two copies, both inside the first-run 24h window. They collapse to one card, and the
        // older one must not be read as "already sent".
        $this->createTestMessage($poster, [
            'subject' => 'OFFER: First Run Bike (TestLocation)',
            'textbody' => 'A bike.',
            'arrival' => now()->subHours(3),
        ]);
        $this->createTestMessage($poster, [
            'subject' => 'OFFER: First Run Bike (TestLocation)',
            'textbody' => 'A bike.',
            'arrival' => now()->subHour(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $member->id);

        $this->assertEquals(1, $stats['emails_sent'], 'a first digest still goes out');
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
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        $this->createTestMessage($poster, ['subject' => 'OFFER: Item (TestLocation)']);

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
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => -1]);
        $this->createTestMessage($poster);

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
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        $this->createTestMessage($poster);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent']);
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

        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $msg = $this->createTestMessage($poster, [
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
        $msg = $this->createTestMessage($poster);

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
        // implementation, which takes the greatest origin->covered-cell
        // distance over the stored grid. The grid covers cells whose CENTRES
        // lie inside the box, so the farthest covered point sits within one
        // 33m lattice cell of the true corner - hence the tolerance.
        $expected = 0.0;
        foreach ([[-0.2, 51.4], [0.0, 51.4], [0.0, 51.6], [-0.2, 51.6]] as [$lng, $lat]) {
            $expected = max($expected, $haversine(51.5, -0.1, $lat, $lng));
        }

        $r = $this->callPrivate($svc, 'reachRadiusMetres', [$msg->id]);
        $this->assertEqualsWithDelta($expected, $r, 50.0);
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        // near post has NEWER arrival; far post has OLDER arrival.
        // Arrival ASC would put far (older) first => [far, near].
        // Score ordering should put near first => [near, far].
        $near = $this->createTestMessage($poster, [
            'subject' => 'OFFER: Near (TestLocation)',
            'lat' => 51.5, 'lng' => -0.12, 'arrival' => now()->subHours(2),
        ]);
        $far = $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        // A normal, recent, in-window post.
        $normal = $this->createTestMessage($poster, [
            'subject' => 'OFFER: normal recent (TestLocation)',
            'lat' => 51.5, 'lng' => -0.12, 'arrival' => now()->subHours(1),
        ]);
        // A PINNED post that arrived 10 days ago — OUTSIDE the first-digest 24h window, so
        // getPostsForUser would NOT return it. Pinning must force it in, at the very top.
        $pinnedMsg = $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        // The only post is pinned but has been TAKEN (closed). Pinning applies only while a
        // post is open, so it must NOT be force-included, and nothing should send.
        $pinnedTaken = $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1, 'added' => now()->subHours(72)]);
        $member->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]];
        $member->save();

        $message = $this->createTestMessage($poster);
        DB::table('messages')->where('id', $message->id)->update([
            'collection' => Message::COLLECTION_APPROVED,
            'arrival' => now()->subHours(1),
        ]);
        // Reach (status 'expanding', just updated) covering the member's location.
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))'), 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );
        DB::table('rippling_reach')->where('msgid', $message->id)->update(['reach_labels' => 'label-bytes']);

        // The stored label admits every asked point: the reach-mail pass is
        // under test, not the geometry.
        Http::fake(function ($request) {
            if (!str_contains($request->url(), 'reach-arrival')) {
                return null;
            }
            $results = array_map(
                fn ($pt) => ['arrival' => 100, 'in' => true],
                $request['points'] ?? []
            );

            return Http::response(['results' => $results]);
        });

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
    // (createTestMessage); Edinburgh (55.9533, -3.1889) is
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        // ~0.9 miles from the recipient — inside the 5-mile cap.
        $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $this->createTestMessage($poster, [
            'subject' => 'OFFER: Far item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'the sentinel value means unlimited — unaffected by the new filter');
    }

    public function test_daily_digest_filters_completed_post_beyond_distance_preference(): void
    {
        // Discourse 10167/1: a member with a distance limit saw a "came and
        // went" (Taken) post from far outside it. $posts (live) is narrowed by
        // filterByDistancePreference; $completedPosts must be too.
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        // ~0.9 miles away, inside the 2-mile cap — keeps the digest non-empty
        // so this test isolates the completed-post filtering, not the send/no-send decision.
        $this->createTestMessage($poster, [
            'subject' => 'OFFER: Near item (London)',
            'lat' => 51.52,
            'lng' => -0.1278,
        ]);

        // Taken, ~330 miles away (Edinburgh) — outside the 2-mile cap.
        $farTaken = $this->createTestMessage($poster, [
            'subject' => 'OFFER: Far taken item (Edinburgh)',
            'lat' => 55.9533,
            'lng' => -3.1889,
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $farTaken->id,
            'outcome' => 'Taken',
            'timestamp' => now(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent'], 'the near live post still sends the digest');

        $completedIds = $this->lastDailyDigest()->mailDescriptor()['completed'];
        $this->assertNotContains(
            $farTaken->id,
            $completedIds,
            "a came-and-went post beyond the recipient's distance preference must not appear in the digest"
        );
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

        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        // ~22.7 miles from the recipient — outside the poster's 2-mile outbound cap.
        $this->createTestMessage($poster, [
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

        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $this->createTestMessage($poster, [
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

        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        // The recipient's OWN post, far from their resolved location.
        $this->createTestMessage($recipient, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $this->createTestMessage($poster, [
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
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $far = $this->createTestMessage($poster, [
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

    public function test_mail_newly_reached_filters_distance_limited_member_inside_reach(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $memberC = $this->createTestUser();
        DB::table('users')->where('id', $memberC->id)->update(['emailfrequency' => -1]);
        // ~22.7 miles from the post origin — inside the reach polygon below, but
        // beyond memberC's own 2-mile cap.
        $memberC->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $memberC->save();

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: reach distance (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
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

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $memberC = $this->createTestUser();
        DB::table('users')->where('id', $memberC->id)->update(['emailfrequency' => -1]);
        $memberC->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $memberC->save();

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: reach distance widen (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
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

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        // Poster's own resolved location differs from the post's origin (e.g. they
        // posted from work) and is beyond their own tight distance preference.
        $poster->settings = ['browseMaxDistance' => 2, 'mylocation' => ['lat' => 51.5, 'lng' => 0.4]];
        $poster->save();

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: own reach post (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
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

    public function test_daily_digest_eager_loads_externalmods_for_ai_photo_detection(): void
    {
        // The daily-posts PUSH collage prefers a real photo over an AI illustration
        // (PushNotificationService::attachmentIsAi reads attachments.externalmods).
        // getPostsForUser's shared eager-load must carry externalmods through, or in
        // production every photo is silently classed as real. The existing AI-preference
        // tests build fully-loaded models and so never exercised the real eager-load.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $recipient->lastaccess = now();
        $recipient->save();

        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);

        $msg = $this->createTestMessage($poster, [
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
        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
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

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: rural overflow (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('r', 32),
            'primary' => 1, 'archived' => 0,
        ]);

        // The committed reach stops at lng 0.0 - well short of the member at 0.4.
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)
            ->update(['overflow_cells' => $this->overflowCellsDoc([$lane => [$ringKey => $ringWkt]])]);

        return [$member, $msg];
    }

    /** Answer the spatial server's batch deprivation lookup with one fifth per point. */
    private function fakeQuintiles(array $quintiles): void
    {
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintiles' => Http::response(['quintiles' => $quintiles, 'available' => true])]));
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
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintiles' => Http::response(null, 500)]));

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
        Http::fake(array_merge($this->ringIndexStubs(), ['*/v1/quintiles' => Http::response(['quintiles' => [1], 'available' => true])]));

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

    /**
     * A cluster wedge admits on every surface, mail included.
     *
     * A member a wedge lets in sees the post on browse, finds it in search, is not told it has
     * not reached them, and may reply to it. Telling them about it is the same decision, so it
     * is answered the same way. Showing someone a post the mail never mentions is the same
     * split as mailing someone a post the site hides, only facing the other way.
     */
    public function test_mail_newly_reached_mails_a_cluster_admitted_member(): void
    {
        config([
            'freegle.digest.immediate_allowlist' => '*',
            'freegle.ripple.rural_access.enabled' => true,
            'freegle.ripple.fairness.enabled' => true,
            'freegle.ripple.cluster.enabled' => true,
        ]);
        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);

        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $member->settings = [
            'mylocation' => ['lat' => 51.5, 'lng' => 0.4],
            'browseDensityBand' => 'sparse',
        ];
        $member->save();

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: cluster only (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('c', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        // The committed reach stops at lng 0.0 - well short of the member at 0.4 - and the
        // only overflow lane on this post is 'cluster', which covers them geographically.
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)->update([
            'overflow_cells' => $this->overflowCellsDoc(
                ['cluster' => ['w1' => 'POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))']],
                ['bbox' => [-0.2, 51.4, 0.6, 51.6]],
            ),
        ]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertTrue(
            $this->wasMailed($msg->id, $member->id),
            'a member a cluster wedge admits is mailed, exactly as browse and reply admit them'
        );
    }

    public function test_newly_reached_mail_answers_from_the_label_for_a_retired_grid(): void
    {
        // A retired grid (label stored, squares drained): the "you are now in
        // reach" mail evaluates the stored label at every candidate point in
        // one routing call, instead of probing squares that no longer exist.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($member, 51.5, -0.1);

        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: retired reach mail (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)->update([
            'reach_labels' => 'label-bytes', 'polygon_cells' => null,
            'tick' => 1, 'max_drive_min' => 30, 'schedule' => null,
        ]);

        Http::fake(['*reach-arrival*' => Http::response([
            'results' => [['arrival' => 120, 'in' => true]],
        ])]);

        $this->service->mailNewlyReachedForPost($msg->id);

        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $member->id)->exists(),
            'the label admitted the member, so they are mailed and ledgered'
        );
    }

    /**
     * Seed a reach row whose updated_at is $minutesAgo old, for the watermark tests.
     */
    private function seedReachUpdatedAgo(int $minutesAgo): int
    {
        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: mark (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)->update(['updated_at' => now()->subMinutes($minutesAgo)]);

        return $msg->id;
    }

    /**
     * Capture the reach pass's post-selection query.
     *
     * @return array<int, array{sql: string, bindings: array}>
     */
    private function captureReachSelects(callable $fn): array
    {
        $seen = [];
        DB::listen(function ($query) use (&$seen) {
            if (stripos($query->sql, 'rippling_reach') !== false && stripos($query->sql, 'updated_at') !== false && stripos($query->sql, 'select') === 0) {
                $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
        $fn();

        return $seen;
    }

    /**
     * The reach pass used to re-read every post whose reach changed in the last 60 minutes,
     * every minute: 47-68% of db2, ~95% of it re-doing unchanged work. It now resumes from
     * where the previous pass started. A post updated two hours ago, beyond any window, is
     * still picked up when the stored mark is older than it - a stall loses nothing.
     */
    public function test_reach_pass_resumes_from_its_stored_mark_not_a_window(): void
    {
        $mark = now()->subHours(3);
        DB::table('config')->upsert(
            ['key' => UnifiedDigestService::reachMailMarkKey(0), 'value' => $mark->toDateTimeString()],
            ['key'], ['value']
        );
        $msgid = $this->seedReachUpdatedAgo(120);

        $stats = null;
        $selects = $this->captureReachSelects(function () use (&$stats) {
            $stats = $this->service->sendReachDigests(null, true, 0, 1);
        });

        $this->assertNotEmpty($selects, 'expected the post-selection query');
        $bound = array_values(array_filter($selects[0]['bindings'], fn ($b) => is_string($b) && strtotime($b) !== false));
        $this->assertNotEmpty($bound, 'the selection must be bounded by a datetime');
        $this->assertSame($mark->toDateTimeString(), $bound[0], 'the bound is the stored mark');
        $this->assertSame(1, $stats['posts_processed'], 'a post updated after the mark is processed, however old');
    }

    /**
     * With no mark stored the pass must not sweep the whole table. It starts from an hour ago,
     * which is what the old window did, so a cold start costs what today costs and no more.
     */
    public function test_reach_pass_cold_start_reads_only_the_last_hour(): void
    {
        DB::table('config')->where('key', UnifiedDigestService::reachMailMarkKey(0))->delete();
        $old = $this->seedReachUpdatedAgo(180);
        $recent = $this->seedReachUpdatedAgo(30);

        $stats = $this->service->sendReachDigests(null, true, 0, 1);

        $this->assertSame(1, $stats['posts_processed'], 'only the post inside the last hour is read on a cold start');
    }

    /**
     * The mark stored after a pass is the time the pass STARTED, not the newest updated_at it
     * saw. Timestamps are second-granular, so storing max-seen would skip a row that landed in
     * the same second after the read; pass-start guarantees it is >= mark next tick. The
     * overlap this leaves is harmless because the notified ledger dedupes.
     */
    public function test_reach_pass_stores_the_pass_start_as_its_mark(): void
    {
        DB::table('config')->where('key', UnifiedDigestService::reachMailMarkKey(0))->delete();
        $this->seedReachUpdatedAgo(30);

        $before = now()->subSecond();
        $this->service->sendReachDigests(null, false, 0, 1);
        $after = now()->addSecond();

        $stored = DB::table('config')->where('key', UnifiedDigestService::reachMailMarkKey(0))->value('value');
        $this->assertNotNull($stored, 'the pass must store a mark');
        $storedAt = \Carbon\Carbon::parse($stored);
        $this->assertTrue($storedAt->between($before, $after), "mark {$stored} must be the pass start, not the 30-minute-old row");
    }

    /**
     * Shards partition posts by MOD(msgid, N) and run concurrently, so each keeps its own mark.
     */
    public function test_reach_pass_marks_are_per_shard(): void
    {
        $other = now()->subHours(5)->toDateTimeString();
        DB::table('config')->upsert(
            ['key' => UnifiedDigestService::reachMailMarkKey(1), 'value' => $other],
            ['key'], ['value']
        );
        $this->seedReachUpdatedAgo(30);

        $this->service->sendReachDigests(null, false, 0, 2);

        $this->assertSame(
            $other,
            DB::table('config')->where('key', UnifiedDigestService::reachMailMarkKey(1))->value('value'),
            'running shard 0 must not move shard 1 mark'
        );
    }

    /**
     * A dry run previews the pass and must leave the mark alone: advancing it would make the
     * next real pass skip everything the preview showed.
     */
    public function test_reach_pass_dry_run_does_not_advance_the_mark(): void
    {
        $mark = now()->subHours(3)->toDateTimeString();
        DB::table('config')->upsert(
            ['key' => UnifiedDigestService::reachMailMarkKey(0), 'value' => $mark],
            ['key'], ['value']
        );
        $this->seedReachUpdatedAgo(30);

        $this->service->sendReachDigests(null, true, 0, 1);

        $this->assertSame($mark, DB::table('config')->where('key', UnifiedDigestService::reachMailMarkKey(0))->value('value'));
    }

    /**
     * A settled post: approved, attached, reach seeded over a box, and OUTSIDE the post-side
     * pass (updated_at three hours ago, mark one hour ago). Only the member queue can reach it.
     */
    private function seedSettledPostOutsideThePostPass(): int
    {
        config(['freegle.digest.immediate_allowlist' => '*']);
        $poster = $this->createTestUser();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $msg = $this->createTestMessage($poster, ['subject' => 'OFFER: queue drain (TestLocation)']);
        DB::table('messages')->where('id', $msg->id)
            ->update(['collection' => Message::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('b', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        DB::table('rippling_reach')->where('msgid', $msg->id)->update(['updated_at' => now()->subHours(3)]);
        DB::table('config')->upsert(
            ['key' => UnifiedDigestService::reachMailMarkKey(0), 'value' => now()->subHour()->toDateTimeString()],
            ['key'], ['value']
        );

        return $msg->id;
    }

    private function ledgered(int $msgid, int $userid): bool
    {
        return DB::table('rippling_reach_notified')->where('msgid', $msgid)->where('userid', $userid)->exists();
    }

    /**
     * The member side of reach mail. A member who arrives (joins or moves) after a post's reach has
     * settled is queued, and the next pass mails them about the posts that cover
     * them, then drops the queue row. Today they are mailed only if the join happens to land
     * inside 60 minutes of the post's last reach change.
     */
    public function test_queued_member_inside_a_settled_reach_is_mailed_and_dequeued(): void
    {
        $msgid = $this->seedSettledPostOutsideThePostPass();
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($member, 51.5, -0.1);
        ReachMemberQueueService::enqueue($member->id, ReachMemberQueueService::REASON_JOINED);

        $stats = $this->service->sendReachDigests(null, false, 0, 1);

        $this->assertSame(0, $stats['posts_processed'], 'precondition: the post side did not reach this post');
        $this->assertTrue($this->ledgered($msgid, $member->id), 'the queued member is mailed about the post now covering them');
        $this->assertSame(0, DB::table('rippling_reach_member_pending')->where('userid', $member->id)->count(), 'the queue row is consumed');
        $this->assertSame(1, $stats['members_processed']);
    }

    /**
     * A queued member no live reach covers is simply dequeued.
     */
    public function test_queued_member_outside_every_reach_is_dequeued_without_mail(): void
    {
        $msgid = $this->seedSettledPostOutsideThePostPass();
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($member, 55.0, -3.0);
        ReachMemberQueueService::enqueue($member->id, ReachMemberQueueService::REASON_MOVED);

        $this->service->sendReachDigests(null, false, 0, 1);

        $this->assertFalse($this->ledgered($msgid, $member->id));
        $this->assertSame(0, DB::table('rippling_reach_member_pending')->where('userid', $member->id)->count());
    }

    /**
     * The ledger still dedupes: a member mailed in the post-side pass and later queued is not
     * mailed again.
     */
    public function test_drain_does_not_mail_a_member_already_in_the_ledger(): void
    {
        $msgid = $this->seedSettledPostOutsideThePostPass();
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($member, 51.5, -0.1);
        $this->service->mailNewlyReachedForPost($msgid);
        $this->assertTrue($this->ledgered($msgid, $member->id), 'precondition: mailed once by the post side');
        $before = DB::table('rippling_reach_notified')->where('msgid', $msgid)->count();
        ReachMemberQueueService::enqueue($member->id, ReachMemberQueueService::REASON_RETURNED);

        $this->service->sendReachDigests(null, false, 0, 1);

        $this->assertSame($before, DB::table('rippling_reach_notified')->where('msgid', $msgid)->count());
    }

    /**
     * Draining a member must evaluate that member only. Re-enumerating every member of every
     * candidate post per queued member would cost about what the mark saves. Two members sit
     * inside the reach; only the queued one is mailed.
     */
    public function test_drain_evaluates_only_the_queued_member(): void
    {
        $msgid = $this->seedSettledPostOutsideThePostPass();
        $queued = $this->createTestUser();
        DB::table('users')->where('id', $queued->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($queued, 51.5, -0.1);
        $bystander = $this->createTestUser();
        DB::table('users')->where('id', $bystander->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($bystander, 51.45, -0.15);
        ReachMemberQueueService::enqueue($queued->id, ReachMemberQueueService::REASON_FREQUENCY);

        $this->service->sendReachDigests(null, false, 0, 1);

        $this->assertTrue($this->ledgered($msgid, $queued->id));
        $this->assertFalse($this->ledgered($msgid, $bystander->id), 'a member nobody queued is not evaluated by the drain');
    }

    /**
     * Queued members are partitioned across shards by MOD(userid, N) like posts are by msgid,
     * so shards drain disjoint sets and never race on one row.
     */
    public function test_drain_is_partitioned_by_shard(): void
    {
        $msgid = $this->seedSettledPostOutsideThePostPass();
        $member = $this->createTestUser();
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]);
        $this->setMyLocation($member, 51.5, -0.1);
        ReachMemberQueueService::enqueue($member->id, ReachMemberQueueService::REASON_JOINED);
        $mine = (int) ($member->id % 2);
        $other = 1 - $mine;
        DB::table('config')->upsert(
            ['key' => UnifiedDigestService::reachMailMarkKey($other), 'value' => now()->subHour()->toDateTimeString()],
            ['key'], ['value']
        );

        $this->service->sendReachDigests(null, false, $other, 2);
        $this->assertSame(1, DB::table('rippling_reach_member_pending')->where('userid', $member->id)->count(), 'the other shard leaves the row alone');

        $this->service->sendReachDigests(null, false, $mine, 2);
        $this->assertSame(0, DB::table('rippling_reach_member_pending')->where('userid', $member->id)->count());
        $this->assertTrue($this->ledgered($msgid, $member->id));
    }
}
