<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Models\Message;
use App\Models\User;
use App\Models\UserEmail;
use App\Traits\ChunkedProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserManagementService
{
    use ChunkedProcessing;

    /**
     * Chunk size for batch operations.
     */
    protected int $chunkSize = 1000;

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
     * Update user kudos based on their activity.
     *
     * Selects users with lastaccess > 2 days ago, calculates kudos per user,
     * and writes to users_kudos table via REPLACE INTO.
     */
    public function updateKudos(bool $dryRun = FALSE): int
    {
        $updated = 0;

        $mysqltime = now()->subDays(2)->startOfDay()->toDateString();

        $users = DB::table('users')
            ->select('id')
            ->where('lastaccess', '>', $mysqltime)
            ->get();

        $total = $users->count();

        foreach ($users as $userData) {
            $this->updateKudosForUser($userData->id, $dryRun);
            $updated++;

            if ($updated % 10 === 0) {
                Log::info("Kudos update progress: {$updated} / {$total}");
            }
        }

        return $updated;
    }

    /**
     * Update kudos for a single user.
     *
     * - No existing kudos record, OR
     * - Existing record is more than 24 hours old.
     *
     * Writes to users_kudos table with columns: userid, kudos, posts, chats,
     * newsfeed, events, vols, facebook, platform.
     */
    public function updateKudosForUser(int $userId, bool $dryRun = FALSE): void
    {
        // Check throttle: only update if no existing record or record is older than 24h.
        $current = DB::table('users_kudos')->where('userid', $userId)->first();

        if ($current && $current->timestamp) {
            $age = now()->diffInSeconds(\Carbon\Carbon::parse($current->timestamp));
            if ($age <= 24 * 60 * 60) {
                return;
            }
        }

        $kudosData = $this->calculateKudos($userId);

        $kudos = $kudosData['posts'] + $kudosData['chats'] + $kudosData['newsfeed']
            + $kudosData['events'] + $kudosData['vols'];

        if ($kudos > 0) {
            // No sense in creating entries which are blank or the same.
            $currentKudos = $current ? $current->kudos : 0;

            if ($currentKudos != $kudos) {
                if (!$dryRun) {
                    DB::statement(
                        'REPLACE INTO users_kudos (userid, kudos, posts, chats, newsfeed, events, vols, facebook, platform) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $userId,
                            $kudos,
                            $kudosData['posts'],
                            $kudosData['chats'],
                            $kudosData['newsfeed'],
                            $kudosData['events'],
                            $kudosData['vols'],
                            $kudosData['facebook'] ? 1 : 0,
                            $kudosData['platform'] ? 1 : 0,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Calculate kudos components for a user.
     *
     * - Distinct months with posts (messages table, last 365 days)
     * - Distinct months with chats (chat_messages table, last 365 days)
     * - Distinct months with newsfeed posts (newsfeed table, last 365 days)
     * - Community events count (last 365 days)
     * - Volunteering count (last 365 days)
     * - Facebook login (boolean)
     * - Platform posting (boolean, sourceheader = 'Platform', last 365 days)
     */
    protected function calculateKudos(int $userId): array
    {
        $start = now()->subDays(365)->toDateString();

        // Distinct months with posts.
        $posts = (int) DB::table('messages')
            ->where('fromuser', $userId)
            ->where('date', '>=', $start)
            ->selectRaw("COUNT(DISTINCT CONCAT(YEAR(date), '-', MONTH(date))) AS count")
            ->value('count');

        // Distinct months with chat messages.
        $chats = (int) DB::table('chat_messages')
            ->where('userid', $userId)
            ->where('date', '>=', $start)
            ->selectRaw("COUNT(DISTINCT CONCAT(YEAR(date), '-', MONTH(date))) AS count")
            ->value('count');

        // Distinct months with newsfeed posts.
        $newsfeed = (int) DB::table('newsfeed')
            ->where('userid', $userId)
            ->where('added', '>=', $start)
            ->selectRaw("COUNT(DISTINCT CONCAT(YEAR(timestamp), '-', MONTH(timestamp))) AS count")
            ->value('count');

        // Community events count.
        $events = (int) DB::table('communityevents')
            ->where('userid', $userId)
            ->where('added', '>=', $start)
            ->count();

        // Volunteering count.
        $vols = (int) DB::table('volunteering')
            ->where('userid', $userId)
            ->where('added', '>=', $start)
            ->count();

        // Facebook login.
        $facebook = DB::table('users_logins')
            ->where('userid', $userId)
            ->where('type', 'Facebook')
            ->count() > 0;

        // Posted from the platform (vs email/TN).
        $platform = DB::table('messages')
            ->where('fromuser', $userId)
            ->where('arrival', '>=', $start)
            ->where('sourceheader', 'Platform')
            ->count() > 0;

        return [
            'posts' => $posts,
            'chats' => $chats,
            'newsfeed' => $newsfeed,
            'events' => $events,
            'vols' => $vols,
            'facebook' => $facebook,
            'platform' => $platform,
        ];
    }

    /**
     * Clean up inactive and deleted users.
     *
     * Steps:
     *   1. Delete legacy Yahoo Groups users
     *   2. Forget inactive users (no memberships, no activity in 6 months, no logs in 90 days)
     *   3. Process GDPR forgets (users deleted > 14 days ago)
     *   4. Hard-delete fully forgotten users with no remaining messages
     *
     * @param  bool  $dryRun  If true, count what would be affected but don't modify data.
     */
    public function cleanupUsers(bool $dryRun = FALSE): array
    {
        $stats = [
            'yahoo_users_deleted' => 0,
            'inactive_users_forgotten' => 0,
            'gdpr_forgets_processed' => 0,
            'forgotten_users_deleted' => 0,
        ];

        $stats['yahoo_users_deleted'] = $this->deleteYahooGroupsUsers($dryRun);
        $stats['inactive_users_forgotten'] = $this->forgetInactiveUsers($dryRun);
        $stats['gdpr_forgets_processed'] = $this->processForgets($dryRun);
        $stats['forgotten_users_deleted'] = $this->deleteFullyForgottenUsers($dryRun);

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
    public function forgetInactiveUsers(bool $dryRun = FALSE): int
    {
        $sixMonthsAgo = now()->subMonths(6)->format('Y-m-d');

        // Find candidates: no memberships, no spammer record, no mod notes,
        // last access > 6 months, systemrole = User, not deleted.
        $candidates = DB::select("
            SELECT users.id
            FROM users
            LEFT JOIN memberships ON users.id = memberships.userid
            LEFT JOIN spam_users ON users.id = spam_users.userid
            LEFT JOIN users_comments ON users.id = users_comments.userid
            WHERE memberships.userid IS NULL
              AND spam_users.userid IS NULL
              AND users_comments.userid IS NULL
              AND users.lastaccess < ?
              AND users.systemrole = ?
              AND users.deleted IS NULL
              AND users.forgotten IS NULL
        ", [$sixMonthsAgo, 'User']);

        $count = 0;

        foreach ($candidates as $candidate) {
            // Check for recent meaningful logs (excluding User/Created and User/Deleted).
            $logs = DB::select("
                SELECT DATEDIFF(NOW(), timestamp) AS logsago
                FROM logs
                WHERE user = ?
                  AND (type != 'User' OR (subtype != 'Created' AND subtype != 'Deleted'))
                ORDER BY id DESC
                LIMIT 1
            ", [$candidate->id]);

            // Forget if no logs at all, or most recent meaningful log is > 90 days old.
            if (count($logs) === 0 || $logs[0]->logsago > 90) {
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
    public function processForgets(bool $dryRun = FALSE): int
    {
        $users = DB::select("
            SELECT id
            FROM users
            WHERE deleted IS NOT NULL
              AND DATEDIFF(NOW(), deleted) > 14
              AND forgotten IS NULL
        ");

        $count = count($users);

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
    }

    /**
     * Delete fully forgotten users who have no remaining messages.
     *
     * These users have been forgotten (personal data wiped) and have no messages
     * left as a placeholder — they can be safely hard-deleted.
     *
     */
    public function deleteFullyForgottenUsers(bool $dryRun = FALSE): int
    {
        $sixMonthsAgo = now()->subMonths(6)->format('Y-m-d');

        $users = DB::select("
            SELECT users.id
            FROM users
            LEFT JOIN messages ON messages.fromuser = users.id
            WHERE users.forgotten IS NOT NULL
              AND users.lastaccess < ?
              AND messages.id IS NULL
            LIMIT 100000
        ", [$sixMonthsAgo]);

        $count = count($users);

        if (!$dryRun) {
            $processed = 0;
            foreach ($users as $user) {
                // Remove memberships first (matches V1 User::delete()).
                DB::table('memberships')->where('userid', $user->id)->delete();

                // Hard delete the user.
                DB::table('users')->where('id', $user->id)->delete();

                $processed++;
                if ($processed % 1000 === 0) {
                    Log::info("Deleted {$processed} / {$count} fully forgotten users");
                }
            }
        }

        return $count;
    }

    /**
     * Fallback update of user lastaccess timestamps.
     *
     * Finds users whose lastaccess is more than 10 minutes behind their latest
     * chat message or membership join, and updates accordingly.
     *
     */
    public function updateLastAccess(bool $dryRun = false): array
    {
        $stats = [
            'candidates' => 0,
            'updated' => 0,
        ];

        // Find users whose lastaccess is > 600 seconds behind their latest chat message or membership join.
        $users = DB::select("
            SELECT DISTINCT(userid) FROM (
                SELECT DISTINCT(userid) FROM users
                INNER JOIN chat_messages ON chat_messages.userid = users.id
                WHERE users.lastaccess < chat_messages.date
                    AND TIMESTAMPDIFF(SECOND, users.lastaccess, chat_messages.date) > 600
                UNION
                SELECT DISTINCT(userid) FROM memberships
                INNER JOIN users ON users.id = memberships.userid
                WHERE TIMESTAMPDIFF(SECOND, users.lastaccess, memberships.added) > 600
            ) t
        ");

        $stats['candidates'] = count($users);
        $processed = 0;

        foreach ($users as $user) {
            // Find the latest activity timestamp from chat messages or memberships.
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

        return $stats;
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
