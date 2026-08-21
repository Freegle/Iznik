<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps rippling_reach_overflow in step with rippling_reach.overflow_bounds.
 *
 * The rings themselves stay authoritative in the JSON column. This table holds
 * only their bounding box, as an indexed POLYGON, so the read surfaces can
 * narrow to a few candidate posts before running the exact per-lane test. It
 * exists because the JSON cannot be indexed and rippling_reach cannot be
 * ALTERed without stalling the cluster - see the 2026-08-21 outage.
 *
 * Every writer of overflow_bounds must come through here, or a post's rings
 * become invisible to the read surfaces while looking perfectly fine in the
 * reach row.
 */
class RipplingOverflowIndex
{
    /**
     * Write (or refresh) one post's ring bbox.
     *
     * Silent on failure by design: the index is a prefilter, and losing a row
     * costs one post's ring visibility until the next backfill. It must never
     * take down the expansion that was trying to record a successful ripple.
     */
    public static function upsert(int $msgid, float $minx, float $miny, float $maxx, float $maxy): void
    {
        try {
            DB::statement(
                'INSERT INTO rippling_reach_overflow (msgid, bbox)
                 VALUES (?, ST_SRID(ST_GeomFromText(?), 3857))
                 ON DUPLICATE KEY UPDATE bbox = VALUES(bbox)',
                [$msgid, self::polygonWkt($minx, $miny, $maxx, $maxy)]
            );
        } catch (\Throwable $e) {
            Log::warning('Could not index overflow bbox', ['msgid' => $msgid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Drop a post's row when its rings go away, so a stale box cannot keep
     * offering candidates the exact test will always reject.
     */
    public static function forget(int $msgid): void
    {
        try {
            DB::table('rippling_reach_overflow')->where('msgid', $msgid)->delete();
        } catch (\Throwable $e) {
            Log::warning('Could not remove overflow bbox', ['msgid' => $msgid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Take the bbox straight from the decoded overflow_bounds, so the index can
     * never disagree with the JSON about where the rings are. Returns false when
     * there is nothing indexable, which is the caller's cue to forget the row.
     */
    public static function upsertFromBounds(int $msgid, $bounds): bool
    {
        $decoded = is_string($bounds) ? json_decode($bounds, true) : $bounds;
        $bbox = is_array($decoded) ? ($decoded['bbox'] ?? null) : null;

        if (! is_array($bbox) || count($bbox) < 4) {
            self::forget($msgid);

            return false;
        }

        self::upsert($msgid, (float) $bbox[0], (float) $bbox[1], (float) $bbox[2], (float) $bbox[3]);

        return true;
    }

    /**
     * A closed ring in the order MySQL wants. x is longitude, y latitude, which
     * is the order POINT(lng, lat) is built in everywhere else that touches
     * these geometries.
     */
    private static function polygonWkt(float $minx, float $miny, float $maxx, float $maxy): string
    {
        return sprintf(
            'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
            $minx, $miny, $maxx, $maxy
        );
    }
}
