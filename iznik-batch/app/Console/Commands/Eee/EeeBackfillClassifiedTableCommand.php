<?php

namespace App\Console\Commands\Eee;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill of `eee_classified_attachments` from a bundled CSV
 * snapshot of every (messageid, attid) the EEE classifier has previously
 * processed.
 *
 * The detailed classification data lives in iznik-batch's developer SQLite
 * (`storage/eee/classifications.sqlite`) — that file is NOT deployed to
 * production. To seed production with the historical pointer set, the CSV
 * snapshot (`eee_classified_attachments_backfill.csv`, sibling to this
 * file) is checked into the repo and read at runtime.
 *
 * Once seeded, every NEW classification run upserts its pointer directly
 * via EeeClassificationService::recordClassifiedAttachment(), so this
 * command is one-shot — no need to re-snapshot.
 */
class EeeBackfillClassifiedTableCommand extends Command
{
    protected $signature = 'eee:backfill-classified-table
                            {--batch-size=1000 : Rows per INSERT batch}
                            {--csv=            : Override path to the seed CSV (default: bundled snapshot)}
                            {--dry-run         : Show what would be inserted without writing}';

    protected $description = 'Backfill eee_classified_attachments from a bundled CSV snapshot';

    public function handle(): int
    {
        $csvPath = (string) ($this->option('csv') ?: __DIR__ . '/eee_classified_attachments_backfill.csv');
        if (!is_file($csvPath)) {
            $this->error("Seed CSV not found at {$csvPath}");
            return self::FAILURE;
        }

        $rows = [];
        $fh = fopen($csvPath, 'r');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            [$mid, $aid] = array_map('trim', explode(',', $line, 2));
            if (!ctype_digit($mid) || !ctype_digit($aid)) continue;
            $rows[] = [(int) $mid, (int) $aid];
        }
        fclose($fh);

        $total = count($rows);
        $this->info("Seed CSV has $total distinct (messageid, attid) pairs.");

        if ($this->option('dry-run')) {
            $this->line('First 5:');
            foreach (array_slice($rows, 0, 5) as $r) {
                $this->line("  messageid={$r[0]} attid={$r[1]}");
            }
            return self::SUCCESS;
        }

        $existing = DB::table('eee_classified_attachments')->count();
        $this->info("MySQL has $existing rows before backfill.");

        $batchSize = max(1, (int) $this->option('batch-size'));
        $batches   = array_chunk($rows, $batchSize);

        foreach ($batches as $i => $batch) {
            $placeholders = [];
            $params       = [];
            foreach ($batch as [$mid, $aid]) {
                $placeholders[] = '(?, ?)';
                $params[] = $mid;
                $params[] = $aid;
            }
            $sql = 'INSERT IGNORE INTO eee_classified_attachments (messageid, attid) VALUES ' . implode(', ', $placeholders);
            DB::statement($sql, $params);
            $this->line('  batch ' . ($i + 1) . '/' . count($batches) . ': ' . count($batch) . ' upserted');
        }

        $after = DB::table('eee_classified_attachments')->count();
        $this->info("Done. MySQL now has $after rows (added " . ($after - $existing) . " new pointers).");

        return self::SUCCESS;
    }
}
