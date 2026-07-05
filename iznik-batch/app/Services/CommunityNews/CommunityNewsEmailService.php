<?php

namespace App\Services\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Mail\Traits\FeatureFlags;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Group;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The weekly branded email channel. For each due area, bundle its recent items
 * into a Freegle-branded MJML digest and spool one mail per deduplicated,
 * opted-in member.
 *
 * Opt-out reuses the existing "Newsletters & stories" preference
 * (users.newslettersallowed) — the same switch Stories Newsletter honours — so
 * there's a single, familiar "no more of these" control for members.
 */
class CommunityNewsEmailService
{
    use FeatureFlags;

    /** Email type gate (FREEGLE_MAIL_ENABLED_TYPES) + X-Freegle-Email-Type. */
    public const EMAIL_TYPE = 'CommunityNews';

    /**
     * Send the weekly digest for each due area to its members.
     *
     * @return array{areas:int, sent:int}
     */
    public function sendWeekly(bool $dryRun = false, ?int $onlyAreaId = null): array
    {
        $stats = ['areas' => 0, 'sent' => 0];

        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            Log::info('CommunityNews emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
            return $stats;
        }

        $minDays = (int) config('freegle.communitynews.email_min_days', 7);
        $maxItems = (int) config('freegle.communitynews.email_max_items', 6);
        $freshDays = (int) config('freegle.communitynews.item_freshness_days', 10);
        $userSite = rtrim(config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');

        $query = CommunityNewsArea::query();
        if ($onlyAreaId) {
            $query->where('id', $onlyAreaId);
        }

        foreach ($query->get() as $area) {
            if ($area->lastemailed && $area->lastemailed->gt(now()->subDays($minDays))) {
                continue;
            }

            $items = CommunityNewsItem::where('areaid', $area->id)
                ->whereNull('emailed_at')
                ->where('researched_at', '>', now()->subDays($freshDays))
                ->orderBy('id')
                ->limit($maxItems)
                ->get();

            if ($items->isEmpty()) {
                continue;
            }

            $groupIds = array_map('intval', $area->groupids ?? []);
            if (empty($groupIds)) {
                continue;
            }

            $itemData = $items->map(fn ($i) => [
                'title' => $i->title,
                'blurb' => $i->snippet,
                'url' => $i->url,
                'source' => $i->source,
            ])->all();

            $intro = $area->intro ?: "Here's a little round-up of what's going on around {$area->name}.";

            $sentForArea = 0;
            foreach ($this->eligibleMembers($groupIds)->lazyById(1000, 'users.id', 'id') as $member) {
                $user = DB::table('users')->where('id', $member->id)->first();
                if (!$user || $user->bouncing) {
                    continue;
                }

                $email = User::find($member->id)?->email_preferred;
                if (!$email) {
                    continue;
                }

                $name = $user->fullname
                    ?? trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''))
                    ?: 'Freegle Member';

                if (!$dryRun) {
                    app(EmailSpoolerService::class)->spool(new CommunityNewsMail(
                        userId: (int) $member->id,
                        recipientName: $name,
                        recipientEmail: $email,
                        areaName: $area->name,
                        intro: $intro,
                        items: $itemData,
                        findUrl: "{$userSite}/find?src=communitynews",
                        unsubscribeUrl: "{$userSite}/unsubscribe",
                        settingsUrl: "{$userSite}/settings",
                    ));
                }

                $sentForArea++;
                $stats['sent']++;
            }

            if ($sentForArea > 0) {
                if (!$dryRun) {
                    CommunityNewsItem::whereIn('id', $items->pluck('id'))->update(['emailed_at' => now()]);
                    $area->update(['lastemailed' => now()]);
                }
                $stats['areas']++;
            }
        }

        return $stats;
    }

    /**
     * Distinct, opted-in, non-deleted members of any group in the area.
     *
     * whereExists-free join + distinct on users.id gives one row per user even
     * when they belong to several groups in the area (dedup). Mirrors
     * StoriesNewsletterService's eligible-member query.
     */
    public function eligibleMembers(array $groupIds)
    {
        return DB::table('users')
            ->join('memberships', 'memberships.userid', '=', 'users.id')
            ->join('groups', function ($join) {
                $join->on('groups.id', '=', 'memberships.groupid')
                    ->where('groups.type', Group::TYPE_FREEGLE)
                    ->where('groups.publish', 1);
            })
            ->whereIn('memberships.groupid', $groupIds)
            ->where('memberships.collection', 'Approved')
            ->where('users.newslettersallowed', 1)
            ->whereNull('users.deleted')
            ->distinct()
            ->select('users.id');
    }
}
