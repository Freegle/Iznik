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
        }

        if (!$dryRun) {
            DB::table('memberships_history')
                ->where('id', $entry->id)
                ->update(['processingrequired' => 0]);
        }
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
