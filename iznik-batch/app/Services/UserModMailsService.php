<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maintains the users_modmails table by scanning recent logs for mod actions.
 *
 * Mirrors V1 cron/users_modmails.php:
 * - Scan logs from the last 10 minutes for mod actions on users (Rejected, Deleted, Replied, Mailed).
 * - Only records where byuser != user (mod acting on someone else).
 * - Prune entries older than 30 days.
 */
class UserModMailsService
{
    private const SCAN_MINUTES = 10;

    private const PRUNE_DAYS = 30;

    private const MOD_ACTION_TYPES = [
        ['type' => 'Message', 'subtypes' => ['Rejected', 'Deleted', 'Replied']],
        ['type' => 'User', 'subtypes' => ['Mailed', 'Rejected', 'Deleted']],
    ];

    /**
     * Insert recent mod-action log entries into users_modmails.
     *
     * @return int Number of new entries inserted
     */
    public function updateModMails(): int
    {
        $since = now()->subMinutes(self::SCAN_MINUTES);
        $inserted = 0;

        $logs = DB::table('logs')
            ->where('timestamp', '>', $since)
            ->where(function ($q) {
                foreach (self::MOD_ACTION_TYPES as $group) {
                    $q->orWhere(function ($inner) use ($group) {
                        $inner->where('type', $group['type'])
                            ->whereIn('subtype', $group['subtypes']);
                    });
                }
            })
            ->whereColumn('byuser', '!=', 'user')
            ->whereNotNull('byuser')
            ->whereNotNull('user')
            ->get();

        foreach ($logs as $log) {
            $affected = DB::table('users_modmails')->insertOrIgnore([
                'userid' => $log->user,
                'logid' => $log->id,
                'timestamp' => $log->timestamp,
                'groupid' => $log->groupid ?? 0,
            ]);

            if ($affected > 0) {
                $inserted++;
            }
        }

        Log::info('users_modmails updated', ['inserted' => $inserted, 'logs_scanned' => count($logs)]);

        return $inserted;
    }

    /**
     * Prune users_modmails entries older than 30 days.
     *
     * @return int Number of entries deleted
     */
    public function pruneOldEntries(): int
    {
        $cutoff = now()->subDays(self::PRUNE_DAYS)->startOfDay();

        $deleted = DB::table('users_modmails')
            ->where('timestamp', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            Log::info('users_modmails pruned', ['deleted' => $deleted]);
        }

        return $deleted;
    }
}
