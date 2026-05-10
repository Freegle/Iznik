<?php

namespace App\Services;

use App\Mail\Event\EventsDigestMail;
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

            // Build structured event data for the template.
            $eventData = $events->map(function ($event) use ($userSite) {
                $start = Carbon::parse($event->start)->setTimezone('Europe/London')->format('D, jS F g:ia');
                $end   = Carbon::parse($event->end)->setTimezone('Europe/London')->format('g:ia');
                return [
                    'id'       => $event->id,
                    'title'    => $event->title,
                    'location' => $event->location,
                    'start'    => $start,
                    'end'      => $end,
                    'url'      => "https://{$userSite}/communityevent/{$event->id}",
                ];
            })->values()->all();

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
                ->select(['users_emails.email', 'users.id as userId'])
                ->get();

            foreach ($members as $member) {
                if (!$dryRun) {
                    $unsubscribeUrl = "https://{$userSite}/unsubscribe?email=" . urlencode($member->email);
                    Mail::send(new EventsDigestMail(
                        recipientEmail: $member->email,
                        groupName: $groupRow->nameshort,
                        events: $eventData,
                        unsubscribeUrl: $unsubscribeUrl,
                    ));
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
