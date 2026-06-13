<?php

namespace App\Monitoring\Checks;

use App\Monitoring\OutcomeResult;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Breaches when the freshest side-effect row is older than $maxAgeMinutes (or
 * there are no rows at all). Use for jobs that should ALWAYS have produced
 * something recently. For queue/cursor jobs whose table is legitimately empty
 * during quiet periods, use BacklogCheck instead — a freshness check would
 * false-alarm overnight.
 */
class FreshnessCheck extends AbstractOutcomeCheck
{
    /**
     * @param  (callable(\Illuminate\Database\Query\Builder):void)|null  $where
     */
    public function __construct(
        string $slug,
        protected string $table,
        protected string $column,
        protected int $maxAgeMinutes,
        protected $where = null,
    ) {
        $this->slug = $slug;
        $this->category = 'cursor-staleness';
    }

    protected function check(CarbonInterface $now): OutcomeResult
    {
        $query = DB::table($this->table);
        if ($this->where) {
            ($this->where)($query);
        }

        $latest = $query->max($this->column);

        if ($latest === null) {
            return OutcomeResult::breach(
                $this->slug,
                "{$this->table}.{$this->column}: no rows at all",
                $this->severity
            );
        }

        $latestAt = Carbon::parse($latest);
        $ageMinutes = (int) round(($now->getTimestamp() - $latestAt->getTimestamp()) / 60);
        $threshold = $now->copy()->subMinutes($this->maxAgeMinutes);

        if ($latestAt->lt($threshold)) {
            return OutcomeResult::breach(
                $this->slug,
                "{$this->table}.{$this->column} last advanced {$ageMinutes} min ago (max {$this->maxAgeMinutes})",
                $this->severity
            );
        }

        return OutcomeResult::ok(
            $this->slug,
            "{$this->table}.{$this->column} fresh ({$ageMinutes} min ago, max {$this->maxAgeMinutes})"
        );
    }
}
