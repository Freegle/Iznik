<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class VolunteeringDigestService
{
    // Minimum days between roundups per group (V1: DATEDIFF >= 3)
    public const MIN_INTERVAL_DAYS = 3;

    /**
     * Send volunteering opportunity roundup emails to members of all eligible groups.
     *
     * V1: VolunteeringDigest::send() called for each group in volunteering.php
     *
     * @return array{sent: int, groups_processed: int}
     */
    public function sendVolunteeringDigests(bool $dryRun = false): array
    {
        $noReplyAddr = config('freegle.mail.noreply_addr');
        $userSite = config('freegle.sites.user');

        $sent = 0;
        $groupsProcessed = 0;

        // Find Freegle groups due for a volunteering roundup (not sent in last 3 days).
        $groups = DB::table('groups')
            ->where('type', Group::TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->where(function ($q) {
                $q->whereNull('lastvolunteeringroundup')
                    ->orWhereRaw('DATEDIFF(NOW(), lastvolunteeringroundup) >= ?', [self::MIN_INTERVAL_DAYS]);
            })
            ->whereRaw("nameshort NOT LIKE '%playground%'")
            ->select(['id', 'nameshort', 'settings'])
            ->get();

        foreach ($groups as $groupRow) {
            $group = Group::find($groupRow->id);
            if (!$group) {
                continue;
            }

            // Skip groups where volunteering setting is disabled (default: enabled).
            if (!$group->getSetting('volunteering', true)) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lastvolunteeringroundup' => now()]);
                }
                continue;
            }

            // Skip closed groups.
            if ($group->isClosed()) {
                continue;
            }

            // Find active volunteering opportunities for this group (or with no specific group).
            // V1: includes volunteerings with no group assigned (global opportunities).
            $volunteerings = DB::table('volunteering')
                ->leftJoin('volunteering_groups', 'volunteering_groups.volunteeringid', '=', 'volunteering.id')
                ->where('volunteering.pending', 0)
                ->where('volunteering.deleted', 0)
                ->where('volunteering.expired', 0)
                ->where(function ($q) use ($groupRow) {
                    $q->whereNull('volunteering_groups.groupid')
                        ->orWhere('volunteering_groups.groupid', '=', $groupRow->id);
                })
                ->select([
                    'volunteering.id',
                    'volunteering.title',
                    'volunteering.location',
                    'volunteering.description',
                ])
                ->get()
                ->unique('id');

            if ($volunteerings->isEmpty()) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lastvolunteeringroundup' => now()]);
                }
                continue;
            }

            $groupsProcessed++;

            // Build plain-text summary.
            $textSummary = "Here are volunteering opportunities for {$groupRow->nameshort}.\r\n\r\n";
            foreach ($volunteerings as $vol) {
                $textSummary .= "{$vol->title} — {$vol->location}\r\n";
                $textSummary .= "https://{$userSite}/volunteering/{$vol->id}\r\n\r\n";
            }
            $textSummary .= "View all volunteering: https://{$userSite}/volunteering\r\n";
            $textSummary .= "Change settings: https://{$userSite}/settings\r\n";

            $subject = "[{$groupRow->nameshort}] Volunteer Opportunity Roundup";

            // Find members who have volunteering enabled and are not opted out.
            $members = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->join('users_emails', function ($join) {
                    $join->on('users_emails.userid', '=', 'memberships.userid')
                        ->where('users_emails.preferred', '=', 1);
                })
                ->where('memberships.groupid', $groupRow->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.volunteeringallowed', 1)
                ->where('memberships.emailfrequency', '!=', 0)
                ->whereNull('users.deleted')
                ->whereNotNull('users_emails.email')
                ->select(['users_emails.email', 'users.fullname'])
                ->get();

            foreach ($members as $member) {
                if (!$dryRun) {
                    Mail::raw($textSummary, function ($message) use (
                        $subject, $noReplyAddr, $groupRow, $member
                    ) {
                        $message->from($noReplyAddr, $groupRow->nameshort)
                            ->to($member->email, $member->fullname ?: null)
                            ->subject($subject);
                    });
                }
                $sent++;
            }

            if (!$dryRun) {
                DB::table('groups')->where('id', $groupRow->id)
                    ->update(['lastvolunteeringroundup' => now()]);
            }
        }

        return ['sent' => $sent, 'groups_processed' => $groupsProcessed];
    }
}
