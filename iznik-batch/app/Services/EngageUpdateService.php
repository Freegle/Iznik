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
     * Update engagement classifications for all users.
     * Mirrors V1 Engage::updateEngagement().
     *
     * @return int Number of users updated.
     */
    public function updateEngagement(): int
    {
        $count = 0;

        $count += $this->setNewForRecentNulls();
        $count += $this->setInactiveForRemainingNulls();
        $count += $this->setInactiveForStaleNewOrOccasional();
        $count += $this->setDormantForLongInactive();
        $count += $this->setOccasionalForRecentlyActive();
        $count += $this->setFrequentForActiveOccasional();
        $count += $this->setObsessedForVeryActiveFrequent();
        $count += $this->setFrequentForDroppedObsessed();

        Log::info("EngageUpdate: updated {$count} users");

        return $count;
    }

    private function setNewForRecentNulls(): int
    {
        $cutoff = now()->subDays(self::LOOKBACK_DAYS)->startOfDay()->toDateString();

        $affected = DB::table('users')
            ->whereNull('engagement')
            ->where('added', '>=', $cutoff)
            ->update(['engagement' => 'New']);

        Log::info("EngageUpdate: NULL => New: {$affected}");
        return $affected;
    }

    private function setInactiveForRemainingNulls(): int
    {
        $affected = DB::table('users')
            ->whereNull('engagement')
            ->update(['engagement' => 'Inactive']);

        Log::info("EngageUpdate: NULL => Inactive: {$affected}");
        return $affected;
    }

    private function setInactiveForStaleNewOrOccasional(): int
    {
        $cutoff = now()->subDays(self::RECENT_ACCESS_DAYS)->startOfDay()->toDateString();

        $affected = DB::table('users')
            ->whereIn('engagement', ['New', 'Occasional'])
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('lastaccess')
                    ->orWhere('lastaccess', '<', $cutoff);
            })
            ->update(['engagement' => 'Inactive']);

        Log::info("EngageUpdate: New/Occasional => Inactive: {$affected}");
        return $affected;
    }

    private function setDormantForLongInactive(): int
    {
        $cutoff = now()->subDays(self::USER_INACTIVE_DAYS)->startOfDay()->toDateString();

        $affected = DB::table('users')
            ->where('engagement', '!=', 'Dormant')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('lastaccess')
                    ->orWhere('lastaccess', '<', $cutoff);
            })
            ->update(['engagement' => 'Dormant']);

        Log::info("EngageUpdate: * => Dormant: {$affected}");
        return $affected;
    }

    private function setOccasionalForRecentlyActive(): int
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
                DB::table('users')->where('id', $userId)->update(['engagement' => 'Occasional']);
                $updated++;
            }
        }

        Log::info("EngageUpdate: New/Inactive/Dormant => Occasional: {$updated}");
        return $updated;
    }

    private function setFrequentForActiveOccasional(): int
    {
        $cutoff = now()->subDays(self::FREQUENT_LOOKBACK_DAYS)->toDateString();

        $occasional = DB::table('users')
            ->where('engagement', 'Occasional')
            ->pluck('id');

        $updated = 0;
        foreach ($occasional as $userId) {
            if ($this->postsSince($userId, $cutoff) > self::OCCASIONAL_TO_FREQUENT_POSTS) {
                DB::table('users')->where('id', $userId)->update(['engagement' => 'Frequent']);
                $updated++;
            }
        }

        Log::info("EngageUpdate: Occasional => Frequent: {$updated}");
        return $updated;
    }

    private function setObsessedForVeryActiveFrequent(): int
    {
        $cutoff = now()->subDays(self::OBSESSED_LOOKBACK_DAYS)->toDateString();

        $frequent = DB::table('users')
            ->where('engagement', 'Frequent')
            ->pluck('id');

        $updated = 0;
        foreach ($frequent as $userId) {
            if ($this->postsSince($userId, $cutoff) >= self::FREQUENT_TO_OBSESSED_POSTS) {
                DB::table('users')->where('id', $userId)->update(['engagement' => 'Obsessed']);
                $updated++;
            }
        }

        Log::info("EngageUpdate: Frequent => Obsessed: {$updated}");
        return $updated;
    }

    private function setFrequentForDroppedObsessed(): int
    {
        $cutoff = now()->subDays(self::FREQUENT_LOOKBACK_DAYS)->toDateString();

        $obsessed = DB::table('users')
            ->where('engagement', 'Obsessed')
            ->pluck('id');

        $updated = 0;
        foreach ($obsessed as $userId) {
            if ($this->postsSince($userId, $cutoff) <= self::OCCASIONAL_TO_FREQUENT_POSTS) {
                DB::table('users')->where('id', $userId)->update(['engagement' => 'Frequent']);
                $updated++;
            }
        }

        Log::info("EngageUpdate: Obsessed => Frequent: {$updated}");
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
