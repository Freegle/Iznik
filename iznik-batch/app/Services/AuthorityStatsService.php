<?php

namespace App\Services;

use App\Models\Message;
use App\Support\ReuseBenefit;
use Illuminate\Support\Facades\DB;

/**
 * Reads the data behind the per-authority statistics report that councils
 * receive: membership, weight reused, CO2 and financial benefit, gifts made,
 * a per-postcode breakdown, shortlink clicks and member stories.
 *
 * The spreadsheet rendering lives in the AuthorityStatsCommand; this service
 * returns plain arrays so the numbers can be asserted in isolation.
 *
 * Spatial note: geometries are stored under SRID 3857 with coordinates that are
 * WGS84 degrees, so points are built from lng/lat degree values tagged SRID 3857
 * to match the stored polygons when testing containment.
 */
class AuthorityStatsService
{
    // Stat types (match the `stats`.`type` enum).
    public const APPROVED_MEMBER_COUNT = 'ApprovedMemberCount';
    public const WEIGHT = 'Weight';
    public const OUTCOMES = 'Outcomes';
    public const SEARCHES = 'Searches';

    // Only Freegle groups, published and on the map, count towards an authority.
    public const GROUP_FREEGLE = 'Freegle';

    // Fallback location (roughly the centre of the UK) used when a member's
    // location cannot be resolved any other way.
    private const DEFAULT_LAT = 53.9450;
    private const DEFAULT_LNG = -2.5209;

    private function srid(): int
    {
        return (int) config('freegle.srid', 3857);
    }

    /**
     * The three calendar months of the last full quarter relative to
     * $quarterStart (any strtotime-parsable string, e.g. "3 months ago").
     *
     * Each month: start (Y-m-d, first day), end (Y-m-d, first day of the NEXT
     * month - an exclusive upper bound), formatted (e.g. "Jan-26").
     *
     * @return array<int, array{start:string, end:string, formatted:string}>
     */
    public function getMonths(string $quarterStart): array
    {
        $startDate = strtotime($quarterStart);
        $startQuarter = (int) ceil((int) date('m', $startDate) / 3);
        $startMonth = ($startQuarter * 3) - 2;
        $startYear = (int) date('Y', $startDate);
        $ts = mktime(0, 0, 0, $startMonth, 1, $startYear);

        $months = [];
        for ($i = 0; $i < 3; $i++) {
            $end = strtotime('+1 month', $ts);
            $months[] = [
                'start' => date('Y-m-d', $ts),
                'end' => date('Y-m-d', $end),
                'formatted' => date('M-y', $ts),
            ];
            $ts = $end;
        }

        return $months;
    }

    /** Quarter number (1-4) that $quarterStart falls in. */
    public function getQuarterNumber(string $quarterStart): int
    {
        return (int) ceil((int) date('n', strtotime($quarterStart)) / 3);
    }

    /** Benefit of reuse per tonne in GBP, at current-year prices. */
    public function getBenefitPerTonne(): float
    {
        return ReuseBenefit::getBenefitPerTonne();
    }

    /** tCO2eq saved per tonne reused. */
    public function getCo2PerTonne(): float
    {
        return ReuseBenefit::CO2_PER_TONNE;
    }

