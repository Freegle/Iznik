<?php

namespace App\Services;

use App\Models\Message;
use App\Services\Ripple\ReachBoundsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageSpatialService
{
    // V1: MessageCollection::RECENTPOSTS = "Midnight 31 days ago". Public so other
    // features (e.g. the matched-posts backfill) can bound themselves to the same
    // open-age window that governs messages_spatial membership.
    public const RECENT_DAYS = 31;

    private const SRID = 3857;

    private SpatialAdminService $spatialAdmin;

    /** Prunes/restores rippling sandwich bounds when a post's outcome flips. */
    private ReachBoundsService $reachBounds;

    public function __construct(SpatialAdminService $spatialAdmin, ?ReachBoundsService $reachBounds = null)
    {
        $this->spatialAdmin = $spatialAdmin;
        $this->reachBounds = $reachBounds ?? new ReachBoundsService;
    }

    public function updateSpatialIndex(bool $dryRun = false): array
    {
        $stats = [
            'upserted_recent' => $this->upsertRecentMessages($dryRun),
            'outcomes_updated' => $this->updateOutcomesAndPromises($dryRun),
            'removed_deleted' => $this->removeDeletedMessages($dryRun),
            'removed_old' => $this->removeOldMessages($dryRun),
            'removed_non_approved' => $this->removeNonApprovedMessages($dryRun),
        ];

        $total = array_sum($stats);
        $stats['total'] = $total;

        Log::info('MessageSpatialIndex: '.($dryRun ? 'would update ' : 'updated ')."{$total} entries", $stats);

        return $stats;
    }

    /**
     * Of the posts given, which ones are supposed to be in messages_spatial right now?
     *
     * The index can be missing a post that is perfectly alive: the index job can be down, or
     * die between its delete and add passes. So anything that wants to read "not in the index"
     * as "this post has gone" asks here first, rather than treating an absence as a removal.
     * This shares qualifyingMessages() with upsertRecentMessages so the writer and the reader
     * cannot drift apart. ripple:expand is the caller that matters: it must not read a
     * temporarily-absent post as a retraction.
     *
     * @param  int[]  $msgids
     * @return int[] those that qualify
     */
    public static function stillQualifyForIndex(array $msgids): array
    {
        if (empty($msgids)) {
            return [];
        }

        return self::qualifyingMessages()
            ->whereIn('messages.id', $msgids)
            ->pluck('messages.id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /** The oldest arrival that still belongs in the index: midnight, RECENT_DAYS ago. */
    private static function indexWindowCutoff(): string
    {
        return date('Y-m-d', strtotime('Midnight '.self::RECENT_DAYS.' days ago'));
    }

    /**
     * Base query for "this post belongs in the index": recent arrival, located, live,
     * approved, from a live user, and no disqualifying current outcome. Shared by the add
     * side (upsertRecentMessages, addApprovedMessage) and by stillQualifyForIndex, the check
     * ripple:expand uses before treating an absence as a removal - one predicate, so the two
     * cannot disagree.
     *
     * A post has exactly one row on messages carrying its own moderation state, so this
     * is a plain filtered join - there is no per-community copy to rank or pick between.
     */
    private static function qualifyingMessages(): \Illuminate\Database\Query\Builder
    {
        $cutoff = self::indexWindowCutoff();

        $q = DB::table('messages')
            ->join('users', 'users.id', '=', 'messages.fromuser')
            ->where('messages.arrival', '>=', $cutoff)
            ->whereNotNull('messages.lat')
            ->whereNotNull('messages.lng')
            ->whereNull('messages.deleted')
            ->where('messages.collection', Message::COLLECTION_APPROVED)
            ->whereNull('users.deleted');

        return self::joinLatestOutcome($q, 'messages.id')
            ->where(function ($q) {
                // No outcome, or completed (Taken/Received posts stay in the index). Anything
                // else disqualifies. Only the LATEST outcome row is consulted (see
                // joinLatestOutcome).
                $q->whereNull('messages_outcomes.outcome')
                    ->orWhereIn('messages_outcomes.outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED]);
            });
    }

    /**
     * LEFT JOIN the one messages_outcomes row that states the post's CURRENT outcome.
     *
     * A post's outcome is its latest row. The write paths treat the table that way:
     * reposting deletes previous outcomes, extending a deadline deletes Expired, and
     * Taken/Received/Withdrawn are permanent once current. A few posts still carry
     * both an old row and a newer contradicting one (write paths that skipped the
     * cleanup), and for those the newest row wins. Joining ALL rows instead - as V1
     * and this port originally did - made the passes disagree with each other: the
     * add side saw the Taken row and added the post, the outcome pass saw the Expired
     * row and deleted it, every run, forever.
     */
    private static function joinLatestOutcome($query, string $msgidColumn)
    {
        return $query->leftJoin('messages_outcomes', function ($join) use ($msgidColumn) {
            $join->on('messages_outcomes.msgid', '=', $msgidColumn)
                ->whereNotExists(function ($sub) {
                    $sub->select('newer_outcome.id')
                        ->from('messages_outcomes as newer_outcome')
                        ->whereColumn('newer_outcome.msgid', 'messages_outcomes.msgid')
                        ->whereColumn('newer_outcome.id', '>', 'messages_outcomes.id');
                });
        });
    }

    private function upsertRecentMessages(bool $dryRun = false): int
    {
        $msgs = self::qualifyingMessages()
            ->leftJoin('messages_spatial', 'messages_spatial.msgid', '=', 'messages.id')
            ->where(function ($q) {
                $q->whereNull('messages_spatial.msgid')
                    ->orWhereRaw('ST_X(messages_spatial.point) != messages.lng')
                    ->orWhereRaw('ST_Y(messages_spatial.point) != messages.lat')
                    ->orWhereRaw('messages.arrival != messages_spatial.arrival')
                    // Null-safe: the type is nullable on both sides, so a plain !=
                    // never matches a row that needs correcting.
                    ->orWhereRaw('NOT (messages_spatial.msgtype <=> messages.type)');
            })
            ->select(
                'messages.id',
                'messages.lat',
                'messages.lng',
                'messages.arrival',
                DB::raw('messages.type as msgtype'),
            )
            ->get();

        $count = 0;
        foreach ($msgs as $msg) {
            if (! $dryRun) {
                // Coordinates come from DB, not user input — safe to embed in WKT.
                $wkt = "POINT({$msg->lng} {$msg->lat})";
                $srid = self::SRID;

                DB::statement(
                    "INSERT INTO messages_spatial (msgid, point, msgtype, arrival)
                     VALUES (?, ST_GeomFromText('$wkt', $srid), ?, ?)
                     ON DUPLICATE KEY UPDATE
                       point = ST_GeomFromText('$wkt', $srid),
                       msgtype = ?,
                       arrival = ?",
                    [$msg->id, $msg->msgtype, $msg->arrival,
                        $msg->msgtype, $msg->arrival]
                );
            }
            $count++;
        }

        return $count;
    }

    private function updateOutcomesAndPromises(bool $dryRun = false): int
    {
        // joinLatestOutcome yields at most one outcome row per post, so the remove
        // decision below is made on the post's CURRENT outcome. (V1 ordered this query
        // by outcome timestamp as if the newest row would win, but processed every row,
        // so any old Expired/Withdrawn row deleted a post the add side had just
        // re-added - the two passes fought over the same post every run.)
        $msgs = self::joinLatestOutcome(DB::table('messages_spatial'), 'messages_spatial.msgid')
            ->leftJoin('messages_promises', 'messages_promises.msgid', '=', 'messages_spatial.msgid')
            ->select(
                'messages_spatial.id',
                'messages_spatial.msgid',
                'messages_spatial.successful',
                'messages_spatial.promised',
                'messages_outcomes.outcome',
                'messages_promises.promisedat',
            )
            ->get();

        $count = 0;
        $deletedMsgids = [];
        foreach ($msgs as $msg) {
            if ($msg->outcome === Message::OUTCOME_WITHDRAWN || $msg->outcome === Message::OUTCOME_EXPIRED) {
                if (! $dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->delete();
                    $deletedMsgids[] = $msg->msgid;
                }
                $count++;
            } elseif ($msg->outcome === Message::OUTCOME_TAKEN || $msg->outcome === Message::OUTCOME_RECEIVED) {
                if (! $msg->successful) {
                    if (! $dryRun) {
                        DB::table('messages_spatial')->where('id', $msg->id)->update(['successful' => 1]);
                        // Completed → prune the post from the cheap reach path via its
                        // BOUNDS row only; the exact polygon stays for the consumers that
                        // still need it (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
                        $this->reachBounds->degradeForCompleted((int) $msg->msgid);
                    }
                    $count++;
                }
            } elseif ($msg->successful) {
                if (! $dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['successful' => 0]);
                    // Reopened (outcome removed) → restore working bounds from the stored
                    // polygon, or the post would stay invisible to the cheap reach path.
                    $this->reachBounds->syncFromPolygon((int) $msg->msgid);
                }
                $count++;
            }

            if ($msg->promised && ! $msg->promisedat) {
                if (! $dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['promised' => 0]);
                }
                $count++;
            } elseif (! $msg->promised && $msg->promisedat) {
                if (! $dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['promised' => 1]);
                }
                $count++;
            }
        }

        if (! empty($deletedMsgids)) {
            $this->spatialAdmin->removeItems('messages', $deletedMsgids);
        }

        return $count;
    }

    private function removeDeletedMessages(bool $dryRun = false): int
    {
        $rows = DB::table('messages_spatial')
            ->join('messages', 'messages_spatial.msgid', '=', 'messages.id')
            ->leftJoin('users', 'users.id', '=', 'messages.fromuser')
            ->where(function ($q) {
                $q->whereNull('messages.fromuser')
                    ->orWhereNotNull('messages.deleted')
                    ->orWhereNotNull('users.deleted');
            })
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (! $dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdmin->removeItems('messages', $rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    private function removeOldMessages(bool $dryRun = false): int
    {
        $cutoff = self::indexWindowCutoff();

        // A post is over-age only when it is no longer a live approved message within the
        // window - the same test the add side uses to index it.
        $rows = DB::table('messages_spatial')
            ->whereNotExists(function ($sub) use ($cutoff) {
                $sub->select('messages.id')
                    ->from('messages')
                    ->whereColumn('messages.id', 'messages_spatial.msgid')
                    ->where('messages.arrival', '>=', $cutoff)
                    ->where('messages.collection', Message::COLLECTION_APPROVED)
                    ->whereNull('messages.deleted');
            })
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (! $dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdmin->removeItems('messages', $rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    private function removeNonApprovedMessages(bool $dryRun = false): int
    {
        // Deleted messages are already caught by removeDeletedMessages; this pass exists for
        // a message that moved away from Approved (e.g. re-queued, rejected) without being
        // deleted, which would otherwise sit in browse/search until it aged out.
        $rows = DB::table('messages_spatial')
            ->join('messages', 'messages.id', '=', 'messages_spatial.msgid')
            ->where('messages.collection', '!=', Message::COLLECTION_APPROVED)
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (! $dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdmin->removeItems('messages', $rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    /**
     * Add a single just-approved message to the spatial index immediately, so it
     * appears in browse/search without waiting for the every-5-minute reconciler.
     *
     * Built on the SAME qualifying predicate as the reconciler (qualifyingMessages), so the
     * row it writes is exactly the row the next reconciler run would keep. On top of that
     * shared predicate this path requires NO outcome rows at all, deliberately stricter than
     * the reconciler's latest-outcome rule: it only exists for genuinely fresh approvals, and
     * messages_spatial backs the public browse/map. Safe to call inside the same transaction
     * that set the collection to Approved (it reads its own uncommitted write).
     */
    public function addApprovedMessage(int $msgid): void
    {
        $msg = self::qualifyingMessages()
            ->where('messages.id', $msgid)
            ->whereNull('messages_outcomes.id')
            ->select(
                'messages.id',
                'messages.lat',
                'messages.lng',
                'messages.arrival',
                DB::raw('messages.type as msgtype'),
            )
            ->first();

        if (! $msg) {
            return;
        }

        // Coordinates come from the DB, not user input — safe to embed in WKT.
        $wkt = "POINT({$msg->lng} {$msg->lat})";
        $srid = self::SRID;

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, msgtype, arrival)
             VALUES (?, ST_GeomFromText('$wkt', $srid), ?, ?)
             ON DUPLICATE KEY UPDATE
               point = ST_GeomFromText('$wkt', $srid),
               msgtype = ?,
               arrival = ?",
            [$msg->id, $msg->msgtype, $msg->arrival,
                $msg->msgtype, $msg->arrival]
        );
    }
}
