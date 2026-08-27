<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Builds the payload for the public /electricals page.
 *
 * Every figure here is chosen against measured accuracy. Against volunteer quorum the
 * model is 96% on is-electrical and 93% on condition, so those carry public figures. It is
 * 72% on size and 65% on weight, so neither of those may become a published number: size
 * appears only as a coarse split with its accuracy stated, and weight does not appear at
 * all. Published tonnage comes from the `items.weight` catalog instead, which is the same
 * basis the rest of the site's weight figures already use.
 *
 * Two counting traps this deliberately avoids:
 *
 *  - `messages_groups` has one row per group a post reached, so joining it without
 *    `rippled_in = 0` multiplies every count by the rippling fan-out. On live that is the
 *    difference between ~5-6k and ~1,560 posts a day.
 *  - `is_eee` is nullable, and null means the model observed nothing rather than observed
 *    nothing electrical. Denominators exclude nulls; treating them as false would
 *    understate the electrical share.
 */
class ElectricalsStatsService
{
    /** Rolling window for the headline figures. */
    protected const WINDOW_MONTHS = 12;

    /**
     * Outcomes settle slowly, so the success-rate comparison ignores anything newer than
     * this. A post from last week that has not been marked Taken has not failed, it just
     * has not finished.
     */
    protected const SETTLE_DAYS = 30;

    /**
     * National TOMs NT31 carbon proxy, NT2022 rebasing (it moved from ~£70 after HM
     * Government updated its carbon valuation). Pull the current value from the Open Access
     * workbook before publishing a figure that will be quoted.
     */
    public const CARBON_VALUE_PER_TONNE_GBP = 244.63;

    /**
     * kg CO2e avoided per kg of goods reused. Deliberately a single conservative factor
     * rather than per-category factors: per-category numbers imply a precision the
     * underlying weight data does not support.
     */
    public const CO2E_KG_PER_KG_REUSED = 1.0;

    /** Unusual-items guard. All must hold, see buildUnusual(). */
    protected const UNUSUAL_MIN_USERS  = 3;
    protected const UNUSUAL_MIN_GROUPS = 2;
    protected const UNUSUAL_MAX_WORDS  = 4;
    protected const UNUSUAL_MAX_CHARS  = 30;

    public function __construct(protected EeeVisionService $vision) {}

    public function build(): array
    {
        $model  = $this->vision->getModelName();
        $from   = now()->subMonths(self::WINDOW_MONTHS)->startOfDay()->toDateTimeString();
        $to     = now()->toDateTimeString();
        $settle = now()->subDays(self::SETTLE_DAYS)->toDateTimeString();

        return [
            'generated_at'  => now()->toIso8601String(),
            'window'        => ['from' => $from, 'to' => $to, 'months' => self::WINDOW_MONTHS],
            'model'         => $model,
            'counts'        => $this->buildCounts($model, $from, $to),
            'impact'        => $this->buildImpact($model, $from, $to),
            'popular'       => $this->buildPopular($model, $from, $to),
            'unusual'       => $this->buildUnusual($model, $from, $to),
            'success'       => $this->buildSuccessRates($model, $from, $settle),
            'condition'     => $this->buildCondition($model, $from, $to),
            'monthly_trend' => $this->buildMonthlyTrend($model),
            'accuracy'      => $this->accuracyNotes(),
        ];
    }

    /**
     * Headline counts. `classified` is the denominator, not the total number of posts: an
     * item the model never looked at cannot be counted either way.
     */
    protected function buildCounts(string $model, string $from, string $to): array
    {
        // keep-raw: conditional aggregate (SUM over a boolean predicate) in the same pass as
        // the count. The builder can only express this as selectRaw, which this hook treats
        // identically, so the plain statement is clearer.
        $row = DB::selectOne(
            'SELECT COUNT(*) AS classified,
                    SUM(e.is_eee = 1) AS electrical
             FROM messages_eee e
             INNER JOIN messages m ON m.id = e.msgid
             WHERE e.model = ?
               AND e.is_eee IS NOT NULL
               AND m.arrival >= ? AND m.arrival < ?
               AND m.type = ? AND m.deleted IS NULL',
            [$model, $from, $to, 'Offer']
        );

        $classified = (int) ($row->classified ?? 0);
        $electrical = (int) ($row->electrical ?? 0);

        return [
            'classified'     => $classified,
            'electrical'     => $electrical,
            'electrical_pct' => $classified > 0 ? round($electrical * 100 / $classified, 1) : null,
        ];
    }

