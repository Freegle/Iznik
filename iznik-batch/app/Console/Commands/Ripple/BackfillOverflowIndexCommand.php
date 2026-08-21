<?php

namespace App\Console\Commands\Ripple;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Populate rippling_reach_overflow from rippling_reach.overflow_bounds.
 *
 * The rings are authoritative in rippling_reach.overflow_bounds, which is JSON
 * and therefore unindexable: asking "does any ring admit this point" against it
 * scans a ~17GB table. rippling_reach_overflow carries the same rings' bounding
 * box as an indexed POLYGON so the read surfaces can narrow first and then ask
 * the exact question of a handful of posts by primary key.
 *
 * WALKS msgid RANGES IN CHUNKS, deliberately. The one-liner for this is
 * INSERT ... SELECT over rippling_reach, which is a single write set spanning
 * the whole table - the shape that has produced a Galera lock storm here before.
 * Chunking keeps each transaction small and lets the cluster breathe between
 * them.
 *
 * Idempotent: re-running refreshes existing rows and adds new ones, so it is
 * safe to run repeatedly, and safe to interrupt and resume.
 */
class BackfillOverflowIndexCommand extends Command
{
    protected $signature = 'ripple:backfill-overflow-index
                            {--chunk=500 : msgids per transaction}
                            {--sleep=200 : milliseconds to pause between chunks}
                            {--dry-run : count what would be written, change nothing}';

    protected $description = 'Populate the indexed ring bbox table from rippling_reach.overflow_bounds';

    public function handle(): int
    {
        if (! $this->tableExists('rippling_reach_overflow')) {
            $this->error('rippling_reach_overflow does not exist - apply the schema first.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        // Drive off the PRIMARY key so each pass is an indexed range, never a scan.
        $lastId = 0;
        $written = 0;
        $skipped = 0;

        while (true) {
            $rows = DB::select(
                'SELECT msgid,
                        JSON_EXTRACT(overflow_bounds, "$.bbox[0]") AS minx,
                        JSON_EXTRACT(overflow_bounds, "$.bbox[1]") AS miny,
                        JSON_EXTRACT(overflow_bounds, "$.bbox[2]") AS maxx,
                        JSON_EXTRACT(overflow_bounds, "$.bbox[3]") AS maxy
                   FROM rippling_reach
                  WHERE msgid > ?
                    AND overflow_bounds IS NOT NULL
                  ORDER BY msgid
                  LIMIT '.$chunk,
                [$lastId]
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->msgid;

                // A ring family with no bbox cannot be prefiltered. Skip rather than
                // invent one: a wrong box would admit or exclude the wrong members,
                // and the exact JSON test is what decides either way.
                if ($row->minx === null || $row->miny === null || $row->maxx === null || $row->maxy === null) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $written++;

                    continue;
                }

                $this->upsert(
                    (int) $row->msgid,
                    (float) $row->minx,
                    (float) $row->miny,
                    (float) $row->maxx,
                    (float) $row->maxy
                );
                $written++;
            }

            $this->line(sprintf('  ... msgid <= %d, written %d, skipped %d', $lastId, $written, $skipped));

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info(sprintf('%s %d rows (%d skipped for a missing bbox)',
            $dryRun ? 'Would write' : 'Wrote', $written, $skipped));
        Log::info('ripple:backfill-overflow-index finished', [
            'written' => $written, 'skipped' => $skipped, 'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * One row's bbox as a POLYGON. Shared with the live writers so the geometry
     * is built one way only - see RipplingOverflowIndex::upsert().
     */
    private function upsert(int $msgid, float $minx, float $miny, float $maxx, float $maxy): void
    {
        \App\Services\Ripple\RipplingOverflowIndex::upsert($msgid, $minx, $miny, $maxx, $maxy);
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($row->n ?? 0) > 0;
    }
}
