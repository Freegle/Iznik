<?php

namespace App\Services;

use App\Mail\Welcome\GroupWelcomeMail;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Process pending membership history entries (processingrequired = 1).
 *
 * Mirrors V1 cron/memberships_processing.php + User::processMemberships().
 *
 * For each new approved membership:
 * - Send per-group welcome email if the group has one configured.
 * - Flag member for review if there are flagged mod comments about them.
 * - Flag member for review if they have been seen on many groups (V1
 *   Spam::checkUser "seen on many groups" branch).
 * - Mark the entry as processed (processingrequired = 0).
 *
 * Note: the reply-distance branch of Spam::checkUser (suspect because they
 * reply far from home) is not restored here — that fired on chat/reply, not
 * on join, and belongs in the chat/reply path.
 */
class MembershipsProcessingService
{
    public function processAll(bool $dryRun = false): int
    {
        $entries = DB::table('memberships_history')
            ->where('processingrequired', 1)
            ->orderBy('id', 'asc')
            ->get();

        $count = 0;

        foreach ($entries as $entry) {
            $this->processEntry($entry, $dryRun);
            $count++;
        }

        if ($dryRun) {
            Log::info('MembershipsProcessing: dry run complete', ['would_process' => $count]);
        } else {
            Log::info("MembershipsProcessing: processed {$count} entries");
        }

        return $count;
    }

    private function processEntry(object $entry, bool $dryRun): void
    {
        $userId = $entry->userid;
        $groupId = $entry->groupid;
        $collection = $entry->collection;

        if ($collection === 'Approved') {
            $group = Group::find($groupId);
            $hasWelcome = $group && $group->onhere && !empty($group->welcomemail);
            $user = $hasWelcome ? User::find($userId) : null;
            $wouldSendWelcome = $hasWelcome && $user && $user->email_preferred;

            $flaggedCount = $this->countFlaggedComments($userId, $groupId);

            if ($dryRun) {
                Log::info('MembershipsProcessing: dry run entry', [
                    'entry_id'           => $entry->id,
                    'user'               => $userId,
                    'group'              => $groupId,
                    'would_send_welcome' => $wouldSendWelcome,
                    'would_flag_review'  => $flaggedCount > 0,
                ]);
                return;
            }

            if ($wouldSendWelcome) {
                try {
                    app(\App\Services\EmailSpoolerService::class)->spool(new GroupWelcomeMail($user, $group));
                    Log::info("MembershipsProcessing: sent group welcome", [
                        'user' => $userId,
                        'group' => $groupId,
                    ]);
                } catch (\Throwable $e) {
                    Log::error("MembershipsProcessing: group welcome failed", [
                        'user' => $userId,
                        'group' => $groupId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->applyFlaggedComments($userId, $groupId);
            $this->checkSeenOnManyGroups($userId, $groupId);
        }

        if (!$dryRun) {
            DB::table('memberships_history')
                ->where('id', $entry->id)
                ->update(['processingrequired' => 0]);
        }
    }

    /**
     * V1 parity: Spam::checkUser() "seen on many groups" branch
     * (iznik-server/include/spam/Spam.php ~534-564). A member who has joined
     * more than SEEN_THRESHOLD groups in the last year is "suspect" and flagged
     * for moderator review on ALL their groups.
     *
     * This flagging was lost when the join flow moved to the Go API + this cron:
     * Go AddMembership() explicitly delegates "spam check, and member review" to
     * memberships_processing, but only the flagged-comments check was ported.
     * The result was that the members-flagged-for-review queue dried up to ~0.
     */
    private function checkSeenOnManyGroups(int $userId, int $groupJustAdded): void
    {
        // Distinct groups joined/applied to in a year before a member is "suspect" and flagged.
        // Relaxed 16 -> 35 for rippling-out: a post's reach now follows the poster's declared
        // location + drive-time isochrone, not their group-membership count, so joining many groups
        // is no longer a reach-amplification signal - it is mostly power users with many local
        // groups. At 16 this became a false-positive factory for them; 35 still guards against
        // abnormal join sprees. This WEAKENS detection and is only safe once rippling is live.
        // (This is the live equivalent of V1 Spam::SEEN_THRESHOLD, which is obsolete.)
        $threshold = 35;

        $user = User::find($userId);
        if (!$user) {
            return;
        }

        // Whitelist mods/staff, exactly as V1 ($u->isModerator()).
        if ($user->isModerator()
            || in_array($user->systemrole, ['Moderator', 'Support', 'Admin'], true)) {
            return;
        }

        // Count distinct groups joined in the last year, excluding the
        // just-added group (counted separately below, as it may race the log
        // write) and excluding whitelisted spammer records.
        $start = now()->subDays(365)->format('Y-m-d');
        $count = DB::table('logs')
            ->leftJoin('spam_users', function ($j) {
                $j->on('spam_users.userid', '=', 'logs.user')
                    ->where('spam_users.collection', '=', 'Whitelisted');
            })
            ->where('logs.user', $userId)
            ->where('logs.type', 'Group')
            ->where('logs.subtype', 'Joined')
            ->where('logs.groupid', '!=', $groupJustAdded)
            ->whereNull('spam_users.userid')
            ->where('logs.timestamp', '>=', $start)
            ->distinct()
            ->count('logs.groupid');

        $count++; // the just-added group, excluded from the query above

        if ($count <= $threshold) {
            return;
        }

        // Suspect. Record it and flag the member for review on all their groups,
        // exactly as V1 Spam::checkUser did via User::memberReview().
        DB::table('logs')->insert([
            'type'    => 'User',
            'subtype' => 'Suspect',
            'user'    => $userId,
            'text'    => 'Seen on many groups',
        ]);

        DB::table('memberships')
            ->where('userid', $userId)
            ->update([
                'reviewrequestedat' => now(),
                'reviewreason'      => 'Seen on many groups',
            ]);

        Log::info('MembershipsProcessing: flagged member for review - seen on many groups', [
            'user'        => $userId,
            'group_count' => $count,
        ]);
    }

    private function countFlaggedComments(int $userId, int $groupId): int
    {
        return DB::table('users_comments as uc')
            ->where('uc.userid', $userId)
            ->where('uc.flag', 1)
            ->whereNotExists(function ($q) use ($userId, $groupId) {
                $q->select(DB::raw(1))
                    ->from('memberships as m')
                    ->where('m.userid', $userId)
                    ->where('m.groupid', $groupId)
                    ->whereNotNull('m.reviewedat')
                    ->whereColumn('m.reviewedat', '>=', 'uc.date');
            })
            ->count();
    }

    private function applyFlaggedComments(int $userId, int $groupId): void
    {
        if ($this->countFlaggedComments($userId, $groupId) > 0) {
            DB::table('memberships')
                ->where('userid', $userId)
                ->where('groupid', $groupId)
                ->update([
                    'reviewrequestedat' => now(),
                    'reviewreason' => 'Note flagged to other groups',
                ]);

            Log::info("MembershipsProcessing: flagged member for review due to mod comments", [
                'user' => $userId,
                'group' => $groupId,
            ]);
        }
    }
}
