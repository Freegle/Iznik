<?php

namespace App\Console\Commands\Mail;

use App\Services\Mail\DigestPositionsRollupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Nightly: roll the last few days of unified digest emails into email_digest_positions_daily, which the
 * ModTools SysAdmin "Digest click-through by position" chart sums. Clicks keep arriving for days after
 * a digest is sent, so each run recomputes the last N days ending yesterday (freegle.digest_positions_rollup.days,
 * default 14). Today is never rolled: a partial day would be marked as summarised and the chart would
 * stop computing it live. --from/--to (inclusive, UTC dates) do a one-off backfill, oldest day first.
 */
#[AsCommand(name: 'email:digest-positions-rollup')]
class DigestPositionsRollupCommand extends Command
{
    protected $signature = 'email:digest-positions-rollup
        {--days= : Days to recompute, ending yesterday (default from DIGEST_POSITIONS_ROLLUP_DAYS, 14)}
        {--from= : First day to roll, YYYY-MM-DD (with --to for a backfill)}
        {--to= : Last day to roll, YYYY-MM-DD (default yesterday)}';

    protected $description = 'Roll unified digest click-through by post position into email_digest_positions_daily';

    public function handle(DigestPositionsRollupService $service): int
    {
        $yesterday = Carbon::now('UTC')->startOfDay()->subDay();

        $to = $this->parseDay($this->option('to')) ?? $yesterday;
        if ($this->option('to') !== null && ! $this->parseDay($this->option('to'))) {
            $this->error('--to must be YYYY-MM-DD.');

            return self::FAILURE;
        }
        if ($to->greaterThan($yesterday)) {
            $to = $yesterday;
        }

        if ($this->option('from') !== null) {
            $from = $this->parseDay($this->option('from'));
            if (! $from) {
                $this->error('--from must be YYYY-MM-DD.');

                return self::FAILURE;
            }
        } else {
            $days = $this->option('days') !== null
                ? (int) $this->option('days')
                : (int) config('freegle.digest_positions_rollup.days', 14);
            $from = $to->copy()->subDays(max($days, 1) - 1);
        }

        $failed = 0;
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            try {
                $rows = $service->rollupDay($day);
                $this->info('Rolled '.$day->toDateString().': '.$rows.' position rows.');
            } catch (\Throwable $e) {
                $failed++;
                $this->error('Failed '.$day->toDateString().': '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function parseDay(?string $s): ?Carbon
    {
        if ($s === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        $d = Carbon::createFromFormat('!Y-m-d', $s, 'UTC');

        return $d->format('Y-m-d') === $s ? $d : null;
    }
}