    /**
     * Tonnage and carbon for electrical items that were actually taken.
     *
     * Mirrors the weight query StatsGenerationService already uses, so this page's tonnage
     * is consistent with the site's other weight figures: DISTINCT on msgid so a post
     * counts once, catalog weight where known, popularity-weighted population mean where
     * not, bulk offers excluded, and `rippled_in = 0` so a post reaching forty groups is
     * one item and not forty.
     */
    protected function buildImpact(string $model, string $from, string $to): array
    {
        $averageWeight = $this->populationAverageWeight();

        // keep-raw: a DISTINCT derived table with a COALESCE/NULLIF fallback per row, then
        // aggregated. The builder cannot express the inner SELECT DISTINCT as a subquery
        // source without raw fragments, and this deliberately mirrors the proven query in
        // StatsGenerationService::regenerateWeightForRange character for character so the
        // two cannot drift apart.
        $row = DB::selectOne(
            'SELECT COUNT(*) AS items, SUM(sub.eff_weight) AS total_kg
             FROM (
               SELECT DISTINCT mo.msgid, COALESCE(NULLIF(i.weight, 0), ?) AS eff_weight
               FROM messages_outcomes mo
               INNER JOIN messages_eee e ON e.msgid = mo.msgid AND e.model = ? AND e.is_eee = 1
               INNER JOIN messages_groups mg ON mg.msgid = mo.msgid AND mg.rippled_in = 0
               INNER JOIN messages_items mi ON mi.msgid = mo.msgid
               LEFT JOIN items i ON i.id = mi.itemid
               WHERE mo.timestamp >= ? AND mo.timestamp < ?
                 AND mo.outcome IN (?, ?)
                 AND NOT EXISTS (SELECT 1 FROM messages_bulk_items bxi WHERE bxi.msgid = mo.msgid)
             ) sub',
            [$averageWeight, $model, $from, $to, 'Taken', 'Received']
        );

        $items  = (int) ($row->items ?? 0);
        $kg     = (float) ($row->total_kg ?? 0);
        $co2e   = $kg * self::CO2E_KG_PER_KG_REUSED / 1000;

        return [
            'items_taken'                => $items,
            'tonnes'                     => round($kg / 1000, 1),
            'tonnes_co2e'                => round($co2e, 1),
            'carbon_value_gbp'           => round($co2e * self::CARBON_VALUE_PER_TONNE_GBP),
            'mean_item_kg'               => $items > 0 ? round($kg / $items, 1) : null,
            'carbon_proxy_gbp_per_tonne' => self::CARBON_VALUE_PER_TONNE_GBP,
            'basis'                      => 'items.weight catalog, population mean where unknown; '
                                            . 'not the vision model, whose per-item weight is 65% accurate',
        ];
    }

    /** Popularity-weighted mean item weight, the fallback for items with no catalog weight. */
    protected function populationAverageWeight(): float
    {
        // keep-raw: SUM(a*b)/SUM(b) is a single scalar expression the builder would need
        // selectRaw for anyway.
        $row = DB::selectOne(
            'SELECT SUM(popularity * weight) / SUM(popularity) AS average
             FROM items WHERE weight IS NOT NULL AND weight != 0 AND popularity > 0'
        );

        return (float) ($row->average ?? 0);
    }

