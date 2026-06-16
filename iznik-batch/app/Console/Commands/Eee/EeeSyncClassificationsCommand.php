<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeSqliteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Push eee_classifications rows from the iznik-batch SQLite up to the
 * eee-browser SQLite on Fly. The eee-browser is where the dashboard
 * scores model accuracy, so its classification set has to be at least
 * as fresh as ours; otherwise volunteers label items the dashboard
 * can't score.
 *
 * Mirror of EeeSyncMvLabelsCommand but in the opposite direction:
 *   labels   :  prod MySQL -> eee-browser SQLite
 *   classify :  dev SQLite -> eee-browser SQLite
 *
 * The receiver upserts on (messageid, attid, model, prompt_version), so
 * re-runs are idempotent and safe.
 */
class EeeSyncClassificationsCommand extends Command
{
    protected $signature = 'eee:sync-classifications
                            {--url=                : Override eee-browser URL (default: EEE_SYNC_URL env or https://freegle-eee-browser.fly.dev)}
                            {--batch-size=200      : Rows per HTTP POST}
                            {--prompt-versions=1.4.1,1.4.2 : Only push rows at these prompt versions}
                            {--dry-run             : Show counts without pushing}';

    protected $description = 'Push eee_classifications rows to the eee-browser SQLite so dashboard accuracy can join them';

    public function __construct(protected EeeSqliteService $sqlite)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $url    = $this->option('url') ?: env('EEE_SYNC_URL', 'https://freegle-eee-browser.fly.dev');
        $secret = env('EEE_SYNC_SECRET');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$secret) {
            $this->error('EEE_SYNC_SECRET env var is required.');
            return self::FAILURE;
        }

        $versions = array_filter(array_map('trim', explode(',', $this->option('prompt-versions'))));
        if (empty($versions)) {
            $this->error('At least one prompt version required');
            return self::FAILURE;
        }

        $pdo = $this->sqlite->getPdo();
        $placeholders = implode(',', array_fill(0, count($versions), '?'));
        $stmt = $pdo->prepare("SELECT * FROM eee_classifications WHERE attid IS NOT NULL AND prompt_version IN ($placeholders) ORDER BY messageid, attid");
        $stmt->execute($versions);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->info(sprintf('Found %d classification rows at prompt versions %s', count($rows), implode(', ', $versions)));

        if (empty($rows)) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('First 3:');
            foreach (array_slice($rows, 0, 3) as $r) {
                $this->line('  ' . substr(json_encode([
                    'messageid' => $r['messageid'],
                    'attid' => $r['attid'],
                    'model' => $r['model'],
                    'prompt_version' => $r['prompt_version'],
                    'condition' => $r['condition'] ?? null,
                ]), 0, 200));
            }
            return self::SUCCESS;
        }

        $totalInserted = 0;
        $totalRejected = 0;
        foreach (array_chunk($rows, $batchSize) as $i => $chunk) {
            // Drop bulky columns we don't need on the dashboard. Keep payload small.
            $payload = array_map(function ($r) {
                unset($r['id'], $r['raw_response']);
                return $r;
            }, $chunk);

            $resp = Http::timeout(60)
                ->withHeaders(['X-EEE-Sync-Secret' => $secret])
                ->post($url . '/api/admin/sync-classifications', ['rows' => $payload]);

            if (!$resp->successful()) {
                $this->error(sprintf('Batch %d failed: HTTP %d %s', $i, $resp->status(), $resp->body()));
                return self::FAILURE;
            }
            $body = $resp->json();
            $totalInserted += $body['inserted'] ?? 0;
            $totalRejected += $body['rejected'] ?? 0;
            $this->line(sprintf('  batch %d: inserted=%d rejected=%d (%d cumulative)', $i, $body['inserted'] ?? 0, $body['rejected'] ?? 0, $totalInserted));
        }

        $this->info("Done. Upserted {$totalInserted}, rejected {$totalRejected}.");
        return self::SUCCESS;
    }
}