    /**
     * Everything the report needs for one authority, as plain arrays.
     *
     * Weights are raw kilograms; the command applies the WRAP CO2/benefit
     * conversions when rendering. Members are rounded per group and then summed
     * for the authority total.
     *
     * @return array{
     *   name:string, quarter:int, year:string,
     *   months:array<int, array{start:string,end:string,formatted:string}>,
     *   benefitPerTonne:float, co2PerTonne:float,
     *   totals:array<int, array{members:int, weight:float, outcomes:float}>,
     *   groups:array<int, array{namedisplay:string, members:array<int,int>, weight:array<int,float>, outcomes:array<int,float>}>,
     *   shortlinks:array<int, array{id:int, name:string, clicks:array<int,int>}>,
     *   stories:array<int, array{headline:string, story:string}>,
     *   postcodes:array<string, array{Offer:int,Wanted:int,Searches:int,Outcomes:int,Weight:float}>
     * }|null
     */
    public function computeReport(int $authorityId, string $quarterStart): ?array
    {
        $months = $this->getMonths($quarterStart);
        $authority = $this->getAuthority($authorityId);
        if ($authority === null) {
            return null;
        }

        // Keep only groups that reused more than 3 kg over the whole quarter,
        // so trivial overlaps do not clutter the report.
        $nontrivial = [];
        foreach ($authority['groups'] as $group) {
            $stats = $this->getMultiStats([$group['id']], $months[0]['start'], $months[2]['end'], [self::WEIGHT]);
            $totWeight = 0.0;
            foreach ($stats[self::WEIGHT] as $stat) {
                $totWeight += $stat['count'] * $group['overlap'];
            }
            if ($totWeight > 3) {
                $nontrivial[] = $group;
            }
        }

        $types = [self::APPROVED_MEMBER_COUNT, self::WEIGHT, self::OUTCOMES];
        $totals = [];
        $perGroup = [];

        for ($m = 0; $m < 3; $m++) {
            $totals[$m] = ['members' => 0, 'weight' => 0.0, 'outcomes' => 0.0];

            foreach ($nontrivial as $group) {
                $gid = $group['id'];
                $overlap = $group['overlap'];
                $stats = $this->getMultiStats([$gid], $months[$m]['start'], $months[$m]['end'], $types);

                // Members: the count on the latest date in the month.
                $members = 0.0;
                foreach ($stats[self::APPROVED_MEMBER_COUNT] as $stat) {
                    $members = round($stat['count'] * $overlap);
                }

                // Weight and outcomes: summed across the month.
                $weight = 0.0;
                foreach ($stats[self::WEIGHT] as $stat) {
                    $weight += $stat['count'] * $overlap;
                }
                $outcomes = 0.0;
                foreach ($stats[self::OUTCOMES] as $stat) {
                    $outcomes += $stat['count'] * $overlap;
                }

                $perGroup[$gid][$m] = [
                    'members' => (int) $members,
                    'weight' => $weight,
                    'outcomes' => $outcomes,
                ];

                $totals[$m]['members'] += (int) $members;
                $totals[$m]['weight'] += $weight;
                $totals[$m]['outcomes'] += $outcomes;
            }
        }

        // Per-group rows and shortlinks, only for groups that still have members
        // in the final month (this drops the tiniest overlaps).
        $groups = [];
        $links = [];
        foreach ($nontrivial as $group) {
            $gid = $group['id'];
            if (empty($perGroup[$gid][2]['members'])) {
                continue;
            }

            $groups[] = [
                'namedisplay' => $group['namedisplay'] . ($group['overlap'] < 1 ? ' *' : ''),
                'members' => [$perGroup[$gid][0]['members'], $perGroup[$gid][1]['members'], $perGroup[$gid][2]['members']],
                'weight' => [$perGroup[$gid][0]['weight'], $perGroup[$gid][1]['weight'], $perGroup[$gid][2]['weight']],
                'outcomes' => [$perGroup[$gid][0]['outcomes'], $perGroup[$gid][1]['outcomes'], $perGroup[$gid][2]['outcomes']],
            ];

            foreach ($this->getShortlinks($gid) as $link) {
                $links[] = [
                    'id' => $link['id'],
                    'name' => $link['name'],
                    'clicks' => $this->bucketClicksByMonth($this->getClickHistory($link['id']), $months),
                ];
            }
        }

        usort($links, static fn ($a, $b) => strcmp(strtolower($a['name']), strtolower($b['name'])));

        return [
            'name' => $authority['name'],
            'quarter' => $this->getQuarterNumber($quarterStart),
            'year' => date('Y'),
            'months' => $months,
            'benefitPerTonne' => $this->getBenefitPerTonne(),
            'co2PerTonne' => $this->getCo2PerTonne(),
            'totals' => $totals,
            'groups' => $groups,
            'shortlinks' => $links,
            'stories' => $this->getStories($authorityId, 10),
            'postcodes' => $this->getByAuthority([$authorityId], $months[0]['start'], $months[2]['end']),
        ];
    }

