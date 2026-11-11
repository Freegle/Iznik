<?php

namespace App\Console\Commands\Cron;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Records a run of a scheduled job that lives outside the Laravel scheduler in
 * cron_job_status, the table behind the ModTools SysAdmin Cron Jobs tab. The
 * scheduler's own jobs are recorded by CronJobStatusListener; this is for
 * scripts/maintenance/freegle-maint, whose systemd timers run on the Docker host
 * and call this through `docker exec -i`, with the run's log on stdin when
 * --log-stdin is given. The job must also be in the Go API's cron registry
 * (iznik-server-go/housekeeper/housekeeper.go) to show in the tab.
 */
#[AsCommand(name: 'cron:record')]
class RecordCommand extends Command
{
    // The same cap CronJobStatusListener puts on a scheduled job's output.
    private const MAX_OUTPUT = 65536;

    protected $signature = 'cron:record
        {job : the command as the registry names it, for example "freegle-maint db"}
        {status : success or failure}
        {summary : one line, shown first in the expanded log}
        {--started= : when the run started (any date PHP parses); now if omitted}
        {--log-stdin : append the log read from stdin}';

    protected $description = 'Record a run of an external scheduled job in the SysAdmin Cron Jobs tab';

    public function handle(): int
    {
        $status = $this->argument('status');

        if (! in_array($status, ['success', 'failure'], true)) {
            $this->error('status must be success or failure');

            return Command::INVALID;
        }

        $started = now();

        if ($this->option('started') !== null) {
            try {
                $started = Carbon::parse($this->option('started'));
            } catch (\Throwable) {
                $this->error('--started is not a date: '.$this->option('started'));

                return Command::INVALID;
            }
        }

        $output = $this->argument('summary');

        if ($this->option('log-stdin')) {
            $log = stream_get_contents(STDIN);

            if ($log !== false && trim($log) !== '') {
                $output .= "\n\n".$log;
            }
        }

        // Keep the end of a long log, where the outcome is.
        if (strlen($output) > self::MAX_OUTPUT) {
            $output = substr($output, -self::MAX_OUTPUT);
        }

        DB::table('cron_job_status')->updateOrInsert(
            ['command' => $this->argument('job')],
            [
                'last_run_at' => $started,
                'last_finished_at' => now(),
                'last_exit_code' => $status === 'success' ? 0 : 1,
                'last_output' => $output,
                'updated_at' => now(),
            ]
        );

        $this->info("Recorded {$this->argument('job')}: {$status}");

        return Command::SUCCESS;
    }
}
