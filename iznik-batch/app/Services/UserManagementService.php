<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Models\Message;
use App\Models\User;
use App\Models\UserDeletion;
use App\Models\UserEmail;
use App\Traits\ChunkedProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserManagementService
{
    use ChunkedProcessing;

    /**
     * Chunk size for batch operations.
     */
    protected int $chunkSize = 1000;

    /**
     * How long we keep a users_deletions tombstone.
     *
     * Partners poll /api/changes for what has moved since a timestamp, typically
     * every few minutes. Three months is far longer than any sane catch-up window
     * after an outage, and keeps the table small enough to stay uninteresting.
     */
    public const DELETION_RETENTION_DAYS = 90;

    /** Where the hourly lastaccess pass remembers how far it has looked. */
    private const LASTACCESS_CURSOR_KEY = 'users.lastaccess_cursor';

    /**
     * How far before the last run the hourly pass still looks. Covers a run that
     * overlapped the previous one, a clock adjustment, and rows written slightly out
     * of order, at the cost of re-examining an hour already known to be up to date.
     */
    private const LASTACCESS_OVERLAP_HOURS = 2;

    private LokiService $lokiService;

    public function __construct(LokiService $lokiService)
    {
        $this->lokiService = $lokiService;
    }

    /**
     * Merge duplicate user accounts.
     * Users with the same email should be merged.
     */
    public function mergeDuplicates(bool $dryRun = false): array
    {
        $stats = [
            'duplicates_found' => 0,
            'users_merged' => 0,
            'errors' => 0,
        ];

        // Find email addresses linked to multiple users.
        // keep-raw: COUNT(DISTINCT userid) is an aggregate with no query builder
        // method - having() only compares a column/alias to a value, it cannot
        // express a DISTINCT-counted aggregate condition.
        $duplicates = UserEmail::select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(DISTINCT userid) > 1')
            ->get();

        $stats['duplicates_found'] = $duplicates->count();

        foreach ($duplicates as $duplicate) {
            try {
                if (!$dryRun) {
                    $this->mergeUsersForEmail($duplicate->email);
                }
                $stats['users_merged']++;
            } catch (\Exception $e) {
                Log::error("Error merging users for email {$duplicate->email}: ".$e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Merge all users associated with an email into the oldest account.
     */
    protected function mergeUsersForEmail(string $email): void
    {
        $userIds = UserEmail::where('email', $email)
            ->whereNotNull('userid')
            ->orderBy('userid')
            ->pluck('userid')
            ->unique();

        if ($userIds->count() < 2) {
            return;
        }

        // Keep the oldest (lowest ID) user.
        $keepUserId = $userIds->first();
        $mergeUserIds = $userIds->slice(1);

        foreach ($mergeUserIds as $mergeUserId) {
            $this->mergeUser($keepUserId, $mergeUserId);
        }
    }

    /**
     * Merge one user into another.
     */
    protected function mergeUser(int $keepUserId, int $mergeUserId): void
    {
        DB::transaction(function () use ($keepUserId, $mergeUserId) {
            // Update foreign keys pointing to merged user.
            $tables = [
                'memberships' => 'userid',
                'chat_rooms' => 'user1',
                'chat_rooms' => 'user2',
                'chat_messages' => 'userid',
                'messages' => 'fromuser',
                'users_donations' => 'userid',
                'users_emails' => 'userid',
            ];

            foreach ($tables as $table => $column) {
                try {
                    DB::table($table)
                        ->where($column, $mergeUserId)
                        ->update([$column => $keepUserId]);
                } catch (\Exception $e) {
                    // May fail on unique constraints, which is fine.
                    Log::debug("Could not update {$table}.{$column}: ".$e->getMessage());
                }
            }

            // Soft delete the merged user.
            User::where('id', $mergeUserId)
                ->update(['deleted' => now()]);

            Log::info("Merged user {$mergeUserId} into {$keepUserId}");
        });
    }

    /**
     * Check and update user email validity via bounce tracking.
     * Emails that have bounced (bounced timestamp is set) and were validated
     * are marked as invalid (validated set to NULL).
     */
    public function processBouncedEmails(bool $dryRun = false): array
    {
        $stats = [
            'processed' => 0,
            'marked_invalid' => 0,
        ];

        // Get validated emails that have bounced.
        $bouncedEmails = DB::table('users_emails')
            ->whereNotNull('bounced')
            ->whereNotNull('validated')
            ->limit($this->chunkSize)
            ->get();

        foreach ($bouncedEmails as $email) {
            if (!$dryRun) {
                UserEmail::where('id', $email->id)
                    ->update(['validated' => null]);

                $this->lokiService->logBounceEvent(
                    $email->email ?? '',
                    $email->userid ?? 0,
                    true,
                    'Bounced email marked invalid',
                );
            }

            $stats['marked_invalid']++;
            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Clean up inactive and deleted users.
     *
     * Steps:
     *   1. Delete legacy Yahoo Groups users
     *   2. Forget inactive users (no memberships, no activity in 6 months, no logs in 90 days)
     *   3. Process GDPR forgets (users deleted > 14 days ago)
     *   4. Hard-delete fully forgotten users with no remaining messages
     *   5. Prune deletion tombstones older than any partner would poll for
     *
     * @param  bool  $dryRun  If true, count what would be affected but don't modify data.
     * @param  int|null  $limit  Max users to process per phase. Each forget is ~20 DB
     *                           statements, so an unbounded run against a large backlog
     *                           can hammer the database. NULL uses the per-phase defaults.
     */
    public function cleanupUsers(bool $dryRun = FALSE, ?int $limit = NULL): array
    {
        $stats = [
            'yahoo_users_deleted' => 0,
            'inactive_users_forgotten' => 0,
            'gdpr_forgets_processed' => 0,
            'forgotten_users_deleted' => 0,
            'deletion_records_pruned' => 0,
        ];

        $stats['yahoo_users_deleted'] = $this->deleteYahooGroupsUsers($dryRun);
        $stats['inactive_users_forgotten'] = $this->forgetInactiveUsers($dryRun, $limit);
        $stats['gdpr_forgets_processed'] = $this->processForgets($dryRun, $limit);
        $stats['forgotten_users_deleted'] = $this->deleteFullyForgottenUsers($dryRun, $limit);
        $stats['deletion_records_pruned'] = $this->pruneDeletions($dryRun);

        Log::info('User cleanup completed', $stats);

        return $stats;
    }

    /**
     * Delete users with @yahoogroups.com emails.
     *
     * These are legacy Yahoo Groups users that no longer serve a purpose.
     */
    public function deleteYahooGroupsUsers(bool $dryRun = FALSE): int
    {
        $yahooUsers = DB::table('users')
            ->join('users_emails', 'users.id', '=', 'users_emails.userid')
            ->where('users_emails.email', 'LIKE', '%@yahoogroups.com')
            ->whereNull('users.deleted')
            ->distinct()
            ->pluck('users.id');

        $count = $yahooUsers->count();

        if (!$dryRun) {
            foreach ($yahooUsers as $userId) {
                Log::info("Deleting Yahoo Groups user #{$userId}");

                // Remove memberships first (matches V1 User::delete()).
                DB::table('memberships')->where('userid', $userId)->delete();

                // Hard delete the user.
                DB::table('users')->where('id', $userId)->delete();

                UserDeletion::record($userId, UserDeletion::TYPE_PURGED, 'Yahoo Groups user');
            }
        }

        return $count;
    }

    /**
     * Forget inactive users who meet all criteria:
     * - No group memberships
     * - Last access > 6 months ago
     * - Not a spammer
     * - No moderator notes (users_comments)
     * - No meaningful logs in 90 days (excluding User/Created and User/Deleted log entries)
     * - systemrole = 'User'
     * - Not already deleted
     *
     */
    public function forgetInactiveUsers(bool $dryRun = FALSE, ?int $limit = NULL): int
    {
        $sixMonthsAgo = now()->subMonths(6)->format('Y-m-d');
        $limit = $limit ?? 50000;

        // Find candidates: no memberships, no spammer record, no mod notes,
        // last access > 6 months, systemrole = User, not deleted.
        $candidates = DB::table('users')
            ->select('users.id')
            ->leftJoin('memberships', 'users.id', '=', 'memberships.userid')
            ->leftJoin('spam_users', 'users.id', '=', 'spam_users.userid')
            ->leftJoin('users_comments', 'users.id', '=', 'users_comments.userid')
            ->whereNull('memberships.userid')
            ->whereNull('spam_users.userid')
            ->whereNull('users_comments.userid')
            ->where('users.lastaccess', '<', $sixMonthsAgo)
            ->where('users.systemrole', 'User')
            ->whereNull('users.deleted')
            ->whereNull('users.forgotten')
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($candidates as $candidate) {
            // Check for recent meaningful logs (excluding User/Created and User/Deleted).
            // Equivalent to the original "no logs at all OR most recent meaningful
            // log's DATEDIFF(NOW(), timestamp) > 90": DATEDIFF > 90 is DATEDIFF >= 91,
            // which is whereDate(timestamp, '<=', today()->subDays(91)); negating
            // that (the "recent enough" case) gives whereDate('>', today()->subDays(91)).
            // Checking existence rather than fetching the single most-recent row is
            // equivalent because logs.id increases with timestamp, so if any
            // qualifying log is recent enough the most-recent one is too.
            $hasRecentLog = DB::table('logs')
                ->where('user', $candidate->id)
                ->where(function ($q) {
                    $q->where('type', '!=', 'User')
                        ->orWhere(function ($q2) {
                            $q2->where('subtype', '!=', 'Created')
                                ->where('subtype', '!=', 'Deleted');
                        });
                })
                ->whereDate('timestamp', '>', today()->subDays(91))
                ->exists();

            // Forget if no logs at all, or most recent meaningful log is > 90 days old.
            if (!$hasRecentLog) {
                if (!$dryRun) {
                    Log::info("Forgetting inactive user #{$candidate->id}");
                    $this->forgetUser($candidate->id, 'Inactive');
                }
                $count++;
            }
        }

        return $count;
    }

    /**
     * Process GDPR forgets: users with deleted timestamp > 14 days ago
     * who haven't been forgotten yet.
     *
     */
    public function processForgets(bool $dryRun = FALSE, ?int $limit = NULL): int
    {
        $limit = $limit ?? 50000;

        // DATEDIFF(NOW(), deleted) > 14 is DATEDIFF >= 15, i.e.
        // whereDate(deleted, '<=', today()->subDays(15)).
        $users = DB::table('users')
            ->select('id')
            ->whereNotNull('deleted')
            ->whereDate('deleted', '<=', today()->subDays(15))
            ->whereNull('forgotten')
            ->limit($limit)
            ->get();

        $count = $users->count();

        if (!$dryRun) {
            foreach ($users as $user) {
                Log::info("GDPR forget for user #{$user->id} (grace period expired)");
                $this->forgetUser($user->id, 'Grace period');
            }
        }

        return $count;
    }

    /**
     * Wipe a user's personal data for GDPR right to be forgotten.
     *
     * deletes non-internal emails, logins, community events, volunteering,
     * newsfeed, stories, searches, about me, ratings, addresses, images,
     * promises, sessions; nullifies message content; removes group memberships;
     * marks user as forgotten.
     */
    public function forgetUser(int $userId, string $reason): void
    {
        // Clear personal fields.
        DB::table('users')->where('id', $userId)->update([
            'firstname' => NULL,
            'lastname' => NULL,
            'fullname' => "Deleted User #{$userId}",
            'settings' => NULL,
            'yahooid' => NULL,
        ]);

        // Delete non-internal-domain emails (keep our platform emails).
        $emails = DB::table('users_emails')->where('userid', $userId)->get();
        foreach ($emails as $email) {
            if (!MailHelper::isOurDomain($email->email)) {
                DB::table('users_emails')->where('id', $email->id)->delete();
            }
        }

        // Delete all logins.
        DB::table('users_logins')->where('userid', $userId)->delete();

        // Wipe message content for Offer/Wanted messages from this user.
        $messageIds = DB::table('messages')
            ->where('fromuser', $userId)
            ->whereIn('type', ['Offer', 'Wanted'])
            ->pluck('id');

        foreach ($messageIds as $msgId) {
            DB::table('messages')->where('id', $msgId)->update([
                'fromip' => NULL,
                'message' => NULL,
                'envelopefrom' => NULL,
                'fromname' => NULL,
                'fromaddr' => NULL,
                'messageid' => NULL,
                'textbody' => NULL,
                'htmlbody' => NULL,
                'deleted' => now(),
            ]);

            DB::table('messages_groups')->where('msgid', $msgId)->update([
                'deleted' => 1,
            ]);

            // Delete outcome comments (may contain personal data).
            DB::table('messages_outcomes')->where('msgid', $msgId)->update([
                'comments' => NULL,
            ]);
        }

        // Remove content of all chat messages sent by this user.
        DB::table('chat_messages')->where('userid', $userId)->update([
            'message' => NULL,
        ]);

        // Delete community events, volunteering, newsfeed, stories, searches, about me.
        DB::table('communityevents')->where('userid', $userId)->delete();
        DB::table('volunteering')->where('userid', $userId)->delete();
        DB::table('newsfeed')->where('userid', $userId)->delete();
        DB::table('users_stories')->where('userid', $userId)->delete();
        DB::table('users_searches')->where('userid', $userId)->delete();
        DB::table('users_aboutme')->where('userid', $userId)->delete();

        // Delete ratings by and about this user.
        DB::table('ratings')->where('rater', $userId)->delete();
        DB::table('ratings')->where('ratee', $userId)->delete();

        // Remove from all groups.
        DB::table('memberships')->where('userid', $userId)->delete();

        // Remove from Related Members — deleted users should not appear as related to anyone.
        DB::table('users_related')
            ->where('user1', $userId)
            ->orWhere('user2', $userId)
            ->delete();

        // Delete postal addresses.
        DB::table('users_addresses')->where('userid', $userId)->delete();

        // Delete profile images.
        DB::table('users_images')->where('userid', $userId)->delete();

        // Remove promises.
        DB::table('messages_promises')->where('userid', $userId)->delete();

        // Mark as forgotten and clear TN user ID.
        DB::table('users')->where('id', $userId)->update([
            'forgotten' => now(),
            'tnuserid' => NULL,
        ]);

        // Delete sessions.
        DB::table('sessions')->where('userid', $userId)->delete();

        // Log the forget action.
        DB::table('logs')->insert([
            'type' => 'User',
            'subtype' => 'Deleted',
            'user' => $userId,
            'text' => $reason,
            'timestamp' => now(),
        ]);

        // Tell partners, who mirror our users and can only find out by polling.
        UserDeletion::record($userId, UserDeletion::TYPE_FORGOTTEN, $reason);
    }

    /**
     * Delete fully forgotten users who have no remaining messages.
     *
     * These users have been forgotten (personal data wiped) and have no messages
     * left as a placeholder — they can be safely hard-deleted.
     *
     */
    public function deleteFullyForgottenUsers(bool $dryRun = FALSE, ?int $limit = NULL): int
    {
        $sixMonthsAgo = now()->subMonths(6)->format('Y-m-d');
        $limit = $limit ?? 100000;

        $users = DB::table('users')
            ->select('users.id')
            ->leftJoin('messages', 'messages.fromuser', '=', 'users.id')
            ->whereNotNull('users.forgotten')
            ->where('users.lastaccess', '<', $sixMonthsAgo)
            ->whereNull('messages.id')
            ->limit($limit)
            ->get();

        $count = $users->count();

        if (!$dryRun) {
            $processed = 0;
            foreach ($users as $user) {
                // Remove memberships first (matches V1 User::delete()).
                DB::table('memberships')->where('userid', $user->id)->delete();

                // Hard delete the user.
                DB::table('users')->where('id', $user->id)->delete();

                UserDeletion::record($user->id, UserDeletion::TYPE_PURGED, 'Fully forgotten');

                $processed++;
                if ($processed % 1000 === 0) {
                    Log::info("Deleted {$processed} / {$count} fully forgotten users");
                }
            }
        }

        return $count;
    }

    /**
     * Drop deletion tombstones that no partner could still be asking about.
     *
     * @return int Rows removed (or that would be removed, on a dry run).
     */
    public function pruneDeletions(bool $dryRun = FALSE): int
    {
        $cutoff = now()->subDays(self::DELETION_RETENTION_DAYS);

        $query = UserDeletion::where('timestamp', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }

    /**
     * Fallback update of user lastaccess timestamps.
     *
     * Finds users whose lastaccess is more than 10 minutes behind their latest
     * chat message or membership join, and updates accordingly.
     *
     */
    public function updateLastAccess(bool $dryRun = false, bool $full = false): array
    {
        // Taken before any work, so activity arriving while this runs is left for the
        // next run rather than being skipped by a cursor that moved past it.
        $startedAt = now();

        $stats = [
            'candidates' => 0,
            'updated' => 0,
        ];

        // Find users whose lastaccess is > 600 seconds behind their latest chat message
        // or membership join.
        //
        // Hourly, this only looks at activity since the last run. Unbounded, both arms
        // join users against the whole history of chat_messages and of the 4.96M-row
        // memberships table with a non-sargable TIMESTAMPDIFF, which cost about 4,145
        // seconds of database time a day to find roughly 37 users. Nothing older than
        // the window can newly qualify: a user only falls behind when fresh activity
        // arrives, and this job is a top-up over the lastaccess the API writes anyway.
        //
        // The nightly unbounded pass is what covers the one case the window cannot: a
        // writer inserting rows with timestamps older than the window. It is not
        // optional - it is the whole correctness argument for narrowing the hourly one.
        $since = $full ? null : $this->lastAccessWindowStart();

        // keep-raw: both arms compare one table's column against another's with an
        // interval, and union two DISTINCT sets. The query builder cannot express the
        // correlated column-to-column comparison without raw fragments anyway, and the
        // shape is unchanged from the version this replaces bar the window.
        $users = DB::select("
            SELECT DISTINCT(userid) FROM (
                SELECT DISTINCT(userid) FROM users
                INNER JOIN chat_messages ON chat_messages.userid = users.id
                WHERE users.lastaccess < chat_messages.date
                    AND TIMESTAMPDIFF(SECOND, users.lastaccess, chat_messages.date) > 600
                    AND (? IS NULL OR chat_messages.date >= ?)
                UNION
                SELECT DISTINCT(userid) FROM memberships
                INNER JOIN users ON users.id = memberships.userid
                WHERE TIMESTAMPDIFF(SECOND, users.lastaccess, memberships.added) > 600
                    AND (? IS NULL OR memberships.added >= ?)
            ) t
            LIMIT 50000
        ", [$since, $since, $since, $since]);

        $stats['full'] = $full;
        $stats['since'] = $since;

        $stats['candidates'] = count($users);
        $processed = 0;

        foreach ($users as $user) {
            // Find the latest activity timestamp from chat messages or memberships.
            // keep-raw: GREATEST() and COALESCE() have no query builder equivalents.
            $result = DB::selectOne("
                SELECT GREATEST(
                    COALESCE((SELECT MAX(date) FROM chat_messages WHERE userid = ?), '1970-01-01'),
                    COALESCE((SELECT MAX(added) FROM memberships WHERE userid = ?), '1970-01-01')
                ) AS max
            ", [$user->userid, $user->userid]);

            if ($result && $result->max && $result->max !== '1970-01-01') {
                $currentAccess = DB::table('users')
                    ->where('id', $user->userid)
                    ->value('lastaccess');

                $diff = strtotime($result->max) - strtotime($currentAccess);

                if ($diff > 600) {
                    if (!$dryRun) {
                        DB::table('users')
                            ->where('id', $user->userid)
                            ->update(['lastaccess' => $result->max]);
                    }

                    $stats['updated']++;
                }
            }

            $processed++;

            if ($processed % 1000 === 0) {
                Log::info("Processed {$processed} / {$stats['candidates']} lastaccess candidates");
            }
        }

        if (!$dryRun) {
            $this->writeLastAccessCursor($startedAt);
        }

        return $stats;
    }

    /**
     * How far back the hourly pass looks: the last run, less a safety margin.
     *
     * The margin covers a run that overlapped the previous one, a clock adjustment,
     * and rows written slightly out of order. Two hours against an hourly job is
     * generous, and the cost of it is re-examining an hour of activity that was
     * already up to date - which the per-user check below discards immediately.
     *
     * With nothing stored, returns a window wide enough to behave like a first full
     * pass rather than silently examining nothing.
     */
    private function lastAccessWindowStart(): string
    {
        $raw = DB::table('config')->where('key', self::LASTACCESS_CURSOR_KEY)->value('value');

        if (!$raw) {
            return now()->subDays(7)->toDateTimeString();
        }

        return Carbon::parse($raw)->subHours(self::LASTACCESS_OVERLAP_HOURS)->toDateTimeString();
    }

    private function writeLastAccessCursor(Carbon $startedAt): void
    {
        DB::table('config')->upsert(
            [['key' => self::LASTACCESS_CURSOR_KEY, 'value' => $startedAt->toDateTimeString()]],
            ['key'],
            ['value'],
        );
    }

    /**
     * Update support tools access based on team membership.
     *
     * Grants SYSTEMROLE_SUPPORT to users who are members of teams with supporttools=1.
     * Removes the role from users who no longer qualify (downgrading to Moderator).
     * Never touches Admin users.
     *
     */
    public function updateSupportRoles(bool $dryRun = false): array
    {
        $stats = [
            'granted' => 0,
            'removed' => 0,
        ];

        // Users who currently have Support or Admin role.
        $currentSupport = DB::table('users')
            ->whereIn('systemrole', ['Support', 'Admin'])
            ->pluck('id')
            ->all();

        // Users who should have support tools access (in teams with supporttools=1).
        $needSupport = DB::table('teams_members')
            ->join('teams', 'teams.id', '=', 'teams_members.teamid')
            ->where('teams.supporttools', 1)
            ->distinct()
            ->pluck('teams_members.userid')
            ->all();

        // Grant support role to users who need it but don't have it.
        foreach ($needSupport as $userId) {
            if (!in_array($userId, $currentSupport)) {
                if (!$dryRun) {
                    DB::table('users')
                        ->where('id', $userId)
                        ->update(['systemrole' => 'Support']);

                    Log::info("Granted support role to user #{$userId}");
                }

                $stats['granted']++;
            }
        }

        // Remove support role from users who have it but shouldn't.
        // Don't touch Admin users - only downgrade Support to Moderator.
        $removeFrom = array_diff($currentSupport, $needSupport);

        foreach ($removeFrom as $userId) {
            $currentRole = DB::table('users')
                ->where('id', $userId)
                ->value('systemrole');

            // Only downgrade Support, never Admin.
            if ($currentRole === 'Support') {
                if (!$dryRun) {
                    DB::table('users')
                        ->where('id', $userId)
                        ->update(['systemrole' => 'Moderator']);

                    Log::info("Removed support role from user #{$userId}");
                }

                $stats['removed']++;
            }
        }

        return $stats;
    }

    /**
     * Demote stale Moderator systemroles. A user whose users.systemrole is
     * 'Moderator' but who no longer holds an Owner/Moderator membership on any
     * group is set back to 'User'. Support and Admin are never touched — they
     * are granted deliberately and outrank Moderator.
     *
     * This backfills the historical gap where the Go membership-removal path
     * (leave/ban) did not reconcile systemrole the way V1 User::updateSystemRole
     * did, leaving ex-moderators carrying a Moderator systemrole and the
     * elevated access it implies. The ongoing fix lives in the Go API
     * (user.SyncSystemRole on membership deletion); this one-off cleans up the
     * accumulated rows. Each user is updated individually (Galera-safe) and the
     * UPDATE is guarded on systemrole = 'Moderator' so a concurrent change is
     * never clobbered.
     */
    public function backfillModeratorSystemRoles(bool $dryRun = false): array
    {
        $stats = ['demoted' => 0];

        $staleMods = DB::table('users')
            ->where('systemrole', 'Moderator')
            ->whereNotExists(function ($q) {
                // No select() call needed: the subquery is used only for its
                // existence, not its column list, so the default "select *" is
                // functionally identical to "select 1" here.
                $q->from('memberships')
                    ->whereColumn('memberships.userid', 'users.id')
                    ->whereIn('memberships.role', ['Moderator', 'Owner']);
            })
            ->pluck('id')
            ->all();

        foreach ($staleMods as $userId) {
            if (!$dryRun) {
                DB::table('users')
                    ->where('id', $userId)
                    ->where('systemrole', 'Moderator')
                    ->update(['systemrole' => 'User']);

                Log::info("Demoted stale Moderator systemrole to User for user #{$userId}");
            }

            $stats['demoted']++;
        }

        return $stats;
    }

    /**
     * Validate recently-added non-bouncing emails and delete invalid ones.
     *
     * Uses Message::EMAIL_REGEXP. Scoped to the last 30 days because the regex
     * is purely a function of the address — once a row passes it can never
     * become invalid retroactively, so a full-table sweep would be wasted work.
     *
     */
    public function validateEmails(bool $dryRun = false): array
    {
        $stats = [
            'total' => 0,
            'invalid' => 0,
        ];

        $since = now()->subDays(30);

        DB::table('users_emails')
            ->join('users', 'users.id', '=', 'users_emails.userid')
            ->where('users.bouncing', 0)
            ->whereNull('users_emails.bounced')
            ->where('users_emails.added', '>=', $since)
            ->select('users_emails.id', 'users_emails.email', 'users_emails.userid')
            ->orderBy('users_emails.id')
            ->chunkById(5000, function ($emails) use (&$stats, $dryRun) {
                foreach ($emails as $email) {
                    $stats['total']++;

                    if (!preg_match(Message::EMAIL_REGEXP, $email->email)) {
                        if (!$dryRun) {
                            DB::table('users_emails')->where('id', $email->id)->delete();
                            Log::info("Deleted invalid email: {$email->email} for user #{$email->userid}");
                        }
                        $stats['invalid']++;
                    }

                    if ($stats['total'] % 1000 === 0) {
                        Log::info("Validated {$stats['total']} emails so far, {$stats['invalid']} invalid");
                    }
                }
            }, 'users_emails.id', 'id');

        return $stats;
    }

    /**
     * Update rating visibility based on chat interactions.
     *
     * A rating is visible if the rater and ratee have had meaningful chat interaction:
     * - At least one message from each in the same chat room, OR
     * - The ratee replied to a post (refmsgid is set).
     *
     * This prevents frivolous ratings from users who haven't actually interacted.
     *
     */
    public function updateRatingVisibility(string $since = '1 hour ago', bool $dryRun = false): array
    {
        $stats = [
            'processed' => 0,
            'made_visible' => 0,
            'made_hidden' => 0,
        ];

        $cutoff = date('Y-m-d', strtotime($since));

        $ratings = DB::table('ratings')
            ->where('timestamp', '>=', $cutoff)
            ->get();

        foreach ($ratings as $rating) {
            $visible = false;

            $chats = DB::table('chat_rooms')
                ->where(function ($q) use ($rating) {
                    $q->where('user1', $rating->rater)->where('user2', $rating->ratee);
                })
                ->orWhere(function ($q) use ($rating) {
                    $q->where('user2', $rating->rater)->where('user1', $rating->ratee);
                })
                ->pluck('id');

            foreach ($chats as $chatId) {
                // Check if both users have sent messages (excluding system/refmsg-only).
                $distinctUsers = DB::table('chat_messages')
                    ->where('chatid', $chatId)
                    ->whereNull('refmsgid')
                    ->whereNotNull('message')
                    ->distinct()
                    ->count('userid');

                if ($distinctUsers >= 2) {
                    $visible = true;
                    break;
                }

                // Check if ratee replied to a post.
                $replies = DB::table('chat_messages')
                    ->where('chatid', $chatId)
                    ->where('userid', $rating->ratee)
                    ->whereNotNull('refmsgid')
                    ->whereNotNull('message')
                    ->count();

                if ($replies > 0) {
                    $visible = true;
                    break;
                }
            }

            $oldVisible = (bool) $rating->visible;

            if ($visible !== $oldVisible) {
                if (!$dryRun) {
                    DB::table('ratings')
                        ->where('id', $rating->id)
                        ->update([
                            'visible' => $visible,
                            'timestamp' => now(),
                        ]);
                }

                if ($visible) {
                    $stats['made_visible']++;
                } else {
                    $stats['made_hidden']++;
                }
            }

            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Clean up inactive user data for GDPR compliance.
     */
}