    /**
     * Authority name plus the Freegle groups overlapping it.
     *
     * Each returned group: id, namedisplay, overlap (fraction of the group's
     * area inside the authority, rounded up to 1 when above 0.95).
     *
     * @return array{name:string, groups:array<int, array{id:int, namedisplay:string, overlap:float}>}|null
     */
    public function getAuthority(int $id): ?array
    {
        $auth = DB::table('authorities')->where('id', $id)->first(['id', 'name']);
        if (!$auth) {
            return null;
        }

        // Overlap of each group's polyindex with the authority polygon, in both
        // directions, so we can keep any group that meaningfully intersects.
        $rows = DB::select(
            "SELECT groups.id AS id, nameshort, namefull,
                CASE WHEN ST_GeometryType(ST_Intersection(polyindex, COALESCE(simplified, polygon))) IN ('POLYGON', 'MULTIPOLYGON') THEN
                    CASE WHEN polyindex = COALESCE(simplified, polygon) THEN 1
                    ELSE ST_Area(ST_Intersection(polyindex, COALESCE(simplified, polygon))) / ST_Area(polyindex)
                    END
                ELSE 0
                END AS overlap,
                CASE WHEN ST_GeometryType(ST_Intersection(polyindex, COALESCE(simplified, polygon))) IN ('POLYGON', 'MULTIPOLYGON') THEN
                    CASE WHEN polyindex = COALESCE(simplified, polygon) THEN 1
                    ELSE ST_Area(polyindex) / ST_Area(ST_Intersection(polyindex, COALESCE(simplified, polygon)))
                    END
                ELSE 0
                END AS overlap2
            FROM `groups`
            INNER JOIN authorities ON ( polyindex = COALESCE(simplified, polygon) OR ST_Intersects(polyindex, COALESCE(simplified, polygon)) )
            WHERE type = ? AND publish = 1 AND onmap = 1 AND authorities.id = ?",
            [self::GROUP_FREEGLE, $id]
        );

        $groups = [];
        foreach ($rows as $row) {
            $overlap = (float) $row->overlap;
            $overlap2 = (float) $row->overlap2;

            if ($overlap > 0.95) {
                $overlap = 1.0;
            }

            // Keep groups with a meaningful overlap in either direction.
            if ($overlap >= 0.05 || $overlap2 >= 0.05) {
                $groups[] = [
                    'id' => (int) $row->id,
                    'namedisplay' => $row->namefull ?: $row->nameshort,
                    'overlap' => $overlap,
                ];
            }
        }

        return ['name' => $auth->name, 'groups' => $groups];
    }

    /**
     * Aggregate `stats` rows by date for the given groups over [$start, $end)
     * (end exclusive), one entry per stat type.
     *
     * @param  array<int>  $groupids
     * @param  array<string>  $types
     * @return array<string, array<int, array{date:string, count:float}>>
     */
    public function getMultiStats(array $groupids, string $start, string $end, array $types): array
    {
        $start = date('Y-m-d', strtotime($start));
        $end = date('Y-m-d', strtotime($end));

        $ret = [];
        foreach ($types as $type) {
            $rows = DB::select(
                'SELECT SUM(count) AS count, date FROM stats
                 WHERE date >= ? AND date < ? AND groupid IN (' . $this->placeholders($groupids) . ') AND type = ?
                 GROUP BY date ORDER BY date ASC',
                array_merge([$start, $end], $groupids, [$type])
            );

            $ret[$type] = array_map(static fn ($r) => [
                'date' => $r->date,
                'count' => (float) $r->count,
            ], $rows);
        }

        return $ret;
    }

