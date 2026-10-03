<?php

namespace App\Services;

use App\Mail\Event\EventsDigestMail;
use App\Models\User;
use App\Services\Concerns\BuildsUserRoundups;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Community-event roundup: one email per opted-in member covering every upcoming
 * event, each shown once. The site is national, so there are no communities to
 * scope events or members by. An event carries no coordinates, so the roundup is
 * not filtered by distance either.
 */
class EventsDigestService
{
    use BuildsUserRoundups;

    /** users_digests.mode value used for the per-user cadence guard. */
    public const DIGEST_MODE = 'events';

    /** Only include events starting within this many days. */
    public const HORIZON_DAYS = 30;

    /**
     * Send community-event roundups.
     *
     * @return array{sent: int, users_processed: int}
     */
    public function sendEventDigests(bool $dryRun = false): array
    {
        $userSite = config('freegle.sites.user');

        $sent = 0;
        $usersProcessed = 0;

        // Pre-fetch all upcoming events and their images once, rather than per user.
        $events = $this->fetchUpcomingEvents($userSite);

        if (empty($events)) {
            return ['sent' => 0, 'users_processed' => 0];
        }

        foreach ($this->eligibleUsers('eventsallowed', self::DIGEST_MODE) as $userRow) {
            $usersProcessed++;

            $user = User::find($userRow->id);
            if (!$user) {
                continue;
            }

            // V1 parity: pick the user's preferred external email; skip if they
            // only have internal-alias addresses.
            $email = $user->email_preferred;
            if (!$email) {
                continue;
            }

            if (!$dryRun) {
                $unsubscribeUrl = "{$userSite}/unsubscribe?email=" . urlencode($email);
                // spool() builds the message (incl. MJML render) up front and only
                // swallows permanent address failures; a transient render/build
                // error re-throws. Catch it so one bad recipient doesn't abort the
                // whole run.
                try {
                    app(EmailSpoolerService::class)->spool(new EventsDigestMail(
                        recipientEmail: $email,
                        events: $events,
                        unsubscribeUrl: $unsubscribeUrl,
                        userId: $user->id,
                    ), $email);
                } catch (\Throwable $e) {
                    Log::warning('Skipping events digest for user after spool failure; continuing loop', [
                        'email' => $email,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            $this->markRoundupSent($user->id, self::DIGEST_MODE, $dryRun);
            $sent++;
        }

        return [
            'sent' => $sent,
            'users_processed' => $usersProcessed,
        ];
    }

    /**
     * Fetch all upcoming events, earliest date first, as display data.
     *
     * @return array<int,array>
     */
    protected function fetchUpcomingEvents(string $userSite): array
    {
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $tusUploader = config('freegle.tus_uploader', 'https://uploads.ilovefreegle.org:8080');
        $deliveryUrl = config('freegle.delivery.base_url');

        $rawEvents = DB::table('communityevents')
            ->join('communityevents_dates', 'communityevents_dates.eventid', '=', 'communityevents.id')
            ->where('communityevents_dates.start', '>=', now())
            ->whereRaw('DATEDIFF(communityevents_dates.start, NOW()) <= ?', [self::HORIZON_DAYS])
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
            ->unique('id'); // earliest upcoming date per event (rows are start-ordered)

        if ($rawEvents->isEmpty()) {
            return [];
        }

        $eventIds = $rawEvents->pluck('id')->all();

        // First non-archived image per event.
        $images = DB::table('communityevents_images')
            ->whereIn('eventid', $eventIds)
            ->where('archived', 0)
            ->orderBy('eventid')
            ->orderBy('id')
            ->get()
            ->groupBy('eventid')
            ->map(fn ($imgs) => $imgs->first());

        // The DB stores HTML-encoded text (e.g. "Soup &amp; Song"). Decode before
        // passing to Blade so {{ }} escaping yields "&amp;" not double-encoded
        // "&amp;amp;".
        $decode = fn (?string $s): ?string =>
            $s !== null ? html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        $events = [];
        foreach ($rawEvents as $event) {
            $start = Carbon::parse($event->start)->setTimezone('Europe/London')->format('D, jS F g:ia');
            $end = ($event->end && $event->end !== '0000-00-00 00:00:00')
                ? Carbon::parse($event->end)->setTimezone('Europe/London')->format('g:ia')
                : null;

            // Build a working image URL. The previous fallback pointed at
            // {userSite}/communityevent/{id}/image/{imgid}, which is not a real route
            // on the user site and 404s in every client. Mirror how volunteering (and
            // V1) serve images: an externally-hosted URL as-is; a TUS upload through
            // the delivery/resize proxy; otherwise the image domain's community-event
            // thumbnail route (cimg_/tcimg_ -> /api/image?id=X&communityevent=1).
            $imageUrl = null;
            if ($images->has($event->id)) {
                $img = $images->get($event->id);
                $mods = $img->externalmods ? json_decode($img->externalmods, true) : [];
                if (!empty($mods['url'])) {
                    $imageUrl = $mods['url'];
                } elseif ($img->externaluid
                    && ($p = strpos($img->externaluid, 'freegletusd-')) !== false) {
                    $fileId = substr($img->externaluid, $p + strlen('freegletusd-'));
                    $source = $tusUploader . '/' . $fileId;
                    $imageUrl = $deliveryUrl
                        ? $deliveryUrl . '?url=' . urlencode($source) . '&w=400'
                        : $source;
                } else {
                    $imageUrl = "{$imagesDomain}/tcimg_{$img->id}.jpg";
                }
            }

            $events[] = [
                'id'           => (int) $event->id,
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
        }

        return $events;
    }
}
