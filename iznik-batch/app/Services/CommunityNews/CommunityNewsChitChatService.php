<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\Newsfeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ChitChat engagement trial: drip-post Community News items to the newsfeed
 * (a "ChitChat" post is a newsfeed row of type 'Message') as the Freegle account,
 * placed at the area centre so members nearby see it in their feed. Every few
 * days per area. Engagement (loves + replies) is read back to judge the trial
 * before committing to the email channel.
 */
class CommunityNewsChitChatService
{
    /**
     * Posted as type Alert, not Message: the clients render Alert posts with a
     * hard-coded Freegle logo and "Freegle" byline (NewsAlert.vue), which makes
     * the provenance unmistakable — the system account's own avatar is just a
     * gravatar identicon. They are excluded from the nearby-distance calculation
     * so they can't stretch anyone's feed radius.
     *
     * These posts are left UNPINNED deliberately. The feed query serves pinned
     * alerts from any location (they are central Freegle announcements) but
     * filters unpinned ones by MBRContains like any other post, and caps them at
     * NEWSFEED_ALERTS_PER_FEED so a news run cannot crowd out members' own
     * ChitChat. Pinning one of these would push it nationwide.
     */
    public const POST_TYPE = 'Alert';

    public function __construct(private CommunityNewsImageService $images)
    {
    }

    /**
     * Resolve the "Freegle" system user id to post as (idle noreply account).
     */
    public function systemUserId(): ?int
    {
        $email = config('freegle.communitynews.system_user_email', 'noreply@ilovefreegle.org');
        $id = DB::table('users_emails')->where('email', $email)->value('userid');

        return $id ? (int) $id : null;
    }

    /**
     * Drip un-posted items for every area that is due.
     *
     * @return array{posts:int, areas:int}
     */
    public function drip(bool $dryRun = false, ?int $onlyAreaId = null, bool $force = false, ?int $itemsPerPost = null): array
    {
        $perPost = max(1, $itemsPerPost ?? (int) config('freegle.communitynews.chitchat_items_per_post', 1));
        $minDays = (int) config('freegle.communitynews.chitchat_min_days', 3);
        $freshDays = (int) config('freegle.communitynews.item_freshness_days', 10);

        $systemUserId = $this->systemUserId();
        if (!$systemUserId) {
            Log::warning('CommunityNews ChitChat: no system user for ' . config('freegle.communitynews.system_user_email'));
            return ['posts' => 0, 'areas' => 0];
        }

        $query = CommunityNewsArea::query();
        if ($onlyAreaId) {
            $query->where('id', $onlyAreaId);
        }

        $posts = 0;
        $areasPosted = 0;

        foreach ($query->get() as $area) {
            if (!$force && $area->lastposted && $area->lastposted->gt(now()->subDays($minDays))) {
                continue; // posted too recently
            }

            $items = CommunityNewsItem::where('areaid', $area->id)
                ->whereNull('posted_at')
                ->where('researched_at', '>', now()->subDays($freshDays))
                // Same rule as the newsletter: never tell people about something
                // that has already happened. An item stays eligible for days after
                // it was researched, so an event found last week can easily be over
                // by the time it reaches the top of the queue. Undated items are
                // unaffected; today's events still count.
                ->where(function ($q) {
                    $q->whereNull('event_date')
                        ->orWhere('event_date', '>=', now()->startOfDay());
                })
                ->orderBy('id')
                ->limit($perPost)
                ->get();

            if ($items->isEmpty()) {
                continue;
            }

            $anyPosted = false;
            foreach ($items as $item) {
                $newsfeedId = $this->postItem($area, $item, $systemUserId, $dryRun);
                if ($newsfeedId === null) {
                    continue;
                }
                if (!$dryRun) {
                    $item->update(['newsfeedid' => $newsfeedId, 'posted_at' => now()]);
                }
                $posts++;
                $anyPosted = true;
            }

            if ($anyPosted) {
                if (!$dryRun) {
                    $area->update(['lastposted' => now()]);
                }
                $areasPosted++;
            }
        }

        return ['posts' => $posts, 'areas' => $areasPosted];
    }

