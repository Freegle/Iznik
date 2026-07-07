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

        $query = "
            SELECT cr1.id as old_id, cr2.id as new_id,
                   cr1.user1 as old_user1, cr1.user2 as old_user2,
                   cr2.user1 as new_user1, cr2.user2 as new_user2,
                   cr1.created as old_created, cr2.created as new_created,
                   (SELECT COUNT(*) FROM chat_messages WHERE chatid = cr1.id) as old_msgs,
                   (SELECT COUNT(*) FROM chat_messages WHERE chatid = cr2.id) as new_msgs
            FROM chat_rooms cr1
            JOIN chat_rooms cr2 ON cr1.user1 = cr2.user2
                AND cr1.user2 = cr2.user1
                AND cr1.chattype = cr2.chattype
            WHERE cr1.chattype = 'User2User'
              AND cr1.id < cr2.id
        ";

        $params = [];

        if ($userId) {
            $query .= " AND (cr1.user1 = ? OR cr1.user2 = ? OR cr2.user1 = ? OR cr2.user2 = ?)";
            $params = [(int) $userId, (int) $userId, (int) $userId, (int) $userId];
        }

        $query .= " ORDER BY cr2.created DESC";

        if ($limit > 0) {
            $query .= " LIMIT $limit";
        }

        $pairs = DB::select($query, $params);

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
                $movedMsgs = DB::update(
                    'UPDATE chat_messages SET chatid = ? WHERE chatid = ?',
                    [$canonicalId, $duplicateId]
                );
                $this->line("  Moved $movedMsgs messages");

                // 2. Move roster entries (ignore duplicates - user may be in both rosters)
                $rosterEntries = DB::select(
                    'SELECT userid, status, lastmsgseen, lastemailed, lastmsgemailed, lastip FROM chat_roster WHERE chatid = ?',
                    [$duplicateId]
                );

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
                DB::delete('DELETE FROM chat_roster WHERE chatid = ?', [$duplicateId]);

                // 4. Update latestmessage on canonical room to be the most recent
                DB::update(
                    'UPDATE chat_rooms SET latestmessage = (SELECT MAX(date) FROM chat_messages WHERE chatid = ?) WHERE id = ?',
                    [$canonicalId, $canonicalId]
                );

                // 5. Insert redirect so email replies to old chatid still work
                DB::table('chat_room_redirects')->insertOrIgnore([
                    'old_id' => $duplicateId,
                    'new_id' => $canonicalId,
                ]);

                // 6. Delete the duplicate room
                DB::delete('DELETE FROM chat_rooms WHERE id = ?', [$duplicateId]);

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
