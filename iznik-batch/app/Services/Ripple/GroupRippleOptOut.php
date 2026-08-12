<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\DB;

/**
 * Per-community rippling opt-out, stored in `groups.settings`:
 *
 *     { "rippling": { "out": 0, "in": 0 } }
 *
 *   out = off  -> posts made ON this community never start rippling, so nothing
 *                 posted here is ever crossposted elsewhere or surfaced in another
 *                 member's nearby feed (ExpandService::initialiseNew skips them, and
 *                 any reach they already had is retracted).
 *   in  = off  -> other communities' posts are never rippled INTO this one
 *                 (ExpandService::rippleIntoNewGroups skips it as a target).
 *
 * The two directions are separate because they are separate decisions: a community
 * that doesn't want the extra moderation load of rippled-in posts may still be happy
 * for its own posts to travel, and vice versa.
 *
 * ABSENT MEANS ON. Every community ripples in both directions unless it has explicitly
 * said otherwise, so deploying this changes nothing until a setting is written.
 *
 * Set ONLY by `php artisan ripple:opt-out` (App\Console\Commands\Ripple\OptOutCommand).
 * Deliberately not exposed to moderators: which communities are phantom is a central
 * decision, not something a community configures for itself.
 *
 * Why the phantom/training communities need it: FreeglePlayground places its practice
 * posts at a real Edinburgh postcode, and before this the only exclusion rippling knew
 * about was `nameshort NOT LIKE '%playground%'` on the ripple-IN target list. Nothing
 * gated ripple-OUT at all, so a practice post would crosspost into the real Lothians
 * communities and show up in real members' nearby feeds.
 *
 * Resolution happens in PHP, not in MySQL JSON SQL, deliberately: a JSON null CASTs to
 * 0, so an SQL truth test would read a malformed value as "opted out" and silently stop
 * a real community rippling. See isOff() for the fail-safe direction.
 */
class GroupRippleOptOut
{
    /** Posts made on the community spreading outwards. */
    public const DIRECTION_OUT = 'out';

    /** Other communities' posts being rippled in. */
    public const DIRECTION_IN = 'in';

    /** @var array<string, list<int>>|null Memoized for the life of the instance (one batch run). */
    private ?array $excluded = null;

    /**
     * Group ids that have opted OUT of the given direction.
     *
     * Returned as a plain int list so callers can splice it straight into SQL — every
     * element comes from the DB as an int, so there is nothing to inject.
     *
     * @return list<int>
     */
    public function excludedGroupIds(string $direction): array
    {
        if ($this->excluded === null) {
            $this->excluded = $this->load();
        }

        return $this->excluded[$direction] ?? [];
    }

    /** True when the community still permits rippling in the given direction. */
    public function permits(int $groupid, string $direction): bool
    {
        return !in_array($groupid, $this->excludedGroupIds($direction), true);
    }

    /** Test-only: forget the memoized set after changing a group's settings mid-test. */
    public function forget(): void
    {
        $this->excluded = null;
    }

    /**
     * Read every community that has a `rippling` entry in its settings and sort the
     * opt-outs by direction.
     *
     * The LIKE is a cheap prefilter on a ~2k-row table (settings is a small blob and
     * almost no community sets this), so the common case decodes a handful of rows.
     *
     * @return array<string, list<int>>
     */
    private function load(): array
    {
        $excluded = [self::DIRECTION_OUT => [], self::DIRECTION_IN => []];

        $rows = DB::table('groups')
            ->where('settings', 'like', '%rippling%')
            ->get(['id', 'settings']);

        foreach ($rows as $row) {
            $settings = json_decode((string) $row->settings, true);
            if (!is_array($settings) || !isset($settings['rippling']) || !is_array($settings['rippling'])) {
                continue;
            }

            foreach ([self::DIRECTION_OUT, self::DIRECTION_IN] as $direction) {
                if (!array_key_exists($direction, $settings['rippling'])) {
                    continue;
                }
                if (self::isOff($settings['rippling'][$direction])) {
                    $excluded[$direction][] = (int) $row->id;
                }
            }
        }

        return $excluded;
    }

    /**
     * Is this stored value an explicit "off"?
     *
     * ripple:opt-out and the seeding migration both write integer 0, but a hand-edited blob
     * may hold a real JSON boolean or a string, so all the plausible spellings of false count.
     *
     * Everything else, INCLUDING a malformed or unexpected value, leaves rippling ON. The
     * fail-safe direction matters: wrongly-on ripples a phantom post (visible, and a mod
     * can reject the copy), wrongly-off silently stops a real community rippling and
     * nobody would notice for weeks.
     */
    private static function isOff(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0' || $value === 'false';
    }
}
