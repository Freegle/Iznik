<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class EventsDigestService
{
    // Minimum days between roundups per group (V1: DATEDIFF >= 3)
    public const MIN_INTERVAL_DAYS = 3;

    /**
     * Send community event roundup emails to members of all eligible groups.
     *
     * @return array{sent: int, groups_processed: int}
     */
    public function sendEventDigests(bool $dryRun = false): array
    {
        $noReplyAddr = config('freegle.mail.noreply_addr');
        $userSite = config('freegle.sites.user');

        $sent = 0;
        $groupsProcessed = 0;

        // Find Freegle groups due for an event roundup (not sent in last 3 days).
        $groups = DB::table('groups')
            ->where('type', Group::TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->where(function ($q) {
                $q->whereNull('lasteventsroundup')
                    ->orWhereRaw('DATEDIFF(NOW(), lasteventsroundup) >= ?', [self::MIN_INTERVAL_DAYS]);
            })
            ->whereRaw("nameshort NOT LIKE '%playground%'")
            ->select(['id', 'nameshort', 'settings'])
            ->get();

        foreach ($groups as $groupRow) {
            // Instantiate the Group model to use getSetting().
            $group = Group::find($groupRow->id);
            if (!$group) {
                continue;
            }

            // Skip groups where communityevents setting is disabled (default: enabled).
            if (!$group->getSetting('communityevents', true)) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lasteventsroundup' => now()]);
                }
                continue;
            }

            // Skip closed groups.
            if ($group->isClosed()) {
                continue;
            }

            // Find upcoming events for this group (starting within the next 30 days).
            $events = DB::table('communityevents')
                ->join('communityevents_groups', function ($join) use ($groupRow) {
                    $join->on('communityevents_groups.eventid', '=', 'communityevents.id')
                        ->where('communityevents_groups.groupid', '=', $groupRow->id);
                })
                ->join('communityevents_dates', 'communityevents_dates.eventid', '=', 'communityevents.id')
                ->where('communityevents_dates.start', '>=', now())
                ->whereRaw('DATEDIFF(communityevents_dates.start, NOW()) <= 30')
                ->where('communityevents.pending', 0)
                ->where('communityevents.deleted', 0)
                ->orderBy('communityevents_dates.start')
                ->select([
                    'communityevents.id',
                    'communityevents.title',
                    'communityevents.location',
                    'communityevents_dates.start',
                    'communityevents_dates.end',
                ])
                ->get()
                ->unique('id');

            if ($events->isEmpty()) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lasteventsroundup' => now()]);
                }
                continue;
            }

            $groupsProcessed++;

            // Build plain-text summary.
            $textSummary = "Here are upcoming Community Events for {$groupRow->nameshort}.\r\n\r\n";
            foreach ($events as $event) {
                $start = Carbon::parse($event->start)->setTimezone('Europe/London')->format('D, jS F g:ia');
                $textSummary .= "{$event->title} — {$start} at {$event->location}\r\n";
                $textSummary .= "https://{$userSite}/communityevent/{$event->id}\r\n\r\n";
            }
            $textSummary .= "View all events: https://{$userSite}/communityevents\r\n";
            $textSummary .= "Change settings: https://{$userSite}/settings\r\n";

            $subject = "[{$groupRow->nameshort}] Community Event Roundup";

            // Find members who have events enabled and are not opted out.
            $members = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->join('users_emails', function ($join) {
                    $join->on('users_emails.userid', '=', 'memberships.userid')
                        ->where('users_emails.preferred', '=', 1);
                })
                ->where('memberships.groupid', $groupRow->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.eventsallowed', 1)
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
                    ->update(['lasteventsroundup' => now()]);
            }
        }

        return ['sent' => $sent, 'groups_processed' => $groupsProcessed];
    }
}
