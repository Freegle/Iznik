<?php

namespace App\Services;

use App\Mail\Group\WelcomeReviewMail;
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
                // V1 parity: skip our own per-user-alias domains so the mail can't loop back as chat.
                $email = \App\Models\User::find($modId)?->email_preferred;

                if (!$email) {
                    continue;
                }

                $sent++;

                if (!$dryRun) {
                    app(\App\Services\EmailSpoolerService::class)->spool(new WelcomeReviewMail(
                        recipientEmail: $email,
                        groupName: $groupName,
                        welcomeContent: $group->welcomemail,
                    ));
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
