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
 * - Mark the entry as processed (processingrequired = 0).
 *
 * Note: Full spam check (Spam::checkUser) is not implemented here.
 * The users:remove-spammers command handles spam detection separately.
 */
class MembershipsProcessingService
{
    /**
     * Process all pending membership history entries.
     *
     * @return int Number of entries processed.
     */
    public function processAll(): int
    {
        $entries = DB::table('memberships_history')
            ->where('processingrequired', 1)
            ->orderBy('id', 'asc')
            ->get();

        $count = 0;

        foreach ($entries as $entry) {
            $this->processEntry($entry);
            $count++;
        }

        Log::info("MembershipsProcessing: processed {$count} entries");

        return $count;
    }

    private function processEntry(object $entry): void
    {
        $userId = $entry->userid;
        $groupId = $entry->groupid;
        $collection = $entry->collection;

        // Send per-group welcome email for newly approved members on groups that have one.
        if ($collection === 'Approved') {
            $group = Group::find($groupId);

            if ($group && $group->onhere && !empty($group->welcomemail)) {
                $user = User::find($userId);

                if ($user && $user->email_preferred) {
                    try {
                        Mail::send(new GroupWelcomeMail($user, $group));
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
            }

            // Check for flagged mod comments not yet covered by a review.
            // If found, flag the member for review on this group.
            $this->checkFlaggedComments($userId, $groupId);
        }

        DB::table('memberships_history')
            ->where('id', $entry->id)
            ->update(['processingrequired' => 0]);
    }

    /**
     * Check if there are flagged comments about this user that post-date the last review.
     * If so, flag the membership for review.
     *
     * Mirrors V1: User::processMembership() flagged-comment check.
     */
    private function checkFlaggedComments(int $userId, int $groupId): void
    {
        $count = DB::table('users_comments as uc')
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

        if ($count > 0) {
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
