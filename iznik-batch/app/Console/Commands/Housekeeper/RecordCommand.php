<?php

namespace App\Console\Commands\Housekeeper;

use App\Services\HousekeeperService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Records a run of a task that lives outside Laravel in housekeeper_tasks, the
 * ModTools SysAdmin housekeeping tab. Used by scripts/maintenance/freegle-maint,
 * which runs on the Docker host and calls this through `docker exec -i`, with the
 * run's log on stdin when --log-stdin is given.
 */
#[AsCommand(name: 'housekeeper:record')]
class RecordCommand extends Command
{
    protected $signature = 'housekeeper:record
        {task : task_key, for example freegle-maint-db}
        {status : success or failure}
        {summary : one line shown in the tab}
        {--name= : display name}
        {--description= : shown under the name}
        {--interval-hours= : the task is overdue this long after its last run}
        {--disabled : show the row greyed out, never overdue}
        {--log-stdin : read the expandable log from stdin}';

    protected $description = 'Record a run of an external task in the SysAdmin housekeeping tab';

    public function handle(HousekeeperService $housekeeper): int
    {
        $status = $this->argument('status');

        if (! in_array($status, ['success', 'failure'], true)) {
            $this->error('status must be success or failure');

            return Command::INVALID;
        }

        $fields = [
            'enabled' => $this->option('disabled') ? 0 : 1,
            'placeholder' => 0,
        ];

        foreach (['name' => 'name', 'description' => 'description'] as $option => $column) {
            if ($this->option($option) !== null) {
                $fields[$column] = $this->option($option);
            }
        }

        if ($this->option('interval-hours') !== null) {
            $fields['interval_hours'] = max(1, (int) $this->option('interval-hours'));
        }

        $log = null;

        if ($this->option('log-stdin')) {
            $log = stream_get_contents(STDIN);
            // The column is MEDIUMTEXT; keep the end of a long log, where the outcome is.
            $log = $log === false ? null : mb_substr($log, -200000);
        }

        $housekeeper->recordRun($this->argument('task'), $status, mb_substr($this->argument('summary'), 0, 2000), $log, $fields);

        $this->info("Recorded {$this->argument('task')}: {$status}");

        return Command::SUCCESS;
    }
}
