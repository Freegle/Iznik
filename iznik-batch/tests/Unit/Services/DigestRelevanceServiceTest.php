<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Services\DigestRelevanceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DigestRelevanceServiceTest extends TestCase
{
    private const DIM = 256;

    protected function setUp(): void
    {
        parent::setUp();
        // The embedding memo is shared across recipients within a digest run, so
        // drop it between tests or one test's rows leak into the next.
        DigestRelevanceService::flushEmbeddingCache();
    }

    /**
     * Build a unit vector distinct per seed, matching the test-vector recipe used
     * on the Go side so a round-trip is meaningful.
     *
     * @return array<int, float>
     */
    private function unitVec(float $seed): array
    {
        $v = [];
        $norm = 0.0;
        for ($i = 0; $i < self::DIM; $i++) {
            $v[$i] = $seed + $i * 0.01;
            $norm += $v[$i] * $v[$i];
        }
        $norm = sqrt($norm);
        for ($i = 0; $i < self::DIM; $i++) {
            $v[$i] /= $norm;
        }

        return $v;
    }

    /** Little-endian float32 pack — must match the service's unpack('g'). */
    private function pack(array $vec): string
    {
        return pack('g*', ...$vec);
    }

    private function insertEmbedding(int $msgid, array $vec): void
    {
        DB::table('messages_embeddings')->insert([
            'msgid' => $msgid,
            'subject_embedding' => $this->pack($vec),
            'model_version' => 'test',
        ]);
    }

    /** A tiny fake post object as the digest passes (only ->id is read). */
    private function makePost(int $id): object
    {
        return (object) ['id' => $id];
    }

    public function test_pack_unpack_round_trips_little_endian(): void
    {
        // The blob the service decodes must equal the vector we packed — this is
        // the cross-language contract with the Go writer.
        $vec = $this->unitVec(1.0);
        $blob = $this->pack($vec);
        $this->assertSame(self::DIM * 4, strlen($blob));
        $decoded = array_values(unpack('g'.self::DIM, $blob));
        for ($i = 0; $i < self::DIM; $i++) {
            $this->assertEqualsWithDelta($vec[$i], $decoded[$i], 1e-6);
        }
    }

    public function test_rank_orders_by_relevance_to_interest(): void
    {
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        // Ensure not in the holdout so ranking actually runs.
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('user landed in holdout; rerun');
        }
        $group = $this->createTestGroup();

        // The user's own recent post establishes an interest vector.
        $ownVec = $this->unitVec(2.0);
        $own = $this->createTestMessage($user, $group, ['type' => Message::TYPE_WANTED, 'arrival' => now()]);
        $this->insertEmbedding($own->id, $ownVec);

        // Three candidate posts: one near the interest, two unrelated. The near
        // one must rank first even though it's given LAST in input order.
        $poster = $this->createTestUser();
        $far1 = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $far2 = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $near = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($far1->id, $this->unitVec(9.0));
        $this->insertEmbedding($far2->id, $this->unitVec(8.0));
        $this->insertEmbedding($near->id, $this->unitVec(2.0001)); // cosine ~1 with ownVec

        $service = new DigestRelevanceService;
        $ranked = $service->rank($user->id, collect([$this->makePost($far1->id), $this->makePost($far2->id), $this->makePost($near->id)]));

        $this->assertSame($near->id, $ranked->first()->id, 'the post most similar to the user interest ranks first');
    }

    public function test_flag_off_returns_input_order_unchanged(): void
    {
        config(['freegle.digest.relevance_enabled' => false]);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $own = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));
        $a = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $b = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($a->id, $this->unitVec(9.0));
        $this->insertEmbedding($b->id, $this->unitVec(2.0001));

        $input = collect([$this->makePost($a->id), $this->makePost($b->id)]);
        $ranked = (new DigestRelevanceService)->rank($user->id, $input);
        $this->assertSame([$a->id, $b->id], $ranked->map(fn ($p) => $p->id)->all(), 'flag off → unchanged order');
    }

    public function test_holdout_user_returns_input_order_unchanged(): void
    {
        config(['freegle.digest.relevance_enabled' => true]);

        // A fixed, high id that is a multiple of 10 (so it is always in the
        // holdout) and safely above any auto-increment value in the test DB.
        $holdoutId = 2000000000;
        DB::table('users')->insert(['id' => $holdoutId, 'firstname' => 'Hold', 'lastname' => 'Out', 'fullname' => 'Hold Out']);

        // Give the holdout user an interest vector that WOULD rank $b first if
        // ranking ran — the point of the test is that holdout suppresses it.
        $group = $this->createTestGroup();
        $own = $this->createTestMessage($this->createTestUser(), $group, ['fromuser' => $holdoutId, 'arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));
        $a = $this->createTestMessage($this->createTestUser(), $group, ['arrival' => now()]);
        $b = $this->createTestMessage($this->createTestUser(), $group, ['arrival' => now()]);
        $this->insertEmbedding($a->id, $this->unitVec(9.0));
        $this->insertEmbedding($b->id, $this->unitVec(2.0001)); // most similar to the interest

        $input = collect([$this->makePost($a->id), $this->makePost($b->id)]);
        $ranked = (new DigestRelevanceService)->rank($holdoutId, $input);
        $this->assertSame([$a->id, $b->id], $ranked->map(fn ($p) => $p->id)->all(), 'holdout → unchanged order despite a matching interest');
    }

    public function test_user_with_no_interests_returns_input_order_unchanged(): void
    {
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('holdout');
        }
        $group = $this->createTestGroup();
        // No own posts and no views → no interest signal.
        $a = $this->createTestMessage($this->createTestUser(), $group, ['arrival' => now()]);
        $b = $this->createTestMessage($this->createTestUser(), $group, ['arrival' => now()]);
        $this->insertEmbedding($a->id, $this->unitVec(9.0));
        $this->insertEmbedding($b->id, $this->unitVec(2.0001));

        $input = collect([$this->makePost($a->id), $this->makePost($b->id)]);
        $ranked = (new DigestRelevanceService)->rank($user->id, $input);
        $this->assertSame([$a->id, $b->id], $ranked->map(fn ($p) => $p->id)->all(), 'no interests → unchanged order');
    }

    public function test_candidate_without_an_embedding_holds_its_position(): void
    {
        // A post with no embedding is of UNKNOWN relevance, not low relevance. The
        // digest is truncated at DIGEST_POST_CAP, so sinking it would delete it
        // from any digest longer than the cap for want of an embedding — content
        // loss driven by pipeline coverage rather than by relevance. It must keep
        // its slot while the embedded posts reorder around it.
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('holdout');
        }
        $group = $this->createTestGroup();
        $own = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));

        $poster = $this->createTestUser();
        $dull = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $noEmb = $this->createTestMessage($poster, $group, ['arrival' => now()]); // no embedding row
        $relevant = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($dull->id, $this->unitVec(9.0));
        $this->insertEmbedding($relevant->id, $this->unitVec(2.0001)); // cosine ~1 with own

        $input = collect([
            $this->makePost($dull->id),
            $this->makePost($noEmb->id),
            $this->makePost($relevant->id),
        ]);
        $ranked = (new DigestRelevanceService)->rank($user->id, $input);

        // Slots 0 and 2 held the embedded posts and swap; slot 1 is untouched.
        $this->assertSame(
            [$relevant->id, $noEmb->id, $dull->id],
            $ranked->map(fn ($p) => $p->id)->all(),
            'embedded posts reorder among their own slots; the unembedded post keeps position 1'
        );
    }

    public function test_a_digest_post_does_not_become_its_own_interest_vector(): void
    {
        // A post the member viewed in the last VIEW_DAYS is otherwise one of its
        // own interest vectors: it self-matches at cosine 1.0 and takes the top
        // slot, resurfacing exactly the already-seen post the digest's seen
        // penalty pushed down.
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('holdout');
        }
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();

        // The member's genuine interest, from their own recent post.
        $own = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));

        // A digest candidate matching that interest.
        $wanted = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($wanted->id, $this->unitVec(2.0001));

        // A candidate the member already viewed, unrelated to their interest.
        $seen = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($seen->id, $this->unitVec(9.0));
        DB::table('messages_likes')->insert([
            'msgid' => $seen->id, 'userid' => $user->id, 'type' => 'View',
            'pageview' => 1, 'count' => 1, 'timestamp' => now(),
        ]);

        $input = collect([$this->makePost($seen->id), $this->makePost($wanted->id)]);
        $ranked = (new DigestRelevanceService)->rank($user->id, $input);

        $this->assertSame(
            $wanted->id,
            $ranked->first()->id,
            'the already-viewed candidate must not self-match to the top slot'
        );
    }

    public function test_pinned_posts_keep_the_front_of_the_list(): void
    {
        // The two nearest posts are pinned to the top upstream to answer "I keep
        // seeing posts far away". Re-sorting the whole collection would discard
        // that pin for every ranked member.
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('holdout');
        }
        $group = $this->createTestGroup();
        $own = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));

        $poster = $this->createTestUser();
        $pinned = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $dull = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $relevant = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        // The pinned post is the LEAST relevant, so an unguarded rank would sink it.
        $this->insertEmbedding($pinned->id, $this->unitVec(9.0));
        $this->insertEmbedding($dull->id, $this->unitVec(8.0));
        $this->insertEmbedding($relevant->id, $this->unitVec(2.0001));

        $pinnedPost = $this->makePost($pinned->id);
        $pinnedPost->_pinned = true;

        $input = collect([$pinnedPost, $this->makePost($dull->id), $this->makePost($relevant->id)]);
        $ranked = (new DigestRelevanceService)->rank($user->id, $input);

        $this->assertSame($pinned->id, $ranked->first()->id, 'the pinned post stays at the front');
        $this->assertSame($relevant->id, $ranked->get(1)->id, 'the rest are ranked below the pin');
    }

    public function test_did_rank_reports_whether_ranking_was_applied(): void
    {
        // The dashboard's ranked arm filters on what was recorded. Reporting a
        // member as ranked when rank() bailed would put recipients of an identical
        // unranked digest in the treatment arm and dilute the measured effect.
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('holdout');
        }
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $a = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $b = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($a->id, $this->unitVec(9.0));
        $this->insertEmbedding($b->id, $this->unitVec(8.0));
        $input = collect([$this->makePost($a->id), $this->makePost($b->id)]);

        // No interest signal at all: the digest goes out unranked.
        $noSignal = new DigestRelevanceService;
        $noSignal->rank($user->id, $input);
        $this->assertFalse($noSignal->didRank(), 'a member with no interest signal is not in the ranked arm');

        // Give the member an interest and it does rank.
        $own = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));
        $withSignal = new DigestRelevanceService;
        $withSignal->rank($user->id, $input);
        $this->assertTrue($withSignal->didRank(), 'a genuinely reranked member is in the ranked arm');

        // The holdout is never ranked, whatever signal they have.
        config(['freegle.digest.relevance_enabled' => false]);
        $flagOff = new DigestRelevanceService;
        $flagOff->rank($user->id, $input);
        $this->assertFalse($flagOff->didRank(), 'flag off is never in the ranked arm');
    }

    public function test_embeddings_are_fetched_once_across_recipients(): void
    {
        // interests()/embeddingsFor() run once per digest recipient, and recipients
        // in the same area see largely the same posts. Without a memo the same
        // blobs are re-fetched and re-decoded for every recipient in the run.
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $a = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($a->id, $this->unitVec(5.0));

        DigestRelevanceService::flushEmbeddingCache();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'messages_embeddings')) {
                $queries++;
            }
        });

        $service = new DigestRelevanceService;
        $method = new \ReflectionMethod($service, 'embeddingsFor');
        $method->setAccessible(true);
        $first = $method->invoke($service, [$a->id]);
        $second = $method->invoke(new DigestRelevanceService, [$a->id]);

        $this->assertSame(1, $queries, 'the second recipient reuses the memo rather than re-querying');
        $this->assertEquals($first, $second, 'the memo returns the same decoded vectors');
    }

    public function test_interests_counts_a_genuinely_viewed_post_pageview_1(): void
    {
        // The interest signal includes posts the member actually OPENED - a
        // messages_likes View with pageview=1 - not only their own posts. This is
        // the "recently viewed" half of the UNION, previously untested.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $viewed = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $vec = $this->unitVec(3.0);
        $this->insertEmbedding($viewed->id, $vec);
        DB::table('messages_likes')->insert([
            'msgid' => $viewed->id, 'userid' => $user->id, 'type' => 'View',
            'pageview' => 1, 'count' => 1, 'timestamp' => now(),
        ]);

        $interests = (new DigestRelevanceService)->interests($user->id);

        $this->assertCount(1, $interests, 'a genuinely-viewed post contributes to interests');
        foreach ($vec as $i => $expected) {
            $this->assertEqualsWithDelta($expected, $interests[0][$i], 1e-6);
        }
    }

    public function test_interests_ignores_a_scroll_impression_pageview_0(): void
    {
        // A pageview=0 View row is a mere list-scroll impression (MarkSeen), not a
        // genuine read. It must NOT count as an interest signal, or an active
        // browser's 40-slot budget is swamped by everything that scrolled past.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $scrolled = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($scrolled->id, $this->unitVec(4.0));
        DB::table('messages_likes')->insert([
            'msgid' => $scrolled->id, 'userid' => $user->id, 'type' => 'View',
            'pageview' => 0, 'count' => 1, 'timestamp' => now(),
        ]);

        $interests = (new DigestRelevanceService)->interests($user->id);

        $this->assertCount(0, $interests, 'a scroll-impression (pageview=0) is not an interest signal');
    }
}
