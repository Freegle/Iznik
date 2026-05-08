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

        $msgs = DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->join('users', 'users.id', '=', 'messages.fromuser')
            ->leftJoin('messages_spatial', 'messages_spatial.msgid', '=', 'messages_groups.msgid')
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->where('messages_groups.arrival', '>=', $cutoff)
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
                $q->whereNull('messages_spatial.msgid')
                    ->orWhereRaw('ST_X(messages_spatial.point) != messages.lng')
                    ->orWhereRaw('ST_Y(messages_spatial.point) != messages.lat')
                    ->orWhereNull('messages_spatial.groupid')
                    ->orWhereRaw('messages_spatial.groupid != messages_groups.groupid')
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
        foreach ($msgs as $msg) {
            if ($msg->outcome === Message::OUTCOME_WITHDRAWN || $msg->outcome === Message::OUTCOME_EXPIRED) {
                if (!$dryRun) {
                    DB::table('messages_spatial')->where('id', $msg->id)->delete();
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

        return $count;
    }

    private function removeDeletedMessages(bool $dryRun = false): int
    {
        $ids = DB::table('messages_spatial')
            ->join('messages', 'messages_spatial.msgid', '=', 'messages.id')
            ->leftJoin('users', 'users.id', '=', 'messages.fromuser')
            ->where(function ($q) {
                $q->whereNull('messages.fromuser')
                    ->orWhereNotNull('messages.deleted')
                    ->orWhereNotNull('users.deleted');
            })
            ->pluck('messages_spatial.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $ids)->delete();
        }

        return $ids->count();
    }

    private function removeOldMessages(bool $dryRun = false): int
    {
        $cutoff = date('Y-m-d', strtotime('Midnight ' . self::RECENT_DAYS . ' days ago'));

        $ids = DB::table('messages_spatial')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_spatial.msgid')
            ->where('messages_groups.arrival', '<', $cutoff)
            ->pluck('messages_spatial.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $ids)->delete();
        }

        return $ids->count();
    }

    private function removeNonApprovedMessages(bool $dryRun = false): int
    {
        $ids = DB::table('messages_spatial')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_spatial.msgid')
            ->where('messages_groups.collection', '!=', MessageGroup::COLLECTION_APPROVED)
            ->pluck('messages_spatial.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        if (!$dryRun) {
            DB::table('messages_spatial')->whereIn('id', $ids)->delete();
        }

        return $ids->count();
    }
}
