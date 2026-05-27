<?php

namespace App\Services;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\Event\EventsDigestMail;
use App\Services\BulkMail\BulkMjmlCompiler;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                    $imageUrl = $mods['url'] ?? "{$userSite}/communityevent/{$event->id}/image/{$img->id}";
                }

                // The DB stores HTML-encoded text (e.g. "Soup &amp; Song").
                // Decode before passing to templates so Blade's {{ }} escaping
                // produces correct HTML source ("&amp;") rather than the
                // double-encoded "&amp;amp;" that the email client would render
                // as the literal string "&amp;" instead of the intended "&".
                $decode = fn (?string $s): ?string =>
                    $s !== null ? html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

                return [
                    'id'           => $event->id,
                    'title'        => $decode($event->title),
                    'location'     => $decode($event->location),
                    'description'  => $decode($event->description),
                    'contactname'  => $decode($event->contactname),
                    'contactphone' => $event->contactphone,
                    'contactemail' => $event->contactemail,
                    'contacturl'   => $event->contacturl,
                    'start'        => $start,
                    'end'          => $end,
                    'imageUrl'     => $imageUrl,
                    'url'          => "{$userSite}/communityevent/{$event->id}",
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
                ->select(['users.id as userId'])
                ->join('memberships', 'memberships.userid', '=', 'users.id')
                ->where('memberships.groupid', $groupRow->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.eventsallowed', 1)
                ->where('memberships.emailfrequency', '!=', 0)
                ->receivingOurMails()
                ->get();

            // One BulkMjmlCompiler per group: all members of this group share
            // events + groupName, so one MJML compile feeds every member's
            // substitution.
            $bulkEnabled = (bool) config('freegle.bulk_mail.enabled', true);
            $bulkCompiler = $bulkEnabled ? app(BulkMjmlCompiler::class) : null;

            foreach ($members as $member) {
                // V1 parity: pick the user's preferred external email and skip
                // when they have none (only internal-alias addresses).
                $email = User::find($member->userId)?->email_preferred;
                if (!$email) {
                    continue;
                }

                if (!$dryRun) {
                    $unsubscribeUrl = "{$userSite}/unsubscribe?email=" . urlencode($email);
                    $mailable = new EventsDigestMail(
                        recipientEmail: $email,
                        groupName: $groupRow->nameshort,
                        events: $eventData,
                        unsubscribeUrl: $unsubscribeUrl,
                        userId: $member->userId,
                    );

                    if ($bulkCompiler !== null && $mailable instanceof BulkRenderable) {
                        $mailable->setPrerenderedHtml($bulkCompiler->htmlFor($mailable));
                    }

                    // spool() builds the message (incl. MJML render) up front and
                    // only swallows permanent address failures internally; a
                    // transient render/build error re-throws. Without this catch
                    // it would escape the per-member foreach and abort the whole
                    // digest run. Skip the one recipient and keep the loop going.
                    try {
                        app(\App\Services\EmailSpoolerService::class)->spool($mailable, $email);
                    } catch (\Throwable $e) {
                        Log::warning('Skipping events digest for member after spool failure; continuing loop', [
                            'email' => $email,
                            'user_id' => $member->userId,
                            'group' => $groupRow->nameshort,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
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
