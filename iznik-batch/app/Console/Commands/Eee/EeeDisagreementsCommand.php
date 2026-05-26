<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Console\Command;

/**
 * Export messages where models disagreed on EEE classification.
 *
 * Disagreements are the most interesting cases for optional human review and
 * are also the most informative training examples for fine-tuning.
 *
 *   php artisan eee:disagreements
 *   php artisan eee:disagreements --output=disagreements.csv --limit=100
 */
class EeeDisagreementsCommand extends Command
{
    protected $signature = 'eee:disagreements
                            {--output=     : CSV output path (default: print table)}
                            {--limit=50    : Max disagreements to show/export}
                            {--threshold=  : Confidence threshold to filter (default: no filter)}';

    protected $description = 'Export messages where models disagreed on EEE classification';

    public function __construct(
        protected EeeSqliteService $sqlite,
        protected EeeVisionService $vision,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit     = (int) $this->option('limit');
        $threshold = $this->option('threshold') ? (float) $this->option('threshold') : null;
        $output    = $this->option('output');

        $pdo = $this->sqlite->getPdo();

        // Find messages classified by more than one model where is_eee differs.
        $sql = "
            SELECT
                a.messageid,
                GROUP_CONCAT(a.model || ':' || COALESCE(a.is_eee, 'null'), ' | ') as model_votes,
                GROUP_CONCAT(DISTINCT a.is_eee) as distinct_votes,
                COUNT(DISTINCT a.is_eee) as vote_spread,
                MAX(a.conflict_flag) as has_text_conflict,
                a.short_description,
                a.is_eee_reasoning
            FROM eee_classifications a
            GROUP BY a.messageid
            HAVING COUNT(DISTINCT a.model) > 1 AND COUNT(DISTINCT a.is_eee) > 1
            ORDER BY a.messageid DESC
            LIMIT ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $this->info('No disagreements found. Have you run eee:compare-models yet?');
            return Command::SUCCESS;
        }

        $this->info(count($rows) . ' messages with model disagreements.');

        if ($output) {
            $fp = fopen($output, 'w');
            fputcsv($fp, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);
            $this->info("Written to {$output}");
        } else {
            $this->table(
                ['Message ID', 'Model votes', 'Text conflict', 'Short description'],
                array_map(fn($r) => [
                    $r['messageid'],
                    $r['model_votes'],
                    $r['has_text_conflict'] ? 'YES' : '-',
                    substr($r['short_description'] ?? '', 0, 60),
                ], $rows),
            );
        }

        // Summary stats.
        $total = $pdo->query("SELECT COUNT(DISTINCT messageid) FROM eee_classifications")->fetchColumn();
        $this->info(sprintf(
            'Disagreement rate: %d / %d (%.1f%%)',
            count($rows),
            $total,
            $total > 0 ? 100 * count($rows) / $total : 0,
        ));

        return Command::SUCCESS;
    }
}
