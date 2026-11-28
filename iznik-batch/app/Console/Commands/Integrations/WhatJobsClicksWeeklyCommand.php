<?php

namespace App\Console\Commands\Integrations;

use App\Services\WhatJobs\ClickMonitorService;
use App\Services\WhatJobs\ClickReportComposer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Wednesdays: mail the summary of the last Monday to Sunday of WhatJobs clicks, with the trend
 * chart. WhatJobs publish a day at midnight and take 48 hours more to finalise it, so Sunday is
 * final on Wednesday morning. It reads only whatjobs_clicks_daily, so it still reports, and says
 * the figures are missing, when the dashboard could not be read.
 */
#[AsCommand(name: 'integrations:whatjobs-clicks-weekly')]
class WhatJobsClicksWeeklyCommand extends Command
{
    protected $signature = 'integrations:whatjobs-clicks-weekly
        {--to= : Send to this address instead of freegle.whatjobs.clicks_report_to}';

    protected $description = 'Mail the weekly WhatJobs clicks summary and trend chart';

    public function __construct(
        private readonly ClickMonitorService $monitor,
        private readonly ClickReportComposer $composer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->monitor->weeklySummary(CarbonImmutable::now('UTC'));
        if (! $summary) {
            $this->info('No WhatJobs figures stored for last week: nothing to send.');

            return self::SUCCESS;
        }

        $to = $this->option('to') ?: config('freegle.whatjobs.clicks_report_to');
        Mail::to($to)->send($this->composer->weekly($summary));
        $this->info("Weekly WhatJobs summary for {$summary['start']} to {$summary['end']} sent to {$to}.");

        return self::SUCCESS;
    }
}
