<?php

namespace App\Console\Commands\Google;

use App\Services\Google\GoogleAdsService;
use App\Services\Google\GoogleCheckResult;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Weekly Google Ads / Ad Grants check. See docs/developers/reference/google-housekeeping.md.
 */
#[AsCommand(name: 'google:ads-check')]
class AdsCheckCommand extends AbstractGoogleCheckCommand
{
    public const TASK_KEY = 'google-ads';

    protected $signature = 'google:ads-check';

    protected $description = 'Check the Google Ads account for policy disapprovals and Ad Grants compliance';

    protected function taskKey(): string
    {
        return self::TASK_KEY;
    }

    protected function taskDescription(): array
    {
        return [
            'name' => 'Google Ads (Ad Grants)',
            'description' => 'Weekly: checks the account is enabled, no ad is disapproved by policy, click-through rate is above the Ad Grants 5% floor and no keyword has Quality Score 1 or 2',
        ];
    }

    protected function check(): GoogleCheckResult
    {
        return app(GoogleAdsService::class)->run();
    }
}
