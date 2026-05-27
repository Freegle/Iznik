<?php

namespace App\Services;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\Stories\StoriesNewsletterMail;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Services\BulkMail\BulkMjmlCompiler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoriesNewsletterService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'StoriesNewsletter';
    public const MIN_STORIES = 3;
    public const MAX_STORIES = 10;

    /**
     * Generate the stories newsletter and send to all eligible members.
     *
     * Mirrors the logic from iznik-server Story::generateNewsletter() + Newsletter::send().
     *
     * @return array{stories: int, sent: int}
     */
    public function generateAndSend(bool $dryRun = false): array
    {
        $stats = ['stories' => 0, 'sent' => 0];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('StoriesNewsletter emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return $stats;
        }

        // Find the date of the last Stories newsletter — only include stories updated since then.
        $since = DB::table('newsletters')->where('type', 'Stories')->max('created');

        // Get unsent stories ranked by vote count (likes), limited to MAX_STORIES.
        // The LEFT JOIN to likes gives one row per like per story; COUNT(*) gives vote count.
        // Stories with no likes still appear as one row (LEFT JOIN produces NULL for like columns).
        $rawStories = DB::table('users_stories')
            ->leftJoin('users_stories_likes', 'users_stories_likes.storyid', '=', 'users_stories.id')
            ->leftJoin('users_stories_images', 'users_stories_images.storyid', '=', 'users_stories.id')
            ->where('users_stories.newsletterreviewed', 1)
            ->where('users_stories.newsletter', 1)
            ->where('users_stories.mailedtomembers', 0)
            ->when($since, fn ($q) => $q->where('users_stories.updated', '>', $since))
            ->select(['users_stories.id'])
            ->selectRaw('users_stories_images.id AS photoid')
            ->selectRaw('COUNT(*) AS vote_count')
            ->groupBy('users_stories.id', 'users_stories_images.id')
            ->orderByDesc('vote_count')
            ->limit(self::MAX_STORIES)
            ->get();

        if ($rawStories->count() < self::MIN_STORIES) {
            return $stats;
        }

        // Shuffle for variety — high-voted stories float up first, then we randomise.
        $rawStories = $rawStories->shuffle()->values();

        $imageDomain  = rtrim(config('freegle.images.domain', ''), '/');
        $tusUploader  = rtrim(config('freegle.tus_uploader', ''), '/');
        $userSite     = rtrim(config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');

        $storyData = [];
        foreach ($rawStories as $row) {
            $story = DB::table('users_stories')->where('id', $row->id)->first();
            if (!$story) {
                continue;
            }

            $groupName = DB::table('memberships')
                ->join('groups', 'groups.id', '=', 'memberships.groupid')
                ->where('memberships.userid', $story->userid)
                ->where('groups.type', Group::TYPE_FREEGLE)
                ->where('groups.onmap', 1)
                ->selectRaw('COALESCE(groups.namefull, groups.nameshort) AS namedisplay')
                ->value('namedisplay');

            $photoUrl = null;
            if ($row->photoid) {
                $image = DB::table('users_stories_images')->where('id', $row->photoid)->first();
                if ($image) {
                    if (!empty($image->externaluid) && str_contains($image->externaluid, 'freegletusd-')) {
                        $suffix   = substr($image->externaluid, strlen('freegletusd-'));
                        $photoUrl = "{$tusUploader}/{$suffix}/";
                    } else {
                        $photoUrl = "{$imageDomain}/simg_{$image->id}.jpg";
                    }
                }
            }

            $userRecord = DB::table('users')->where('id', $story->userid)->first();
            $userName = null;
            if ($userRecord) {
                $userName = $userRecord->fullname
                    ?: trim(($userRecord->firstname ?? '') . ' ' . ($userRecord->lastname ?? ''))
                    ?: null;
            }

            $profileImg = DB::table('users_images')
                ->where('userid', $story->userid)
                ->orderByDesc('default')
                ->orderBy('id')
                ->first(['id', 'url']);
            $profileUrl = null;
            if ($profileImg) {
                if (!empty($profileImg->url) && !str_contains($profileImg->url, 'defaultprofile.png')) {
                    $profileUrl = $profileImg->url;
                } elseif (empty($profileImg->url)) {
                    $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
                    $profileUrl = "{$imagesDomain}/tuimg_{$profileImg->id}.jpg";
                }
            }

            $storyData[] = [
                'id'         => $row->id,
                'headline'   => $story->headline,
                'story'      => $story->story,
                'groupname'  => $groupName,
                'photo'      => $photoUrl,
                'username'   => $userName,
                'profileurl' => $profileUrl,
            ];
        }

        $stats['stories'] = count($storyData);

        if ($dryRun) {
            return $stats;
        }

        // Create the newsletter record before marking stories — so if we crash mid-send,
        // the next run will see a newer $since and skip these stories rather than re-queuing them.
        $preview = "This is a selection of recent stories from other freeglers. " .
            "If you can't read the HTML version, have a look at {$userSite}/stories";

        DB::table('newsletters')->insert([
            'subject'  => 'Lovely stories from other freeglers!',
            'textbody' => $preview,
            'type'     => 'Stories',
            'created'  => now(),
        ]);

        // Mark stories as sent to members.
        foreach ($storyData as $story) {
            DB::table('users_stories')->where('id', $story['id'])->update(['mailedtomembers' => 1]);
        }

        // Random header image (1–5) — matches V1 behaviour.
        $imgNumber       = rand(1, 5);
        $headerImageUrl  = "{$userSite}/images/story{$imgNumber}.png";

        // CTAs with tracking source param.
        $tellUrl = "{$userSite}/stories?src=storynewsletter";
        $giveUrl = "{$userSite}/give?src=storynewsletter";
        $findUrl = "{$userSite}/find?src=storynewsletter";

        // Find all eligible members:
        //   - in a published Freegle group
        //   - group has not disabled newsletters in its settings
        //   - user has newslettersallowed = 1
        //   - user is not deleted
        $eligibleMembers = DB::table('users')
            ->join('memberships', 'memberships.userid', '=', 'users.id')
            ->join('groups', function ($join) {
                $join->on('groups.id', '=', 'memberships.groupid')
                    ->where('groups.type', Group::TYPE_FREEGLE)
                    ->where('groups.publish', 1);
            })
            ->where('users.newslettersallowed', 1)
            ->whereNull('users.deleted')
            ->where(function ($q) {
                // newsletter defaults to on (1) when not set; only excluded if explicitly set to 0.
                $q->whereNull('groups.settings')
                    ->orWhereRaw("COALESCE(JSON_EXTRACT(groups.settings, '$.newsletter'), 1) != 0");
            })
            ->distinct()
            ->select('users.id');

        // One BulkMjmlCompiler for the whole newsletter run. All eligible
        // recipients share the same content (stories, hero, CTAs) — the only
        // body variation is the "sent to {email}" footer line. ⇒ exactly one
        // MJML sidecar call instead of N.
        $bulkEnabled = (bool) config('freegle.bulk_mail.enabled', true);
        $bulkCompiler = $bulkEnabled ? app(BulkMjmlCompiler::class) : null;

        // Stream eligible members in keyset-paginated chunks; pluck()-ing the entire
        // eligible newsletter userbase (hundreds of thousands of ids) at once exhausts memory.
        foreach ($eligibleMembers->lazyById(1000, 'users.id', 'id') as $member) {
            $userId = $member->id;
            $user = DB::table('users')->where('id', $userId)->first();
            if (!$user || $user->bouncing) {
                continue;
            }

            // V1 parity: skip our own per-user-alias domains so the mail can't loop back as chat.
            $email = \App\Models\User::find($userId)?->email_preferred;

            if (!$email) {
                continue;
            }

            $name = $user->fullname
                ?? trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''))
                ?: 'Freegle Member';

            $mailable = new StoriesNewsletterMail(
                userId: $userId,
                recipientName: $name,
                recipientEmail: $email,
                stories: $storyData,
                headerImageUrl: $headerImageUrl,
                tellUrl: $tellUrl,
                giveUrl: $giveUrl,
                findUrl: $findUrl,
                previewText: $preview,
                unsubscribeUrl: "{$userSite}/unsubscribe",
                settingsUrl: "{$userSite}/settings",
            );

            if ($bulkCompiler !== null && $mailable instanceof BulkRenderable) {
                $mailable->setPrerenderedHtml($bulkCompiler->htmlFor($mailable));
            }

            app(\App\Services\EmailSpoolerService::class)->spool($mailable);

            $stats['sent']++;
        }

        if ($bulkCompiler !== null) {
            Log::info('StoriesNewsletterService: bulk compile stats', [
                'compiles' => $bulkCompiler->compileCount(),
                'hits' => $bulkCompiler->hitCounts(),
            ]);
        }

        return $stats;
    }
}
