<?php

namespace App\Services;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\User;
use App\Services\Concerns\BuildsUserRoundups;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Volunteering-opportunity roundup: one email per opted-in member covering the
 * active opportunities whose reach covers them, nearest first, then the online ones,
 * which reach everyone. There are no communities to scope opportunities by; each is
 * shown around its own centre instead (see itemsReaching).
 */
class VolunteeringDigestService
{
    use BuildsUserRoundups;

    /** users_digests.mode value used for the per-user cadence guard. */
    public const DIGEST_MODE = 'volunteering';

    /**
     * Send volunteering-opportunity roundups.
     *
     * @return array{sent: int, users_processed: int}
     */
    public function sendVolunteeringDigests(bool $dryRun = false): array
    {
        $userSite = config('freegle.sites.user');

        $sent = 0;
        $usersProcessed = 0;

        // Pre-fetch active opportunities once.
        $opps = $this->fetchActiveOpportunities($userSite);

        if (empty($opps)) {
            return ['sent' => 0, 'users_processed' => 0];
        }

        foreach ($this->eligibleUsers('volunteeringallowed', self::DIGEST_MODE) as $userRow) {
            $usersProcessed++;

            $user = User::find($userRow->id);
            if (!$user) {
                continue;
            }

            $email = $user->email_preferred;
            if (!$email) {
                continue;
            }

            $userOpps = $this->itemsReaching($opps, $this->memberLatLng($user));
            if (empty($userOpps)) {
                continue;
            }

            // Local job ads are personalised by the user's location;
            // getJobAds() returns an empty collection when no location is set.
            $jobAds = $user->getJobAds()['jobs'] ?? collect();

            if (!$dryRun) {
                $unsubscribeUrl = "{$userSite}/unsubscribe?email=" . urlencode($email);
                // spool() re-throws transient render/build errors (only permanent
                // address failures are swallowed). Catch so one bad recipient
                // doesn't abort the whole run.
                try {
                    app(EmailSpoolerService::class)->spool(new VolunteeringDigestMail(
                        recipientEmail: $email,
                        volunteerings: $userOpps,
                        unsubscribeUrl: $unsubscribeUrl,
                        jobAds: $jobAds,
                        userId: $user->id,
                    ), $email);
                } catch (\Throwable $e) {
                    Log::warning('Skipping volunteering digest for user after spool failure; continuing loop', [
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
     * Fetch all active opportunities as display data, newest first.
     *
     * @return array<int,array>
     */
    protected function fetchActiveOpportunities(string $userSite): array
    {
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $tusUploader = config('freegle.tus_uploader', 'https://uploads.ilovefreegle.org:8080');
        $deliveryUrl = config('freegle.delivery.base_url');

        $rawOpps = DB::table('volunteering')
            ->leftJoin('volunteering_images', 'volunteering_images.opportunityid', '=', 'volunteering.id')
            ->leftJoin('volunteering_dates', 'volunteering_dates.volunteeringid', '=', 'volunteering.id')
            ->where('volunteering.pending', 0)
            ->where('volunteering.deleted', 0)
            ->where('volunteering.expired', 0)
            ->select([
                'volunteering.id',
                'volunteering.userid',
                'volunteering.title',
                'volunteering.location',
                'volunteering.description',
                'volunteering.timecommitment',
                'volunteering.online',
                'volunteering.contactname',
                'volunteering.contactphone',
                'volunteering.contactemail',
                'volunteering.contacturl',
                'volunteering.lat',
                'volunteering.lng',
                'volunteering.reachmiles',
                'volunteering_images.id as photo_id',
                'volunteering_images.externaluid as photo_externaluid',
                'volunteering_images.externalmods as photo_externalmods',
                'volunteering_dates.applyby as applyby',
            ])
            ->orderByDesc('volunteering.id')
            ->get()
            ->unique('id');

        if ($rawOpps->isEmpty()) {
            return [];
        }

        $decode = fn (?string $s): ?string =>
            $s !== null ? html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        $opps = [];
        foreach ($rawOpps as $v) {
            $id = (int) $v->id;

            $photoThumb = null;
            if ($v->photo_id) {
                $mods = $v->photo_externalmods ? json_decode($v->photo_externalmods, true) : [];
                if (!empty($mods['url'])) {
                    $photoThumb = $mods['url'];
                } elseif ($v->photo_externaluid) {
                    $p = strrpos($v->photo_externaluid, 'freegletusd-');
                    if ($p !== false) {
                        $fileId = substr($v->photo_externaluid, $p + strlen('freegletusd-'));
                        $source = $tusUploader . '/' . $fileId;
                        $photoThumb = $deliveryUrl
                            ? $deliveryUrl . '?url=' . urlencode($source) . '&w=400'
                            : $source;
                    }
                } else {
                    $photoThumb = "{$imagesDomain}/toimg_{$v->photo_id}.jpg";
                }
            }

            $applyby = $v->applyby
                ? Carbon::parse($v->applyby)->setTimezone('Europe/London')->format('D, jS F Y')
                : null;

            $opps[] = [
                'id'             => $id,
                'userid'         => $v->userid !== null ? (int) $v->userid : null,
                'title'          => $decode($v->title),
                'location'       => $decode($v->location),
                'description'    => $decode($v->description),
                'timecommitment' => $decode($v->timecommitment),
                'online'         => (bool) $v->online,
                'contactname'    => $decode($v->contactname),
                'contactphone'   => $v->contactphone,
                'contactemail'   => $v->contactemail,
                'contacturl'     => $v->contacturl,
                'photo_thumb'    => $photoThumb,
                'applyby'        => $applyby,
                'url'            => "{$userSite}/volunteering/{$id}",
                'lat'            => $v->lat !== null ? (float) $v->lat : null,
                'lng'            => $v->lng !== null ? (float) $v->lng : null,
                'reachmiles'     => $v->reachmiles !== null ? (float) $v->reachmiles : null,
            ];
        }

        return $opps;
    }
}
