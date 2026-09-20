<?php

namespace App\Console\Commands\Discourse;

use App\Services\AgmCategoryService;
use Illuminate\Console\Command;

/**
 * Yearly maintenance of the AGM category on Discourse. Run by hand, not
 * scheduled: each step is a deliberate decision about when to notify everyone.
 *
 *   php artisan discourse:agm setup                # create AGM <this year>
 *   ... write the information posts in Discourse ...
 *   php artisan discourse:agm announce             # put every user on Watching
 *   php artisan discourse:agm close --year=2026    # after the AGM
 */
class AgmCommand extends Command
{
    protected $signature = 'discourse:agm
        {action : setup, announce or close}
        {--year= : Defaults to the current year}
        {--force : announce only - re-apply even if already watching, so the backfill runs}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Set up, announce or close the annual AGM category on Discourse';

    public function handle(AgmCategoryService $service): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        $year = (int) ($this->option('year') ?: now()->year);
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($action, ['setup', 'announce', 'close'], true)) {
            $this->error("Unknown action '{$action}'. Expected setup, announce or close.");

            return self::FAILURE;
        }

        try {
            $result = match ($action) {
                'setup' => $service->setup($year, $dryRun),
                'announce' => $service->announce($year, (bool) $this->option('force'), $dryRun),
                'close' => $service->close($year, $dryRun),
            };
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['skipped'] ?? false) {
            $this->warn('Discourse API key not configured - skipped.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run - nothing was written.');
        }

        match ($action) {
            'setup' => $this->reportSetup($result, $year),
            'announce' => $this->reportAnnounce($result),
            'close' => $this->reportClose($result, $year),
        };

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $result */
    private function reportSetup(array $result, int $year): void
    {
        $this->info(sprintf(
            '%s category "%s"%s.',
            $result['created'] ? 'Created' : 'Re-applied settings to existing',
            $result['name'],
            $result['categoryId'] ? ' (id '.$result['categoryId'].')' : ''
        ));

        foreach ($result['permissions'] as $group => $permission) {
            $this->line(sprintf('  %-14s %s', $group, $this->permissionLabel((int) $permission)));
        }

        if (!$result['created']) {
            $this->line('  Description left as-is - edit the "About the ..." topic to change it.');
        }

        $this->line('');
        $this->line('Next: add the information posts, then run:');
        $this->line("  php artisan discourse:agm announce --year={$year}");
    }

    /** @param  array<string, mixed>  $result */
    private function reportAnnounce(array $result): void
    {
        if ($result['alreadyWatching'] && !$result['backfilled']) {
            $this->warn(sprintf(
                'Category %d is already in %s ("%s").',
                $result['categoryId'],
                AgmCategoryService::WATCHING_SETTING,
                $result['previous']
            ));
            $this->line('Discourse only backfills users when the setting actually changes, so nothing happened.');
            $this->line("Re-run with --force to remove and re-add it, which does backfill existing users.");

            return;
        }

        $this->info(sprintf(
            '%s = "%s" (was "%s").',
            AgmCategoryService::WATCHING_SETTING,
            $result['value'],
            $result['previous']
        ));

        if ($result['backfilled']) {
            $this->line('Existing users backfilled to Watching. New posts in the AGM category now notify everyone.');
        }
    }

    /** @param  array<string, mixed>  $result */
    private function reportClose(array $result, int $year): void
    {
        $this->info(sprintf('Closed the AGM %d category (id %d).', $year, $result['categoryId']));

        if ($result['wasWatching']) {
            $this->line(sprintf(
                '  %s = "%s" - Watching rows for this category removed.',
                AgmCategoryService::WATCHING_SETTING,
                $result['value']
            ));
        } else {
            $this->line('  It was not in the watching setting, so nothing to unwatch.');
        }

        foreach ($result['permissions'] as $group => $permission) {
            $this->line(sprintf('  %-14s %s', $group, $this->permissionLabel((int) $permission)));
        }

        $this->line('  Topics are kept and stay readable.');
    }

    private function permissionLabel(int $permission): string
    {
        return match ($permission) {
            1 => 'create / reply / see',
            2 => 'reply / see',
            3 => 'see only',
            default => 'unknown ('.$permission.')',
        };
    }
}
