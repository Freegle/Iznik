<?php

namespace App\Services;

use App\Database\Expressions\Alias;
use App\Database\Expressions\Arithmetic;
use App\Database\Expressions\Coalesce;
use App\Database\Expressions\Count;
use App\Database\Expressions\In;
use App\Database\Expressions\Length;
use App\Database\Expressions\Point;
use App\Database\Expressions\StContains;
use App\Database\Expressions\StSrid;
use App\Database\Expressions\Substring;
use App\Database\Expressions\Sum;
use App\Database\Expressions\Value;
use App\Models\Message;
use App\Models\User;
use App\Support\ReuseBenefit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the data behind the per-authority statistics report that councils
 * receive: membership, weight reused, CO2 and financial benefit, gifts made,
 * a per-postcode breakdown and member stories.
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
    public const WEIGHT = 'Weight';
    public const OUTCOMES = 'Outcomes';
    public const SEARCHES = 'Searches';

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

    /** CPI series for this run: the config row, read once, or the fallback. */
    private ?array $cpiData = null;

    private function cpiData(): array
    {
        return $this->cpiData ??= app(CPIService::class)->getCPIData();
    }

    /** Benefit of reuse per tonne in GBP, at current-year prices. */
    public function getBenefitPerTonne(): float
    {
        return ReuseBenefit::getBenefitPerTonne(null, $this->cpiData());
    }

    /** Fly-tipping clear-up cost avoided per item given away, in GBP, at current-year prices. */
    public function getFlyTipSavingPerItem(): float
    {
        return ReuseBenefit::getFlyTipSavingPerItem(null, $this->cpiData());
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
     * conversions, and the fly-tipping saving on the gifts, when rendering.
     *
     * @return array{
     *   name:string, quarter:int, year:string,
     *   months:array<int, array{start:string,end:string,formatted:string}>,
     *   benefitPerTonne:float, co2PerTonne:float, flyTipPerItem:float,
     *   totals:array<int, array{members:int, weight:float, outcomes:float}>,
     *   stories:array<int, array{headline:string, story:string}>,
     *   postcodes:array<string, array{Offer:int,Wanted:int,Searches:int,Outcomes:int,Weight:float}>
     * }|null
     */
    public function computeReport(int $authorityId, string $quarterStart): ?array
    {
        $months = $this->getMonths($quarterStart);
        $name = DB::table('authorities')->where('id', $authorityId)->value('name');
        if ($name === null) {
            return null;
        }

        // Every figure is about the authority's own area: members who live inside it and
        // posts made inside it. There are no communities to apportion by overlap.
        $totals = [];
        for ($m = 0; $m < 3; $m++) {
            $byPostcode = $this->getByAuthority([$authorityId], $months[$m]['start'], date('Y-m-d', strtotime($months[$m]['end'] . ' -1 day')));

            $totals[$m] = [
                'members' => $this->getMemberCount($authorityId, $months[$m]['end']),
                'weight' => (float) array_sum(array_column($byPostcode, self::WEIGHT)),
                'outcomes' => (float) array_sum(array_column($byPostcode, self::OUTCOMES)),
            ];
        }

        return [
            'name' => $name,
            'quarter' => $this->getQuarterNumber($quarterStart),
            'year' => date('Y'),
            'months' => $months,
            'benefitPerTonne' => $this->getBenefitPerTonne(),
            'co2PerTonne' => $this->getCo2PerTonne(),
            'flyTipPerItem' => $this->getFlyTipSavingPerItem(),
            'totals' => $totals,
            'stories' => $this->getStories($authorityId, 10),
            'postcodes' => $this->getByAuthority([$authorityId], $months[0]['start'], $months[2]['end']),
        ];
    }

    /**
     * Members whose home location lies inside the authority and who had joined, and not
     * left, before $before.
     */
    public function getMemberCount(int $authorityId, string $before): int
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS count FROM users
             INNER JOIN locations_spatial ON locations_spatial.locationid = users.lastlocation
             INNER JOIN authorities ON authorities.id = ?
             WHERE ST_Contains(authorities.polygon, locations_spatial.geometry)
             AND users.added < ? AND (users.deleted IS NULL OR users.deleted >= ?)',
            [$authorityId, $before, $before]
        )->count ?? 0);
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
     * message they geolocated, then the UK-centre fallback.
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

        // 1. settings.mylocation (never for a TN member, see User::chosenLatLng)  2. users.lastlocation
        $users = DB::table('users')->whereIn('id', $userids)->get(['id', 'settings', 'lastlocation', 'tnuserid']);
        $lastLocationIds = [];
        foreach ($users as $u) {
            [$lat, $lng] = User::chosenLatLng($u->settings, $u->tnuserid) ?? [null, null];
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

        // 4. UK-centre fallback.
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

    /** Build a comma-separated list of `?` placeholders for an IN() clause. */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
