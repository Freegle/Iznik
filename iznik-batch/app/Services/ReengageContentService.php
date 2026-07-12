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
            // /give = offer something, /find = post a wanted, /browse = search
            // and see what's already been offered nearby.
            'giveUrl'         => $user->loginLink('/give', $src),
            'findUrl'         => $user->loginLink('/find', $src),
            'browseUrl'       => $user->loginLink('/browse', $src),
            'settingsUrl'     => $user->loginLink('/settings', $src),
            'unsubscribeUrl'  => $user->listUnsubscribeUrl(),
            'volunteerName'   => $volunteer['name'] ?? null,
            'volunteerGroup'  => $volunteer['group'] ?? null,
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
            'findUrl'        => $userSite . '/find',
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
                ],
                'highlight' => [
                    'title' => 'What makes a good offer',
                    'items' => [
                        'A clear title - just say what it is ("Single duvet", "Pine bookshelf").',
                        'A photo - even a quick phone snap makes all the difference.',
                        "The condition - honest is perfect. Well-loved and working is very welcome.",
                        'Roughly where you are, so people know if they can collect.',
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
                        'Roughly where you are.',
                        "Stay open on brand and condition - you'll get more offers.",
                    ],
                ],
                'ctaLabel' => 'Post a wanted',
                'ctaUrl'   => $base['findUrl'],
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
     * Find a real local volunteer to sign the tip off. Mirrors BirthdayService's
     * "local volunteer" rule so the two stay consistent:
     *   - only communities the member GENUINELY joined (collection Approved,
     *     rippled = 0 - never a Rippling-Out auto-join);
     *   - nearest to the member first (haversine on the group centroid), so the
     *     sign-off feels local rather than from the far side of the country;
     *   - the volunteer must be an active (accessed within a year), publicly
     *     shown (users.settings.showmod, default true) Moderator/Owner.
     *
     * Returns null when there's no such volunteer, so the caller falls back to
     * the plain Freegle voice.
     *
     * @return array{name: string, group: string}|null
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
            // Nearest genuine community first; groups with no centroid sort last.
            $groupsQuery->orderByRaw(
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
                    'name'  => $first,
                    'group' => (string) ($group->namefull ?: $group->nameshort),
                ];
            }
        }

        return null;
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
