<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill: copy every distinct (messageid, attid) the EEE
 * classifier has ever processed from the iznik-batch SQLite into the new
 * MySQL `eee_classified_attachments` pointer table.
 *
 * Once the new pointer table is populated, the Go micro-volunteering API
 * joins against it so volunteers label items the model has classified —
 * which is what the Condition/Weight/Size accuracy scoring relies on.
 */
class EeeBackfillClassifiedTableCommand extends Command
{
    protected $signature = 'eee:backfill-classified-table
                            {--batch-size=1000 : Rows per INSERT batch}
                            {--dry-run         : Show what would be inserted without writing}';

    protected $description = 'Backfill eee_classified_attachments from the SQLite eee_classifications history';

    public function __construct(protected EeeSqliteService $sqlite)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pdo = $this->sqlite->getPdo();

        // Distinct image classifications only — text-only rows have attid IS NULL.
        $rows = $pdo->query("SELECT DISTINCT messageid, attid FROM eee_classifications WHERE attid IS NOT NULL")->fetchAll(\PDO::FETCH_ASSOC);
        $total = count($rows);
        $this->info("SQLite has $total distinct (messageid, attid) image classifications.");

        if ($this->option('dry-run')) {
            $this->line('First 5:');
            foreach (array_slice($rows, 0, 5) as $r) {
                $this->line('  ' . json_encode($r));
            }
            return self::SUCCESS;
        }

        // Existing rows on the MySQL side (so we can report new vs already-present).
        $existing = DB::table('eee_classified_attachments')->count();
        $this->info("MySQL has $existing rows before backfill.");

        $batchSize = max(1, (int) $this->option('batch-size'));
        $batches   = array_chunk($rows, $batchSize);
        $inserted  = 0;

        foreach ($batches as $i => $batch) {
            $values = [];
            $params = [];
            foreach ($batch as $r) {
                $values[] = '(?, ?)';
                $params[] = (int) $r['messageid'];
                $params[] = (int) $r['attid'];
            }
            $sql = 'INSERT IGNORE INTO eee_classified_attachments (messageid, attid) VALUES ' . implode(', ', $values);
            DB::statement($sql, $params);
            $inserted += count($batch);
            $this->line("  batch " . ($i + 1) . "/" . count($batches) . ": " . count($batch) . " upserted");
        }

        $after = DB::table('eee_classified_attachments')->count();
        $this->info("Done. MySQL now has $after rows (added " . ($after - $existing) . " new pointers).");

        return self::SUCCESS;
    }
}
