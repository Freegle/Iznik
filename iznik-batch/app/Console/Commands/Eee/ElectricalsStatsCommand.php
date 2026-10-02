<?php

namespace App\Console\Commands\Eee;

use App\Services\ElectricalsStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generate the payload for the public /electricals page.
 *
 * Separate from eee:stats, which reads the dev SQLite research store and feeds the
 * model-comparison dashboard. That one is still useful and is left alone; this is the
 * production path, reading messages_eee in MySQL and writing a row the Go API serves.
 *
 *   php artisan electricals:stats
 *   php artisan electricals:stats --dry-run --pretty
 */
class ElectricalsStatsCommand extends Command
{
    protected $signature = 'electricals:stats
                            {--dry-run : Print the payload without storing it}
                            {--pretty  : Pretty-print when printing}
                            {--keep=30 : How many previous generations to retain}';

    protected $description = 'Generate the public /electricals stats payload';

    public function __construct(protected ElectricalsStatsService $stats)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->stats->build();

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($payload, $flags);

        if ($json === false) {
            $this->error('Could not encode payload: ' . json_last_error_msg());
            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line($json);
            return Command::SUCCESS;
        }

        DB::table('electricals_stats')->insert([
            'generated_at' => now()->toDateTimeString(),
            'payload'      => $json,
        ]);

        $this->pruneOldGenerations((int) $this->option('keep'));

        $counts = $payload['counts'];
        $this->info(sprintf(
            'Stored: %s electrical of %s classified (%s%%), %s tonnes reused',
            number_format($counts['electrical']),
            number_format($counts['classified']),
            $counts['electrical_pct'] ?? '-',
            $payload['impact']['tonnes'],
        ));

        return Command::SUCCESS;
    }

    /**
     * Keep a short history so a bad generation can be compared against its predecessor,
     * without letting the table grow without bound.
     */
    protected function pruneOldGenerations(int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $cutoff = DB::table('electricals_stats')
            ->orderByDesc('id')
            ->skip($keep - 1)
            ->take(1)
            ->value('id');

        if ($cutoff) {
            DB::table('electricals_stats')->where('id', '<', $cutoff)->delete();
        }
    }
}
