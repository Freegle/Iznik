<?php

namespace App\Console\Commands\Google;

use App\Services\Google\GoogleCheckResult;
use App\Services\Google\SearchConsoleService;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Weekly Search Console check. See docs/developers/reference/google-housekeeping.md.
 */
#[AsCommand(name: 'google:search-console-check')]
class SearchConsoleCheckCommand extends AbstractGoogleCheckCommand
{
    public const TASK_KEY = 'google-search-console';

    protected $signature = 'google:search-console-check
                            {--submit-missing : Submit the expected sitemap to a property that has none}';

    protected $description = 'Check Search Console sitemaps and sample pages for problems';

    protected function taskKey(): string
    {
        return self::TASK_KEY;
    }

    protected function taskDescription(): array
    {
        return [
            'name' => 'Google Search Console',
            'description' => 'Weekly: checks every Search Console property for sitemap errors, warnings, a sitemap Google has stopped reading, and sample pages Google cannot fetch',
        ];
    }

    protected function check(): GoogleCheckResult
    {
        return app(SearchConsoleService::class)->run((bool) $this->option('submit-missing'));
    }
}
