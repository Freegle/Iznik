<?php

namespace App\Console\Commands\Desirability;

use App\Services\Desirability\DesirabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports a desirability artifact into item_desirability.
 *
 * The artifact is produced by the offline analysis: a JSONL file, one object
 * per canonical title:
 *   {"canonical": "...", "cluster_rep": null|"...", "lift_replies": 1.23,
 *    "evidence": 45.6, "lift_views": 0.98, "taken_rate": 0.61, "n_posts": 57,
 *    "bucket": "high", "embedding": null|"<base64 of 256 little-endian float32>"}
 *
 * Import replaces all rows for the target model version atomically enough for
 * a batch consumer (delete + chunked insert inside a transaction per chunk;
 * scoring reads are tolerant of a partially-built version because score-new
 * only starts once artifactReady() and re-runs hourly).
 *
 *   php artisan desirability:import-artifact /path/to/artifact.jsonl --model-version=desir-2026-08
 */
class ImportArtifactCommand extends Command
{
    protected $signature = 'desirability:import-artifact
                            {path : Path to the artifact JSONL file}
                            {--model-version= : Version tag to import as (default: configured version)}
                            {--keep-existing : Do not delete existing rows for this version first}';

    protected $description = 'Import an offline-built item desirability artifact';

    public function __construct(protected DesirabilityService $desirability)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $version = (string) ($this->option('model-version') ?: $this->desirability->modelVersion());

        if (! is_readable($path)) {
            $this->error("Cannot read artifact file: {$path}");

            return Command::FAILURE;
        }

        $fh = fopen($path, 'r');
        if (! $fh) {
            $this->error("Cannot open artifact file: {$path}");

            return Command::FAILURE;
        }

        if (! $this->option('keep-existing')) {
            $deleted = DB::table('item_desirability')->where('model_version', $version)->delete();
            $this->info("Removed {$deleted} existing rows for {$version}.");
        }

        $batch = [];
        $imported = 0;
        $skipped = 0;
        $withEmbedding = 0;
        $now = now()->toDateTimeString();

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['canonical'], $row['lift_replies'], $row['evidence'], $row['bucket'])) {
                $skipped++;

                continue;
            }
            $embedding = null;
            if (! empty($row['embedding'])) {
                $embedding = base64_decode((string) $row['embedding'], true);
                if ($embedding === false || strlen($embedding) !== DesirabilityService::EMBEDDING_DIM * 4) {
                    $skipped++;

                    continue;
                }
                $withEmbedding++;
            }
            $batch[] = [
                'canonical' => mb_substr((string) $row['canonical'], 0, 191),
                'cluster_rep' => isset($row['cluster_rep']) ? mb_substr((string) $row['cluster_rep'], 0, 191) : null,
                'lift_replies' => (float) $row['lift_replies'],
                'evidence' => (float) $row['evidence'],
                'lift_views' => isset($row['lift_views']) ? (float) $row['lift_views'] : null,
                'taken_rate' => isset($row['taken_rate']) ? (float) $row['taken_rate'] : null,
                'n_posts' => (int) ($row['n_posts'] ?? 0),
                'bucket' => in_array($row['bucket'], ['low', 'medium', 'high'], true) ? $row['bucket'] : 'medium',
                'embedding' => $embedding,
                'model_version' => $version,
                'built_at' => $now,
            ];
            if (count($batch) >= 500) {
                DB::table('item_desirability')->upsert($batch, ['canonical', 'model_version'],
                    ['cluster_rep', 'lift_replies', 'evidence', 'lift_views', 'taken_rate', 'n_posts', 'bucket', 'embedding', 'built_at']);
                $imported += count($batch);
                $batch = [];
                if ($imported % 50000 === 0) {
                    $this->info("... {$imported} rows");
                }
            }
        }
        fclose($fh);

        if (count($batch)) {
            DB::table('item_desirability')->upsert($batch, ['canonical', 'model_version'],
                ['cluster_rep', 'lift_replies', 'evidence', 'lift_views', 'taken_rate', 'n_posts', 'bucket', 'embedding', 'built_at']);
            $imported += count($batch);
        }

        $this->info("Imported {$imported} rows for {$version} ({$withEmbedding} with embeddings, {$skipped} skipped).");

        return $skipped > 0 && $imported === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
