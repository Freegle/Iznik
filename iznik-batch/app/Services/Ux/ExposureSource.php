<?php

namespace App\Services\Ux;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A metric source that counts from ux_experiment_exposures and the database, in one grouped query.
 * docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the server".
 *
 * The counted cohort is these rows and nothing else counts. Each member is counted once, on their
 * first exposure of a type the source counts (from the start of the run, or from the decision for a
 * holdout look). The maturity test runs AFTER the first exposure is chosen: a first exposure that is
 * younger than the window is not counted yet, and is never replaced by a later one. So a member
 * mailed and then pushed is one count, on the first. A web view writes no row, so an experiment that
 * changes mail and the site is measured from its first server exposure.
 *
 *   SELECT c.arm, c.stratum, COUNT(*), SUM(<converted>), SUM(<bad>) ...
 *   FROM (the cohort) c <joins> GROUP BY c.arm, c.stratum
 *
 * A subclass writes types(), words(), guardrails(), joins(), converted() and bad(). They are SQL over
 * the alias c (an exposure row) and the token :window, which is replaced by design.window_hours as a
 * whole number before the query is run. A guardrail's base is the members counted. Strata are the
 * exposure's own stratum, and a null stratum is one pooled stratum. A subclass may override counts()
 * or add keys to extra() to show more.
 */
abstract class ExposureSource implements UxMetricSource
{
    /** SQL joins over the cohort, alias c. Empty when the measure needs none. */
    abstract protected function joins(object $exp): string;

    /** SQL boolean: the member converted within :window hours of the exposure. */
    abstract protected function converted(object $exp): string;

    /**
     * Guardrail name => SQL boolean: the member did the bad thing (within :window hours, where that
     * matters). The names are those of guardrails().
     *
     * @return array<string, string>
     */
    abstract protected function bad(object $exp): array;

    /**
     * More keys for the read's detail, beside exposed and counted.
     *
     * @return array<string, mixed>
     */
    protected function extra(object $exp, Carbon $now, ?Carbon $since): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function designOf(object $exp): array
    {
        return json_decode($exp->design ?? '', true) ?: [];
    }

    /** The hours after an exposure that a member has to convert in. */
    protected function windowOf(object $exp): int
    {
        return max(1, (int) ($this->designOf($exp)['window_hours'] ?? 1));
    }

    /** A window as staff read it: "a day", "7 days", "1 hour" or "N hours". */
    protected static function windowText(int $hours): string
    {
        return match ($hours) {
            24 => 'a day',
            168 => '7 days',
            1 => '1 hour',
            default => $hours.' hours',
        };
    }

    public function counts(object $exp, Carbon $now, ?Carbon $since): array
    {
        $window = $this->windowOf($exp);
        $from = ($since ?: Carbon::parse($exp->started_at, 'UTC'))->copy()->utc()->format('Y-m-d H:i:s');
        $mature = $now->copy()->utc()->subHours($window)->format('Y-m-d H:i:s');

        $arms = [];
        $guards = [];
        $exposed = [];
        $counted = [];
        $bad = $this->bad($exp);
        foreach (array_keys(json_decode($exp->arms, true) ?: []) as $arm) {
            $arms[$arm] = ['starts' => 0, 'completes' => 0, 'strata' => []];
            $exposed[$arm] = 0;
            $counted[$arm] = 0;
            foreach (array_keys($bad) as $name) {
                $guards[$name][$arm] = ['bad' => 0, 'base' => 0];
            }
        }

        $types = $this->types();
        $in = implode(',', array_fill(0, count($types), '?'));
        // Each member's first exposure of a counted type, from the start of the run.
        $first = "SELECT MIN(id) id FROM ux_experiment_exposures WHERE experiment = ? AND exposed_at >= ? AND subject_type IN ($in) GROUP BY userid";
        $firstBindings = array_merge([$exp->name, $from], $types);

        // Everyone with a first exposure, mature or not, so the tab shows progress before anything matures.
        // keep-raw: a grouped count over the first-exposure subquery; the query builder cannot express a derived-table join.
        $rows = DB::select("SELECT x.arm, COUNT(*) AS n FROM ux_experiment_exposures x JOIN ($first) f ON f.id = x.id GROUP BY x.arm", $firstBindings);
        foreach ($rows as $row) {
            if (isset($exposed[$row->arm])) {
                $exposed[$row->arm] = (int) $row->n;
            }
        }

        $select = 'c.arm, c.stratum, COUNT(*) AS n, SUM(CASE WHEN ('.$this->converted($exp).') THEN 1 ELSE 0 END) AS done';
        $names = array_keys($bad);
        foreach ($names as $i => $name) {
            $select .= ', SUM(CASE WHEN ('.$bad[$name].") THEN 1 ELSE 0 END) AS bad_$i";
        }
        $sql = "SELECT $select FROM (SELECT x.* FROM ux_experiment_exposures x JOIN ($first) f ON f.id = x.id WHERE x.exposed_at <= ?) c "
            .$this->joins($exp).' GROUP BY c.arm, c.stratum';
        // The window is a whole number from the design, put in as a literal because a named binding
        // cannot be used twice in one prepared statement.
        $sql = str_replace(':window', (string) $window, $sql);

        // keep-raw: one grouped query over the counted cohort and the source's own joins; the query builder cannot express it.
        $rows = DB::select($sql, array_merge($firstBindings, [$mature]));
        foreach ($rows as $row) {
            if (! isset($arms[$row->arm])) {
                continue;
            }
            $stratum = $row->stratum === null ? 'all' : (string) $row->stratum;
            $arms[$row->arm]['starts'] += (int) $row->n;
            $arms[$row->arm]['completes'] += (int) $row->done;
            $arms[$row->arm]['strata'][$stratum] = ['starts' => (int) $row->n, 'completes' => (int) $row->done];
            $counted[$row->arm] += (int) $row->n;
            foreach ($names as $i => $name) {
                $col = "bad_$i";
                $guards[$name][$row->arm]['bad'] += (int) $row->$col;
            }
        }
        foreach ($guards as $name => $byArm) {
            foreach ($byArm as $arm => $_) {
                $guards[$name][$arm]['base'] = $counted[$arm];
            }
        }

        return [
            'arms' => $arms,
            'guards' => $guards,
            'extra' => ['exposed' => $exposed, 'counted' => $counted] + $this->extra($exp, $now, $since),
        ];
    }
}
