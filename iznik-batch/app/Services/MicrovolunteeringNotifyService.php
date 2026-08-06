<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends onsite notifications asking active users to review pending messages.
 *
 * Migrated from the legacy V1 PHP microvolunteering cron script → MicroVolunteering::notifyForMessages().
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
    // Only mark-seen notifications from the recent window. There are ~2.5M unseen
    // type='Exhort' rows and no index on `type`, so an unbounded UPDATE full-scans
    // and holds gap/next-key locks across the whole range for the duration of the
    // (every-5-min) run — blocking concurrent Exhort inserts with 1205 lock-wait
    // timeouts (Sentry 2026-07-06) and risking Galera on the large write. Reviews
    // happen within days of the notification, and the touser_2 (timestamp,seen,mailed)
    // index range-scans this cheaply, so a 7-day bound keeps it correct + tiny.
    private const MARK_SEEN_WINDOW_DAYS = 7;

    /** @var array<string, int[]> "{groupid}:{P|A}" => active member userid[] cache for the current run */
    private array $eligibleCache = [];

    /** @var array<int, bool> userid => true for users with any Exhort microvolunteering notification today */
    private array $alreadyNotifiedToday = [];

    /** @var array<int, array<int, bool>> msgid => {userid => true} for users who already reviewed that message */
    private array $reviewedByMessage = [];

    public function notifyForMessages(bool $dryRun = false): array
    {
        $this->eligibleCache       = [];
        $this->alreadyNotifiedToday = $this->loadAlreadyNotifiedToday();
        $this->reviewedByMessage    = [];

        // Second surface of Discourse 9856: the exclusion below only stops a NEW
        // duplicate notification from being created. It does nothing about an
        // Exhort notification that already exists and is still unseen for a
        // message the recipient has since reviewed (e.g. inserted before this
        // exclusion existed, or via any other race) - that row would otherwise
        // stay seen=0 for ever, keeping the badge lit and its link re-presenting
        // the already-reviewed post. Sweep those clear on every run, independent
        // of today's candidate set, so the badge/count stays consistent with the
        // review-selection exclusion (confirmed against production: 81 such
        // stuck rows exist at time of writing).
        $staleNotificationsCleared = $this->markStaleReviewedNotificationsSeen($dryRun);

        $stats = [
            'messages_considered'         => 0,
            'users_notified'              => 0,
            'users_skipped'               => 0,
            'stale_notifications_cleared' => $staleNotificationsCleared,
        ];

        // keep-raw: the LEFT JOIN condition matches users_notifications.url against
        // CONCAT('/microvolunteering/message/', messages.id) - a per-row correlated
        // string built from a joined column. The query builder's join closure supports
        // ->on()/->where() for column/value comparisons but has no method that projects
        // CONCAT() over a column, so the match condition itself must stay raw SQL.
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
              -- A hold is per-group. Every other predicate here is already
              -- scoped to this messages_groups row (arrival, and groupid via
              -- the SELECT above); this one wasn't. messages.heldby is a
              -- legacy message-wide mirror that nothing writes any more, so
              -- checking it here let a copy held on this row's group still
              -- be offered up for \"please review\" - the hold only ever
              -- belonged to the group it was placed on.
              AND messages_groups.heldby IS NULL
              AND users_notifications.id IS NULL
              AND groups.microvolunteering = 1
        ", [self::NOTIFICATION_TYPE]);

        $stats['messages_considered'] = count($msgs);

        // Users who have already recorded a CheckMessage microaction for a message
        // must never be re-notified about it. Without this, a rippling post - whose
        // messages_groups.arrival is refreshed each time it ripples into a new group -
        // keeps re-entering the "arrival within 1 day" gate and re-lights the same
        // person's "post to check" badge for ever, even after they have reviewed it.
        // See Discourse 9856.
        $this->reviewedByMessage = $this->loadReviewedByMessage(
            array_values(array_unique(array_map(fn ($m) => (int) $m->id, $msgs)))
        );

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

                $existingCount = DB::table('users_notifications')
                    ->where('touser', $uid)
                    ->where(function ($q) use ($url) {
                        $q->where('url', 'like', $url)
                          ->orWhere('timestamp', '>=', now()->subDay());
                    })
                    ->where('type', self::NOTIFICATION_TYPE)
                    ->count();

                if ($existingCount >= self::MAX_PER_USER) {
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

        $reviewed = $this->reviewedByMessage[$msg->id] ?? [];

        $available = [];
        foreach ($pool as $uid) {
            if (isset($skip[$uid])) {
                continue;
            }
            if (isset($this->alreadyNotifiedToday[$uid])) {
                continue;
            }
            if (isset($reviewed[$uid])) {
                // Already reviewed this message - don't ask again.
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

        $query = DB::table('memberships')
            ->join('users', 'memberships.userid', '=', 'users.id')
            ->where('memberships.groupid', $groupid)
            ->where('users.lastaccess', '>=', now()->subDays(31))
            ->distinct()
            ->select('memberships.userid');

        if ($collection === 'Pending') {
            $query->whereIn('users.trustlevel', ['Moderate', 'Advanced']);
        } else {
            $query->where('memberships.role', 'Member')
                  ->whereIn('users.trustlevel', ['Basic', 'Moderate', 'Advanced']);
        }

        $this->eligibleCache[$key] = $query->pluck('userid')->map(fn ($v) => (int) $v)->all();

        return $this->eligibleCache[$key];
    }

    /**
     * Build {msgid => {userid => true}} for users who have already recorded a
     * CheckMessage microaction for each of the given messages. Preloaded once per
     * run (mirrors loadAlreadyNotifiedToday) so pickCandidates can skip anyone who
     * has already reviewed the message without a per-candidate query.
     *
     * @param int[] $msgids
     * @return array<int, array<int, bool>>
     */
    private function loadReviewedByMessage(array $msgids): array
    {
        if (empty($msgids)) {
            return [];
        }

        $rows = DB::table('microactions')
            ->select('userid', 'msgid')
            ->where('actiontype', 'CheckMessage')
            ->whereIn('msgid', $msgids)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->msgid][(int) $row->userid] = true;
        }
        return $map;
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
        $rows = DB::table('users_notifications')
            ->select('touser')
            ->distinct()
            ->where('timestamp', '>=', now()->subDay())
            ->where('url', 'like', '/microvolunteering/message/%')
            ->where('type', self::NOTIFICATION_TYPE)
            ->get();

        $set = [];
        foreach ($rows as $row) {
            $set[(int) $row->touser] = true;
        }
        return $set;
    }

    /**
     * Mark seen any existing Exhort "post to check" notification whose recipient
     * has already recorded a CheckMessage microaction for the message it points
     * at. The per-message exclusion in pickCandidates only prevents a NEW
     * duplicate from being created; it cannot retroactively clear a notification
     * that already exists (e.g. inserted before this exclusion shipped, or via
     * any other race). Without this sweep the badge stays lit and the
     * notification's link keeps re-presenting an already-reviewed post
     * indefinitely. See Discourse 9856.
     *
     * @return int Number of notifications cleared (0 in dry-run mode, since
     *             nothing is written).
     */
    private function markStaleReviewedNotificationsSeen(bool $dryRun): int
    {
        // keep-raw: both statements below join on
        // un.url = CONCAT('/microvolunteering/message/', ma.msgid) - a per-row
        // correlated string built from the joined microactions.msgid column. There is
        // no query-builder method that projects CONCAT() over a column, so the join
        // condition (and therefore the surrounding UPDATE ... JOIN / COUNT ... JOIN)
        // has to stay raw SQL. The UPDATE also needs its own raw statement rather than
        // update() with a join, since the query builder has no join-update construct.
        if ($dryRun) {
            $row = DB::selectOne(
                "SELECT COUNT(*) AS count
                 FROM users_notifications un
                 INNER JOIN microactions ma
                     ON ma.userid = un.touser
                     AND ma.actiontype = 'CheckMessage'
                     AND un.url = CONCAT('/microvolunteering/message/', ma.msgid)
                 WHERE un.type = ? AND un.seen = 0
                   AND un.timestamp >= DATE_SUB(NOW(), INTERVAL " . self::MARK_SEEN_WINDOW_DAYS . " DAY)",
                [self::NOTIFICATION_TYPE]
            );

            return (int) $row->count;
        }

        return DB::update(
            "UPDATE users_notifications un
             INNER JOIN microactions ma
                 ON ma.userid = un.touser
                 AND ma.actiontype = 'CheckMessage'
                 AND un.url = CONCAT('/microvolunteering/message/', ma.msgid)
             SET un.seen = 1
             WHERE un.type = ? AND un.seen = 0
               AND un.timestamp >= DATE_SUB(NOW(), INTERVAL " . self::MARK_SEEN_WINDOW_DAYS . " DAY)",
            [self::NOTIFICATION_TYPE]
        );
    }
}
