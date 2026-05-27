<?php

namespace App\Services;

use App\Mail\Stories\StoriesToCentralMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StoriesService
{
    public function sendToCentral(bool $dryRun = false): int
    {
        $stories = DB::table('users_stories')
            ->where('newsletter', 1)
            ->where('public', 1)
            ->where('newsletterreviewed', 1)
            ->where('mailedtomembers', 0)
            ->where('mailedtocentral', 0)
            ->select(['id', 'headline', 'story', 'userid'])
            ->get();

        if ($stories->isEmpty()) {
            return 0;
        }

        $imageDomain = rtrim(config('freegle.images.domain'), '/');
        $tusUploader = rtrim(config('freegle.tus_uploader', ''), '/');
        $userSite = rtrim(config('freegle.sites.user'), '/');
        $voteUrl = $userSite . '/stories/fornewsletter';
        $previewText = "Please go to $voteUrl to vote for which go into the next member newsletter.";

        $storyData = [];
        foreach ($stories as $story) {
            $groupName = DB::table('memberships')
                ->join('groups', 'groups.id', '=', 'memberships.groupid')
                ->where('memberships.userid', $story->userid)
                ->where('groups.type', 'Freegle')
                ->where('groups.onmap', 1)
                ->selectRaw('COALESCE(groups.namefull, groups.nameshort) AS namedisplay')
                ->value('namedisplay');

            $image = DB::table('users_stories_images')
                ->where('storyid', $story->id)
                ->select(['id', 'externaluid'])
                ->first();

            $photoUrl = null;
            if ($image) {
                if (!empty($image->externaluid) && str_contains($image->externaluid, 'freegletusd-')) {
                    $suffix = substr($image->externaluid, strlen('freegletusd-'));
                    $photoUrl = "{$tusUploader}/{$suffix}/";
                } else {
                    $photoUrl = "{$imageDomain}/simg_{$image->id}.jpg";
                }
            }

            $storyData[] = [
                'headline' => $story->headline,
                'story' => $story->story,
                'groupname' => $groupName,
                'photo' => $photoUrl,
            ];

            if (!$dryRun) {
                DB::table('users_stories')
                    ->where('id', $story->id)
                    ->update(['mailedtocentral' => 1]);
            }
        }

        if (!$dryRun) {
            $subject = now()->format('d-M-Y') . ' Recent stories from freeglers - please vote';
            app(\App\Services\EmailSpoolerService::class)->spool(new StoriesToCentralMail($storyData, $previewText, $voteUrl, $subject));
        }

        return count($storyData);
    }
}
