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
 * Two passes. The first only validates: the command refuses to touch the table
 * at all unless the file yields at least one importable row, so an empty or
 * truncated upload cannot destroy the live rows and still exit 0. The second
 * pass runs inside one transaction - delete existing rows for the version,
 * then insert - so a crash mid-import rolls back to the previous artifact and
 * scoring never sees a partially-built version.
 *
 * Correcting an artifact under the SAME model version does not rescore
 * messages already in messages_desirability (score-new's resume check is per
 * (msgid, model_version)); import under a new version to force a rescore.
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

        // Pass 1: validate only. Nothing is deleted or written unless the file
        // actually contains importable rows.
        $counts = $this->scan($path);
        if ($counts === null) {
            return Command::FAILURE;
        }
        [$valid, $skipped, $collided] = $counts;
        if ($valid === 0) {
            $this->error("Artifact contains no importable rows ({$skipped} skipped) - existing data left untouched.");

            return Command::FAILURE;
        }
        $this->info("Artifact validated: {$valid} rows ({$skipped} skipped, {$collided} truncation collisions).");

        // Pass 2: replace atomically. A crash anywhere in here rolls the whole
        // version back to its previous state.
        $imported = 0;
        $withEmbedding = 0;
        DB::transaction(function () use ($path, $version, &$imported, &$withEmbedding) {
            if (! $this->option('keep-existing')) {
                $deleted = DB::table('item_desirability')->where('model_version', $version)->delete();
                $this->info("Removed {$deleted} existing rows for {$version}.");
            }
            $now = now()->toDateTimeString();
            $batch = [];
            $fh = fopen($path, 'r');
            while (($line = fgets($fh)) !== false) {
                $row = $this->parseRow($line);
                if ($row === null) {
                    continue;
                }
                if (! empty($row['embedding'])) {
                    $withEmbedding++;
                }
                $batch[] = $row + ['model_version' => $version, 'built_at' => $now];
                if (count($batch) >= 500) {
                    $this->upsertBatch($batch);
                    $imported += count($batch);
                    $batch = [];
                    if ($imported % 50000 === 0) {
                        $this->info("... {$imported} rows");
                    }
                }
            }
            fclose($fh);
            if (count($batch)) {
                $this->upsertBatch($batch);
                $imported += count($batch);
            }
        });

        $this->info("Imported {$imported} rows for {$version} ({$withEmbedding} with embeddings).");
        $already = DB::table('messages_desirability')->where('model_version', $version)->count();
        if ($already > 0) {
            $this->warn("{$already} messages are already scored under {$version} and will NOT be rescored - import under a new model version to rescore.");
        }

        return Command::SUCCESS;
    }

    /** @return ?array{0: int, 1: int, 2: int} [valid, skipped, truncation collisions]; null on read error */
    private function scan(string $path): ?array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            $this->error("Cannot open artifact file: {$path}");

            return null;
        }
        $valid = 0;
        $skipped = 0;
        $collided = 0;
        $seen = [];
        while (($line = fgets($fh)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $row = $this->parseRow($line);
            if ($row === null) {
                $skipped++;

                continue;
            }
            // Two distinct canonicals that agree on their first 191 chars would
            // silently overwrite each other through the unique key.
            if (isset($seen[$row['canonical']])) {
                $collided++;
                $this->warn('Truncation collision on canonical: '.$row['canonical']);
            }
            $seen[$row['canonical']] = true;
            $valid++;
        }
        fclose($fh);

        return [$valid, $skipped, $collided];
    }

    /** @return ?array<string, mixed> null when the line is not importable */
    private function parseRow(string $line): ?array
    {
        $row = json_decode(trim($line), true);
        if (! is_array($row) || ! isset($row['canonical'], $row['lift_replies'], $row['evidence'], $row['bucket'])) {
            return null;
        }
        $embedding = null;
        if (! empty($row['embedding'])) {
            $embedding = base64_decode((string) $row['embedding'], true);
            if ($embedding === false || strlen($embedding) !== DesirabilityService::EMBEDDING_DIM * 4) {
                return null;
            }
        }

        return [
            'canonical' => mb_substr((string) $row['canonical'], 0, 191),
            'cluster_rep' => isset($row['cluster_rep']) ? mb_substr((string) $row['cluster_rep'], 0, 191) : null,
            'lift_replies' => (float) $row['lift_replies'],
            'evidence' => (float) $row['evidence'],
            'lift_views' => isset($row['lift_views']) ? (float) $row['lift_views'] : null,
            'taken_rate' => isset($row['taken_rate']) ? (float) $row['taken_rate'] : null,
            'n_posts' => (int) ($row['n_posts'] ?? 0),
            'bucket' => in_array($row['bucket'], ['low', 'medium', 'high'], true) ? $row['bucket'] : 'medium',
            'embedding' => $embedding,
        ];
    }

    /** @param array<int, array<string, mixed>> $batch */
    private function upsertBatch(array $batch): void
    {
        DB::table('item_desirability')->upsert($batch, ['canonical', 'model_version'],
            ['cluster_rep', 'lift_replies', 'evidence', 'lift_views', 'taken_rate', 'n_posts', 'bucket', 'embedding', 'built_at']);
    }
}
