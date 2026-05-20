<?php

namespace App\Services;

use App\Mail\Event\EventsDigestMail;
use App\Support\SafeMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
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

            // Find upcoming community events for this group (starting within the next 30 days).
            $rawEvents = DB::table('communityevents')
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
                    'communityevents.description',
                    'communityevents.contactname',
                    'communityevents.contactphone',
                    'communityevents.contactemail',
                    'communityevents.contacturl',
                    'communityevents_dates.start',
                    'communityevents_dates.end',
                ])
                ->get()
                ->unique('id');

            if ($rawEvents->isEmpty()) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lasteventsroundup' => now()]);
                }
                continue;
            }

            // Batch-fetch first non-archived image per event.
            $eventIds = $rawEvents->pluck('id')->all();
            $images = DB::table('communityevents_images')
                ->whereIn('eventid', $eventIds)
                ->where('archived', 0)
                ->orderBy('eventid')
                ->orderBy('id')
                ->get()
                ->groupBy('eventid')
                ->map(fn ($imgs) => $imgs->first());

            // Build structured event data for the template.
            $eventData = $rawEvents->map(function ($event) use ($userSite, $images) {
                $start = Carbon::parse($event->start)->setTimezone('Europe/London')->format('D, jS F g:ia');
                $end   = ($event->end && $event->end !== '0000-00-00 00:00:00')
                    ? Carbon::parse($event->end)->setTimezone('Europe/London')->format('g:ia')
                    : null;

                $imageUrl = null;
                if ($images->has($event->id)) {
                    $img  = $images->get($event->id);
                    $mods = $img->externalmods ? json_decode($img->externalmods, true) : [];
                    $imageUrl = $mods['url'] ?? "https://{$userSite}/communityevent/{$event->id}/image/{$img->id}";
                }

                return [
                    'id'           => $event->id,
                    'title'        => $event->title,
                    'location'     => $event->location,
                    'description'  => $event->description,
                    'contactname'  => $event->contactname,
                    'contactphone' => $event->contactphone,
                    'contactemail' => $event->contactemail,
                    'contacturl'   => $event->contacturl,
                    'start'        => $start,
                    'end'          => $end,
                    'imageUrl'     => $imageUrl,
                    'url'          => "https://{$userSite}/communityevent/{$event->id}",
                ];
            })->values()->all();

            $groupsProcessed++;

            // Find members who have events enabled and are not opted out.
            // Activity / holiday / bouncing / simplemail filters live on
            // User::scopeReceivingOurMails so events + volunteering digests +
            // any future bulk-mail batch job share the same definition of
            // "deliverable" — V1 events.php enforced these via sendOurMails()
            // per recipient, and skipping them inflated the V2 dry-run from
            // ~49k (V1 baseline) to 722k sends.
            $members = User::query()
                ->select(['users_emails.email', 'users.id as userId'])
                ->join('memberships', 'memberships.userid', '=', 'users.id')
                ->join('users_emails', function ($join) {
                    $join->on('users_emails.userid', '=', 'memberships.userid')
                        ->where('users_emails.preferred', '=', 1);
                })
                ->where('memberships.groupid', $groupRow->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.eventsallowed', 1)
                ->where('memberships.emailfrequency', '!=', 0)
                ->whereNotNull('users_emails.email')
                ->receivingOurMails()
                ->get();

            foreach ($members as $member) {
                if (!$dryRun) {
                    $unsubscribeUrl = "https://{$userSite}/unsubscribe?email=" . urlencode($member->email);
                    // SafeMail catches permanent (bounce + skip) and transient
                    // (mail-host hiccup mid-run) SMTP failures so one bad address
                    // or one closed-connection doesn't crash the rest of the
                    // ~94k-recipient run. The mailable's own envelope() sets the
                    // to: address, so use sendMailable() (Mail::send-style) not
                    // send() (which would Mail::to(...) and duplicate the
                    // recipient).
                    SafeMail::sendMailable(
                        new EventsDigestMail(
                            recipientEmail: $member->email,
                            groupName: $groupRow->nameshort,
                            events: $eventData,
                            unsubscribeUrl: $unsubscribeUrl,
                            userId: $member->userId,
                        ),
                        $member->email,
                    );
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
