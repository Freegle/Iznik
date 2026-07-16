<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Services\DigestRelevanceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DigestRelevanceServiceTest extends TestCase
{
    private const DIM = 256;

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

    public function test_candidates_without_embeddings_sink_below_embedded(): void
    {
        config(['freegle.digest.relevance_enabled' => true]);

        $user = $this->createTestUser();
        if ($user->id % 10 === 0) {
            $this->markTestSkipped('holdout');
        }
        $group = $this->createTestGroup();
        $own = $this->createTestMessage($user, $group, ['arrival' => now()]);
        $this->insertEmbedding($own->id, $this->unitVec(2.0));

        $poster = $this->createTestUser();
        $embedded = $this->createTestMessage($poster, $group, ['arrival' => now()]);
        $this->insertEmbedding($embedded->id, $this->unitVec(2.0001)); // relevant
        $noEmb = $this->createTestMessage($poster, $group, ['arrival' => now()]); // no embedding row

        $input = collect([$this->makePost($noEmb->id), $this->makePost($embedded->id)]);
        $ranked = (new DigestRelevanceService)->rank($user->id, $input);
        $this->assertSame($embedded->id, $ranked->first()->id, 'embedded relevant post outranks the unembedded one');
        $this->assertSame($noEmb->id, $ranked->last()->id, 'unembedded post sinks to the bottom');
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
