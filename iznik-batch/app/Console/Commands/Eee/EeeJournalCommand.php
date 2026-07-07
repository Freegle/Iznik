<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use Illuminate\Console\Command;

/**
 * Display the EEE research journal — observations that have been recorded
 * automatically at the end of each run, ordered by confidence level.
 *
 * Confidence levels (most to least confident):
 *   verified    — manually confirmed or human-spot-checked
 *   consistent  — same finding seen across 3+ runs or 100+ samples
 *   emerging    — seen across 2 runs or 50+ samples
 *   preliminary — first observed, not yet corroborated
 *
 * Usage:
 *   php artisan eee:journal
 *   php artisan eee:journal --min-confidence=emerging
 *   php artisan eee:journal --scope=mixed_types
 *   php artisan eee:journal --promote=42 --to=consistent
 */
class EeeJournalCommand extends Command
{
    protected $signature = 'eee:journal
                            {--min-confidence=preliminary : Show findings at or above this level}
                            {--scope=                     : Filter by scope (e.g. mixed_types, ambiguous_types, model:llama)}
                            {--promote=                   : Observation ID to promote to a higher confidence level}
                            {--to=                        : Target confidence level for --promote}
                            {--evidence                   : Show raw evidence JSON}';

    protected $description = 'Display and manage the EEE research journal';

    protected array $confidenceOrder = ['preliminary', 'emerging', 'consistent', 'verified'];

    protected array $confidenceColours = [
        'preliminary' => 'comment',
        'emerging'    => 'info',
        'consistent'  => 'question',
        'verified'    => 'fg=green',
    ];

    public function __construct(protected EeeSqliteService $sqlite)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Handle promotion.
        if ($id = $this->option('promote')) {
            return $this->promoteObservation((int) $id, $this->option('to') ?? 'emerging');
        }

        $minConfidence = $this->option('min-confidence');
        $scopeFilter   = $this->option('scope');

        $observations = $this->sqlite->getObservations($minConfidence);

        if ($scopeFilter) {
            $observations = array_values(array_filter(
                $observations,
                fn($o) => str_contains($o['scope'], $scopeFilter),
            ));
        }

        if (empty($observations)) {
            $this->info('No observations recorded yet. Run eee:classify-item-types to generate the first findings.');
            return Command::SUCCESS;
        }

        $this->line('');
        $this->line('=== EEE Research Journal ===');
        $this->line('');

        $currentConfidence = null;
        foreach ($observations as $obs) {
            if ($obs['confidence'] !== $currentConfidence) {
                $currentConfidence = $obs['confidence'];
                $label = strtoupper($currentConfidence);
                $tag   = $this->confidenceColours[$currentConfidence] ?? 'comment';
                $this->line("<{$tag}>── {$label} ──</{$tag}>");
                $this->line('');
            }

            $date  = substr($obs['observed_at'], 0, 16);
            $scope = $obs['scope'];
            $this->line("  <options=bold>[#{$obs['id']}]</> {$date}  <comment>{$scope}</comment>");
            $this->line("  {$obs['finding']}");

            if ($this->option('evidence') && !empty($obs['evidence'])) {
                $decoded = json_decode($obs['evidence'], true);
                $this->line('  Evidence: ' . json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $this->line('');
        }

        $this->line('To promote a finding: php artisan eee:journal --promote=<id> --to=<confidence>');
        $this->line('Confidence levels: preliminary → emerging → consistent → verified');

        return Command::SUCCESS;
    }

    protected function promoteObservation(int $id, string $toLevel): int
    {
        if (!in_array($toLevel, $this->confidenceOrder)) {
            $this->error("Invalid confidence level '{$toLevel}'. Use: " . implode(', ', $this->confidenceOrder));
            return Command::FAILURE;
        }

        $pdo = $this->sqlite->getPdo();
        $obs = $pdo->prepare("SELECT * FROM eee_observations WHERE id = ?")->execute([$id]);
        $row = $pdo->prepare("SELECT * FROM eee_observations WHERE id = ?")->execute([$id]) ? null : null;

        $stmt = $pdo->prepare("SELECT * FROM eee_observations WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->error("Observation #{$id} not found.");
            return Command::FAILURE;
        }

        $fromLevel = $row['confidence'];
        $fromIdx   = array_search($fromLevel, $this->confidenceOrder);
        $toIdx     = array_search($toLevel, $this->confidenceOrder);

        if ($toIdx <= $fromIdx) {
            $this->warn("Observation #{$id} is already at '{$fromLevel}'. Choose a higher level.");
            return Command::FAILURE;
        }

        $pdo->prepare("UPDATE eee_observations SET confidence = ? WHERE id = ?")
            ->execute([$toLevel, $id]);

        $this->info("Observation #{$id} promoted: {$fromLevel} → {$toLevel}");
        $this->line("  \"{$row['finding']}\"");

        return Command::SUCCESS;
    }
}
