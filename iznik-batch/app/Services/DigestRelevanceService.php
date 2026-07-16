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
 * byte-identical to today. That holdout, plus the existing click-by-position
 * dashboard (which measures whatever order post_msgids records), is how we tell
 * whether the ranking is worth it.
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

    /**
     * Reorder a member's daily-digest posts by relevance. Each post must expose
     * an `id` (the msgid). Returns a new collection in ranked order, or the
     * input unchanged when ranking is off / holdout / no interest signal.
     */
    public function rank(int $userid, Collection $posts): Collection
    {
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

        $interests = $this->interests($userid);
        if (empty($interests)) {
            return $posts;
        }

        $ids = $posts->map(fn ($p) => (int) $p->id)->all();
        $embeddings = $this->embeddingsFor($ids);
        if (empty($embeddings)) {
            return $posts;
        }

        // Score each post by its best match to any interest vector. Posts with no
        // embedding score below everything so they sink to the bottom (but keep
        // their relative order). A stable sort keeps the existing order as the
        // tiebreak, so relevance only *reorders*, it never invents an order.
        $indexed = $posts->values();
        $scored = $indexed->map(function ($post, $i) use ($embeddings, $interests) {
            $vec = $embeddings[(int) $post->id] ?? null;
            $score = $vec === null ? -INF : $this->maxCosine($vec, $interests);

            return ['post' => $post, 'score' => $score, 'orig' => $i];
        });

        $sorted = $scored->sort(function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['orig'] <=> $b['orig'];
            }

            return $b['score'] <=> $a['score'];
        })->values();

        return $sorted->map(fn ($x) => $x['post'])->values();
    }

    /**
     * The member's interest vectors: subject embeddings of their own posts in the
     * last OWN_POST_DAYS and the posts they viewed in the last VIEW_DAYS, newest
     * first, capped at MAX_INTERESTS. Returns an array of float arrays.
     *
     * @return array<int, array<int, float>>
     */
    public function interests(int $userid): array
    {
        $rows = DB::select(
            'SELECT emb FROM (
                SELECT me.subject_embedding AS emb, m.arrival AS ts
                FROM messages m
                INNER JOIN messages_embeddings me ON me.msgid = m.id
                WHERE m.fromuser = ? AND m.arrival >= DATE_SUB(NOW(), INTERVAL ? DAY)
                UNION ALL
                SELECT me.subject_embedding AS emb, ml.timestamp AS ts
                FROM messages_likes ml
                INNER JOIN messages_embeddings me ON me.msgid = ml.msgid
                WHERE ml.userid = ? AND ml.type = ? AND ml.pageview = 1 AND ml.timestamp >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ) x
            ORDER BY x.ts DESC
            LIMIT ?',
            [$userid, self::OWN_POST_DAYS, $userid, 'View', self::VIEW_DAYS, self::MAX_INTERESTS]
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

        $rows = DB::table('messages_embeddings')
            ->whereIn('msgid', $ids)
            ->select('msgid', 'subject_embedding')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $vec = $this->decode($row->subject_embedding);
            if ($vec !== null) {
                $out[(int) $row->msgid] = $vec;
            }
        }

        return $out;
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