    /** Most-offered electrical item types in the window. */
    protected function buildPopular(string $model, string $from, string $to, int $limit = 20): array
    {
        $rows = DB::table('messages_eee as e')
            ->join('messages as m', 'm.id', '=', 'e.msgid')
            ->join('messages_items as mi', 'mi.msgid', '=', 'm.id')
            ->join('items as i', 'i.id', '=', 'mi.itemid')
            ->where('e.model', $model)
            ->where('e.is_eee', 1)
            ->where('m.arrival', '>=', $from)
            ->where('m.arrival', '<', $to)
            ->where('m.type', 'Offer')
            ->whereNull('m.deleted')
            ->groupBy('i.id', 'i.name')
            ->orderByDesc('n')
            ->limit($limit)
            // keep-raw: COUNT(DISTINCT ...) aliased for both the select and the ordering;
            // the builder has no first-class distinct-count aggregate.
            ->select('i.name', DB::raw('COUNT(DISTINCT m.id) AS n'))
            ->get();

        return $rows->map(fn($r) => ['name' => $r->name, 'count' => (int) $r->n])->all();
    }

    /**
     * Genuinely unusual electrical items, not one member's odd phrasing.
     *
     * Raw rarity is useless here: the rarest item names are overwhelmingly typos, plurals
     * and whole sentences typed into a subject line. So an entry must prove it is a real
     * recurring item before it is allowed to be called rare - offered by several different
     * people, in more than one community, with a name shaped like an item rather than a
     * sentence. That loses some true one-offs, which is the right trade for a public page.
     */
    protected function buildUnusual(string $model, string $from, string $to, int $limit = 20): array
    {
        // keep-raw: three distinct-count aggregates, a HAVING over their aliases, and a
        // word count done as CHAR_LENGTH minus CHAR_LENGTH(REPLACE(...)). None of that is
        // expressible in the builder without raw fragments throughout, at which point the
        // statement reads better whole.
        $rows = DB::select(
            'SELECT i.name,
                    COUNT(DISTINCT m.id)        AS n,
                    COUNT(DISTINCT m.fromuser)  AS users,
                    COUNT(DISTINCT mg.groupid)  AS groupcount
             FROM messages_eee e
             INNER JOIN messages m ON m.id = e.msgid
             INNER JOIN messages_items mi ON mi.msgid = m.id
             INNER JOIN items i ON i.id = mi.itemid
             INNER JOIN messages_groups mg ON mg.msgid = m.id AND mg.rippled_in = 0
             WHERE e.model = ? AND e.is_eee = 1
               AND m.arrival >= ? AND m.arrival < ?
               AND m.type = ? AND m.deleted IS NULL
               AND CHAR_LENGTH(i.name) <= ?
               AND (CHAR_LENGTH(i.name) - CHAR_LENGTH(REPLACE(i.name, " ", ""))) < ?
             GROUP BY i.id, i.name
             HAVING users >= ? AND groupcount >= ?
             ORDER BY n ASC, i.name ASC
             LIMIT ?',
            [
                $model, $from, $to, 'Offer',
                self::UNUSUAL_MAX_CHARS,
                self::UNUSUAL_MAX_WORDS,
                self::UNUSUAL_MIN_USERS,
                self::UNUSUAL_MIN_GROUPS,
                $limit,
            ]
        );

        return [
            'items' => array_map(
                fn($r) => [
                    'name'   => $r->name,
                    'count'  => (int) $r->n,
                    'users'  => (int) $r->users,
                    'groups' => (int) $r->groupcount,
                ],
                $rows
            ),
            'guard' => [
                'min_users'  => self::UNUSUAL_MIN_USERS,
                'min_groups' => self::UNUSUAL_MIN_GROUPS,
                'max_words'  => self::UNUSUAL_MAX_WORDS,
                'max_chars'  => self::UNUSUAL_MAX_CHARS,
                'note'       => 'An item only counts as rare once several different people in more '
                                . 'than one community have offered one, so a single odd listing '
                                . 'cannot appear here.',
            ],
        ];
    }

