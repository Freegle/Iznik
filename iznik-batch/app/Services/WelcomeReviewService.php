<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class WelcomeReviewService
{
    // Max groups to process per run — matches V1 $limit=10
    public const BATCH_LIMIT = 10;

    // Days between annual review sends
    public const REVIEW_INTERVAL_DAYS = 365;

    /**
     * Send a copy of each group's welcome mail to mods for annual review.
     *
     * @return array{sent: int, groups_processed: int}
     */
    public function sendWelcomeReviews(bool $dryRun = false): array
    {
        $modSite = config('freegle.sites.mod');
        $supportAddr = config('freegle.mail.support_addr');

        $groups = DB::table('groups')
            ->whereNotNull('welcomemail')
            ->where(function ($q) {
                $q->whereNull('welcomereview')
                    ->orWhereRaw('DATEDIFF(NOW(), welcomereview) >= ?', [self::REVIEW_INTERVAL_DAYS]);
            })
            ->limit(self::BATCH_LIMIT)
            ->select(['id', 'nameshort', 'namefull', 'welcomemail'])
            ->get();

        $sent = 0;
        $groupsProcessed = 0;

        foreach ($groups as $group) {
            $groupName = $group->namefull ?: $group->nameshort;

            // Get all Owner/Moderator members who have not opted out of emails
            $mods = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->where('memberships.groupid', $group->id)
                ->whereIn('memberships.role', [Membership::ROLE_OWNER, Membership::ROLE_MODERATOR])
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.emailfrequency', '!=', 0)
                ->whereNull('users.deleted')
                ->pluck('memberships.userid')
                ->toArray();

            $groupsProcessed++;

            foreach ($mods as $modId) {
                $email = DB::table('users_emails')
                    ->where('userid', $modId)
                    ->orderByDesc('preferred')
                    ->value('email');

                if (!$email) {
                    continue;
                }

                $subject = "Please review: Welcome to {$groupName}";

                $body = "This is the welcome mail that gets sent to members who join {$groupName}.\r\n\r\n"
                    . "We send you this once a year so you can check it's up-to-date. If you'd like to edit it, "
                    . "you can do so at {$modSite}/modtools/settings/.\r\n\r\n"
                    . "---\r\n\r\n"
                    . $group->welcomemail . "\r\n\r\n"
                    . "---\r\n\r\n"
                    . "Freegle";

                $sent++;

                if (!$dryRun) {
                    Mail::raw($body, function ($message) use ($subject, $supportAddr, $email, $groupName) {
                        $message->from($supportAddr, "{$groupName} Volunteers")
                            ->to($email)
                            ->subject($subject);
                    });
                }
            }

            if (!$dryRun) {
                DB::table('groups')
                    ->where('id', $group->id)
                    ->update(['welcomereview' => now()]);
            }
        }

        return ['sent' => $sent, 'groups_processed' => $groupsProcessed];
    }
}
