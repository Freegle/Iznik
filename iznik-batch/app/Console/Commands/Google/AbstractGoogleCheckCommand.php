<?php

namespace App\Console\Commands\Google;

use App\Mail\Housekeeper\HousekeeperResultsMail;
use App\Services\EmailSpoolerService;
use App\Services\Google\GoogleCheckResult;
use App\Services\HousekeeperService;
use Illuminate\Console\Command;

/**
 * Shared shape of the weekly Google checks: run, record the result in
 * housekeeper_tasks (the ModTools housekeeping badge), and email a failure to
 * Geeks. Same pattern as cookieyes:check.
 */
abstract class AbstractGoogleCheckCommand extends Command
{
    /** The housekeeper_tasks key. */
    abstract protected function taskKey(): string;

    /** @return array{name:string, description:string} */
    abstract protected function taskDescription(): array;

    abstract protected function check(): GoogleCheckResult;

    public function handle(HousekeeperService $housekeeper, EmailSpoolerService $spooler): int
    {
        try {
            $result = $this->check();
        } catch (\Throwable $e) {
            // Anything unexpected still has to reach someone, or the check goes quiet.
            report($e);
            $result = new GoogleCheckResult(false, 'The check crashed: ' . $e->getMessage(), [(string) $e]);
        }

        $housekeeper->recordRun(
            $this->taskKey(),
            $result->ok ? 'success' : 'failure',
            $result->summary,
            implode("\n", $result->log),
            $this->taskDescription() + [
                // A week plus a day's grace, so a run that stops happening shows as overdue.
                'interval_hours' => 192,
                'enabled' => 1,
                'placeholder' => 0,
            ]
        );

        foreach ($result->log as $line) {
            $this->line($line);
        }

        if ($result->ok) {
            $this->info($result->summary);
        } else {
            $this->error($result->summary);
            $spooler->spool(
                new HousekeeperResultsMail($this->taskKey(), 'failure', $result->summary),
                config('freegle.mail.geeks_addr')
            );
        }

        // A failed check is reported above, not through the exit code: the job itself
        // worked, and a non-zero exit would raise the cron-failure badge for the same
        // problem as well.
        return Command::SUCCESS;
    }
}
