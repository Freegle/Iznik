<?php

namespace App\Monitoring\Checks;

use App\Console\BackupDrain;
use App\Monitoring\OutcomeResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Breaches when more than $threshold "pending" rows have been waiting longer
 * than $maxAgeMinutes. Use for CURSOR/QUEUE jobs: the failure mode is a stuck
 * worker letting work pile up, NOT an empty queue (which is normal and must
 * never alarm). $pending applies the predicate that defines an unprocessed row.
 *
 * While batch work is held off for the nightly backup (App\Console\BackupDrain) and the
 * hold has lasted longer than this check's maximum age, a backlog is the drain doing its job
 * rather than a stuck worker, and the check reports SKIPPED. A threshold longer than the
 * window, such as the 24-hour rippling check, is never excused by it. Skipped, not ok:
 * nothing was assessed, and the first run after the workers have caught up assesses it
 * properly.
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
        if (BackupDrain::explainsBacklog($this->maxAgeMinutes, $now)) {
            return OutcomeResult::skipped(
                $this->slug,
                "{$this->table}: not assessed - batch work has been held off for the backup for longer than {$this->maxAgeMinutes} min"
            );
        }

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
