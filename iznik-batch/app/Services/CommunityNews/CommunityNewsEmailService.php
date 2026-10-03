<?php

namespace App\Services\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Mail\Traits\FeatureFlags;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
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
    ) {}

    /**
     * Send the weekly digest for each due area to its members.
     *
     * @return array{areas:int, sent:int}
     */
    public function sendWeekly(bool $dryRun = false, ?int $onlyAreaId = null, bool $force = false): array
    {
        $stats = ['areas' => 0, 'sent' => 0];

        if (! self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
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
            if (! $force && $area->lastemailed && $area->lastemailed->gt(now()->subDays($minDays))) {
                continue;
            }

            // An area with no authority behind it has nowhere to find members
            // or a story: nothing to send.
            if (! $area->authorityid) {
                continue;
            }

            $items = CommunityNewsItem::where('areaid', $area->id)
                ->whereNull('emailed_at')
                ->where('researched_at', '>', now()->subDays($freshDays))
                // Leave out events that have already happened. Research runs
                // hourly but this email goes weekly, and an item stays fresh for
                // days after we found it, so without this a Wednesday jumble sale
                // found on Monday still went out on Friday. Undated items (most of
                // them — a new cycle path, a library reopening) have no event_date
                // and are unaffected. Today's events still count: someone reading
                // on the morning of can still go.
                ->where(function ($q) {
                    $q->whereNull('event_date')
                        ->orWhere('event_date', '>=', now()->startOfDay());
                })
                ->orderBy('id')
                ->limit($maxItems)
                ->get()
                // Backstop for the event_date filter above: the research model
                // omits event_date on most items — including ones whose own
                // blurb names a day ("On Saturday 8 August ...", mailed six
                // days late on 2026-08-14) — so also drop anything whose TEXT
                // says it is already over. May leave the email under
                // $maxItems; fewer items beats stale ones.
                ->reject(fn ($i) => MentionedDates::visiblyOver($i, now()))
                ->values();

            if ($items->isEmpty()) {
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

            // Intros are stored and reused for up to a week, so strip token
            // Welsh/Gaelic greetings at send time too - an intro written before
            // the parse-time backstop existed would otherwise keep going out
            // until the area is next researched.
            $intro = IntroLanguage::stripForeignGreeting((string) ($area->intro ?? ''))
                ?: "Here's a little round-up of what's going on around {$area->name}.";
            $story = $this->pickStory($area);

            $sentForArea = 0;
            foreach ($this->eligibleMembers($area)->lazyById(1000, 'users.id', 'id') as $member) {
                $user = DB::table('users')->where('id', $member->id)->first();
                if (! $user || $user->bouncing) {
                    continue;
                }

                $email = User::find($member->id)?->email_preferred;
                if (! $email) {
                    continue;
                }

                // Provider is refusing our mail. Community News is weekly and
                // dropped rather than caught up on release: next week's issue
                // is a better email than a stale one. Counted anyway so the
                // scale of what a member missed is visible in ModTools.
                if (app(\App\Services\Mail\MailSuppressionService::class)
                    ->shouldSkip($email, (int) $member->id, 'communitynews')) {
                    continue;
                }

                $name = $user->fullname
                    ?? trim(($user->firstname ?? '').' '.($user->lastname ?? ''))
                    ?: 'Freegle Member';

                if (! $dryRun) {
                    app(EmailSpoolerService::class)->spool(new CommunityNewsMail(
                        userId: (int) $member->id,
                        recipientName: $name,
                        recipientEmail: $email,
                        areaName: $area->name,
                        intro: $intro,
                        items: $itemData,
                        askUrl: "{$userSite}/ask?src=communitynews",
                        settingsUrl: "{$userSite}/settings",
                        story: $story,
                    ));
                }

                $sentForArea++;
                $stats['sent']++;
            }

            if ($sentForArea > 0) {
                if (! $dryRun) {
                    CommunityNewsItem::whereIn('id', $items->pluck('id'))->update(['emailed_at' => now()]);
                    $area->update(['lastemailed' => now()]);
                }
                $stats['areas']++;
            }
        }

        return $stats;
    }

    /**
     * One member story from the area to warm the email up, if a good one was
     * told since the last mail. Candidates are Freegle members whose location
     * falls within the area's authority.
     *
     * Candidates must already carry the moderator "suitable for newsletter"
     * flags (public + newsletterreviewed + newsletter — the same bar the
     * Stories Newsletter uses). On top of that, Gemini picks at most one that
     * is genuinely positive in tone and clearly written; if the AI is
     * unavailable or unconvinced, the email simply goes out without a story.
     *
     * @return array{headline:string, story:string, name:string}|null
     */
    public function pickStory(CommunityNewsArea $area): ?array
    {
        if (! $area->authorityid) {
            return null;
        }

        $minDays = (int) config('freegle.communitynews.email_min_days', 7);
        $since = $area->lastemailed ?: now()->subDays($minDays);

        $candidates = DB::table('users_stories')
            ->join('users', 'users.id', '=', 'users_stories.userid')
            ->leftJoin('locations as lastloc', 'lastloc.id', '=', 'users.lastlocation')
            ->crossJoin('authorities')
            ->where('authorities.id', (int) $area->authorityid)
            ->whereRaw("ST_Contains(authorities.polygon, {$this->memberPointSql()})")
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
            "These are stories Freegle members told about giving or receiving things in {$area->name}. ".
            'Pick the ONE best suited to a cheery local email round-up: it must be genuinely positive in tone, '.
            "clearly written, and make sense on its own. If none qualifies, choose null.\n\n{$numbered}\n\n".
            'Reply with JSON only: {"choice": <story number or null>}'
        );

        $choice = $verdict['choice'] ?? null;
        if (! is_int($choice) && ! ctype_digit((string) $choice)) {
            return null;
        }

        $picked = $candidates->values()->get((int) $choice - 1);
        if (! $picked) {
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
     * Distinct, opted-in, deliverable members whose location falls within the
     * area's authority: authorities.polygon must ST_Contains the member's
     * point. The member's point is settings.mylocation (when both coords are
     * present) else lastlocation — the same resolution order as
     * UnifiedDigestService/resolveUserLatLng. Members with no resolvable
     * location simply don't match ST_Contains and are not mailed.
     *
     * "Deliverable" is User::scopeReceivingOurMails — the same gate the Stories
     * newsletter and the events/volunteering roundups use, and the SQL form of
     * V1's User::sendOurMails().
     */
    public function eligibleMembers(CommunityNewsArea $area)
    {
        if (! $area->authorityid) {
            return User::query()->whereRaw('1 = 0')->select('users.id');
        }

        return User::query()
            ->crossJoin('authorities')
            ->leftJoin('locations as lastloc', 'lastloc.id', '=', 'users.lastlocation')
            ->where('authorities.id', (int) $area->authorityid)
            ->where('users.newslettersallowed', 1)
            // People we should be mailing at all: not deleted, seen within
            // User::USER_INACTIVE_DAYS, simplemail not 'None', not on holiday,
            // not bouncing — the SQL form of V1's User::sendOurMails(), shared
            // with the events/volunteering roundups and the Stories newsletter.
            // Without the activity half of it the 2026-08-15 send spooled
            // 643,931 mails — every member however dormant — and the dead
            // mailboxes among them caused a mass deferral storm at the relay.
            ->receivingOurMails()
            ->whereRaw("ST_Contains(authorities.polygon, {$this->memberPointSql()})")
            ->distinct()
            ->select('users.id');
    }

    /**
     * The member's location point for ST_Contains checks: settings.mylocation
     * when both coordinates are present, else the resolved lastlocation — the
     * same resolution order as UnifiedDigestService/resolveUserLatLng.
     * Requires a `lastloc` alias for `locations` to already be joined.
     */
    private function memberPointSql(): string
    {
        $srid = (int) config('freegle.srid', 3857);

        return 'ST_SRID(POINT('.
            "CASE WHEN JSON_EXTRACT(users.settings, '$.mylocation.lat') IS NOT NULL".
            "          AND JSON_EXTRACT(users.settings, '$.mylocation.lng') IS NOT NULL".
            "     THEN CAST(JSON_EXTRACT(users.settings, '$.mylocation.lng') AS DECIMAL(10,6))".
            '     ELSE lastloc.lng END, '.
            "CASE WHEN JSON_EXTRACT(users.settings, '$.mylocation.lat') IS NOT NULL".
            "          AND JSON_EXTRACT(users.settings, '$.mylocation.lng') IS NOT NULL".
            "     THEN CAST(JSON_EXTRACT(users.settings, '$.mylocation.lat') AS DECIMAL(10,6))".
            '     ELSE lastloc.lat END'.
            "), {$srid})";
    }
}
