<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Generate the EEE stats JSON consumed by the public stats web page.
 *
 * Attributes are only included if the corresponding publish_* config flag is
 * true (controlled via EEE_PUBLISH_* env vars). Toggle each attribute on only
 * after model comparison validates high inter-model agreement for that field.
 *
 *   php artisan eee:stats --output=storage/app/public/eee-stats.json
 */
class EeeStatsCommand extends Command
{
    protected $signature = 'eee:stats
                            {--output=     : Path to write JSON file (default: stdout)}
                            {--pretty      : Pretty-print JSON}';

    protected $description = 'Generate EEE stats JSON for the public stats page';

    public function __construct(
        protected EeeSqliteService $sqlite,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $stats = $this->sqlite->getStats();

        $payload = [
            'generated_at'  => now()->toIso8601String(),
            'total_eee'     => $stats['eeeCount'],
            'total_analysed'=> $stats['total'],
            'unusual_eee'   => $stats['unusual'],
            'item_types_classified' => $stats['typesCount'],
            'monthly_trend' => $stats['monthlyTrend'],
        ];

        // Conditionally include attributes based on publish flags.
        if (Config::get('freegle.eee.publish_category')) {
            $payload['by_weee_category'] = $stats['byCategory'];
        }

        if (Config::get('freegle.eee.publish_condition')) {
            $payload['by_condition'] = $stats['byCondition'];
        }

        if (Config::get('freegle.eee.publish_weight')) {
            $payload['total_weight_kg'] = $stats['weightTotal'] ? round((float) $stats['weightTotal'], 1) : null;
        }

        if (Config::get('freegle.eee.publish_brands')) {
            $payload['top_brands'] = $stats['topBrands'];
        }

        $flags = JSON_UNESCAPED_UNICODE;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json   = json_encode($payload, $flags);
        $output = $this->option('output');

        if ($output) {
            $dir = dirname($output);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($output, $json);
            $this->info("Stats written to {$output}");
            $this->line("  Total EEE: {$stats['eeeCount']} / {$stats['total']} analysed");
        } else {
            $this->line($json);
        }

        return Command::SUCCESS;
    }
}
