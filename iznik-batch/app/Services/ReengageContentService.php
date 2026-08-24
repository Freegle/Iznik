<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds the content for the first-week ONBOARDING tip sequence (one short,
 * friendly tip a day for a new member's first five days, following the welcome
 * mail). Kept under the historical "reengage" name (table/classes/dashboard) but
 * the purpose is onboarding, not lapsed-user win-back.
 *
 * Every tip is signed off by a REAL local volunteer where we can find one: an
 * active, publicly-shown moderator/owner of a community the member genuinely
 * joined (nearest first, rippled-in memberships excluded). If there's no such
 * volunteer we fall back to the plain Freegle voice rather than inventing one.
 */
class ReengageContentService
{
    /** Total tips in the sequence (day 1..N). */
    public const TIPS = 5;

    /**
     * SRID that `groups.polyindex` is stored under (GroupStatsService::SRID).
     * MySQL treats 3857 as Cartesian, which is what the containment test and
     * ST_Area comparison below rely on.
     */
    private const SRID = 3857;

    /**
     * Build the full template data for a given new member and day (1..TIPS).
     *
     * @return array<string, mixed> Plain strings/ints/arrays, safe to serialise
     *                              onto a queued Mailable.
     */
    public function buildContent(User $user, int $day): array
    {
        $userSite = rtrim((string) config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');
        $src = 'onboard-' . $day;

        $volunteer = $this->resolveVolunteer($user);

        $base = [
            'name'            => $this->firstName($user),
            'email'           => $user->email_preferred,
            'userSite'        => $userSite,
            // /give = offer something, /ask = post a wanted, /browse = search
            // and see what's already been offered nearby.
            'giveUrl'         => $user->loginLink('/give', $src),
            'askUrl'         => $user->loginLink('/ask', $src),
            'browseUrl'       => $user->loginLink('/browse', $src),
            'settingsUrl'     => $user->loginLink('/settings', $src),
            'unsubscribeUrl'  => $user->listUnsubscribeUrl(),
            'volunteerName'   => $volunteer['name'] ?? null,
            'volunteerGroup'  => $volunteer['group'] ?? null,
            // Not rendered - carried through so ReengageService can record HOW the
            // sign-off community was chosen (see the sysadmin Sign-off tab). Scalars
            // only: objects in Mailable view data segfault PHP 8.4.
            'volunteerGroupId' => $volunteer['groupid'] ?? null,
            // 'home' | 'nearest' | 'unknown' when we found someone, else 'none'.
            'volunteerSource'  => $volunteer['source'] ?? 'none',
        ];

        return array_merge($base, $this->tip($day, $base));
    }

    /**
     * Sample content for the operator preview (`mail:reengage --preview=`), so
     * each day's tip can be eyeballed in mailpit without a real DB user. Renders
     * with a sample local-volunteer sign-off.
     *
     * @return array<string, mixed>
     */
    public function previewContent(int $day, string $email): array
    {
        $userSite = rtrim((string) config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');

        $base = [
            'name'           => 'Alex',
            'email'          => $email,
            'userSite'       => $userSite,
            'giveUrl'        => $userSite . '/give',
            'askUrl'        => $userSite . '/ask',
            'browseUrl'      => $userSite . '/browse',
            'settingsUrl'    => $userSite . '/settings',
            'unsubscribeUrl' => $userSite . '/unsubscribe',
            'volunteerName'  => 'Priya',
            'volunteerGroup' => 'Edinburgh Freegle',
        ];

        return array_merge($base, $this->tip($day, $base));
    }

    /**
     * The tip copy for a given day. Each returns a heading, a short intro, one or
     * more body paragraphs, an optional highlight box (title + bullet list), a
     * preheader (inbox preview line) and a single clear call to action.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function tip(int $day, array $base): array
    {
        $day = max(1, min(self::TIPS, $day));
        $total = self::TIPS;

        $tips = [
            1 => [
                'preheader' => "One short tip a day for your first five days - here's the first.",
                'heading'   => 'Welcome to Freegle!',
                'intro'     => "Over the next five days we'll send you one short tip a day to help you get the hang of things. Here's the first.",
                'body'      => [
                    "The heart of Freegle is offering things you no longer need to neighbours who do. A good post gets snapped up fast, and it only takes a moment.",
                    "When someone wants it, you'll arrange a quick doorstep handover - sort the details out in Freegle chat rather than in a public post, and a little common sense keeps it lovely.",
                ],
                'highlight' => [
                    'title' => 'What makes a good offer',
                    'items' => [
                        'A clear title - just say what it is ("Single duvet", "Pine bookshelf").',
                        'A photo - even a quick phone snap makes all the difference.',
                        "The condition - well-loved is fine, and even broken things can find a home, but people need to know what they're getting.",
                    ],
                ],
                'ctaLabel' => 'Offer something',
                'ctaUrl'   => $base['giveUrl'],
            ],
            2 => [
                'preheader' => "Don't assume nobody wants it - on Freegle, they nearly always do.",
                'heading'   => 'The stuff you think nobody wants',
                'intro'     => "Today's tip is the one that surprises people most.",
                'body'      => [
                    "Have a look in that drawer you never open, the back of the cupboard, the shed. The half-tin of paint, the odd cables, the jam jars, the fabric offcuts, the clothes the kids grew out of.",
                    "It's tempting to think \"nobody would want this\". On Freegle, they nearly always do. The things sitting neglected are exactly what someone nearby is hunting for - and it's genuinely surprising what gets claimed within the hour.",
                ],
                'highlight' => [
                    'title' => 'Often snapped up in no time',
                    'items' => [
                        'Half-used craft, hobby and DIY bits',
                        'Spare cables, chargers, pots and jars',
                        'Curtains, offcuts and odd balls of wool',
                        "Kids' clothes and toys they've outgrown",
                    ],
                ],
                'ctaLabel' => 'Offer something',
                'ctaUrl'   => $base['giveUrl'],
            ],
            3 => [
                'preheader' => "Freegle isn't only for giving - you can ask for what you need, too.",
                'heading'   => 'Need something? Just ask',
                'intro'     => "Freegle isn't only for giving - it's for getting, too.",
                'body'      => [
                    "If there's something you're after, post a wanted and let your neighbours know. People love helping out, and it saves usable things from heading to landfill.",
                ],
                'highlight' => [
                    'title' => 'What makes a good wanted',
                    'items' => [
                        "Say clearly what you're after.",
                        'Add a line of why - it helps someone think "I\'ve got just the thing".',
                        "Stay open on brand and condition - you'll get more offers.",
                    ],
                ],
                'ctaLabel' => 'Post a wanted',
                'ctaUrl'   => $base['askUrl'],
            ],
            4 => [
                'preheader' => 'You can search for things that are already being offered nearby.',
                'heading'   => 'You can search for things',
                'intro'     => "Waiting and hoping isn't the only option.",
                'body'      => [
                    "Freegle has a search. Type in what you need - a desk, a travel cot, a particular book - and see what's already been offered near you right now.",
                    "New things are posted all the time, so it's worth a look, and worth saving a search so the right one doesn't pass you by.",
                ],
                'ctaLabel' => 'Search Freegle',
                'ctaUrl'   => $base['browseUrl'],
            ],
            5 => [
                'preheader' => "That's the lot - you're a freegler now.",
                'heading'   => "You're a freegler now",
                'intro'     => "That's the lot - you've got the hang of it.",
                'body'      => [
                    "A quick recap: offer the things you're not using, ask for what you need, and search for anything specific. Then keep an eye on what's popping up nearby.",
                    "One last thing. Freegle works because of neighbours being kind to each other - reply promptly, arrange an easy doorstep pickup, and say thanks. That's really all there is to it.",
                ],
                'highlight' => [
                    'title' => 'Your Freegle in a nutshell',
                    'items' => [
                        'Offer the things you no longer need',
                        'Ask for the things you do',
                        'Search for anything specific',
                        'Be a good neighbour',
                    ],
                ],
                'ctaLabel' => 'See what\'s near you',
                'ctaUrl'   => $base['browseUrl'],
            ],
        ];

        return array_merge($tips[$day], ['day' => $day, 'totalDays' => $total]);
    }

    /**
     * Find a real local volunteer to sign the tip off:
     *   - only communities the member GENUINELY joined (collection Approved,
     *     rippled = 0 - never a Rippling-Out auto-join);
     *   - the member's HOME group first: the community whose catchment actually
     *     contains where they live (see below);
     *   - the volunteer must be an active (accessed within a year), publicly
     *     shown (users.settings.showmod, default true) Moderator/Owner.
     *
     * Home group is decided by catchment CONTAINMENT, not centre distance.
     * `groups.polyindex` is the group's real area (DPA `poly`, else CGA
     * `polyofficial`), so "which community is this member in?" is a point-in-
     * polygon test. Ordering by haversine to `groups.lat/lng` instead gets big or
     * awkwardly-shaped catchments wrong, because a group's centre can sit far
     * from much of its own area - Bristol being the case that prompted this. A
     * member well inside Bristol's catchment can be nearer the CENTRE of a
     * smaller neighbouring group, and would be signed off by the wrong community.
     *
     * The Go side already learned this (location.go ClosestGroups, bug #9518:
     * "polygon containment is authoritative and beats any centre-distance
     * heuristic"), and this matches it, including the POINT(lng, lat)/SRID
     * convention. Centre distance stays only as the tiebreak for members whose
     * point falls in no catchment at all.
     *
     * Returns null when there's no such volunteer, so the caller falls back to
     * the plain Freegle voice.
     *
     * @return array{name: string, group: string, groupid: int, source: string}|null
     */
    public function resolveVolunteer(User $user): ?array
    {
        [$lat, $lng] = $this->resolveLatLng($user);

        $groupsQuery = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $user->id)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('memberships.rippled', 0)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->select('groups.id', 'groups.nameshort', 'groups.namefull');

        if ($lat !== null && $lng !== null) {
            // A degenerate polyindex is stored as POINT(0 0) for groups with no
            // real area, so exclude those rather than let them match nothing
            // noisily. Same guard the rippling code uses.
            $containsSql = "(groups.polyindex IS NOT NULL"
                . " AND ST_GeometryType(groups.polyindex) <> 'POINT'"
                . " AND ST_Contains(groups.polyindex, ST_SRID(POINT(?, ?), " . self::SRID . ')))';

            // Recorded so the sysadmin dashboard can show how often the home
            // group was genuinely known rather than guessed at by distance.
            $groupsQuery->selectRaw($containsSql . ' AS is_home', [$lng, $lat]);

            $groupsQuery
                // 1. Catchment containment wins outright.
                ->orderByRaw($containsSql . ' DESC', [$lng, $lat])
                // 2. Overlapping catchments: the smallest (most local) one.
                ->orderByRaw(
                    'CASE WHEN ' . $containsSql . ' THEN ST_Area(groups.polyindex) ELSE 0 END ASC',
                    [$lng, $lat]
                )
                // 3. No containing catchment: fall back to nearest centre.
                ->orderByRaw(
                    'groups.lat IS NULL, haversine(?, ?, groups.lat, groups.lng) ASC',
                    [$lat, $lng]
                );
        }

        $groups = $groupsQuery->limit(8)->get();
        $oneYearAgo = now()->subYear()->toDateTimeString();

        foreach ($groups as $group) {
            $mod = DB::table('memberships')
                ->join('users', 'users.id', '=', 'memberships.userid')
                ->where('memberships.groupid', $group->id)
                ->where('memberships.collection', Membership::COLLECTION_APPROVED)
                ->whereIn('memberships.role', ['Moderator', 'Owner'])
                ->whereNull('users.deleted')
                ->where('users.lastaccess', '>=', $oneYearAgo)
                ->whereRaw("(JSON_EXTRACT(users.settings, '$.showmod') IS NULL OR JSON_EXTRACT(users.settings, '$.showmod') = TRUE)")
                ->inRandomOrder()
                ->select('users.fullname', 'users.firstname')
                ->first();

            if ($mod) {
                $display = trim((string) ($mod->fullname ?: $mod->firstname ?: 'Volunteer'));
                $first = $display !== '' ? explode(' ', $display)[0] : 'Volunteer';

                return [
                    'name'    => $first,
                    'group'   => (string) ($group->namefull ?: $group->nameshort),
                    'groupid' => (int) $group->id,
                    // 'home'    = their catchment contains where they live.
                    // 'nearest' = no catchment matched; nearest centre used.
                    // 'unknown' = we had no location to test at all.
                    'source'  => $this->volunteerSource($group, $lat, $lng),
                ];
            }
        }

        return null;
    }

    /**
     * How a chosen sign-off group was arrived at, for the sysadmin breakdown.
     * `is_home` is only selected when we had a location to test.
     */
    private function volunteerSource(object $group, ?float $lat, ?float $lng): string
    {
        if ($lat === null || $lng === null) {
            return 'unknown';
        }

        // MySQL returns the boolean expression as 1/0 (possibly the string "0"),
        // so compare explicitly rather than lean on empty()'s "0" quirk.
        return ((int) ($group->is_home ?? 0)) === 1 ? 'home' : 'nearest';
    }

    /**
     * Lat/lng waterfall matching NewsfeedDigestService::resolveLatLng: the saved
     * 'mylocation' setting first, then the user's lastlocation.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function resolveLatLng(User $user): array
    {
        $settings = $user->settings ?? [];
        if (isset($settings['mylocation']['lat'], $settings['mylocation']['lng'])) {
            return [(float) $settings['mylocation']['lat'], (float) $settings['mylocation']['lng']];
        }

        return $user->getLatLng();
    }

    private function firstName(User $user): string
    {
        $first = $user->firstname ?: null;
        if ($first) {
            return $first;
        }

        $display = trim((string) $user->display_name);

        return $display !== '' ? explode(' ', $display)[0] : 'there';
    }
}
