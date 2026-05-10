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

    /** @var array<string, int[]> "{groupid}:{P|A}" => active member userid[] cache for the current run */
    private array $eligibleCache = [];

    /** @var array<int, bool> userid => true for users with any Exhort microvolunteering notification today */
    private array $alreadyNotifiedToday = [];

    public function notifyForMessages(bool $dryRun = false): array
    {
        $this->eligibleCache       = [];
        $this->alreadyNotifiedToday = $this->loadAlreadyNotifiedToday();

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

            $candidates = $this->pickCandidates($msg, $notifiedThisRun);

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

    /**
     * Pick up to CANDIDATES_PER_MESSAGE eligible reviewers for a message.
     *
     * V1 (and the original Laravel port) ran a `memberships ⨝ users LEFT JOIN
     * users_notifications … ORDER BY RAND() LIMIT 10` query per message,
     * which made MySQL evaluate the LEFT JOIN against `users_notifications`
     * for every member of the group and then sort the result. Across ~1900
     * messages that took ~3 minutes per cron tick.
     *
     * This version replaces that with three cheap pieces:
     *
     *   1. The active-member pool per (group, collection) — fetched once
     *      per pair from `memberships ⨝ users` with the lastaccess/trust/
     *      role predicates only. Cached for the run.
     *   2. The set of users who already received any microvolunteering
     *      Exhort notification in the last 24 h — fetched once at the
     *      start of the run and held as a {userid => true} map.
     *   3. Per-message: filter the cached pool by removing the poster,
     *      anyone in `notifiedThisRun`, and anyone already-notified-today;
     *      then `array_rand` up to CANDIDATES_PER_MESSAGE.
     *
     * Behaviourally close to V1: each eligible user has approximately
     * uniform probability of being picked per message, MAX_PER_USER cap
     * still applies via the existence-check in the caller, and the
     * `notifiedThisRun` dedup mirrors V1's $notified array.
     *
     * @return array<object{userid:int}>
     */
    private function pickCandidates(object $msg, array $notifiedThisRun): array
    {
        $pool = $this->getActivePoolForGroup($msg->groupid, $msg->collection);

        if (empty($pool)) {
            return [];
        }

        $skip = array_flip($notifiedThisRun);
        $skip[$msg->fromuser] = true;

        $available = [];
        foreach ($pool as $uid) {
            if (isset($skip[$uid])) {
                continue;
            }
            if (isset($this->alreadyNotifiedToday[$uid])) {
                continue;
            }
            $available[] = $uid;
        }

        if (empty($available)) {
            return [];
        }

        if (count($available) <= self::CANDIDATES_PER_MESSAGE) {
            $picked = $available;
        } else {
            $keys   = (array) array_rand($available, self::CANDIDATES_PER_MESSAGE);
            $picked = [];
            foreach ($keys as $k) {
                $picked[] = $available[$k];
            }
        }

        return array_map(fn (int $uid) => (object) ['userid' => $uid], $picked);
    }

    /**
     * Active members for (group, collection-type), cached per run.
     *
     * For a Pending message: members active in the past 31 days with
     * trustlevel Moderate/Advanced (any role).
     *
     * For non-Pending: members active in the past 31 days with role=Member
     * and trustlevel Basic/Moderate/Advanced.
     *
     * The "no microvolunteering notification today" filter is applied
     * per-message via the run-scoped `alreadyNotifiedToday` map — it
     * doesn't appear here so this query stays index-friendly.
     *
     * @return int[]
     */
    private function getActivePoolForGroup(int $groupid, string $collection): array
    {
        $key = $groupid . ':' . ($collection === 'Pending' ? 'P' : 'A');

        if (isset($this->eligibleCache[$key])) {
            return $this->eligibleCache[$key];
        }

        if ($collection === 'Pending') {
            $sql = "SELECT DISTINCT memberships.userid
                    FROM memberships
                    INNER JOIN users ON memberships.userid = users.id
                    WHERE memberships.groupid = ?
                      AND users.lastaccess >= DATE_SUB(NOW(), INTERVAL 31 DAY)
                      AND users.trustlevel IN ('Moderate', 'Advanced')";
        } else {
            $sql = "SELECT DISTINCT memberships.userid
                    FROM memberships
                    INNER JOIN users ON memberships.userid = users.id
                    WHERE memberships.groupid = ?
                      AND memberships.role = 'Member'
                      AND users.lastaccess >= DATE_SUB(NOW(), INTERVAL 31 DAY)
                      AND users.trustlevel IN ('Basic', 'Moderate', 'Advanced')";
        }

        $rows = DB::select($sql, [$groupid]);
        $this->eligibleCache[$key] = array_map(fn ($r) => (int) $r->userid, $rows);

        return $this->eligibleCache[$key];
    }

    /**
     * Build {userid => true} for users with any microvolunteering Exhort
     * notification in the last 24 h. Equivalent to the per-message
     * `LEFT JOIN users_notifications … IS NULL` filter the V1 candidate
     * query did, hoisted out of the per-message hot loop.
     *
     * @return array<int, bool>
     */
    private function loadAlreadyNotifiedToday(): array
    {
        $rows = DB::select(
            "SELECT DISTINCT touser
             FROM users_notifications
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
               AND url LIKE '/microvolunteering/message/%'
               AND type = ?",
            [self::NOTIFICATION_TYPE]
        );

        $set = [];
        foreach ($rows as $row) {
            $set[(int) $row->touser] = true;
        }
        return $set;
    }
}
