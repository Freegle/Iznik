<?php

namespace App\Services\FirstReply;

/**
 * Percentage rollout for the first-reply work, so it can be trialled before it
 * is switched on for everyone.
 *
 * Bucketed by POST, not by member, for two reasons. The outcome being judged is
 * a property of the post - did it get a reply, and how fast - so the post is the
 * right unit to split on. And it means one post is in or out for its whole life
 * and across ALL THREE levers at once: a post in the trial gets the passthrough,
 * scouting and the Freegle chat, and a post outside it gets none of them. Split
 * per lever instead and the arms overlap, so nothing can be attributed to
 * anything.
 *
 * Bucketed by CRC32(msgid . '|firstreply') % 100, NOT by msgid % 100. Message
 * ids are minted under Galera's auto_increment_increment stride (currently 3),
 * and a raw modulus is only uniform while the stride stays coprime with 100 -
 * change the cluster size or increment and the split silently skews or empties.
 * CRC32 spreads uniformly for any modulus (the same choice ExperimentBucket and
 * the digest worker sharding made), and the '|firstreply' salt keeps this
 * split independent of any other experiment bucketed on the same id. The Go
 * passthrough (firstreply/passthrough.go) and the metrics arm split
 * (firstreply/metrics.go) MUST use the identical expression - PHP crc32,
 * MySQL CRC32() and Go crc32.ChecksumIEEE all implement the same polynomial,
 * and a pinned cross-language test holds them together. Still stable - a post
 * never changes arm - but no longer auditable by eye; check a post with
 * SELECT CRC32(CONCAT(msgid, '|firstreply')) % 100.
 *
 * DEFAULTS TO 0. Turning a lever on therefore does nothing until a percentage is
 * set as well. That is deliberate: the failure mode of forgetting the percentage
 * is "nothing happened, and the cron log says why", where the reverse default's
 * failure mode is an unplanned full-network rollout of a feature that sends mail.
 * Every command prints the active percentage on each run so the quiet case is
 * never a mystery.
 */
class Rollout
{
    /** The configured share of posts in the trial, clamped to 0-100. */
    public static function percent(): int
    {
        return max(0, min(100, (int) config('freegle.firstreply.rollout_percent', 0)));
    }

    /** Is this post in the trial? */
    public static function includes(int $msgid): bool
    {
        $percent = self::percent();

        if ($percent >= 100) {
            return true;
        }

        if ($percent <= 0) {
            return false;
        }

        return (crc32($msgid . '|firstreply') % 100) < $percent;
    }

    /**
     * SQL fragment selecting in-trial posts, for the queries that pick candidate
     * posts in bulk. Returns an empty string at 100% so the common case adds
     * nothing to the query, and a never-true condition at 0% so a misconfigured
     * run selects nothing rather than everything.
     *
     * @param string $col the msgid column to bucket on
     */
    /**
     * The same bucketing, applied to a query builder rather than concatenated
     * into a SQL string - so it can be bound and composed.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public static function apply($query, string $col)
    {
        $percent = self::percent();

        if ($percent >= 100) {
            return $query;
        }

        if ($percent <= 0) {
            // An empty whereIn renders as "0 = 1" - a first-class way to say
            // "match nothing".
            return $query->whereIn($col, []);
        }

        // keep-raw: CRC32/CONCAT bucketing over a dynamic column name - the
        // builder has no expression API for this.
        return $query->whereRaw("(CRC32(CONCAT($col, '|firstreply')) % 100) < ?", [$percent]);
    }

    public static function sqlFilter(string $col): string
    {
        $percent = self::percent();

        if ($percent >= 100) {
            return '';
        }

        if ($percent <= 0) {
            return ' AND 1 = 0';
        }

        return " AND (CRC32(CONCAT($col, '|firstreply')) % 100) < " . $percent;
    }

    /** One line for a cron log, so a quiet run explains itself. */
    public static function describe(): string
    {
        $percent = self::percent();

        return $percent <= 0
            ? 'rollout 0% - no posts selected (set FIRSTREPLY_ROLLOUT_PERCENT to trial)'
            : "rollout {$percent}% of posts";
    }
}
