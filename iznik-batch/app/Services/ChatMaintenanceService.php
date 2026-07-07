<?php

namespace App\Services;

use App\Models\ChatRoom;
use App\Models\ChatRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatMaintenanceService
{
    /**
     * Update message counts and latest message dates for recently active chat rooms.
     *
     * Migrated from iznik-server/scripts/cron/chat_latestmessage.php
     */
    public function updateMessageCounts(bool $dryRun = false): array
    {
        $stats = [
            'rooms_updated' => 0,
            'rooms_reopened' => 0,
        ];

        $since = now()->subDays(31)->toDateString();

        // Get distinct chat IDs with messages in last 31 days.
        $chatIds = DB::table('chat_messages')
            ->where('date', '>=', $since)
            ->distinct()
            ->pluck('chatid');

        foreach ($chatIds as $chatId) {
            $this->updateRoomCounts($chatId, $dryRun);
            $stats['rooms_updated']++;

            if ($stats['rooms_updated'] % 1000 === 0) {
                Log::info("Updated {$stats['rooms_updated']} / {$chatIds->count()} chat rooms");
            }
        }

        // Reopen closed User2Mod chats with unseen messages from mods.
        $stats['rooms_reopened'] = $this->reopenClosedUser2ModChats($since, $dryRun);

        return $stats;
    }

    /**
     * Update message valid/invalid counts and latest message date for a single chat room.
     *
     * Replicates ChatRoom::updateMessageCounts() from iznik-server.
     */
    protected function updateRoomCounts(int $chatId, bool $dryRun = false): void
    {
        // Count valid vs invalid messages.
        // Valid = reviewrequired=0 AND reviewrejected=0 AND processingsuccessful=1
        $counts = DB::table('chat_messages')
            ->selectRaw('CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 THEN 1 ELSE 0 END AS valid, COUNT(*) AS count')
            ->where('chatid', $chatId)
            ->groupByRaw('CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 THEN 1 ELSE 0 END')
            ->get();

        $validCount = 0;
        $invalidCount = 0;

        foreach ($counts as $row) {
            if ($row->valid == 1) {
                $validCount = $row->count;
            } else {
                $invalidCount = $row->count;
            }
        }

        // For Mod2Mod chats, don't count invalid messages (could hide the chat).
        $chatType = DB::table('chat_rooms')->where('id', $chatId)->value('chattype');
        if ($chatType === ChatRoom::TYPE_MOD2MOD) {
            $invalidCount = 0;
        }

        $maxDate = DB::table('chat_messages')
            ->where('chatid', $chatId)
            ->max('date');

        if ($maxDate && !$dryRun) {
            DB::table('chat_rooms')
                ->where('id', $chatId)
                ->update([
                    'msgvalid' => $validCount,
                    'msginvalid' => $invalidCount,
                    'latestmessage' => $maxDate,
                ]);
        }
    }

    /**
     * Reopen closed User2Mod chats where the latest message is newer than the roster close date.
     *
     * This ensures messages from moderators are seen by users who closed their chat.
     */
    protected function reopenClosedUser2ModChats(string $since, bool $dryRun = false): int
    {
        $chats = DB::table('chat_rooms')
            ->join('chat_roster', 'chat_roster.chatid', '=', 'chat_rooms.id')
            ->where('chat_rooms.user1', '=', DB::raw('chat_roster.userid'))
            ->where('chat_roster.status', ChatRoster::STATUS_CLOSED)
            ->where('chat_rooms.chattype', ChatRoom::TYPE_USER2MOD)
            ->whereColumn('chat_rooms.latestmessage', '>', 'chat_roster.date')
            ->where('chat_rooms.latestmessage', '>=', $since)
            ->select('chat_rooms.id', 'chat_rooms.user1')
            ->distinct()
            ->get();

        if (!$dryRun) {
            foreach ($chats as $chat) {
                DB::table('chat_roster')
                    ->where('chatid', $chat->id)
                    ->where('userid', $chat->user1)
                    ->update(['status' => ChatRoster::STATUS_AWAY]);
            }
        }

        if ($chats->count() > 0) {
            Log::info("Reopened {$chats->count()} closed User2Mod chats");
        }

        return $chats->count();
    }
}
