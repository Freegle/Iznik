<?php

namespace App\Services;

use App\Database\Expressions\Alias;
use App\Database\Expressions\Arithmetic;
use App\Database\Expressions\CaseWhen;
use App\Database\Expressions\CastAs;
use App\Database\Expressions\Coalesce;
use App\Database\Expressions\Comparison;
use App\Database\Expressions\Count;
use App\Database\Expressions\In;
use App\Database\Expressions\Length;
use App\Database\Expressions\Point;
use App\Database\Expressions\StArea;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StGeometryType;
use App\Database\Expressions\StIntersection;
use App\Database\Expressions\StIntersects;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\Substring;
use App\Database\Expressions\Sum;
use App\Database\Expressions\Value;
use App\Models\Message;
use App\Support\ReuseBenefit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        //
        // Structured equivalent of the original raw CASE expressions: the outer
        // CaseWhen guards against ST_Area() throwing on a degenerate
        // intersection (a LINESTRING/POINT where two polygons only touch -
        // verified empirically that ST_Area() errors, rather than returning 0,
        // on anything but a POLYGON/MULTIPOLYGON), the inner CaseWhen shortcuts
        // an identical-geometry join match to a clean 1.0 rather than dividing a
        // polygon's area by itself.
        $coalesced = fn () => new Coalesce('simplified', 'polygon');
        $intersection = fn () => new StIntersection('polyindex', $coalesced());
        $intersectionIsPolygonal = fn () => new In(
            new StGeometryType($intersection()),
            [Value::of('POLYGON'), Value::of('MULTIPOLYGON')]
        );
        $identicalGeometry = fn () => new Comparison('polyindex', '=', $coalesced());

        $overlap = (new CaseWhen())
            ->when($intersectionIsPolygonal())
            ->then(
                (new CaseWhen())
                    ->when($identicalGeometry())->then(1)
                    ->otherwise(new Arithmetic(new StArea($intersection()), '/', new StArea('polyindex')))
            )
            ->otherwise(0);

        $overlap2 = (new CaseWhen())
            ->when($intersectionIsPolygonal())
            ->then(
                (new CaseWhen())
                    ->when($identicalGeometry())->then(1)
                    ->otherwise(new Arithmetic(new StArea('polyindex'), '/', new StArea($intersection())))
            )
            ->otherwise(0);

        $rows = DB::table('groups')
            ->join('authorities', function ($join) use ($coalesced) {
                $join->where(new Comparison('polyindex', '=', $coalesced()))
                    ->orWhere(new StIntersects('polyindex', $coalesced()));
            })
            ->select(
                'groups.id as id',
                'nameshort',
                'namefull',
                new Alias($overlap, 'overlap'),
                new Alias($overlap2, 'overlap2'),
            )
            ->where('type', self::GROUP_FREEGLE)
            ->where('publish', 1)
            ->where('onmap', 1)
            ->where('authorities.id', $id)
            ->get();

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
            $rows = DB::table('stats')
                ->select('date', new Alias(new Sum('count'), 'count'))
                ->where('date', '>=', $start)
                ->where('date', '<', $end)
                ->whereIn('groupid', $groupids)
                ->where('type', $type)
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            $ret[$type] = array_map(static fn ($r) => [
                'date' => $r->date,
                'count' => (float) $r->count,
            ], $rows->all());
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
        $avgExpr = new Arithmetic(new Sum(new Arithmetic('popularity', '*', 'weight')), '/', new Sum('popularity'));
        $avg = (float) (DB::table('items')
            ->whereNotNull('weight')
            ->where('weight', '!=', 0)
            ->select(new Alias($avgExpr, 'average'))
            ->value('average') ?? 0);

        // Materialise the locations inside the authority once, so the per-metric
        // queries stay cheap. The pre-emptive and final drops stay raw: MySQL
        // temporary tables shadow a same-named permanent table only for the
        // session that already created one - before the CREATE below runs there
        // is no session-local `pc` yet, so a plain `DROP TABLE IF EXISTS pc`
        // (which is all Schema::dropIfExists() can ever emit - confirmed in
        // MySqlGrammar::compileDrop()/compileDropIfExists(), neither ever adds
        // the TEMPORARY keyword) would risk dropping a genuine permanent table
        // named `pc` if one ever existed. DROP TEMPORARY TABLE has no such risk
        // (MySQL refuses it outright if `pc` isn't a temporary table) and is
        // exactly the guarantee wanted here.
        // keep-raw: Schema::dropIfExists() is NOT equivalent and is actively
        // dangerous here. MySqlGrammar::compileDropIfExists() emits
        // "drop table if exists `pc`" with no TEMPORARY keyword - Blueprint's
        // temporary flag is only read by compileCreateTable(). Proven by
        // creating a PERMANENT table and running it: the permanent table was
        // dropped. That is exactly the state before this method creates its
        // temp table, so converting would destroy a same-named permanent
        // table. The raw form's TEMPORARY keyword makes it a no-op against a
        // permanent table - verified: it left one untouched.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS pc');
        Schema::create('pc', function (Blueprint $table) {
            $table->temporary();
            $table->unsignedBigInteger('locationid');
        });
        DB::table('pc')->insertUsing(
            ['locationid'],
            DB::table('authorities')
                ->join('locations_spatial', function ($join) use ($authorityIds) {
                    $join->where(new In('authorities.id', $authorityIds))
                        ->where(new StContains('authorities.polygon', 'locations_spatial.geometry'));
                })
                ->select('locationid')
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

        // Outward postcode: the full postcode minus its trailing " XX" inward
        // code. `->useWritePdo()` on every query against `pc` below (matching
        // the original `$useReadPdo = false`) is load-bearing, not optional:
        // `pc` is a temporary table that only exists on the connection that
        // created it (sticky=false means a plain read would land on a
        // different connection/session and see "table doesn't exist" - see
        // Schema::create() above, which - like every write - runs on the write
        // connection) - verified empirically that a read-pooled query against a
        // temp table created moments earlier fails with error 1146 without it.
        $partialPc = new Substring('locations.name', 1, new Arithmetic(new Length('locations.name'), '-', 2));

        // Offer / Wanted message counts.
        foreach ([Message::TYPE_OFFER, Message::TYPE_WANTED] as $type) {
            $rows = DB::table('pc')
                ->join('messages', 'messages.locationid', '=', 'pc.locationid')
                ->join('locations', 'messages.locationid', '=', 'locations.id')
                ->where('locations.type', 'Postcode')
                ->where('locations.name', 'like', '% %')
                ->where('messages.type', $type)
                ->whereBetween('messages.arrival', [$start, $end])
                ->select(new Alias($partialPc, 'partialpc'), new Alias(new Count(), 'count'))
                ->groupBy('partialpc')
                ->orderBy('locations.name')
                ->useWritePdo()
                ->get();
            foreach ($rows as $r) {
                $ensure($r->partialpc);
                $ret[$r->partialpc][$type] += (int) $r->count;
            }
        }

        // Outcomes (Taken / Received).
        $rows = DB::table('pc')
            ->join('messages', 'messages.locationid', '=', 'pc.locationid')
            ->join('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->join('locations', 'messages.locationid', '=', 'locations.id')
            ->where('locations.type', 'Postcode')
            ->where('locations.name', 'like', '% %')
            ->whereBetween('messages.arrival', [$start, $end])
            ->whereIn('outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED])
            ->select(new Alias($partialPc, 'partialpc'), new Alias(new Count(), 'count'))
            ->groupBy('partialpc')
            ->orderBy('locations.name')
            ->useWritePdo()
            ->get();
        foreach ($rows as $r) {
            $ensure($r->partialpc);
            $ret[$r->partialpc][self::OUTCOMES] += (int) $r->count;
        }

        // Weight of items with an outcome (fall back to the population average).
        $rows = DB::table('pc')
            ->join('messages', 'messages.locationid', '=', 'pc.locationid')
            ->join('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->join('messages_items as mi', 'messages.id', '=', 'mi.msgid')
            ->join('items as i', 'mi.itemid', '=', 'i.id')
            ->join('locations', 'messages.locationid', '=', 'locations.id')
            ->where('locations.type', 'Postcode')
            ->where('locations.name', 'like', '% %')
            ->whereBetween('messages.arrival', [$start, $end])
            ->whereIn('outcome', [Message::OUTCOME_TAKEN, Message::OUTCOME_RECEIVED])
            ->select(
                new Alias($partialPc, 'partialpc'),
                new Alias(new Sum(new Coalesce('weight', Value::of($avg))), 'weight'),
            )
            ->groupBy('partialpc')
            ->orderBy('locations.name')
            ->useWritePdo()
            ->get();
        foreach ($rows as $r) {
            $ensure($r->partialpc);
            $ret[$r->partialpc][self::WEIGHT] += (float) $r->weight;
        }

        // Searches.
        $rows = DB::table('pc')
            ->join('search_history', 'search_history.locationid', '=', 'pc.locationid')
            ->join('locations', 'search_history.locationid', '=', 'locations.id')
            ->where('locations.type', 'Postcode')
            ->where('locations.name', 'like', '% %')
            ->whereBetween('search_history.date', [$start, $end])
            ->select(new Alias($partialPc, 'partialpc'), new Alias(new Count(), 'count'))
            ->groupBy('partialpc')
            ->orderBy('locations.name')
            ->useWritePdo()
            ->get();
        foreach ($rows as $r) {
            $ensure($r->partialpc);
            $ret[$r->partialpc][self::SEARCHES] += (int) $r->count;
        }

        // keep-raw: see the DROP above - Schema::dropIfExists() can only ever
        // emit a non-TEMPORARY drop.
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
        return DB::table('shortlink_clicks')
            ->select(new Alias(new CastAs('timestamp', 'DATE'), 'date'), new Alias(new Count(), 'count'))
            ->where('shortlinkid', $shortlinkid)
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->all();
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
            $rows = DB::table('messages')
                ->select('fromuser as userid', 'lat', 'lng')
                ->whereIn('fromuser', $remaining)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->orderBy('arrival')
                ->get();
            foreach ($rows as $r) {
                $ret[(int) $r->userid] = ['lat' => (float) $r->lat, 'lng' => (float) $r->lng];
            }
            $remaining = array_values(array_diff($userids, array_keys($ret)));
        }

        // 4. Most recent group membership's group location.
        if ($remaining) {
            $rows = DB::table('groups')
                ->join('memberships', 'memberships.groupid', '=', 'groups.id')
                ->whereIn('userid', $remaining)
                ->orderBy('added')
                ->get(['userid', 'groups.lat as lat', 'groups.lng as lng']);
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

        // JSON_TABLE() is a table-valued function: it has to appear in the FROM
        // clause, which fromRaw() can do (with the JSON payload as a genuine
        // bound parameter, same as the original) - the query builder has no
        // dedicated method for the construct itself, but nothing about it
        // resists structured composition once it's just another FROM source.
        $rows = DB::query()
            ->fromRaw(
                "JSON_TABLE(?, '\$[*]' COLUMNS ("
                ."userid BIGINT PATH '\$.userid', "
                ."lat DOUBLE PATH '\$.lat', "
                ."lng DOUBLE PATH '\$.lng'"
                .')) AS jt',
                [json_encode($points)]
            )
            ->join('authorities as a', function ($join) use ($authorityId) {
                $join->where('a.id', '=', $authorityId);
            })
            ->where(new StContains('a.polygon', new StSrid(new Point('jt.lng', 'jt.lat'), $this->srid())))
            ->select('jt.userid as userid')
            ->get();

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
}
