<?php

namespace App\Services;

use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
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
            // Two separate queries avoid correlated-subquery ambiguity in some MySQL contexts:
            // 1) group-specific volunteerings (inner join on this group's rows)
            // 2) global volunteerings (left join + IS NULL — those with no group assignment at all)
            $volColumns = [
                'volunteering.id',
                'volunteering.title',
                'volunteering.location',
                'volunteering.description',
                'volunteering.timecommitment',
                'volunteering.contactname',
                'volunteering.contactphone',
                'volunteering.contactemail',
                'volunteering.contacturl',
            ];

            $groupSpecific = DB::table('volunteering')
                ->join('volunteering_groups', 'volunteering_groups.volunteeringid', '=', 'volunteering.id')
                ->where('volunteering.pending', 0)
                ->where('volunteering.deleted', 0)
                ->where('volunteering.expired', 0)
                ->where('volunteering_groups.groupid', $groupRow->id)
                ->select($volColumns)
                ->get();

            $globalVols = DB::table('volunteering')
                ->leftJoin('volunteering_groups', 'volunteering_groups.volunteeringid', '=', 'volunteering.id')
                ->where('volunteering.pending', 0)
                ->where('volunteering.deleted', 0)
                ->where('volunteering.expired', 0)
                ->whereNull('volunteering_groups.volunteeringid')
                ->select($volColumns)
                ->get();

            $volunteerings = $groupSpecific->merge($globalVols)->sortByDesc('id')->values();

            if ($volunteerings->isEmpty()) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $groupRow->id)
                        ->update(['lastvolunteeringroundup' => now()]);
                }
                continue;
            }

            $groupsProcessed++;

            // Fetch photos separately to avoid interfering with the NOT EXISTS clause above.
            $volIds = $volunteerings->pluck('id')->all();
            $photos = DB::table('volunteering_images')
                ->whereIn('opportunityid', $volIds)
                ->select(['opportunityid', 'id', 'externaluid'])
                ->get()
                ->keyBy('opportunityid');

            // Build structured volunteering data for the template.
            $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
            $tusUploader = config('freegle.tus_uploader', 'https://uploads.ilovefreegle.org:8080');
            $deliveryUrl = config('freegle.delivery.base_url');
            $volData = $volunteerings->map(function ($v) use ($userSite, $imagesDomain, $tusUploader, $deliveryUrl, $photos) {
                $photoThumb = null;
                $photo = $photos->get($v->id);
                if ($photo) {
                    if ($photo->externaluid) {
                        $p = strrpos($photo->externaluid, 'freegletusd-');
                        if ($p !== false) {
                            $fileId = substr($photo->externaluid, $p + strlen('freegletusd-'));
                            $source = $tusUploader . '/' . $fileId;
                            $photoThumb = $deliveryUrl
                                ? $deliveryUrl . '?url=' . urlencode($source) . '&w=80'
                                : $source;
                        }
                    } else {
                        $photoThumb = "{$imagesDomain}/toimg_{$photo->id}.jpg";
                    }
                }
                return [
                    'id'             => $v->id,
                    'title'          => $v->title,
                    'location'       => $v->location,
                    'description'    => $v->description,
                    'timecommitment' => $v->timecommitment,
                    'contactname'    => $v->contactname,
                    'contactphone'   => $v->contactphone,
                    'contactemail'   => $v->contactemail,
                    'contacturl'     => $v->contacturl,
                    'photo_thumb'    => $photoThumb,
                    'url'            => "https://{$userSite}/volunteering/{$v->id}",
                ];
            })->values()->all();

            // Find members who have volunteering enabled and are not opted out.
            $members = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->join('users_emails', function ($join) {
                    $join->on('users_emails.userid', '=', 'memberships.userid')
                        ->where('users_emails.preferred', '=', 1);
                })
                ->leftJoin('locations', 'locations.id', '=', 'users.lastlocation')
                ->where('memberships.groupid', $groupRow->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->where('memberships.volunteeringallowed', 1)
                ->where('memberships.emailfrequency', '!=', 0)
                ->whereNull('users.deleted')
                ->whereNotNull('users_emails.email')
                ->select(['users_emails.email', 'users.id as userId', 'locations.lat', 'locations.lng'])
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
                    Mail::send(new VolunteeringDigestMail(
                        recipientEmail: $member->email,
                        groupName: $groupRow->nameshort,
                        volunteerings: $volData,
                        unsubscribeUrl: $unsubscribeUrl,
                        jobAds: $jobAds,
                        userId: $member->userId,
                    ));
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
