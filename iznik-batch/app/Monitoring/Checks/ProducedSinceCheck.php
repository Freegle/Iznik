<?php

namespace App\Monitoring\Checks;

use App\Monitoring\OutcomeResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Breaches when fewer than $floor rows were written since the period's window
 * start. Use for FIRE-ONCE jobs — "did it produce output this period?".
 *
 * $windowStart is a closure (CarbonInterface $now): CarbonInterface so the
 * window can track London-local day boundaries rather than naive UTC days.
 */
class ProducedSinceCheck extends AbstractOutcomeCheck
{
    /**
     * @param  callable(CarbonInterface):CarbonInterface  $windowStart
     * @param  (callable(\Illuminate\Database\Query\Builder):void)|null  $where
     */
    public function __construct(
        string $slug,
        protected string $table,
        protected string $column,
        protected $windowStart,
        protected int $floor = 1,
        protected $where = null,
    ) {
        $this->slug = $slug;
        $this->category = 'fire-once-output';
    }

    protected function check(CarbonInterface $now): OutcomeResult
    {
        $start = ($this->windowStart)($now);

        $query = DB::table($this->table)->where($this->column, '>=', $start);
        if ($this->where) {
            ($this->where)($query);
        }

        $count = $query->count();
        $since = $start->copy()->toDateTimeString();

        if ($count < $this->floor) {
            return OutcomeResult::breach(
                $this->slug,
                "{$this->table}: {$count} row(s) since {$since} (floor {$this->floor}) — did it run?",
                $this->severity
            );
        }

        return OutcomeResult::ok(
            $this->slug,
            "{$this->table}: {$count} row(s) since {$since} (floor {$this->floor})"
        );
    }
}
