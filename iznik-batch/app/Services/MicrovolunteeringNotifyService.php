<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends onsite notifications asking active users to review pending messages.
 *
 * Migrated from iznik-server/scripts/cron/microvolunteering.php → MicroVolunteering::notifyForMessages().
 *
 * For each recent message from a microvolunteering-enabled group that has not yet
 * had a notification sent today, finds up to 10 eligible users per message and
 * inserts a users_notifications row of type 'Exhort' for each.
 *
 * Pending-collection messages require Moderate or Advanced trustlevel.
 * Approved-collection messages target regular Members with any trust level.
 */
class MicrovolunteeringNotifyService
{
    private const NOTIFICATION_TYPE = 'Exhort';
    private const NOTIFICATION_TITLE = 'Could you review this message to help us keep the site safe?';
    private const MAX_PER_USER = 3;
    private const CANDIDATES_PER_MESSAGE = 10;

    public function notifyForMessages(bool $dryRun = false): array
    {
        $stats = [
            'messages_considered' => 0,
            'users_notified'      => 0,
            'users_skipped'       => 0,
        ];

        $msgs = DB::select("
            SELECT messages.id, messages.fromuser, messages_groups.groupid, messages.subject, messages_groups.collection
            FROM messages
            INNER JOIN messages_groups ON messages.id = messages_groups.msgid
            INNER JOIN `groups` ON messages_groups.groupid = groups.id
            LEFT JOIN users_notifications
                ON users_notifications.timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND users_notifications.url LIKE CONCAT('/microvolunteering/message/', messages.id)
                AND users_notifications.type = ?
            WHERE messages_groups.arrival > DATE_SUB(NOW(), INTERVAL 1 DAY)
              AND messages.deleted IS NULL
              AND messages.heldby IS NULL
              AND users_notifications.id IS NULL
              AND groups.microvolunteering = 1
        ", [self::NOTIFICATION_TYPE]);

        $stats['messages_considered'] = count($msgs);

        Log::info("MicrovolunteeringNotify: considering " . count($msgs) . " messages");

        $notifiedThisRun = [];

        foreach ($msgs as $msg) {
            $url = '/microvolunteering/message/' . $msg->id;

            if ($msg->collection === 'Pending') {
                $candidates = DB::select("
                    SELECT DISTINCT memberships.userid
                    FROM memberships
                    INNER JOIN users ON memberships.userid = users.id
                    LEFT JOIN users_notifications
                        ON users_notifications.touser = memberships.userid
                        AND users_notifications.timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                        AND users_notifications.url LIKE '/microvolunteering/message/%'
                        AND users_notifications.type = ?
                    WHERE memberships.groupid = ?
                      AND users.lastaccess >= DATE_SUB(NOW(), INTERVAL 31 DAY)
                      AND users.id != ?
                      AND users.trustlevel IN ('Moderate', 'Advanced')
                      AND users_notifications.id IS NULL
                    ORDER BY RAND()
                    LIMIT ?
                ", [self::NOTIFICATION_TYPE, $msg->groupid, $msg->fromuser, self::CANDIDATES_PER_MESSAGE]);
            } else {
                $candidates = DB::select("
                    SELECT DISTINCT memberships.userid
                    FROM memberships
                    INNER JOIN users ON memberships.userid = users.id
                    LEFT JOIN users_notifications
                        ON users_notifications.touser = memberships.userid
                        AND users_notifications.timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                        AND users_notifications.url LIKE '/microvolunteering/message/%'
                        AND users_notifications.type = ?
                    WHERE memberships.groupid = ?
                      AND users.lastaccess >= DATE_SUB(NOW(), INTERVAL 31 DAY)
                      AND users.id != ?
                      AND memberships.role = 'Member'
                      AND users.trustlevel IN ('Basic', 'Moderate', 'Advanced')
                      AND users_notifications.id IS NULL
                    ORDER BY RAND()
                    LIMIT ?
                ", [self::NOTIFICATION_TYPE, $msg->groupid, $msg->fromuser, self::CANDIDATES_PER_MESSAGE]);
            }

            foreach ($candidates as $candidate) {
                $uid = $candidate->userid;

                if (in_array($uid, $notifiedThisRun)) {
                    $stats['users_skipped']++;
                    continue;
                }

                $existing = DB::selectOne("
                    SELECT COUNT(*) AS count
                    FROM users_notifications
                    WHERE touser = ?
                      AND (url LIKE ? OR timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY))
                      AND type = ?
                ", [$uid, $url, self::NOTIFICATION_TYPE]);

                if ($existing->count >= self::MAX_PER_USER) {
                    $stats['users_skipped']++;
                    continue;
                }

                Log::debug("MicrovolunteeringNotify: notify user {$uid} about message {$msg->id}");

                if (!$dryRun) {
                    DB::table('users_notifications')->insert([
                        'fromuser'   => null,
                        'touser'     => $uid,
                        'type'       => self::NOTIFICATION_TYPE,
                        'newsfeedid' => null,
                        'url'        => $url,
                        'title'      => self::NOTIFICATION_TITLE,
                        'text'       => 'Click here to review: ' . $msg->subject,
                    ]);
                }

                $notifiedThisRun[] = $uid;
                $stats['users_notified']++;
            }
        }

        return $stats;
    }
}
