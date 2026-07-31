<?php

namespace App\Services\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Mail\Traits\FeatureFlags;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Group;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Services\GeminiService;
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

    public function __construct(
        private CommunityNewsImageService $images,
        private GeminiService $gemini,
    ) {
    }

    /**
     * Send the weekly digest for each due area to its members.
     *
     * @return array{areas:int, sent:int}
     */
    public function sendWeekly(bool $dryRun = false, ?int $onlyAreaId = null, bool $force = false): array
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
            if (!$force && $area->lastemailed && $area->lastemailed->gt(now()->subDays($minDays))) {
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

            $itemData = $items->map(function ($i) {
                $uploaded = $this->images->uploadItemImage($i);

                return [
                    'title' => $i->title,
                    'blurb' => $i->snippet,
                    'url' => $i->url,
                    'source' => $i->source,
                    'image' => $uploaded ? $this->images->deliveryUrl($uploaded['externaluid']) : null,
                ];
            })->all();

            $intro = $area->intro ?: "Here's a little round-up of what's going on around {$area->name}.";
            $story = $this->pickStory($groupIds, $area);

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
                        settingsUrl: "{$userSite}/settings",
                        story: $story,
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
     * One member story from the area's groups to warm the email up, if a good
     * one was told since the last mail.
     *
     * Candidates must already carry the moderator "suitable for newsletter"
     * flags (public + newsletterreviewed + newsletter — the same bar the
     * Stories Newsletter uses). On top of that, Gemini picks at most one that
     * is genuinely positive in tone and clearly written; if the AI is
     * unavailable or unconvinced, the email simply goes out without a story.
     *
     * @return array{headline:string, story:string, name:string}|null
     */
    public function pickStory(array $groupIds, CommunityNewsArea $area): ?array
    {
        $minDays = (int) config('freegle.communitynews.email_min_days', 7);
        $since = $area->lastemailed ?: now()->subDays($minDays);

        $candidates = DB::table('users_stories')
            ->join('users', 'users.id', '=', 'users_stories.userid')
            ->join('memberships', 'memberships.userid', '=', 'users_stories.userid')
            ->whereIn('memberships.groupid', array_map('intval', $groupIds))
            ->where('memberships.collection', 'Approved')
            ->where('users_stories.public', 1)
            ->where('users_stories.newsletterreviewed', 1)
            ->where('users_stories.newsletter', 1)
            ->where('users_stories.date', '>=', $since)
            ->whereNull('users.deleted')
            ->orderByDesc('users_stories.date')
            ->distinct()
            ->limit(10)
            ->get(['users_stories.id', 'users_stories.headline', 'users_stories.story', 'users.firstname', 'users.fullname']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $numbered = $candidates->values()->map(function ($s, $i) {
            $n = $i + 1;
            $text = mb_substr(trim((string) $s->story), 0, 800);

            return "{$n}. \"{$s->headline}\" — {$text}";
        })->implode("\n\n");

        $verdict = $this->gemini->generateJson(
            "These are stories Freegle members told about giving or receiving things in {$area->name}. " .
            "Pick the ONE best suited to a cheery local email round-up: it must be genuinely positive in tone, " .
            "clearly written, and make sense on its own. If none qualifies, choose null.\n\n{$numbered}\n\n" .
            'Reply with JSON only: {"choice": <story number or null>}'
        );

        $choice = $verdict['choice'] ?? null;
        if (!is_int($choice) && !ctype_digit((string) $choice)) {
            return null;
        }

        $picked = $candidates->values()->get((int) $choice - 1);
        if (!$picked) {
            return null;
        }

        $name = trim((string) ($picked->fullname ?? '')) ?: trim((string) ($picked->firstname ?? '')) ?: 'A Freegle member';

        return [
            'headline' => (string) $picked->headline,
            'story' => trim((string) $picked->story),
            'name' => $name,
        ];
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
