<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranks the daily digest's live posts by vector similarity to what a member
 * cares about — the subject embeddings of their own recent posts and the posts
 * they've recently viewed — so the most relevant items float to the top.
 *
 * Ships behind config('freegle.digest.relevance_enabled') (default OFF). When
 * off, or for the 10% holdout (userid % 10 == 0), or when a member has no
 * interest signal, rank() returns the input order UNCHANGED, so the digest is
 * byte-identical to the unranked one. That holdout, plus the existing
 * click-by-position dashboard (which measures whatever order post_msgids
 * records), is how we tell whether the ranking is worth it. didRank() reports
 * whether ranking was really applied, so the dashboard's ranked arm can exclude
 * members who were never reranked instead of assuming everyone outside the
 * holdout was.
 *
 * Two things the ranking deliberately leaves alone:
 *  - posts pinned upstream (the two nearest, UnifiedDigestService::pinClosestTwo)
 *    keep the front of the list;
 *  - a post with no embedding holds its position rather than sinking, since the
 *    digest is truncated at DigestStyle::DIGEST_POST_CAP and sinking it would
 *    delete it from a long digest for want of an embedding.
 *
 * Embeddings are the same nomic-embed-text-v1.5 256-dim vectors used for search,
 * stored little-endian in messages_embeddings.subject_embedding and normalized
 * to unit length, so a plain dot product is the cosine similarity.
 */
class DigestRelevanceService
{
    /** Max interest vectors to consider (newest first). Keeps the per-user cost bounded. */
    public const MAX_INTERESTS = 40;

    /** Days of the member's own posts to treat as interest signal. */
    public const OWN_POST_DAYS = 60;

    /** Days of the member's views to treat as interest signal. */
    public const VIEW_DAYS = 30;

    private const DIM = 256;

    /** Entries held by the cross-recipient embedding memo before it is dropped. */
    private const EMBEDDING_CACHE_MAX = 20000;

    /**
     * Decoded subject embeddings keyed by msgid, shared across recipients within a
     * digest run (a null value records "no embedding", so misses aren't re-queried).
     * Static because the service is resolved per recipient.
     *
     * @var array<int, array<int, float>|null>
     */
    private static array $embeddingCache = [];

    /**
     * Whether the last rank() call actually applied relevance ranking, as opposed
     * to returning the input untouched (flag off, holdout, no interest signal,
     * no embeddings, nothing rankable). The digest records this so the click
     * dashboard's "ranked" arm contains only genuinely ranked recipients.
     */
    private bool $ranked = false;

    public function didRank(): bool
    {
        return $this->ranked;
    }

    /**
     * Reorder a member's daily-digest posts by relevance. Each post must expose
     * an `id` (the msgid). Returns a new collection in ranked order, or the
     * input unchanged when ranking is off / holdout / no interest signal.
     *
     * Posts carrying `_pinned` (set by UnifiedDigestService::pinClosestTwo) keep
     * their place at the front: relevance reorders only what sits below the pin.
     */
    public function rank(int $userid, Collection $posts): Collection
    {
        $this->ranked = false;

        if (!config('freegle.digest.relevance_enabled')) {
            return $posts;
        }
        // 10% holdout: always keep the existing order so we have a clean control.
        if ($userid % 10 === 0) {
            return $posts;
        }
        if ($posts->count() < 2) {
            return $posts;
        }

        // The two nearest posts are pinned to the top upstream to answer "I keep
        // seeing posts far away", and that pin deliberately skips posts the
        // recipient has already seen. Re-sorting the whole collection would throw
        // it away for every ranked member, so hold the pinned posts in place and
        // rank only the tail.
        $indexed = $posts->values();
        $pinned = $indexed->filter(fn ($p) => !empty($p->_pinned))->values();
        $rankable = $indexed->reject(fn ($p) => !empty($p->_pinned))->values();

        if ($rankable->count() < 2) {
            return $posts;
        }

        $ids = $rankable->map(fn ($p) => (int) $p->id)->all();

        // Exclude the digest's own posts from the interest set. A post the member
        // viewed within VIEW_DAYS is otherwise one of its own interest vectors, so
        // it self-matches at cosine 1.0 and takes the top slot — resurfacing the
        // very post the digest's seen penalty just pushed down.
        $interests = $this->interests($userid, $indexed->map(fn ($p) => (int) $p->id)->all());
        if (empty($interests)) {
            return $posts;
        }

        $embeddings = $this->embeddingsFor($ids);
        if (empty($embeddings)) {
            return $posts;
        }

        // Score each post the member could care about by its best match to any
        // interest vector.
        //
        // A post with no embedding is of UNKNOWN relevance, not low relevance, so
        // it holds its position rather than sinking. The digest is truncated at
        // DigestStyle::DIGEST_POST_CAP, so sinking unembedded posts would drop
        // them from any digest longer than the cap — silent content deletion
        // driven by embedding coverage rather than by relevance, landing hardest
        // on the newest posts, which are the ones still waiting on the embedding
        // pipeline. Instead the embedded posts are permuted among the slots they
        // already collectively occupied, so which posts survive the cap changes
        // only through relevance.
        $list = $rankable->all();
        $slots = [];
        $scored = [];
        foreach ($list as $i => $post) {
            $vec = $embeddings[(int) $post->id] ?? null;
            if ($vec === null) {
                continue;
            }
            $slots[] = $i;
            $scored[] = ['post' => $post, 'score' => $this->maxCosine($vec, $interests), 'orig' => $i];
        }

        if (count($scored) < 2) {
            return $posts;
        }

        // Descending by score, with the existing order as a stable tiebreak, so
        // relevance only reorders — it never invents an order.
        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['orig'] <=> $b['orig'];
            }

