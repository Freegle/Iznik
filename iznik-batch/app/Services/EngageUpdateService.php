<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EngageUpdateService
{
    // V1: Engage::USER_INACTIVE = 365 * 24 * 60 * 60 / 2
    private const USER_INACTIVE_DAYS = 182;

    // V1: Engage::LOOKBACK = 31
    private const LOOKBACK_DAYS = 31;

    private const RECENT_ACCESS_DAYS = 14;
    private const OCCASIONAL_TO_FREQUENT_POSTS = 3;  // > 3 in 90 days
    private const FREQUENT_TO_OBSESSED_POSTS = 4;    // >= 4 in 31 days
    private const FREQUENT_LOOKBACK_DAYS = 90;
    private const OBSESSED_LOOKBACK_DAYS = 31;

    /**
     * Run a WHERE-driven bulk UPDATE against `users` safely under Galera.
     *
     * Step 1: plucks every matching ID up front (read-only, releases when done).
     * Step 2: updates each row by primary key (narrow PK lock, no gap-lock scan).
     *
     * Matches the per-row pattern used elsewhere in the codebase. Avoids the
     * gap-lock deadlock a full-table-scan UPDATE causes when concurrent
     * writes on `users` overlap with the WHERE column.
     *
     * @param  callable  $applyWhere  receives the query builder, applies WHERE clauses
     * @param  array     $update      column => value to set
     * @return int                    total rows affected
     */
    private function bulkUpdate(callable $applyWhere, array $update): int
    {
        $query = DB::table('users');
        $applyWhere($query);
        $ids = $query->orderBy('id')->pluck('id');

        $total = 0;
        foreach ($ids as $id) {
            DB::table('users')->where('id', $id)->update($update);
            $total++;
        }

        return $total;
    }

    /**
     * Update engagement classifications for all users.
     * Mirrors V1 Engage::updateEngagement().
     *
     * @return int Number of users updated.
     */
    public function updateEngagement(bool $dryRun = false): array
    {
        $stats = [
            'null_to_new' => $this->setNewForRecentNulls($dryRun),
            'null_to_inactive' => $this->setInactiveForRemainingNulls($dryRun),
            'new_or_occasional_to_inactive' => $this->setInactiveForStaleNewOrOccasional($dryRun),
            'to_dormant' => $this->setDormantForLongInactive($dryRun),
            'to_occasional' => $this->setOccasionalForRecentlyActive($dryRun),
            'occasional_to_frequent' => $this->setFrequentForActiveOccasional($dryRun),
            'frequent_to_obsessed' => $this->setObsessedForVeryActiveFrequent($dryRun),
            'obsessed_to_frequent' => $this->setFrequentForDroppedObsessed($dryRun),
        ];
        $stats['total'] = array_sum($stats);

        Log::info('EngageUpdate: ' . ($dryRun ? 'would update ' : 'updated ') . "{$stats['total']} users", $stats);

        return $stats;
    }

    private function setNewForRecentNulls(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateString();

        $where = function ($q) use ($cutoff) {
            $q->whereNull('engagement')->where('added', '>=', $cutoff);
        };

        if ($dryRun) {
            $count = DB::table('users')->where($where)->count();
            Log::info("EngageUpdate: NULL => New: would-{$count}");
            return $count;
        }

        $affected = $this->bulkUpdate($where, ['engagement' => 'New']);
        Log::info("EngageUpdate: NULL => New: {$affected}");
        return $affected;
    }

    private function setInactiveForRemainingNulls(bool $dryRun = false): int
    {
        $where = function ($q) {
            $q->whereNull('engagement');
        };

        if ($dryRun) {
            $count = DB::table('users')->where($where)->count();
            Log::info("EngageUpdate: NULL => Inactive: would-{$count}");
            return $count;
        }

        $affected = $this->bulkUpdate($where, ['engagement' => 'Inactive']);
        Log::info("EngageUpdate: NULL => Inactive: {$affected}");
        return $affected;
    }

    private function setInactiveForStaleNewOrOccasional(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(self::RECENT_ACCESS_DAYS)->startOfDay()->toDateString();

        $where = function ($q) use ($cutoff) {
            $q->whereIn('engagement', ['New', 'Occasional'])
                ->where(function ($qq) use ($cutoff) {
                    $qq->whereNull('lastaccess')
                        ->orWhere('lastaccess', '<', $cutoff);
                });
        };

        if ($dryRun) {
            $count = DB::table('users')->where($where)->count();
            Log::info("EngageUpdate: New/Occasional => Inactive: would-{$count}");
            return $count;
        }

        $affected = $this->bulkUpdate($where, ['engagement' => 'Inactive']);
        Log::info("EngageUpdate: New/Occasional => Inactive: {$affected}");
        return $affected;
    }

    private function setDormantForLongInactive(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(self::USER_INACTIVE_DAYS)->startOfDay()->toDateString();

        $where = function ($q) use ($cutoff) {
            $q->where('engagement', '!=', 'Dormant')
                ->where(function ($qq) use ($cutoff) {
                    $qq->whereNull('lastaccess')
                        ->orWhere('lastaccess', '<', $cutoff);
                });
        };

        if ($dryRun) {
            $count = DB::table('users')->where($where)->count();
            Log::info("EngageUpdate: * => Dormant: would-{$count}");
            return $count;
        }

        $affected = $this->bulkUpdate($where, ['engagement' => 'Dormant']);
        Log::info("EngageUpdate: * => Dormant: {$affected}");
        return $affected;
    }

    private function setOccasionalForRecentlyActive(bool $dryRun = false): int
    {
        $recentCutoff = now()->subDays(self::RECENT_ACCESS_DAYS)->startOfDay()->toDateString();
        $recentTimestamp = now()->subDays(self::RECENT_ACCESS_DAYS)->timestamp;

        // Find candidates: recently accessed + engagement in New/Inactive/Dormant
        $candidates = DB::table('users')
            ->whereIn('engagement', ['New', 'Inactive', 'Dormant'])
            ->where('lastaccess', '>=', $recentCutoff)
            ->pluck('id');

        $updated = 0;
        foreach ($candidates as $userId) {
            $lastActivity = $this->lastPostOrReply($userId);

            if ($lastActivity && strtotime($lastActivity) > $recentTimestamp) {
                if (!$dryRun) {
                    DB::table('users')->where('id', $userId)->update(['engagement' => 'Occasional']);
                }
                $updated++;
            }
        }

        Log::info('EngageUpdate: New/Inactive/Dormant => Occasional: ' . ($dryRun ? "would-{$updated}" : $updated));
        return $updated;
    }

    private function setFrequentForActiveOccasional(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(self::FREQUENT_LOOKBACK_DAYS)->toDateString();

        $occasional = DB::table('users')
            ->where('engagement', 'Occasional')
            ->pluck('id');

        $updated = 0;
        foreach ($occasional as $userId) {
            if ($this->postsSince($userId, $cutoff) > self::OCCASIONAL_TO_FREQUENT_POSTS) {
                if (!$dryRun) {
                    DB::table('users')->where('id', $userId)->update(['engagement' => 'Frequent']);
                }
                $updated++;
            }
        }

        Log::info('EngageUpdate: Occasional => Frequent: ' . ($dryRun ? "would-{$updated}" : $updated));
        return $updated;
    }

    private function setObsessedForVeryActiveFrequent(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(self::OBSESSED_LOOKBACK_DAYS)->toDateString();

        $frequent = DB::table('users')
            ->where('engagement', 'Frequent')
            ->pluck('id');

        $updated = 0;
        foreach ($frequent as $userId) {
            if ($this->postsSince($userId, $cutoff) >= self::FREQUENT_TO_OBSESSED_POSTS) {
                if (!$dryRun) {
                    DB::table('users')->where('id', $userId)->update(['engagement' => 'Obsessed']);
                }
                $updated++;
            }
        }

        Log::info('EngageUpdate: Frequent => Obsessed: ' . ($dryRun ? "would-{$updated}" : $updated));
        return $updated;
    }

    private function setFrequentForDroppedObsessed(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(self::FREQUENT_LOOKBACK_DAYS)->toDateString();

        $obsessed = DB::table('users')
            ->where('engagement', 'Obsessed')
            ->pluck('id');

        $updated = 0;
        foreach ($obsessed as $userId) {
            if ($this->postsSince($userId, $cutoff) <= self::OCCASIONAL_TO_FREQUENT_POSTS) {
                if (!$dryRun) {
                    DB::table('users')->where('id', $userId)->update(['engagement' => 'Frequent']);
                }
                $updated++;
            }
        }

        Log::info('EngageUpdate: Obsessed => Frequent: ' . ($dryRun ? "would-{$updated}" : $updated));
        return $updated;
    }

    private function lastPostOrReply(int $userId): ?string
    {
        $lastChat = DB::table('chat_messages')
            ->where('userid', $userId)
            ->max('date');

        $lastMessage = DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->where('messages.fromuser', $userId)
            ->max('messages_groups.arrival');

        if (!$lastChat && !$lastMessage) {
            return null;
        }

        if (!$lastChat) {
            return $lastMessage;
        }

        if (!$lastMessage) {
            return $lastChat;
        }

        return strtotime($lastChat) > strtotime($lastMessage) ? $lastChat : $lastMessage;
    }

    private function postsSince(int $userId, string $since): int
    {
        return DB::table('messages')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->where('messages.fromuser', $userId)
            ->where('messages_groups.arrival', '>=', $since)
            ->count();
    }
}
