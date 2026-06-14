<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds the semi-localised content for the re-engagement sequence: what's
 * been freegled near the user, the local community's recent impact, and the
 * auto-login CTAs. Every piece degrades gracefully — a user with no location
 * still gets a sensible (non-localised) email rather than a broken one.
 */
class ReengageContentService
{
    /** Radius (km) for the "items near you" count and cards. */
    private const NEARBY_RADIUS_KM = 10;

    /** How many recent days count as "near you this week". */
    private const NEARBY_DAYS = 7;

    /** How far back the local-impact totals look. */
    private const IMPACT_DAYS = 30;

    public function __construct(
        private readonly NearbyOffersService $nearbyOffers = new NearbyOffersService(),
    ) {
    }

    /**
     * Build the full template data array for a given user and sequence stage.
     *
     * @return array<string, mixed> Plain strings/ints/arrays, safe to serialise
     *                              onto a queued Mailable.
     */
    public function buildContent(User $user, string $template): array
    {
        $userSite = rtrim((string) config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');
        $src = 'reengage-' . $template;

        $areaName = $this->areaName($user);

        $base = [
            'name'           => $this->firstName($user),
            'email'          => $user->email_preferred,
            'areaName'       => $areaName,
            'areaLabel'      => $areaName ?: 'your area',
            'userSite'       => $userSite,
            'findUrl'        => $user->loginLink('/find', $src),
            'giveUrl'        => $user->loginLink('/give', $src),
            'browseUrl'      => $user->loginLink('/browse', $src),
            'settingsUrl'    => $user->loginLink('/settings', $src),
            'unsubscribeUrl' => $user->listUnsubscribeUrl(),
        ];

        return match ($template) {
            'nearby'      => array_merge($base, $this->nearbyContent($user)),
            // Stage 2 is a people-and-items collage, not stat counters: item
            // photos (offers) + neighbour avatars/first names (faces).
            'impact'      => array_merge($base, $this->nearbyContent($user, 4), ['faces' => $this->nearbyFaces($user)]),
            'preferences' => array_merge($base, $this->nearbyContent($user, 0)),
            default       => $base,
        };
    }

    /**
     * Sample content for the operator preview (`mail:reengage --preview=`),
     * so the templates can be eyeballed in mailpit without real DB users.
     *
     * @return array<string, mixed>
     */
    public function previewContent(string $template, string $email): array
    {
        $userSite = rtrim((string) config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');
        $placeholder = (string) config('freegle.images.offer_placeholder', $userSite . '/placeholder-offer.png');

        $sampleOffers = collect([
            ['subject' => 'Dining chairs (x4)', 'thumbnail_url' => $placeholder, 'url' => $userSite . '/message/1'],
            ['subject' => 'Children\'s books', 'thumbnail_url' => $placeholder, 'url' => $userSite . '/message/2'],
            ['subject' => 'Garden planters', 'thumbnail_url' => $placeholder, 'url' => $userSite . '/message/3'],
            ['subject' => 'Bookshelf', 'thumbnail_url' => $placeholder, 'url' => $userSite . '/message/4'],
        ]);

        $base = [
            'name'           => 'Alex',
            'email'          => $email,
            'areaName'       => 'Edinburgh',
            'areaLabel'      => 'Edinburgh',
            'userSite'       => $userSite,
            'findUrl'        => $userSite . '/find',
            'giveUrl'        => $userSite . '/give',
            'browseUrl'      => $userSite . '/browse',
            'settingsUrl'    => $userSite . '/settings',
            'unsubscribeUrl' => $userSite . '/unsubscribe',
        ];

        $nearby = [
            'offers'      => $sampleOffers->all(),
            'offerCount'  => 47,
            'hasLocation' => true,
        ];

        $sampleFaces = [
            ['name' => 'Jane',  'avatar' => 'https://i.pravatar.cc/150?img=5'],
            ['name' => 'Mo',    'avatar' => 'https://i.pravatar.cc/150?img=12'],
            ['name' => 'Priya', 'avatar' => 'https://i.pravatar.cc/150?img=32'],
            ['name' => 'Tom',   'avatar' => 'https://i.pravatar.cc/150?img=45'],
        ];

        return match ($template) {
            'nearby'      => array_merge($base, $nearby),
            'impact'      => array_merge($base, ['offers' => $sampleOffers->all(), 'offerCount' => 47, 'hasLocation' => true, 'faces' => $sampleFaces]),
            'preferences' => array_merge($base, ['offers' => [], 'offerCount' => 47, 'hasLocation' => true]),
            default       => $base,
        };
    }

    /**
     * Nearby OFFER activity: a count of items posted near the user this week
     * plus a handful of cards. Falls back to recent offers if no location.
     *
     * @return array{offers: array, offerCount: int, hasLocation: bool}
     */
    private function nearbyContent(User $user, int $cardLimit = 4): array
    {
        [$lat, $lng] = $this->resolveLatLng($user);
        $hasLocation = $lat !== null && $lng !== null;

        $offers = $cardLimit > 0
            ? ($hasLocation
                ? $this->nearbyOffers->getOffersNearLocation((float) $lat, (float) $lng, $cardLimit)
                : $this->nearbyOffers->getRandomOffers($cardLimit))
            : collect();

        return [
            'offers'      => $offers->all(),
            'offerCount'  => $hasLocation ? $this->countNearbyOffers((float) $lat, (float) $lng) : 0,
            'hasLocation' => $hasLocation,
        ];
    }

    /**
     * Count recent approved OFFERs within the nearby box (same bounding-box
     * approximation NearbyOffersService uses).
     */
    private function countNearbyOffers(float $lat, float $lng): int
    {
        $deg = self::NEARBY_RADIUS_KM / 111.0;

        return (int) Message::query()
            ->offers()
            ->approved()
            ->notDeleted()
            ->withLocation()
            ->recent(self::NEARBY_DAYS)
            ->whereBetween('lat', [$lat - $deg, $lat + $deg])
            ->whereBetween('lng', [$lng - $deg, $lng + $deg])
            ->count();
    }

    /**
     * Real neighbours for the stage-2 collage: recent nearby posters who have
     * uploaded a profile photo, returned as first name + avatar URL. This is
     * the same public wall data freeglers already see in-app; we only include
     * users who chose to add an avatar, and show first names only.
     *
     * @return array<int, array{name: string, avatar: string}>
     */
    private function nearbyFaces(User $user, int $limit = 6): array
    {
        [$lat, $lng] = $this->resolveLatLng($user);
        if ($lat === null || $lng === null) {
            return [];
        }

        $deg = self::NEARBY_RADIUS_KM / 111.0;

        $userIds = Message::query()
            ->offers()
            ->approved()
            ->notDeleted()
            ->withLocation()
            ->recent(self::IMPACT_DAYS)
            ->whereBetween('lat', [$lat - $deg, $lat + $deg])
            ->whereBetween('lng', [$lng - $deg, $lng + $deg])
            ->whereNotNull('fromuser')
            ->orderByDesc('arrival')
            ->limit($limit * 5)
            ->pluck('fromuser')
            ->unique()
            ->take($limit * 3)
            ->all();

        if (empty($userIds)) {
            return [];
        }

        $faces = [];
        foreach (User::whereIn('id', $userIds)->get() as $poster) {
            $avatar = $poster->getProfileImageUrl();
            if (!$avatar) {
                continue;
            }

            $first = trim((string) explode(' ', trim((string) $poster->display_name))[0]);
            if ($first === '' || $first === 'Freegle') {
                continue;
            }

            $faces[] = ['name' => $first, 'avatar' => $avatar];
            if (count($faces) >= $limit) {
                break;
            }
        }

        return $faces;
    }

    /**
     * Lat/lng waterfall matching NewsfeedDigestService::resolveLatLng:
     * the saved 'mylocation' setting first, then the user's lastlocation.
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

    /**
     * A short, human place label for "near <X>": the saved location's area
     * name, else its display name, else the lastlocation name (group suffix
     * after the last comma stripped). Null when nothing is known.
     */
    private function areaName(User $user): ?string
    {
        // 1. The area/location the user explicitly picked on the site — cleanest.
        $settings = $user->settings ?? [];
        $picked = $settings['mylocation']['area']['name']
            ?? $settings['mylocation']['name']
            ?? null;
        if (is_string($picked) && trim($picked) !== '') {
            return trim($picked);
        }

        // 2. Their Freegle group's town. Group names are curated and
        //    recognizable ("Coventry Freegle"), unlike the auto-resolved
        //    lastlocation name which is often a road/polygon fragment.
        if ($group = $this->groupAreaName($user)) {
            return $group;
        }

        // 3. Last resort: the saved location's name — but only if it looks like
        //    a real place (guards out fragments like "10"/"12"). Strip any
        //    "Place, GroupSuffix" tail.
        if ($user->lastlocation) {
            $name = DB::table('locations')->where('id', $user->lastlocation)->value('name');
            if (is_string($name)) {
                if (($pos = strrpos($name, ',')) !== false) {
                    $name = substr($name, 0, $pos);
                }
                $name = trim($name);
                if ($name !== '' && preg_match('/[A-Za-z]{3,}/', $name)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * The user's Freegle group as a recognizable place label, with the
     * "Freegle" branding stripped (e.g. "Coventry Freegle" -> "Coventry").
     */
    private function groupAreaName(User $user): ?string
    {
        $groupName = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $user->id)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->orderByDesc('memberships.added')
            ->selectRaw('COALESCE(NULLIF(groups.namefull, ""), groups.nameshort) AS name')
            ->value('name');

        if (!is_string($groupName) || $groupName === '') {
            return null;
        }

        $name = trim((string) preg_replace('/\bfreegle\b/i', '', $groupName));
        $name = trim($name, " -–&");

        return $name !== '' ? $name : null;
    }

    private function firstName(User $user): string
    {
        $first = $user->firstname ?: null;
        if ($first) {
            return $first;
        }

        // Fall back to the first word of the sanitised display name.
        $display = trim((string) $user->display_name);

        return $display !== '' ? explode(' ', $display)[0] : 'there';
    }
}
