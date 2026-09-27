<?php

namespace App\Console\Commands\CookieYes;

use App\Mail\Housekeeper\HousekeeperResultsMail;
use App\Services\CookieYes\CookieYesWatchdogService;
use App\Services\EmailSpoolerService;
use App\Services\HousekeeperService;
use Illuminate\Console\Command;

/**
 * Weekly CookieYes watchdog. Replaces the housekeeper Chrome extension's
 * CookieYes task: the result lands in housekeeper_tasks (the ModTools
 * housekeeping badge) and a failure is emailed to Geeks.
 */
class CheckCommand extends Command
{
    public const TASK_KEY = 'cookieyes';

    protected $signature = 'cookieyes:check';

    protected $description = 'Check the CookieYes banner, cookie categories and scans, starting a scan when one is due';

    public function handle(CookieYesWatchdogService $watchdog, HousekeeperService $housekeeper, EmailSpoolerService $spooler): int
    {
        $result = $watchdog->run();

        $housekeeper->recordRun(
            self::TASK_KEY,
            $result->ok ? 'success' : 'failure',
            $result->summary,
            implode("\n", $result->log),
            [
                'name' => 'CookieYes watchdog',
                'description' => 'Weekly: checks the cookie banner is live, GDPR is on, every cookie is categorised and the last scan is recent; starts a scan monthly',
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
                new HousekeeperResultsMail(self::TASK_KEY, 'failure', $result->summary),
                config('freegle.mail.geeks_addr')
            );
        }

        // A failed check is reported above, not through the exit code: the job
        // itself worked, and a non-zero exit would raise the cron-failure badge
        // for the same problem as well.
        return Command::SUCCESS;
    }
}
