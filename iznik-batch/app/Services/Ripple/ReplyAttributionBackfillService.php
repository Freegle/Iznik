<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * Backfill the DURABLE graded-attribution evidence onto legacy rippling_reply_attribution rows
 * (attribution IS NULL - written before migration 2026_07_07_000002 / the Go capture deploy).
 *
 * Durable bits are re-derivable from timestamped tables, so freezing them now - before the
 * evidence decays further - is the whole point:
 *   was_notified            <- messages_notified (notified_at <= replied_at)
 *   was_ripple_group_member <- an approved membership of a group the post rippled into, added
 *                              before the copy arrived (decays when members leave, hence freeze)
 *   post_had_rippled        <- a rippled-in copy or a reach row existed by reply time
 *
 * The LOCATION bits (in_origin_catchment / in_reach) are NOT backfillable - locations drift and
 * reach polygons grow - so they stay NULL and the derived attribution for backfilled rows can
 * only be home / ripple_notified / ripple_group / unknown (never organic_local / ripple_reach).
 * The ladder matches rippling.DeriveAttribution in iznik-server-go (home wins - conservative -
 * then notified, then rippled-group, else unknown).
 *
 * Idempotent: only visits attribution IS NULL rows, and every visited row gets attribution set.
 */
class ReplyAttributionBackfillService
{
    /**
     * Rows still awaiting backfill.
     */
    public function pendingCount(): int
    {
        return (int) DB::table('rippling_reply_attribution')->whereNull('attribution')->count();
    }

    /**
     * Backfill in batches. Returns the number of rows updated.
     *
     * @param int $batchSize rows per UPDATE statement
     * @param int $limit     max rows to process (0 = all)
     * @param callable|null $progress called with the running total after each batch
     */
    public function backfill(int $batchSize = 500, int $limit = 0, ?callable $progress = null): int
    {
        $total = 0;

        while (true) {
            $chunk = $batchSize;
            if ($limit > 0) {
                $chunk = min($chunk, $limit - $total);
                if ($chunk <= 0) {
                    break;
                }
            }

            /*
             * One set-based statement per batch. The evidence EXISTS clauses are repeated in
             * the attribution CASE rather than referencing the just-assigned columns: in a
             * multi-table UPDATE, MySQL does not guarantee assignments see earlier ones.
             */
            $affected = DB::affectingStatement("
                UPDATE rippling_reply_attribution rra
                JOIN (
                    SELECT msgid, userid FROM rippling_reply_attribution
                    WHERE attribution IS NULL
                    ORDER BY msgid, userid
                    LIMIT {$chunk}
                ) batch ON batch.msgid = rra.msgid AND batch.userid = rra.userid
                SET
                    rra.was_notified = EXISTS(
                        SELECT 1 FROM messages_notified rrn
                        WHERE rrn.msgid = rra.msgid AND rrn.userid = rra.userid
                          AND rrn.notified_at <= rra.replied_at AND rrn.channel = 'reach'),
                    rra.was_ripple_group_member = EXISTS(
                        SELECT 1 FROM messages_groups mg
                        INNER JOIN memberships mem ON mem.groupid = mg.groupid
                          AND mem.userid = rra.userid AND mem.collection = 'Approved'
                          AND mem.added < mg.arrival
                        WHERE mg.msgid = rra.msgid AND mg.rippled_in = 1
                          AND mg.deleted = 0 AND mg.arrival <= rra.replied_at),
                    rra.post_had_rippled = (
                        EXISTS(
                            SELECT 1 FROM messages_groups mg2
                            WHERE mg2.msgid = rra.msgid AND mg2.rippled_in = 1
                              AND mg2.deleted = 0 AND mg2.arrival <= rra.replied_at)
                        OR EXISTS(
                            SELECT 1 FROM rippling_reach rr
                            WHERE rr.msgid = rra.msgid
                              AND (rr.created_at IS NULL OR rr.created_at <= rra.replied_at))),
                    rra.attribution = CASE
                        WHEN rra.was_home_member = 1 THEN 'home'
                        WHEN EXISTS(
                            SELECT 1 FROM messages_notified rrn2
                            WHERE rrn2.msgid = rra.msgid AND rrn2.userid = rra.userid
                              AND rrn2.notified_at <= rra.replied_at AND rrn2.channel = 'reach') THEN 'ripple_notified'
                        WHEN EXISTS(
                            SELECT 1 FROM messages_groups mg3
                            INNER JOIN memberships mem3 ON mem3.groupid = mg3.groupid
                              AND mem3.userid = rra.userid AND mem3.collection = 'Approved'
                              AND mem3.added < mg3.arrival
                            WHERE mg3.msgid = rra.msgid AND mg3.rippled_in = 1
                              AND mg3.deleted = 0 AND mg3.arrival <= rra.replied_at) THEN 'ripple_group'
                        ELSE 'unknown'
                    END
            ");

            $total += $affected;

            if ($progress) {
                $progress($total);
            }

            if ($affected < $chunk) {
                break;
            }
        }

        return $total;
    }
}
