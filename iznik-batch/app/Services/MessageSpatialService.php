<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageGroup;
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

    /**
     * Ranking that decides which single membership represents a post in
     * messages_spatial: the post's own (non-rippled) membership first, then the
     * latest arrival, with groupid as a final tie-break so exactly one row always
     * wins (msgid+groupid is unique). Both consumers are GENERATED from this list -
     * the ORDER BY in addApprovedMessage and the "is any membership better"
     * anti-join in upsertRecentMessages - so the immediate-add path and the
     * reconciler cannot disagree about which row represents a post.
     */
    private const REPRESENTATIVE_ORDER = [
        ['rippled_in', 'asc'],
        ['arrival', 'desc'],
        ['groupid', 'asc'],
    ];

    private SpatialAdminService $spatialAdmin;

    /** Prunes/restores rippling sandwich bounds when a post's outcome flips. */
    private ReachBoundsService $reachBounds;

    public function __construct(SpatialAdminService $spatialAdmin, ?ReachBoundsService $reachBounds = null)
    {
        $this->spatialAdmin = $spatialAdmin;
        $this->reachBounds = $reachBounds ?? new ReachBoundsService();
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

        Log::info("MessageSpatialIndex: " . ($dryRun ? 'would update ' : 'updated ') . "{$total} entries", $stats);

        return $stats;
    }

    /**
     * Of the posts given, which ones are supposed to be in messages_spatial right now?
     *
     * The index can be missing a post that is perfectly alive: the index job can be
     * down, or die between its delete and add passes - and historically its age pass
     * deleted ~3,000 still-qualifying posts at the end of every run off their dead
     * memberships' arrivals (removeOldMessages, fixed alongside this check). Reading
     * those absences as "the post has gone" was retracting live posts' rippling
     * reaches by the thousand per day.
     *
     * So anything that wants to read "not in the index" as "this post has gone" asks
     * here first. This shares qualifyingMemberships() with upsertRecentMessages so
     * the writer and the reader cannot drift apart.
     *
     * @param  int[]  $msgids
     * @return int[]  those that qualify
     */
    public static function stillQualifyForIndex(array $msgids): array
    {
        if (empty($msgids)) {
            return [];
        }

        return self::qualifyingMemberships()
            ->whereIn('messages.id', $msgids)
            ->distinct()
            ->pluck('messages.id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Base query for "this post belongs in the index via this membership": recent
     * arrival, located, live post on a live approved membership from a live user, and
     * no disqualifying current outcome. Shared by the add side (upsertRecentMessages)
     * and by stillQualifyForIndex, the check ripple:expand uses before treating an
     * absence as a removal - one predicate, so the two cannot disagree.
     */
    /** The oldest arrival that still belongs in the index: midnight, RECENT_DAYS ago. */
    private static function indexWindowCutoff(): string
    {
        return date('Y-m-d', strtotime('Midnight ' . self::RECENT_DAYS . ' days ago'));
    }

    private static function qualifyingMemberships(): \Illuminate\Database\Query\Builder
    {
        $cutoff = self::indexWindowCutoff();

        $q = DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('users', 'users.id', '=', 'messages.fromuser')
            ->where('messages_groups.arrival', '>=', $cutoff)
            ->whereNotNull('messages.lat')
            ->whereNotNull('messages.lng')
            ->whereNull('messages.deleted')
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            // The membership itself must be live. Rippling's "removed on origin removal" (and
            // group-leave retraction) sets messages_groups.deleted=1 while leaving
            // collection=Approved and messages.deleted NULL, so without this a removed copy with
            // a recent arrival is indexed straight back into browse. (Whatever set that arrival —
            // e.g. autorepost — should not have touched a dead membership either; see
            // AutoRepostService::getCandidates.)
            ->where('messages_groups.deleted', 0)
            ->whereNull('users.deleted');

        return self::joinLatestOutcome($q, 'messages.id')
            ->where(function ($q) {
                // No outcome, or completed (Taken/Received posts stay in the index). Anything
                // else disqualifies. Same value set as V1; what changed is that only the
                // LATEST outcome row is consulted (see joinLatestOutcome).
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
        $cutoff = self::indexWindowCutoff();

        $msgs = self::qualifyingMemberships()
            // ONE membership represents the post: messages_spatial holds a single row per
            // msgid, and everything downstream reads its groupid as the post's own
            // community. Without this, EVERY qualifying membership whose groupid or
            // arrival differed from the stored row was selected, and the loop below
            // rewrote the same row once per membership, last write winning - a rippled
            // post's recorded community and arrival ping-ponged between its memberships
            // forever, ~182K row rewrites per run for a ~56K-row table. The winner is
            // the membership no qualifying sibling beats under REPRESENTATIVE_ORDER:
            // "better" means strictly ahead on one ranking column while tied on every
            // column before it.
            ->whereNotExists(function ($sub) use ($cutoff) {
                $sub->select('better.msgid')
                    ->from('messages_groups as better')
                    ->whereColumn('better.msgid', 'messages_groups.msgid')
                    ->where('better.arrival', '>=', $cutoff)
                    ->where('better.collection', MessageGroup::COLLECTION_APPROVED)
                    ->where('better.deleted', 0)
                    ->where(function ($q) {
                        $tied = [];
                        foreach (self::REPRESENTATIVE_ORDER as [$col, $dir]) {
                            $q->orWhere(function ($q2) use ($tied, $col, $dir) {
                                foreach ($tied as $t) {
                                    $q2->whereColumn("better.$t", '=', "messages_groups.$t");
                                }
                                $q2->whereColumn("better.$col", $dir === 'asc' ? '<' : '>', "messages_groups.$col");
                            });
                            $tied[] = $col;
                        }
                    });
            })
            ->leftJoin('messages_spatial', 'messages_spatial.msgid', '=', 'messages_groups.msgid')
            ->where(function ($q) {
                $q->whereNull('messages_spatial.msgid')
                    ->orWhereRaw('ST_X(messages_spatial.point) != messages.lng')
                    ->orWhereRaw('ST_Y(messages_spatial.point) != messages.lat')
                    ->orWhereNull('messages_spatial.groupid')
                    ->orWhereRaw('messages_spatial.groupid != messages_groups.groupid')
                    ->orWhereRaw('messages_groups.arrival != messages_spatial.arrival')
                    // Null-safe: the type is nullable on both sides, so a plain !=
                    // never matches a row that needs correcting.
                    ->orWhereRaw('NOT (messages_spatial.msgtype <=> messages.type)');
            })
            ->select(
                'messages.id',
                'messages.lat',
                'messages.lng',
                'messages_groups.groupid',
                'messages_groups.arrival',
                // messages.type, not the denormalised messages_groups.msgtype: the
                // latter is NULL on the origin membership of a web-posted message,
                // and this row is what browse, search and the sitemap filter on.
                DB::raw('messages.type as msgtype'),
            )
            ->distinct()
            ->get();

        $count = 0;
        foreach ($msgs as $msg) {
            if (!$dryRun) {
                // Coordinates come from DB, not user input — safe to embed in WKT.
                $wkt = "POINT({$msg->lng} {$msg->lat})";
                $srid = self::SRID;

                DB::statement(
                    "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                     VALUES (?, ST_GeomFromText('$wkt', $srid), ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       point = ST_GeomFromText('$wkt', $srid),
                       groupid = ?,
                       msgtype = ?,
                       arrival = ?",
                    [$msg->id, $msg->groupid, $msg->msgtype, $msg->arrival,
                     $msg->groupid, $msg->msgtype, $msg->arrival]
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
                if (!$dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->delete();
                    $deletedMsgids[] = $msg->msgid;
                }
                $count++;
            } elseif ($msg->outcome === Message::OUTCOME_TAKEN || $msg->outcome === Message::OUTCOME_RECEIVED) {
                if (!$msg->successful) {
                    if (!$dryRun) {
                        DB::table('messages_spatial')->where('id', $msg->id)->update(['successful' => 1]);
                        // Completed → prune the post from the cheap reach path via its
                        // BOUNDS row only; the exact polygon stays for the consumers that
                        // still need it (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
                        $this->reachBounds->degradeForCompleted((int) $msg->msgid);
                    }
                    $count++;
                }
            } elseif ($msg->successful) {
                if (!$dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['successful' => 0]);
                    // Reopened (outcome removed) → restore working bounds from the stored
                    // polygon, or the post would stay invisible to the cheap reach path.
                    $this->reachBounds->syncFromPolygon((int) $msg->msgid);
                }
                $count++;
            }

            if ($msg->promised && !$msg->promisedat) {
                if (!$dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['promised' => 0]);
                }
                $count++;
            } elseif (!$msg->promised && $msg->promisedat) {
                if (!$dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['promised' => 1]);
                }
                $count++;
            }
        }

        if (!empty($deletedMsgids)) {
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

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdmin->removeItems('messages', $rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    private function removeOldMessages(bool $dryRun = false): int
    {
        $cutoff = self::indexWindowCutoff();

        // A post is over-age only when NO live approved membership is within the
        // window - the same memberships the add side would index it from. Only live
        // rows count on both sides of the decision: a dead membership can neither
        // age a post out nor keep it in.
        //
        // This pass originally joined ALL memberships and deleted on ANY stale one
        // (as V1 did), which tripped over dead rows - the tombstones a retracted
        // rippled-in copy leaves behind (deleted=1). An old post revived by a repost
        // has a fresh live membership too, so ~3,000 such posts were deleted at the
        // end of every run off their tombstones' arrivals and re-added by the next
        // run's upsert: out of browse for minutes of every cycle, completed flags
        // wiped and rewritten, and (before stillQualifyForIndex) thousands of
        // spurious reach retractions and re-initialisations a day.
        $rows = DB::table('messages_spatial')
            ->whereNotExists(function ($sub) use ($cutoff) {
                $sub->select('messages_groups.msgid')
                    ->from('messages_groups')
                    ->whereColumn('messages_groups.msgid', 'messages_spatial.msgid')
                    ->where('messages_groups.arrival', '>=', $cutoff)
                    ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
                    ->where('messages_groups.deleted', 0);
            })
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdmin->removeItems('messages', $rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    private function removeNonApprovedMessages(bool $dryRun = false): int
    {
        // Join on BOTH msgid AND groupid: messages_spatial holds one row per post (unique
        // msgid) for a specific group, so a spatial row must only be dropped when the
        // messages_groups row for ITS OWN group is non-approved. Joining on msgid alone
        // would let a rippled-in Pending row on another group (#6) delete the origin post's
        // approved spatial row, flickering it out of browse every spatial-index run.
        //
        // Also drop it when its own membership is soft-deleted (deleted=1): rippling's "removed
        // on origin removal" leaves collection=Approved but sets deleted=1, so the collection
        // check alone would leave a removed copy in browse until it ages out. upsertRecentMessages
        // runs before this pass, so a message still live on ANOTHER group has already had its
        // spatial row re-pointed to that group and is not caught here.
        $rows = DB::table('messages_spatial')
            ->join('messages_groups', function ($join) {
                $join->on('messages_groups.msgid', '=', 'messages_spatial.msgid')
                    ->on('messages_groups.groupid', '=', 'messages_spatial.groupid');
            })
            ->where(function ($q) {
                $q->where('messages_groups.collection', '!=', MessageGroup::COLLECTION_APPROVED)
                    ->orWhere('messages_groups.deleted', 1);
            })
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdmin->removeItems('messages', $rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    /**
     * Add a single just-approved message to the spatial index immediately, so it
     * appears in browse/search without waiting for the every-5-minute reconciler.
     *
     * Built on the SAME qualifying predicate as the reconciler
     * (qualifyingMemberships) ranked by the same REPRESENTATIVE_ORDER, so the row
     * it writes is exactly the row the next reconciler run would keep - a
     * hand-rolled variant here once picked an out-of-window origin membership the
     * reconciler would never consider, putting a stale row in browse for the five
     * minutes until the reconciler corrected it. On top of the shared predicate
     * this path requires NO outcome rows at all, deliberately stricter than the
     * reconciler's latest-outcome rule: it only exists for genuinely fresh
     * approvals, and messages_spatial backs the public browse/map. Safe to call
     * inside the same transaction that set the collection to Approved (it reads
     * its own uncommitted write).
     */
    public function addApprovedMessage(int $msgid): void
    {
        $query = self::qualifyingMemberships()
            ->where('messages.id', $msgid)
            ->whereNull('messages_outcomes.id')
            ->select(
                'messages.id',
                'messages.lat',
                'messages.lng',
                DB::raw('messages.type as msgtype'),
                'messages_groups.groupid',
                'messages_groups.arrival',
            );

        foreach (self::REPRESENTATIVE_ORDER as [$col, $dir]) {
            $query->orderBy("messages_groups.$col", $dir);
        }

        $msg = $query->first();

        if (!$msg) {
            return;
        }

        // Coordinates come from the DB, not user input — safe to embed in WKT.
        $wkt  = "POINT({$msg->lng} {$msg->lat})";
        $srid = self::SRID;

        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText('$wkt', $srid), ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               point = ST_GeomFromText('$wkt', $srid),
               groupid = ?,
               msgtype = ?,
               arrival = ?",
            [$msg->id, $msg->groupid, $msg->msgtype, $msg->arrival,
             $msg->groupid, $msg->msgtype, $msg->arrival]
        );
    }
}
