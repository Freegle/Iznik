<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeComponentService;
use App\Services\EeeSqliteService;
use Illuminate\Console\Command;

/**
 * Build (or update) the component vector index used for deterministic EEE logic.
 *
 * Extracts all unique electrical component strings observed in eee_classifications,
 * generates Gemini text-embedding-004 vectors, applies keyword-based initial
 * categorisation (primary_eee / supplementary_eee / non_electrical / unknown),
 * and stores the results in eee_component_types.
 *
 * Run after each batch of new classifications to keep the index current.
 */
class EeeBuildComponentIndexCommand extends Command
{
    protected $signature = 'eee:build-component-index
                            {--force        : Re-generate embeddings for components already in the index}
                            {--recategorize : Re-apply keyword rules to existing entries without re-fetching embeddings}
                            {--list         : List current index contents instead of building}
                            {--stats        : Show category counts only}';

    protected $description = 'Build the electrical component vector index for deterministic EEE logic';

    public function __construct(
        protected EeeComponentService $componentService,
        protected EeeSqliteService $sqlite,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($this->option('list')) {
            return $this->listIndex();
        }

        if ($this->option('recategorize')) {
            return $this->recategorize();
        }

        return $this->buildIndex();
    }

    protected function recategorize(): int
    {
        $all     = $this->sqlite->getComponentTypes();
        $updated = 0;

        foreach ($all as $row) {
            $newCategory = $this->componentService->autoCategory($row['canonical_name']);
            if ($newCategory !== $row['category']) {
                $this->sqlite->upsertComponentType([
                    'canonical_name' => $row['canonical_name'],
                    'category'       => $newCategory,
                ]);
                $updated++;
            }
        }

        $this->info("Recategorised {$updated} of " . count($all) . " entries.");
        $this->showStats();
        return Command::SUCCESS;
    }

    protected function buildIndex(): int
    {
        $needsBuilding = $this->componentService->needsBuilding();

        if (!$needsBuilding && !$this->option('force')) {
            $counts = $this->sqlite->countComponentTypes();
            $total  = array_sum($counts);
            $this->info("Component index already exists ({$total} entries). Use --force to regenerate.");
            $this->showStats();
            return Command::SUCCESS;
        }

        $this->info($needsBuilding ? 'Building component index from scratch…' : 'Rebuilding component index (--force)…');

        $bar    = null;
        $result = $this->componentService->buildIndex(function (int $done, int $total) use (&$bar) {
            if ($bar === null) {
                $bar = $this->output->createProgressBar($total);
                $bar->start();
            }
            $bar->setProgress($done);
        });

        if ($bar) {
            $bar->finish();
            $this->newLine();
        }

        $this->info("Done. Added: {$result['added']}, Skipped (already indexed): {$result['skipped']}, Total: {$result['total']}");
        $this->newLine();
        $this->showStats();

        $this->newLine();
        $unknown = $this->sqlite->getComponentTypes('unknown');
        if (!empty($unknown)) {
            $this->warn(count($unknown) . ' components need manual category review (category=unknown):');
            $this->table(
                ['Component', 'Category'],
                array_map(fn($r) => [$r['canonical_name'], $r['category']], $unknown)
            );
            $this->line('Review with: php artisan eee:build-component-index --list');
        }

        return Command::SUCCESS;
    }

    protected function showStats(): int
    {
        $counts = $this->sqlite->countComponentTypes();
        $total  = array_sum($counts);

        $this->info("=== Component Index ===");
        $rows = [];
        foreach (['primary_eee', 'supplementary_eee', 'non_electrical', 'unknown'] as $cat) {
            $rows[] = [$cat, $counts[$cat] ?? 0];
        }
        $rows[] = ['TOTAL', $total];
        $this->table(['Category', 'Count'], $rows);

        return Command::SUCCESS;
    }

    protected function listIndex(): int
    {
        $all = $this->sqlite->getComponentTypes();
        if (empty($all)) {
            $this->warn('Index is empty. Run without --list to build it.');
            return Command::SUCCESS;
        }

        $byCategory = [];
        foreach ($all as $row) {
            $byCategory[$row['category']][] = $row['canonical_name'];
        }

        foreach (['primary_eee', 'supplementary_eee', 'non_electrical', 'unknown'] as $cat) {
            if (empty($byCategory[$cat])) continue;
            $this->line("<comment>{$cat}</comment> (" . count($byCategory[$cat]) . ')');
            foreach ($byCategory[$cat] as $name) {
                $this->line("  • {$name}");
            }
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}