    /**
     * Insert one newsfeed row for an item. Returns the new id, or null if the
     * dup-guard skipped it. In dry-run, returns 0 without writing.
     */
    public function postItem(CommunityNewsArea $area, CommunityNewsItem $item, int $systemUserId, bool $dryRun): ?int
    {
        $message = $this->composeMessage($item);

        // Duplicate guard (mirrors the Go createPost check): skip if this author's
        // most recent newsfeed row is an identical top-level post. Guards the
        // unattended "every few days" cadence against re-posting the same text.
        $last = DB::table('newsfeed')
            ->where('userid', $systemUserId)
            ->orderByDesc('id')
            ->first(['type', 'message', 'replyto']);
        if ($last && $last->type === self::POST_TYPE && $last->replyto === null && $last->message === $message) {
            Log::info('CommunityNews ChitChat: duplicate skipped', ['area' => $area->id, 'item' => $item->id]);
            return null;
        }

        if ($dryRun) {
            return 0;
        }

        $srid = (int) config('freegle.srid', 3857);
        $lng = (float) $area->lng;
        $lat = (float) $area->lat;

        $newsfeed = new Newsfeed();
        $newsfeed->type = self::POST_TYPE;
        $newsfeed->userid = $systemUserId;
        $newsfeed->message = $message;
        $newsfeed->html = $this->composeHtml($item);
        $newsfeed->location = mb_substr($area->name, 0, 80);
        // Geometry via a raw expression, exactly like Group::boot() sets polyindex.
        // keep-raw: ST_GeomFromText() is a spatial function with no builder method.
        $newsfeed->position = DB::raw("ST_GeomFromText('POINT($lng $lat)', $srid)");
        $newsfeed->save();

        // Best-effort picture from the source page's og:image; a post without a
        // picture is fine, so failures only log.
        $image = $this->images->uploadItemImage($item);
        if ($image) {
            $imageid = DB::table('newsfeed_images')->insertGetId([
                'newsfeedid' => $newsfeed->id,
                'externaluid' => $image['externaluid'],
                'contenttype' => $image['contenttype'],
                'archived' => 0,
            ]);
            $newsfeed->imageid = $imageid;
            $newsfeed->save();
        }

        return (int) $newsfeed->id;
    }

    public function composeMessage(CommunityNewsItem $item): string
    {
        // Title as an opening line; add a full stop only if it doesn't already
        // end in sentence punctuation (so "What's on?" doesn't become "What's on?.").
        $title = trim($item->title);
        if ($title !== '' && !preg_match('/[.!?…]$/u', $title)) {
            $title .= '.';
        }

        $parts = [$title, trim($item->snippet)];
        if (!empty($item->url)) {
            $parts[] = $item->url;
        }

        return trim(implode("\n\n", array_filter($parts, fn ($p) => $p !== '')));
    }

    /**
     * HTML rendering for the newsfeed `html` column: the title becomes a
     * hyperlink instead of the bare URL line, so the post reads cleanly and the
     * client suppresses its stacked preview cards. The plain `message` is still
     * always set — the newsfeed digest email, notifications and the duplicate
     * guard read that. `html` is trusted display markup (not settable through
     * the public API and rendered unescaped by the clients), so every value
     * interpolated here must be escaped, and only http(s) URLs are linked.
     */
    public function composeHtml(CommunityNewsItem $item): string
    {
        $title = trim($item->title);
        if ($title !== '' && !preg_match('/[.!?…]$/u', $title)) {
            $title .= '.';
        }
        $title = e($title);

        $url = trim((string) $item->url);
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $title = '<a href="' . e($url) . '" target="_blank" rel="noopener">' . $title . '</a>';
        }

        $parts = ['<p><strong>' . $title . '</strong></p>'];

        $snippet = trim($item->snippet);
        if ($snippet !== '') {
            $parts[] = '<p>' . e($snippet) . '</p>';
        }

        return implode('', $parts);
    }

    /**
     * Engagement for the posts this feature created (loves + direct replies).
     *
     * @return array<int, array{newsfeedid:int, title:string, area:string, loves:int, replies:int, posted_at:?string}>
     */
    public function engagement(?int $onlyAreaId = null): array
    {
        $query = CommunityNewsItem::query()->with('area')->whereNotNull('newsfeedid');
        if ($onlyAreaId) {
            $query->where('areaid', $onlyAreaId);
        }

        $rows = [];
        foreach ($query->orderBy('posted_at')->get() as $item) {
            $rows[] = [
                'newsfeedid' => (int) $item->newsfeedid,
                'title' => $item->title,
                'area' => optional($item->area)->name ?? '',
                'loves' => (int) DB::table('newsfeed_likes')->where('newsfeedid', $item->newsfeedid)->count(),
                'replies' => (int) DB::table('newsfeed')
                    ->where('replyto', $item->newsfeedid)
                    ->whereNull('deleted')
                    ->count(),
                'posted_at' => optional($item->posted_at)?->toDateTimeString(),
            ];
        }

        return $rows;
    }
}
