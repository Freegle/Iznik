<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationExhortService
{
    private const COOLDOWN_DAYS = 90;

    public function __construct(
        private ?PushNotificationService $pushService = null
    ) {
    }

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

        // IGNORE INDEX (deleted): `deleted IS NULL` matches 2,732,883 of 2,872,858 users on
        // production - 95% - so the optimiser used that index to select nearly the whole table
        // and then did 1,488,055 random primary-key lookups off it. A plain scan of all 2.9M
        // rows beats that by 4.3x (6.59s -> 1.55s, measured on db2 2026-09-18). The predicate
        // that would really pay is `lastaccess >= ?` (3,850 rows, 0.13%), but there is no index
        // on lastaccess and the (added, lastaccess) composite cannot help, because
        // `added <= <a week ago>` matches nearly every account. An index on users.lastaccess
        // would be better still - that is a Galera DDL decision for an operator, not a PR.
        //
        // A hint is only as good as the index name it quotes; ExhortUsersCommandTest asserts
        // that `users.deleted` still exists, so renaming it fails there rather than here.
        $users = DB::table(DB::raw('users IGNORE INDEX (deleted)'))
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

                // V1 parity: Notifications::add() fires a device push after the
                // insert (PushNotifications::notify($uid, FALSE)). No-op when the
                // user has no registered Freegle-app devices / Firebase is unset.
                ($this->pushService ?? app(PushNotificationService::class))->notifyUser($userId);
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
