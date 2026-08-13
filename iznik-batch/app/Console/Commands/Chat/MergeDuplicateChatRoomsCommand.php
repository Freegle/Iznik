<?php

namespace App\Console\Commands\Chat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merge duplicate User2User chat rooms where the same pair of users
 * has two rooms with user1/user2 swapped.
 *
 * This was caused by getOrCreateUserChat() normalizing user order
 * (smaller ID first) but only searching for normalized form, missing
 * old rooms from PHP createConversation() that weren't normalized.
 */
class MergeDuplicateChatRoomsCommand extends Command
{
    protected $signature = 'chat:merge-duplicates
        {--dry-run : Show what would be done without making changes}
        {--user= : Only process duplicates involving this user ID}
        {--limit=0 : Limit number of pairs to process (0 = all)}';

    protected $description = 'Merge duplicate User2User chat rooms with swapped user1/user2';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->info('DRY RUN - no changes will be made');
        }

        $query = DB::table('chat_rooms as cr1')
            ->join('chat_rooms as cr2', function ($join) {
                $join->on('cr1.user1', '=', 'cr2.user2')
                    ->on('cr1.user2', '=', 'cr2.user1')
                    ->on('cr1.chattype', '=', 'cr2.chattype');
            })
            ->where('cr1.chattype', 'User2User')
            ->whereColumn('cr1.id', '<', 'cr2.id');

        if ($userId) {
            $userId = (int) $userId;
            $query->where(function ($q) use ($userId) {
                $q->where('cr1.user1', $userId)
                    ->orWhere('cr1.user2', $userId)
                    ->orWhere('cr2.user1', $userId)
                    ->orWhere('cr2.user2', $userId);
            });
        }

        $oldMsgs = DB::table('chat_messages')->whereColumn('chatid', 'cr1.id');
        $oldMsgs->aggregate = ['function' => 'count', 'columns' => ['*']];
        $newMsgs = DB::table('chat_messages')->whereColumn('chatid', 'cr2.id');
        $newMsgs->aggregate = ['function' => 'count', 'columns' => ['*']];

        $query->select([
            'cr1.id as old_id', 'cr2.id as new_id',
            'cr1.user1 as old_user1', 'cr1.user2 as old_user2',
            'cr2.user1 as new_user1', 'cr2.user2 as new_user2',
            'cr1.created as old_created', 'cr2.created as new_created',
        ])
            ->selectSub($oldMsgs, 'old_msgs')
            ->selectSub($newMsgs, 'new_msgs')
            ->orderBy('cr2.created', 'desc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $pairs = $query->get();

        $this->info("Found " . count($pairs) . " duplicate pair(s)");

        $merged = 0;
        $errors = 0;

        foreach ($pairs as $pair) {
            // Keep the older room (canonical), merge the newer room into it
            $canonicalId = $pair->old_id;
            $duplicateId = $pair->new_id;

            $this->line("");
            $this->info("Merging room $duplicateId → $canonicalId");
            $this->line("  Canonical #{$canonicalId}: users({$pair->old_user1},{$pair->old_user2}), created {$pair->old_created}, {$pair->old_msgs} msgs");
            $this->line("  Duplicate #{$duplicateId}: users({$pair->new_user1},{$pair->new_user2}), created {$pair->new_created}, {$pair->new_msgs} msgs");

            if ($dryRun) {
                $this->line("  [DRY RUN] Would move {$pair->new_msgs} messages, merge roster, insert redirect, delete room");
                $merged++;

                continue;
            }

            try {
                DB::beginTransaction();

                // 1. Move all messages from duplicate to canonical
                $movedMsgs = DB::table('chat_messages')
                    ->where('chatid', $duplicateId)
                    ->update(['chatid' => $canonicalId]);
                $this->line("  Moved $movedMsgs messages");

                // 2. Move roster entries (ignore duplicates - user may be in both rosters)
                $rosterEntries = DB::table('chat_roster')
                    ->select('userid', 'status', 'lastmsgseen', 'lastemailed', 'lastmsgemailed', 'lastip')
                    ->where('chatid', $duplicateId)
                    ->get();

                foreach ($rosterEntries as $entry) {
                    DB::table('chat_roster')->updateOrInsert(
                        ['chatid' => $canonicalId, 'userid' => $entry->userid],
                        [
                            'status' => $entry->status,
                            'lastmsgseen' => $entry->lastmsgseen,
                            'lastemailed' => $entry->lastemailed,
                            'lastmsgemailed' => $entry->lastmsgemailed,
                            'lastip' => $entry->lastip,
                        ]
                    );
                }
                $this->line("  Merged " . count($rosterEntries) . " roster entries");

                // 3. Delete old roster entries for duplicate room
                DB::table('chat_roster')->where('chatid', $duplicateId)->delete();

                // 4. Update latestmessage on canonical room to be the most recent
                // A sub-builder IS expanded into a correlated subquery here. An earlier
                // revision of this comment claimed the opposite, having tested
                // Grammar::compileUpdate() directly - but the Builder->subquery conversion
                // happens in Builder::update(), one layer ABOVE the grammar, so testing the
                // grammar alone shows "set x = ?" and hides the feature. Through the real
                // path this renders:
                //   set latestmessage = (select date from chat_messages
                //                        where chatid = ? order by date desc limit 1)
                // with both ids bound, matching the raw statement. MAX(date) becomes
                // ORDER BY date DESC LIMIT 1 because a sub-builder renders as a plain
                // SELECT; both yield NULL for an empty room.
                DB::table('chat_rooms')->where('id', $canonicalId)->update([
                    'latestmessage' => DB::table('chat_messages')
                        ->where('chatid', $canonicalId)
                        ->orderByDesc('date')
                        ->limit(1)
                        ->select('date'),
                ]);

                // 5. Insert redirect so email replies to old chatid still work
                DB::table('chat_room_redirects')->insertOrIgnore([
                    'old_id' => $duplicateId,
                    'new_id' => $canonicalId,
                ]);

                // 6. Delete the duplicate room
                DB::table('chat_rooms')->where('id', $duplicateId)->delete();

                DB::commit();

                $this->info("  Merged successfully");
                Log::info('Merged duplicate chat room', [
                    'canonical_id' => $canonicalId,
                    'duplicate_id' => $duplicateId,
                    'moved_messages' => $movedMsgs,
                    'moved_roster' => count($rosterEntries),
                ]);

                $merged++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("  FAILED: " . $e->getMessage());
                Log::error('Failed to merge duplicate chat room', [
                    'canonical_id' => $canonicalId,
                    'duplicate_id' => $duplicateId,
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->line("");
        $this->info("Done: $merged merged, $errors errors");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
