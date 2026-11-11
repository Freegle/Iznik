<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxRollupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Nightly: roll one UTC day of client telemetry (Loki) and regret signals (MySQL) into the ux_*
 * tables that the ModTools UX tab reads. Rerunning a day replaces it.
 *
 * With no --date it does yesterday, and first fills any of the five days before that which have no
 * ux_telemetry_health_daily rows, so a missed night is not a hole. Loki keeps seven days, and the
 * whole of each of those days is still inside it when the job runs; a sixth would be partly gone
 * and would be written as plausible-looking low counts.
 */
#[AsCommand(name: 'ux:rollup-daily')]
class RollupDailyCommand extends Command
{
    /** Days before yesterday that a missed night is filled for, all wholly inside Loki retention. */
    private const BACKFILL_DAYS = 5;

    protected $signature = 'ux:rollup-daily {--date= : UTC day to roll up, YYYY-MM-DD (default yesterday, plus any missing day of the five before)}';

    protected $description = 'Roll a day of UX telemetry from Loki and MySQL into the ux_* tables';

    public function handle(UxRollupService $service): int
    {
        $given = $this->option('date');
        if ($given !== null) {
            // createFromFormat overflows 2026-02-31 into March rather than failing, so check it
            // round-trips.
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $given) ? Carbon::createFromFormat('!Y-m-d', $given, 'UTC') : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $given) {
                $this->error("--date must be YYYY-MM-DD, got '$given'.");

                return self::FAILURE;
            }
            $days = [$parsed->startOfDay()];
        } else {
            $yesterday = Carbon::now('UTC')->startOfDay()->subDay();
            $days = [];
            // Oldest first: the health check's trailing median reads the days before it.
            for ($back = self::BACKFILL_DAYS; $back >= 1; $back--) {
                $day = $yesterday->copy()->subDays($back);
                if (! DB::table('ux_telemetry_health_daily')->where('date', $day->toDateString())->exists()) {
                    $days[] = $day;
                }
            }
            $days[] = $yesterday;
        }

        $failed = 0;
        foreach ($days as $day) {
            $service->rollupRegret($day);
            if ($service->rollupLokiDay($day)) {
                $this->info('Rolled up '.$day->toDateString().'.');
            } else {
                $failed++;
                $this->error('Loki was unreachable for '.$day->toDateString().'; its Loki tables were left as they were.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
