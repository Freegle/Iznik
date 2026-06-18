<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageSpatialService
{
    // V1: MessageCollection::RECENTPOSTS = "Midnight 31 days ago"
    private const RECENT_DAYS = 31;
    private const SRID = 3857;

    private SpatialAdminService $spatialAdmin;

    public function __construct(SpatialAdminService $spatialAdmin)
    {
        $this->spatialAdmin = $spatialAdmin;
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

    private function upsertRecentMessages(bool $dryRun = false): int
    {
        $cutoff = date('Y-m-d', strtotime('Midnight ' . self::RECENT_DAYS . ' days ago'));

        // messages_spatial is keyed on (msgid, groupid): a cross-posted message has
        // one row per group it is approved on. Match the spatial row on BOTH msgid
        // and groupid so a missing per-group row is detected and inserted (and so we
        // never flip-flop a single row's groupid between the message's groups).
        $msgs = DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('users', 'users.id', '=', 'messages.fromuser')
            ->leftJoin('messages_spatial', function ($join) {
                $join->on('messages_spatial.msgid', '=', 'messages_groups.msgid')
                    ->on('messages_spatial.groupid', '=', 'messages_groups.groupid');
            })
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->where('messages_groups.arrival', '>=', $cutoff)
            ->where('messages_groups.deleted', 0)
            ->whereNotNull('messages.lat')
            ->whereNotNull('messages.lng')
            ->whereNull('messages.deleted')
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->whereNull('users.deleted')
            ->where(function ($q) {
                // Include messages with no outcome, or Taken/Received (same as V1: outcome IS NULL OR outcome IN ('Taken','Received'))
                $q->whereNull('messages_outcomes.outcome')
                    ->orWhereIn('messages_outcomes.outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED]);
            })
            ->where(function ($q) {
                // Insert/refresh when the per-group spatial row is missing, the
                // location moved, or this group's arrival changed.
                $q->whereNull('messages_spatial.msgid')
                    ->orWhereRaw('ST_X(messages_spatial.point) != messages.lng')
                    ->orWhereRaw('ST_Y(messages_spatial.point) != messages.lat')
                    ->orWhereRaw('messages_groups.arrival != messages_spatial.arrival');
            })
            ->select(
                'messages.id',
                'messages.lat',
                'messages.lng',
                'messages_groups.groupid',
                'messages_groups.arrival',
                'messages_groups.msgtype',
            )
            ->distinct()
            ->get();

        $count = 0;
        foreach ($msgs as $msg) {
            if (!$dryRun) {
                // Coordinates come from DB, not user input — safe to embed in WKT.
                $wkt = "POINT({$msg->lng} {$msg->lat})";
                $srid = self::SRID;

                // groupid is part of the unique key — never updated on conflict.
                DB::statement(
                    "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                     VALUES (?, ST_GeomFromText('$wkt', $srid), ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       point = ST_GeomFromText('$wkt', $srid),
                       msgtype = ?,
                       arrival = ?",
                    [$msg->id, $msg->groupid, $msg->msgtype, $msg->arrival,
                     $msg->msgtype, $msg->arrival]
                );
            }
            $count++;
        }

        return $count;
    }

    private function updateOutcomesAndPromises(bool $dryRun = false): int
    {
        // Outcomes and promises are global (per message), so every per-group spatial
        // row for a msgid carries the same successful/promised flags. We iterate all
        // rows; a withdrawn/expired outcome removes the item from every group.
        $msgs = DB::table('messages_spatial')
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages_spatial.msgid')
            ->leftJoin('messages_promises', 'messages_promises.msgid', '=', 'messages_spatial.msgid')
            ->select(
                'messages_spatial.id',
                'messages_spatial.msgid',
                'messages_spatial.successful',
                'messages_spatial.promised',
                'messages_outcomes.outcome',
                'messages_promises.promisedat',
            )
            ->orderByDesc('messages_outcomes.timestamp')
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
                    }
                    $count++;
                }
            } elseif ($msg->successful) {
                if (!$dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->update(['successful' => 0]);
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

        $this->spatialAdminRemoveIfGone($deletedMsgids);

        return $count;
    }

    private function removeDeletedMessages(bool $dryRun = false): int
    {
        // Message deleted / user deleted is a whole-message condition — remove every
        // per-group spatial row for the msgid.
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
            $this->spatialAdminRemoveIfGone($rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    private function removeOldMessages(bool $dryRun = false): int
    {
        $cutoff = date('Y-m-d', strtotime('Midnight ' . self::RECENT_DAYS . ' days ago'));

        // Per group: a spatial row ages out on its OWN group's arrival, so match the
        // spatial row to its specific messages_groups row.
        $rows = DB::table('messages_spatial')
            ->join('messages_groups', function ($join) {
                $join->on('messages_groups.msgid', '=', 'messages_spatial.msgid')
                    ->on('messages_groups.groupid', '=', 'messages_spatial.groupid');
            })
            ->where('messages_groups.arrival', '<', $cutoff)
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdminRemoveIfGone($rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    private function removeNonApprovedMessages(bool $dryRun = false): int
    {
        // Per group: remove a spatial row when its own messages_groups row is gone
        // (per-group delete), soft-deleted, or no longer Approved (held/rejected/
        // spam on that group). Rows for groups where it is still Approved survive.
        $rows = DB::table('messages_spatial')
            ->leftJoin('messages_groups', function ($join) {
                $join->on('messages_groups.msgid', '=', 'messages_spatial.msgid')
                    ->on('messages_groups.groupid', '=', 'messages_spatial.groupid');
            })
            ->where(function ($q) {
                $q->whereNull('messages_groups.msgid')
                    ->orWhere('messages_groups.deleted', '!=', 0)
                    ->orWhere('messages_groups.collection', '!=', MessageGroup::COLLECTION_APPROVED);
            })
            ->select('messages_spatial.id', 'messages_spatial.msgid')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $rows->pluck('id'))->delete();
            $this->spatialAdminRemoveIfGone($rows->pluck('msgid')->all());
        }

        return $rows->count();
    }

    /**
     * Add a single just-approved message to the spatial index immediately, so it
     * appears in browse/search without waiting for the every-5-minute reconciler.
     *
     * No-op unless the message is Approved, has a location, and has no outcome —
     * messages_spatial backs the public browse/map, so Pending/Spam/Rejected
     * messages must never be added here. Safe to call inside the same transaction
     * that set the collection to Approved (it reads its own uncommitted write).
     *
     * Inserts one row per group the message is approved on (the table is keyed on
     * (msgid, groupid)), so a cross-post shows in browse/search on each of them.
     */
    public function addApprovedMessage(int $msgid): void
    {
        $msgs = DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->where('messages.id', $msgid)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereNotNull('messages.lat')
            ->whereNotNull('messages.lng')
            ->whereNull('messages_outcomes.id')
            ->select(
                'messages.id',
                'messages.lat',
                'messages.lng',
                DB::raw('messages.type as msgtype'),
                'messages_groups.groupid',
                'messages_groups.arrival',
            )
            ->get();

        foreach ($msgs as $msg) {
            // Coordinates come from the DB, not user input — safe to embed in WKT.
            $wkt  = "POINT({$msg->lng} {$msg->lat})";
            $srid = self::SRID;

            // groupid is part of the unique key — never updated on conflict.
            DB::statement(
                "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
                 VALUES (?, ST_GeomFromText('$wkt', $srid), ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   point = ST_GeomFromText('$wkt', $srid),
                   msgtype = ?,
                   arrival = ?",
                [$msg->id, $msg->groupid, $msg->msgtype, $msg->arrival,
                 $msg->msgtype, $msg->arrival]
            );
        }
    }

    /**
     * Tell the external spatial server to drop a message only once it has NO
     * per-group rows left. The external index is keyed by msgid (one location per
     * message), so a per-group removal must not evict a message that is still live
     * on another group.
     *
     * @param  array<int,int>  $msgids
     */
    private function spatialAdminRemoveIfGone(array $msgids): void
    {
        $msgids = array_values(array_unique(array_filter($msgids)));
        if (empty($msgids)) {
            return;
        }

        $remaining = DB::table('messages_spatial')
            ->whereIn('msgid', $msgids)
            ->pluck('msgid')
            ->all();

        $gone = array_values(array_diff($msgids, $remaining));
        if (!empty($gone)) {
            $this->spatialAdmin->removeItems('messages', $gone);
        }
    }
}
