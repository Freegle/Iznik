<?php

namespace App\Console\Commands\Integrations;

use App\Services\WhatJobs\ClickMonitorService;
use App\Services\WhatJobs\ClickReportComposer;
use App\Services\WhatJobs\PartnerDashboardClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Daily: copy our click figures from the WhatJobs partner dashboard into whatjobs_clicks_daily,
 * record our own figures for the same days, and mail an alert when a day or a week has changed
 * suddenly. See ClickMonitorService for what counts as sudden.
 *
 * Fails, so the SysAdmin Cron Jobs tab goes red, when the dashboard cannot be read or WhatJobs
 * have not published a day they should have finalised.
 */
#[AsCommand(name: 'integrations:whatjobs-clicks')]
class WhatJobsClicksCommand extends Command
{
    protected $signature = 'integrations:whatjobs-clicks
        {--months=2 : Months of dashboard figures to read, counting the current one (more fills in history)}
        {--no-mail : Judge the days but send no alert}';

    protected $description = 'Read WhatJobs click figures and mail an alert on a sudden change';

    public function __construct(
        private readonly ClickMonitorService $monitor,
        private readonly ClickReportComposer $composer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $client = PartnerDashboardClient::fromConfig();
        if (! $client) {
            $this->info('No WhatJobs partner login configured: nothing to read.');

            return self::SUCCESS;
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $months = max(1, (int) $this->option('months'));
        $first = $today->subDay()->startOfMonth()->subMonths($months - 1);

        $failed = null;
        $stored = 0;
        for ($m = $first; $m->lte($today->subDay()); $m = $m->addMonth()) {
            try {
                $stored += $this->monitor->storePartnerDays($client->month($m->format('Y-m')), $today);
            } catch (\Throwable $e) {
                $failed = $e->getMessage();
                break;
            }
        }

        // Our own figures for the same days, kept even when the dashboard could not be read,
        // because their sources are pruned.
        $this->monitor->snapshotOurSide($first, $today);

        if ($failed !== null) {
            $this->error("Could not read the WhatJobs partner dashboard: {$failed}");

            return self::FAILURE;
        }

        $findings = $this->monitor->judge($today);
        if ($findings && ! $this->option('no-mail')) {
            try {
                Mail::to(config('freegle.whatjobs.clicks_report_to'))->send($this->composer->alert($findings));
            } catch (\Throwable $e) {
                // Judge those days again next run, so the alert is not lost.
                $this->monitor->unjudge(array_column($findings, 'date'));
                $this->error('Could not send the WhatJobs clicks alert: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $latest = $this->monitor->latestPartnerDate();
        $this->info(sprintf('Stored %d day(s) of WhatJobs figures, latest %s; %d sudden change(s)%s.',
            $stored, $latest ?? 'none', count($findings), $findings && $this->option('no-mail') ? ' (not mailed)' : ''));

        $due = $today->subDays(ClickMonitorService::FINAL_AFTER_DAYS)->toDateString();
        if ($latest === null || $latest < $due) {
            $this->error("WhatJobs have not published figures for {$due}; the latest they have is ".($latest ?? 'none').'.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