    /**
     * Taken rate for electricals against everything else, on settled posts only.
     *
     * A post with no outcome row counts as not taken, which is right once it has had a
     * month to resolve and wrong before that, hence the settle window.
     */
    protected function buildSuccessRates(string $model, string $from, string $settle): array
    {
        // keep-raw: conditional aggregate over the outcome enum, grouped by the tri-state
        // is_eee flag.
        $rows = DB::select(
            'SELECT e.is_eee AS electrical,
                    COUNT(*) AS total,
                    SUM(mo.outcome IN (?, ?)) AS taken
             FROM messages_eee e
             INNER JOIN messages m ON m.id = e.msgid
             LEFT JOIN messages_outcomes mo ON mo.msgid = m.id
             WHERE e.model = ?
               AND e.is_eee IS NOT NULL
               AND m.arrival >= ? AND m.arrival < ?
               AND m.type = ? AND m.deleted IS NULL
             GROUP BY e.is_eee',
            ['Taken', 'Received', $model, $from, $settle, 'Offer']
        );

        $out = ['electrical' => null, 'other' => null, 'settled_before' => $settle];

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $taken = (int) $row->taken;
            $key   = ((int) $row->electrical === 1) ? 'electrical' : 'other';

            $out[$key] = [
                'posts'     => $total,
                'taken'     => $taken,
                'taken_pct' => $total > 0 ? round($taken * 100 / $total, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * Condition split for electricals, and how often each condition gets taken. 93%
     * accurate, so publishable.
     *
     * The figure worth showing is not how many are broken but that broken ones still get
     * taken, which is the repair story.
     */
    protected function buildCondition(string $model, string $from, string $to): array
    {
        // keep-raw: conditional aggregate over the outcome enum again.
        $rows = DB::select(
            'SELECT e.item_condition AS item_condition,
                    COUNT(*) AS n,
                    SUM(mo.outcome IN (?, ?)) AS taken
             FROM messages_eee e
             INNER JOIN messages m ON m.id = e.msgid
             LEFT JOIN messages_outcomes mo ON mo.msgid = m.id
             WHERE e.model = ? AND e.is_eee = 1
               AND e.item_condition IS NOT NULL
               AND m.arrival >= ? AND m.arrival < ?
               AND m.type = ? AND m.deleted IS NULL
             GROUP BY e.item_condition',
            ['Taken', 'Received', $model, $from, $to, 'Offer']
        );

        $split = [];
        foreach ($rows as $row) {
            $n     = (int) $row->n;
            $taken = (int) $row->taken;

            $split[$row->item_condition] = [
                'count'     => $n,
                'taken'     => $taken,
                'taken_pct' => $n > 0 ? round($taken * 100 / $n, 1) : null,
            ];
        }

        return $split;
    }

    /** Electrical share by month, for the trend line. */
    protected function buildMonthlyTrend(string $model, int $months = 24): array
    {
        // keep-raw: DATE_FORMAT month bucketing plus a conditional aggregate, grouped and
        // ordered on the derived alias.
        $rows = DB::select(
            'SELECT DATE_FORMAT(m.arrival, "%Y-%m") AS month,
                    COUNT(*) AS classified,
                    SUM(e.is_eee = 1) AS electrical
             FROM messages_eee e
             INNER JOIN messages m ON m.id = e.msgid
             WHERE e.model = ?
               AND e.is_eee IS NOT NULL
               AND m.arrival >= ?
               AND m.type = ? AND m.deleted IS NULL
             GROUP BY month
             ORDER BY month',
            [$model, now()->subMonths($months)->startOfMonth()->toDateTimeString(), 'Offer']
        );

        return array_map(function ($r) {
            $classified = (int) $r->classified;
            $electrical = (int) $r->electrical;

            return [
                'month'          => $r->month,
                'classified'     => $classified,
                'electrical'     => $electrical,
                'electrical_pct' => $classified > 0 ? round($electrical * 100 / $classified, 1) : null,
            ];
        }, $rows);
    }

    /**
     * Measured accuracy, carried in the payload so the page can state it next to each
     * figure rather than presenting everything as equally certain.
     */
    protected function accuracyNotes(): array
    {
        return [
            'is_electrical' => ['pct' => 96, 'basis' => '193 human labels', 'publish' => true],
            'condition'     => ['pct' => 93, 'basis' => 'volunteer quorum, 218 items', 'publish' => true],
            'size'          => ['pct' => 72, 'basis' => 'volunteer quorum, 228 items', 'publish' => false],
            'weight'        => ['pct' => 65, 'basis' => 'volunteer quorum, 227 items', 'publish' => false],
        ];
    }
}
