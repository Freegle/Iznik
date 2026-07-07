<?php

namespace App\Console\Commands\Chat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finds User2Mod chat rooms where user1 (the member) is missing from
 * the chat_roster and inserts the missing entries. Also ensures group
 * mods are present in the roster.
 *
 * Safe to run repeatedly — uses INSERT IGNORE so existing rows are untouched.
 */
class RepairRosterCommand extends Command
{
    protected $signature = 'chat:repair-roster
        {--days=90 : Repair roster for chats with activity in the last N days}
        {--notify-days=3 : Only allow notifications for chats active in the last N days (older ones are marked as already emailed)}
        {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'Add missing member and mod entries to User2Mod chat rosters';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $notifyDays = (int) $this->option('notify-days');
        $dryRun = (bool) $this->option('dry-run');

        // Find User2Mod chats where user1 is missing from the roster.
        $missingMember = DB::select("
            SELECT cr.id AS chatid, cr.user1, cr.groupid, cr.latestmessage,
                   (SELECT MAX(cm.id) FROM chat_messages cm WHERE cm.chatid = cr.id) AS max_msg_id
            FROM chat_rooms cr
            WHERE cr.chattype = 'User2Mod'
              AND cr.latestmessage >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND NOT EXISTS (
                SELECT 1 FROM chat_roster ro WHERE ro.chatid = cr.id AND ro.userid = cr.user1
              )
        ", [$days]);

        $notifyCutoff = now()->subDays($notifyDays);
        $repairedNotify = 0;
        $repairedSilent = 0;

        $this->info("Found " . count($missingMember) . " User2Mod chats missing user1 from roster (last {$days} days).");
        $this->info("Notifications will fire for chats active in last {$notifyDays} days; older ones repaired silently.");

        foreach ($missingMember as $row) {
            $isRecent = $row->latestmessage >= $notifyCutoff->toDateTimeString();

            if ($dryRun) {
                $label = $isRecent ? 'notify' : 'silent';
                $this->line("  [{$label}] Would add user {$row->user1} to chat {$row->chatid} (group {$row->groupid}, last active {$row->latestmessage})");
                $isRecent ? $repairedNotify++ : $repairedSilent++;
                continue;
            }

            // Insert member into roster.
            DB::statement('INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?)', [$row->chatid, $row->user1]);

            // For older chats, mark all messages as already emailed so the
            // notification system doesn't send stale emails.
            if (! $isRecent && $row->max_msg_id) {
                DB::statement(
                    'UPDATE chat_roster SET lastmsgemailed = ? WHERE chatid = ? AND userid = ? AND (lastmsgemailed IS NULL OR lastmsgemailed < ?)',
                    [$row->max_msg_id, $row->chatid, $row->user1, $row->max_msg_id]
                );
            }

            // Ensure group mods are in the roster.
            $modIds = DB::table('memberships')
                ->where('groupid', $row->groupid)
                ->whereIn('role', ['Owner', 'Moderator'])
                ->pluck('userid');

            foreach ($modIds as $modId) {
                DB::statement('INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?)', [$row->chatid, $modId]);
            }

            $isRecent ? $repairedNotify++ : $repairedSilent++;
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Repair complete.');
        $this->info("  {$repairedNotify} chats repaired (notifications enabled)");
        $this->info("  {$repairedSilent} chats repaired silently (older than {$notifyDays} days)");

        if (! $dryRun) {
            Log::info('chat:repair-roster complete', [
                'notify' => $repairedNotify,
                'silent' => $repairedSilent,
                'days' => $days,
                'notify_days' => $notifyDays,
            ]);
        }

        return self::SUCCESS;
    }
}
