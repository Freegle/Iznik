<?php

namespace App\Monitoring\Checks;

use App\Monitoring\OutcomeResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Breaches when more than $threshold "pending" rows have been waiting longer
 * than $maxAgeMinutes. Use for CURSOR/QUEUE jobs: the failure mode is a stuck
 * worker letting work pile up, NOT an empty queue (which is normal and must
 * never alarm). $pending applies the predicate that defines an unprocessed row.
 */
class BacklogCheck extends AbstractOutcomeCheck
{
    /**
     * @param  callable(\Illuminate\Database\Query\Builder):void  $pending
     */
    public function __construct(
        string $slug,
        protected string $table,
        protected string $ageColumn,
        protected int $maxAgeMinutes,
        protected $pending,
        protected int $threshold = 0,
    ) {
        $this->slug = $slug;
        $this->category = 'cursor-staleness';
    }

    protected function check(CarbonInterface $now): OutcomeResult
    {
        $cutoff = $now->copy()->subMinutes($this->maxAgeMinutes);

        $query = DB::table($this->table)->where($this->ageColumn, '<', $cutoff);
        ($this->pending)($query);

        $count = $query->count();

        if ($count > $this->threshold) {
            return OutcomeResult::breach(
                $this->slug,
                "{$this->table}: {$count} pending row(s) older than {$this->maxAgeMinutes} min "
                    . "(threshold {$this->threshold}) — worker stuck?",
                $this->severity
            );
        }

        return OutcomeResult::ok(
            $this->slug,
            "{$this->table}: {$count} pending row(s) older than {$this->maxAgeMinutes} min "
                . "(threshold {$this->threshold})"
        );
    }
}