    /**
     * Per-postcode breakdown for the authority over [$start, $end]. Keyed by
     * outward postcode (the full postcode minus its last two characters).
     *
     * @return array<string, array{Offer:int, Wanted:int, Searches:int, Outcomes:int, Weight:float}>
     */
    public function getByAuthority(array $authorityIds, string $start, string $end): array
    {
        $start = date('Y-m-d', strtotime($start));
        $end = date('Y-m-d 23:59:59', strtotime($end));

        // Population popularity-weighted mean item weight, used where an item's
        // own weight is unknown.
        $avg = (float) (DB::table('items')
            ->whereNotNull('weight')
            ->where('weight', '!=', 0)
            ->selectRaw('SUM(popularity*weight)/SUM(popularity) AS average')
            ->value('average') ?? 0);

        // Materialise the locations inside the authority once, so the per-metric
        // queries stay cheap.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS pc');
        DB::statement(
            'CREATE TEMPORARY TABLE pc AS (
                SELECT locationid FROM authorities
                INNER JOIN locations_spatial ON authorities.id IN (' . $this->placeholders($authorityIds) . ')
                AND ST_Contains(authorities.polygon, locations_spatial.geometry)
            )',
            $authorityIds
        );

        $ret = [];
        $ensure = function (string $pc) use (&$ret) {
            if (!isset($ret[$pc])) {
                $ret[$pc] = [
                    Message::TYPE_OFFER => 0,
                    Message::TYPE_WANTED => 0,
                    self::SEARCHES => 0,
                    self::OUTCOMES => 0,
                    self::WEIGHT => 0.0,
                ];
            }
        };

        $pcExpr = 'SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2)';

        // Offer / Wanted message counts.
        foreach ([Message::TYPE_OFFER, Message::TYPE_WANTED] as $type) {
            $rows = DB::select(
                "SELECT $pcExpr AS partialpc, COUNT(*) AS count FROM pc
                 INNER JOIN messages ON messages.locationid = pc.locationid
                 INNER JOIN locations ON messages.locationid = locations.id
                 WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
                 AND messages.type = ? AND messages.arrival BETWEEN ? AND ?
                 GROUP BY partialpc ORDER BY locations.name",
                [$type, $start, $end],
                false
            );
            foreach ($rows as $r) {
                $ensure($r->partialpc);
                $ret[$r->partialpc][$type] += (int) $r->count;
            }
        }

        // Outcomes (Taken / Received).
        $rows = DB::select(
            "SELECT $pcExpr AS partialpc, COUNT(*) AS count FROM pc
             INNER JOIN messages ON messages.locationid = pc.locationid
             INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
             INNER JOIN locations ON messages.locationid = locations.id
             WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
             AND messages.arrival BETWEEN ? AND ? AND outcome IN (?, ?)
             GROUP BY partialpc ORDER BY locations.name",
            [$start, $end, Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED],
            false
        );
        foreach ($rows as $r) {
            $ensure($r->partialpc);
            $ret[$r->partialpc][self::OUTCOMES] += (int) $r->count;
        }

        // Weight of items with an outcome (fall back to the population average).
        $rows = DB::select(
            "SELECT $pcExpr AS partialpc, SUM(COALESCE(weight, ?)) AS weight FROM pc
             INNER JOIN messages ON messages.locationid = pc.locationid
             INNER JOIN messages_outcomes ON messages_outcomes.msgid = messages.id
             INNER JOIN messages_items mi ON messages.id = mi.msgid
             INNER JOIN items i ON mi.itemid = i.id
             INNER JOIN locations ON messages.locationid = locations.id
             WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
             AND messages.arrival BETWEEN ? AND ? AND outcome IN (?, ?)
             GROUP BY partialpc ORDER BY locations.name",
            [$avg, $start, $end, Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED],
            false
        );
        foreach ($rows as $r) {
            $ensure($r->partialpc);
            $ret[$r->partialpc][self::WEIGHT] += (float) $r->weight;
        }

