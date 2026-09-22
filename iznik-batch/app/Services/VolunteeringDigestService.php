<?php

namespace App\Services;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\User;
use App\Services\Concerns\BuildsUserRoundups;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Volunteering-opportunity roundup, unified per user.
 *
 * Previously one email per group: a member of several groups received several
 * roundups, and the same opportunity cross-posted to multiple of their groups
 * (or a national/global opportunity with no specific group) appeared in each
 * one. This now sends ONE email per user covering opportunities across ALL
 * their volunteering-enabled groups plus global opportunities, with each shown
 * once (deduplicated by opportunity id) and annotated with which of the user's
 * groups it belongs to.
 */
class VolunteeringDigestService
{
    use BuildsUserRoundups;

    /** users_digests.mode value used for the per-user cadence guard. */
    public const DIGEST_MODE = 'volunteering';

    /**
     * Send unified volunteering-opportunity roundups.
     *
     * @return array{sent: int, users_processed: int, groups_processed: int}
     */
    public function sendVolunteeringDigests(bool $dryRun = false): array
    {
        $userSite = config('freegle.sites.user');

        $sent = 0;
        $usersProcessed = 0;

        $eligibleGroups = $this->eligibleGroups('volunteering'); // id => nameshort
        if (empty($eligibleGroups)) {
            return ['sent' => 0, 'users_processed' => 0, 'groups_processed' => 0];
        }
        $groupIds = array_keys($eligibleGroups);

        // Pre-fetch active opportunities + their group associations once.
        // $globalOppIds are opportunities with no specific group (shown to all).
        [$oppsById, $groupsByOpp, $globalOppIds] = $this->fetchActiveOpportunities($userSite);

        if (empty($oppsById)) {
            return ['sent' => 0, 'users_processed' => 0, 'groups_processed' => count($eligibleGroups)];
        }

        foreach ($this->eligibleUsers($groupIds, 'volunteeringallowed', self::DIGEST_MODE) as $userRow) {
            $usersProcessed++;

            $user = User::find($userRow->id);
            if (!$user) {
                continue;
            }

            $email = $user->email_preferred;
            if (!$email) {
                continue;
            }

            $userGroupIds = $this->userGroupIds($user->id, $groupIds, 'volunteeringallowed');
            if (empty($userGroupIds)) {
                continue;
            }

            $userOpps = $this->opportunitiesForUser($oppsById, $groupsByOpp, $globalOppIds, $userGroupIds, $eligibleGroups);
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
            'groups_processed' => count($eligibleGroups),
        ];
    }

    /**
     * Build the deduplicated, group-attributed opportunity list for one user:
     * every global opportunity, plus opportunities shared with any of the user's
     * volunteering-enabled groups, each shown once.
     *
     * @param array<int,array>  $oppsById
     * @param array<int,int[]>  $groupsByOpp     opp id => [group ids] (group-specific opps)
     * @param array<int,bool>   $globalOppIds    opp id => true for opps with no specific group
     * @param array<int>        $userGroupIds
     * @param array<int,array{name:string,url:string}> $eligibleGroups  group id => display name + /explore link
     * @return array<int,array>
     */
    protected function opportunitiesForUser(array $oppsById, array $groupsByOpp, array $globalOppIds, array $userGroupIds, array $eligibleGroups): array
    {
        $out = [];
        foreach ($oppsById as $oid => $oppData) {
            $isGlobal = isset($globalOppIds[$oid]);
            $shared = array_values(array_intersect($groupsByOpp[$oid] ?? [], $userGroupIds));

            if (!$isGlobal && empty($shared)) {
                continue;
            }

            $oppData['groups'] = array_values(array_filter(array_map(
                fn ($gid) => $eligibleGroups[$gid] ?? null,
                $shared
            )));
            $out[] = $oppData;
        }

        return $out;
    }

    /**
     * Fetch all active opportunities, returning per-opportunity display data
     * (keyed by id), the map of opportunity id => its group ids, and the set of
     * opportunity ids that have no specific group (global / national).
     *
     * @return array{0: array<int,array>, 1: array<int,int[]>, 2: array<int,bool>}
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
                'volunteering.title',
                'volunteering.location',
                'volunteering.description',
                'volunteering.timecommitment',
                'volunteering.online',
                'volunteering.contactname',
                'volunteering.contactphone',
                'volunteering.contactemail',
                'volunteering.contacturl',
                'volunteering_images.id as photo_id',
                'volunteering_images.externaluid as photo_externaluid',
                'volunteering_images.externalmods as photo_externalmods',
                'volunteering_dates.applyby as applyby',
            ])
            ->orderByDesc('volunteering.id')
            ->get()
            ->unique('id');

        if ($rawOpps->isEmpty()) {
            return [[], [], []];
        }

        $oppIds = $rawOpps->pluck('id')->all();

        // Every group association for these opportunities (used both to decide
        // globality — an opportunity with no rows at all is global — and for the
        // "shared with" attribution).
        $groupsByOpp = [];
        DB::table('volunteering_groups')
            ->whereIn('volunteeringid', $oppIds)
            ->get(['volunteeringid', 'groupid'])
            ->each(function ($row) use (&$groupsByOpp) {
                if ($row->groupid !== null) {
                    $groupsByOpp[(int) $row->volunteeringid][] = (int) $row->groupid;
                }
            });

        $decode = fn (?string $s): ?string =>
            $s !== null ? html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;

        $oppsById = [];
        $globalOppIds = [];
        foreach ($rawOpps as $v) {
            $id = (int) $v->id;

            if (empty($groupsByOpp[$id])) {
                $globalOppIds[$id] = true;
            }

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

            $oppsById[$id] = [
                'id'             => $id,
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
                'groups'         => [],
            ];
        }

        return [$oppsById, $groupsByOpp, $globalOppIds];
    }
}
