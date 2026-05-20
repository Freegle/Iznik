<?php

namespace App\Services;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Support\SafeMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Carbon\Carbon;
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
            // V1 note: filtering groupid in SQL with IS NULL / NOT IN / NOT EXISTS is unreliable
            // in MySQL with LEFT JOINs. Fetch all with groupid included and filter in PHP instead.
            $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
            $volunteerings = DB::table('volunteering')
                ->leftJoin('volunteering_groups', 'volunteering_groups.volunteeringid', '=', 'volunteering.id')
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
                    'volunteering_groups.groupid',
                    DB::raw('volunteering_images.id AS photo_id'),
                    DB::raw('volunteering_images.externaluid AS photo_externaluid'),
                    DB::raw('volunteering_images.externalmods AS photo_externalmods'),
                    DB::raw('volunteering_dates.applyby AS applyby'),
                ])
                ->orderByDesc('volunteering.id')
                ->get()
                ->filter(fn ($v) => $v->groupid === null || (int) $v->groupid === (int) $groupRow->id)
                ->unique('id');

            if ($volunteerings->isEmpty()) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lastvolunteeringroundup' => now()]);
                }
                continue;
            }

            $groupsProcessed++;

            // Build structured volunteering data for the template.
            $tusUploader = config('freegle.tus_uploader', 'https://uploads.ilovefreegle.org:8080');
            $deliveryUrl = config('freegle.delivery.base_url');
            $volData = $volunteerings->map(function ($v) use ($userSite, $imagesDomain, $tusUploader, $deliveryUrl) {
                $photoThumb = null;
                if ($v->photo_id) {
                    // Prefer externalmods.url (set for Cloudinary/CDN-hosted images)
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

                return [
                    'id'             => $v->id,
                    'title'          => $v->title,
                    'location'       => $v->location,
                    'description'    => $v->description,
                    'timecommitment' => $v->timecommitment,
                    'online'         => (bool) $v->online,
                    'contactname'    => $v->contactname,
                    'contactphone'   => $v->contactphone,
                    'contactemail'   => $v->contactemail,
                    'contacturl'     => $v->contacturl,
                    'photo_thumb'    => $photoThumb,
                    'applyby'        => $applyby,
                    'url'            => "https://{$userSite}/volunteering/{$v->id}",
                ];
            })->values()->all();

            // Find members who have volunteering enabled and are not opted out.
            // Activity / holiday / bouncing / simplemail filters come from
            // User::scopeReceivingOurMails so this query matches the V1
            // sendOurMails() criteria. Without those, the V2 dry-run was
            // 1.34× V1's send count (149k vs 111k).
            $members = User::query()
                ->select([
                    'users_emails.email',
                    'users.id as userId',
                    'locations.lat',
                    'locations.lng',
                ])
                ->join('memberships', 'memberships.userid', '=', 'users.id')
                ->join('users_emails', function ($join) {
                    $join->on('users_emails.userid', '=', 'memberships.userid')
                        ->where('users_emails.preferred', '=', 1);
                })
                ->leftJoin('locations', 'locations.id', '=', 'users.lastlocation')
                ->where('memberships.groupid', $groupRow->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.volunteeringallowed', 1)
                ->where('memberships.emailfrequency', '!=', 0)
                ->whereNotNull('users_emails.email')
                ->receivingOurMails()
                ->get();

            foreach ($members as $member) {
                $jobAds = collect();
                if ($member->lat && $member->lng) {
                    $user = User::find($member->userId);
                    if ($user) {
                        $jobAds = $user->getJobAds()['jobs'];
                    }
                }

                if (!$dryRun) {
                    $unsubscribeUrl = "https://{$userSite}/unsubscribe?email=" . urlencode($member->email);
                    // SafeMail catches permanent (bounce + skip) and transient
                    // (mail-host hiccup mid-run) SMTP failures so one bad
                    // address or one closed-connection doesn't crash the
                    // surrounding ~20k-recipient run. sendMailable() uses
                    // Mail::send-style because VolunteeringDigestMail's
                    // envelope() already sets the to: address.
                    SafeMail::sendMailable(
                        new VolunteeringDigestMail(
                            recipientEmail: $member->email,
                            groupName: $groupRow->nameshort,
                            volunteerings: $volData,
                            unsubscribeUrl: $unsubscribeUrl,
                            jobAds: $jobAds,
                            userId: $member->userId,
                        ),
                        $member->email,
                    );
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