            return $b['score'] <=> $a['score'];
        });

        foreach ($scored as $k => $x) {
            $list[$slots[$k]] = $x['post'];
        }

        $this->ranked = true;

        return $pinned->concat(collect(array_values($list)))->values();
    }

    /**
     * The member's interest vectors: subject embeddings of their own posts in the
     * last OWN_POST_DAYS and the posts they viewed in the last VIEW_DAYS, newest
     * first, capped at MAX_INTERESTS. Returns an array of float arrays.
     *
     * $excludeMsgids drops those posts from the interest signal. rank() passes the
     * digest's own msgids so a post can't be its own interest vector.
     *
     * @param  array<int, int>  $excludeMsgids
     * @return array<int, array<int, float>>
     */
    public function interests(int $userid, array $excludeMsgids = []): array
    {
        $exclude = array_values(array_unique(array_map('intval', $excludeMsgids)));
        $notIn = '';
        if (!empty($exclude)) {
            $notIn = ' AND %s NOT IN ('.implode(',', array_fill(0, count($exclude), '?')).')';
        }

        $params = array_merge(
            [$userid, self::OWN_POST_DAYS],
            $exclude,
            [$userid, 'View', self::VIEW_DAYS],
            $exclude,
            [self::MAX_INTERESTS]
        );

        $rows = DB::select(
            'SELECT emb FROM (
                SELECT me.subject_embedding AS emb, m.arrival AS ts
                FROM messages m
                INNER JOIN messages_embeddings me ON me.msgid = m.id
                WHERE m.fromuser = ? AND m.arrival >= DATE_SUB(NOW(), INTERVAL ? DAY)'
            .($notIn === '' ? '' : sprintf($notIn, 'm.id')).'
                UNION ALL
                SELECT me.subject_embedding AS emb, ml.timestamp AS ts
                FROM messages_likes ml
                INNER JOIN messages_embeddings me ON me.msgid = ml.msgid
                WHERE ml.userid = ? AND ml.type = ? AND ml.pageview = 1 AND ml.timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)'
            .($notIn === '' ? '' : sprintf($notIn, 'ml.msgid')).'
            ) x
            ORDER BY x.ts DESC
            LIMIT ?',
            $params
        );

        $vectors = [];
        foreach ($rows as $row) {
            $vec = $this->decode($row->emb);
            if ($vec !== null) {
                $vectors[] = $vec;
            }
        }

        return $vectors;
    }

    /**
     * Subject embeddings for the given msgids, keyed by msgid.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, float>>
     */
    private function embeddingsFor(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        // Recipients in the same area see largely the same posts, so a digest run
        // asks for the same blobs over and over. Memoise across recipients for the
        // life of the run, and cache the misses too so a post with no embedding
        // isn't re-queried for every recipient it reaches.
        $missing = array_values(array_filter(
            array_map('intval', $ids),
            fn ($id) => !array_key_exists($id, self::$embeddingCache)
        ));

        if (!empty($missing)) {
            if (count(self::$embeddingCache) > self::EMBEDDING_CACHE_MAX) {
                self::$embeddingCache = [];
            }

            $rows = DB::table('messages_embeddings')
                ->whereIn('msgid', $missing)
                ->select('msgid', 'subject_embedding')
                ->get();

            foreach ($missing as $id) {
                self::$embeddingCache[$id] = null;
            }
            foreach ($rows as $row) {
                self::$embeddingCache[(int) $row->msgid] = $this->decode($row->subject_embedding);
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $vec = self::$embeddingCache[(int) $id] ?? null;
            if ($vec !== null) {
                $out[(int) $id] = $vec;
            }
        }

        return $out;
    }

    /**
     * Clear the cross-recipient embedding memo. Called between digest runs (and by
     * tests) so a long-lived worker doesn't serve a stale or unbounded cache.
     */
    public static function flushEmbeddingCache(): void
    {
        self::$embeddingCache = [];
    }

    /**
     * Decode a little-endian float32 embedding BLOB (DIM floats). Returns null
     * for a wrong-sized blob rather than a partial vector.
     *
     * @return array<int, float>|null
     */
    private function decode(?string $blob): ?array
    {
        if ($blob === null || strlen($blob) !== self::DIM * 4) {
            return null;
        }

        // 'g' is a little-endian float — matches Go's binary.LittleEndian +
        // math.Float32bits used to write the blob, and the sidecar's output.
        $vec = array_values(unpack('g'.self::DIM, $blob));

        return $vec;
    }

    /**
     * Best cosine of a vector against any of the interest vectors. Both sides are
     * unit-normalized, so cosine is the dot product.
     *
     * @param  array<int, float>  $vec
     * @param  array<int, array<int, float>>  $interests
     */
    private function maxCosine(array $vec, array $interests): float
    {
        $best = -INF;
        foreach ($interests as $interest) {
            $dot = 0.0;
            for ($i = 0; $i < self::DIM; $i++) {
                $dot += $vec[$i] * $interest[$i];
            }
            if ($dot > $best) {
                $best = $dot;
            }
        }

        return $best;
    }
}