        // Searches.
        $rows = DB::select(
            "SELECT $pcExpr AS partialpc, COUNT(*) AS count FROM pc
             INNER JOIN search_history ON search_history.locationid = pc.locationid
             INNER JOIN locations ON search_history.locationid = locations.id
             WHERE locations.type = 'Postcode' AND LOCATE(' ', locations.name) > 0
             AND search_history.date BETWEEN ? AND ?
             GROUP BY partialpc ORDER BY locations.name",
            [$start, $end],
            false
        );
        foreach ($rows as $r) {
            $ensure($r->partialpc);
            $ret[$r->partialpc][self::SEARCHES] += (int) $r->count;
        }

        DB::statement('DROP TEMPORARY TABLE IF EXISTS pc');

        return $ret;
    }

    /**
     * Shortlinks for a group (id + name), case-insensitively ordered.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function getShortlinks(int $groupid): array
    {
        return DB::table('shortlinks')
            ->where('groupid', $groupid)
            ->orderBy('name') // LOWER() is redundant: name is utf8mb4_unicode_ci
            ->get(['id', 'name'])
            ->map(static fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])
            ->all();
    }

    /**
     * Per-day click counts for a shortlink.
     *
     * @return array<int, object{date:string, count:int}>
     */
    public function getClickHistory(int $shortlinkid): array
    {
        return DB::select(
            'SELECT DATE(timestamp) AS date, COUNT(*) AS count FROM shortlink_clicks
             WHERE shortlinkid = ? GROUP BY date ORDER BY date ASC',
            [$shortlinkid]
        );
    }

    /**
     * Up to $limit reviewed, public stories whose author's location falls inside
     * the authority, most recent first.
     *
     * @return array<int, array{headline:string, story:string}>
     */
    public function getStories(int $authorityId, int $limit = 10): array
    {
        $stories = DB::table('users_stories')
            ->select('id', 'userid')
            ->where('reviewed', 1)
            ->where('public', 1)
            ->whereNotNull('userid')
            ->orderByDesc('date')
            ->get()
            ->all();
        if (!$stories) {
            return [];
        }

        $userids = array_values(array_unique(array_map(static fn ($s) => (int) $s->userid, $stories)));
        $locations = $this->resolveUserLatLngs($userids);

        // Which of those users sit inside the authority polygon (one query).
        $inside = $this->usersInsideAuthority($authorityId, $locations);

        $ids = [];
        foreach ($stories as $s) {
            $loc = $locations[(int) $s->userid] ?? null;
            if ($loc && ($loc['lat'] || $loc['lng']) && isset($inside[(int) $s->userid])) {
                $ids[] = (int) $s->id;
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }

        if (!$ids) {
            return [];
        }

        // Preserve the date-desc order the ids were collected in.
        $rows = DB::table('users_stories')->whereIn('id', $ids)->get(['id', 'headline', 'story'])->keyBy('id');
        $ret = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $ret[] = ['headline' => $rows[$id]->headline, 'story' => $rows[$id]->story];
            }
        }

        return $ret;
    }

    /**
     * Resolve a lat/lng for each user, in priority order: an explicit location
     * in their settings, then their last known location, then the most recent
     * message they geolocated, then their most recent group's location, then the
     * UK-centre fallback.
     *
     * @param  array<int>  $userids
     * @return array<int, array{lat:float, lng:float}>
     */
    private function resolveUserLatLngs(array $userids): array
    {
        $ret = [];
        if (!$userids) {
            return $ret;
        }

        // 1. settings.mylocation  2. users.lastlocation
        $users = DB::table('users')->whereIn('id', $userids)->get(['id', 'settings', 'lastlocation']);
        $lastLocationIds = [];
        foreach ($users as $u) {
            $lat = $lng = null;
            if (!empty($u->settings)) {
                $settings = json_decode($u->settings, true);
                if (isset($settings['mylocation']['lat'], $settings['mylocation']['lng'])) {
                    $lat = (float) $settings['mylocation']['lat'];
                    $lng = (float) $settings['mylocation']['lng'];
                }
            }
            if ($lat === null && $u->lastlocation) {
                $lastLocationIds[(int) $u->lastlocation][] = (int) $u->id;
                continue;
            }
            if ($lat !== null) {
                $ret[(int) $u->id] = ['lat' => $lat, 'lng' => $lng];
            }
        }

        if ($lastLocationIds) {
            $locs = DB::table('locations')->whereIn('id', array_keys($lastLocationIds))->get(['id', 'lat', 'lng']);
            foreach ($locs as $loc) {
                foreach ($lastLocationIds[(int) $loc->id] as $uid) {
                    $ret[$uid] = ['lat' => (float) $loc->lat, 'lng' => (float) $loc->lng];
                }
            }
        }

        $remaining = array_values(array_diff($userids, array_keys($ret)));

        // 3. Most recent geolocated message (ascending order, so the last write wins).
        if ($remaining) {
            $rows = DB::select(
                'SELECT fromuser AS userid, lat, lng FROM messages
                 WHERE fromuser IN (' . $this->placeholders($remaining) . ')
                 AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY arrival ASC',
                $remaining
            );
            foreach ($rows as $r) {
                $ret[(int) $r->userid] = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
            }
            $remaining = array_values(array_diff($userids, array_keys($ret)));
        }

        // 4. Most recent group membership's group location.
        if ($remaining) {
            $rows = DB::select(
                'SELECT userid, `groups`.lat AS lat, `groups`.lng AS lng
                 FROM `groups` INNER JOIN memberships ON memberships.groupid = `groups`.id
                 WHERE userid IN (' . $this->placeholders($remaining) . ') ORDER BY added ASC',
                $remaining
            );
            foreach ($rows as $r) {
                $ret[(int) $r->userid] = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
            }
            $remaining = array_values(array_diff($userids, array_keys($ret)));
        }

        // 5. UK-centre fallback.
        foreach ($remaining as $uid) {
            $ret[$uid] = ['lat' => self::DEFAULT_LAT, 'lng' => self::DEFAULT_LNG];
        }

        return $ret;
    }

    /**
     * Given resolved user locations, return the subset whose point lies inside
     * the authority polygon, in a single spatial query.
     *
     * @param  array<int, array{lat:float, lng:float}>  $locations
     * @return array<int, true>
     */
    private function usersInsideAuthority(int $authorityId, array $locations): array
    {
        if (!$locations) {
            return [];
        }

        $points = [];
        foreach ($locations as $uid => $loc) {
            $points[] = ['userid' => $uid, 'lat' => $loc['lat'], 'lng' => $loc['lng']];
        }

        $rows = DB::select(
            "SELECT jt.userid AS userid
             FROM JSON_TABLE(?, '$[*]' COLUMNS (
                 userid BIGINT PATH '$.userid',
                 lat DOUBLE PATH '$.lat',
                 lng DOUBLE PATH '$.lng'
             )) jt
             INNER JOIN authorities a ON a.id = ?
             WHERE ST_Contains(a.polygon, ST_SRID(POINT(jt.lng, jt.lat), ?))",
            [json_encode($points), $authorityId, $this->srid()]
        );

        $inside = [];
        foreach ($rows as $r) {
            $inside[(int) $r->userid] = true;
        }

        return $inside;
    }

    /**
     * Total clicks in each of the three months for a shortlink's day-by-day
     * click history.
     *
     * @param  array<int, object{date:string, count:int}>  $history
     * @param  array<int, array{start:string, end:string}>  $months
     * @return array<int, int>
     */
    private function bucketClicksByMonth(array $history, array $months): array
    {
        $clicks = [0, 0, 0];
        foreach ($history as $hist) {
            for ($i = 0; $i < 3; $i++) {
                if ($hist->date >= $months[$i]['start'] && $hist->date < $months[$i]['end']) {
                    $clicks[$i] += (int) $hist->count;
                }
            }
        }
        return $clicks;
    }

    /** Build a comma-separated list of `?` placeholders for an IN() clause. */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
