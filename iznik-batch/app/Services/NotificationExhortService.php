<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationExhortService
{
    private const COOLDOWN_DAYS = 90;

    /**
     * Send onsite Exhort notifications to recently-active established users.
     *
     * Mirrors V1 cron/user_exhort.php:
     * - Targets users active within $activeSince who joined before $joinedBefore.
     * - Skips users who received an Exhort notification in the last 90 days.
     * - Inserts into users_notifications with type='Exhort' (no email — onsite only).
     *
     * Production crontab args:
     *   -u "https://www.ilovefreegle.org/stories"
     *   -l "Tell us your Freegle story!"
     *   -x "We love to hear why people Freegle..."
     *   -s "5 minutes ago"
     *   -t "1 week ago"
     */
    public function sendExhort(
        string $url,
        string $title,
        string $text,
        string $activeSince = '5 minutes ago',
        string $joinedBefore = '1 week ago',
        bool $dryRun = false
    ): int {
        $activeSinceTime = date('Y-m-d H:i:s', strtotime($activeSince));
        $joinedBeforeTime = date('Y-m-d H:i:s', strtotime($joinedBefore));
        $cooldownAgo = now()->subDays(self::COOLDOWN_DAYS)->format('Y-m-d H:i:s');

        $users = DB::table('users')
            ->whereNull('deleted')
            ->where('lastaccess', '>=', $activeSinceTime)
            ->where('added', '<=', $joinedBeforeTime)
            ->pluck('id');

        $sent = 0;

        foreach ($users as $userId) {
            $alreadySent = DB::table('users_notifications')
                ->where('touser', $userId)
                ->where('type', 'Exhort')
                ->where('timestamp', '>=', $cooldownAgo)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            if (!$dryRun) {
                DB::table('users_notifications')->insert([
                    'touser' => $userId,
                    'fromuser' => null,
                    'type' => 'Exhort',
                    'url' => $url,
                    'title' => $title,
                    'text' => $text,
                    'timestamp' => now(),
                    'seen' => 0,
                    'mailed' => 0,
                ]);
            }

            $sent++;
        }

        Log::info('NotificationExhortService: sent exhort notifications', [
            'count' => $sent,
            'dry_run' => $dryRun,
        ]);

        return $sent;
    }
}
