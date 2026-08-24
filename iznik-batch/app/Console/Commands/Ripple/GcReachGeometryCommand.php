<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ripple:gc-reach-geometry — reclaim shared geometries nothing points at
 * (plans/2026-08-23-rippling-reach-polygon-dedup.md).
 *
 * There is deliberately NO reference counter to consult: the messages FK
 * cascade deletes reach rows inside InnoDB with no hook, and four explicit
 * delete sites bypass application code, so on Galera a counter only drifts.
 * This sweep PROVES a geometry is unreferenced instead, and wrongly deleting
 * one would lose the reach of every post sharing it (261 in the worst case
 * measured), so it takes three independent locks on the decision:
 *
 *  1. Age grace: a geometry touched within --grace-hours is never a
 *     candidate. createdat is age-since-LAST-UPSERT, not first creation -
 *     every writer's upsert refreshes it - so the upsert-then-reference
 *     window (milliseconds) can never race the sweep, including for a shared
 *     geometry resurrected after its references dropped to zero.
 *  2. Two passes agreeing: a hash is only deletable when BOTH this run and a
 *     previous run at least --grace-hours ago found it unreferenced (the
 *     candidate list rides in the config table between runs). A clip's
 *     transient detach-then-repoint therefore cannot look like garbage.
 *  3. The DELETE itself re-checks the anti-join in its own WHERE, one row per
 *     statement - and the FK RESTRICT on the hash columns is the backstop
 *     that makes deleting a still-referenced row physically fail (caught and
 *     skipped, never fatal).
 *
 * Orphans are EXPECTED, not a fault: a crash between geom upsert and the
 * statement that references it, and the undo-log retry ladder storing
 * different bytes than first attempted, both leave unreferenced rows by
 * design. This sweep is their cleanup.
 */
class GcReachGeometryCommand extends Command
{
    protected $signature = 'ripple:gc-reach-geometry
                            {--limit=500 : Max candidate hashes per pass}
                            {--grace-hours=24 : Min geometry age, and min gap between the two agreeing passes}
                            {--reset : Forget the recorded pass and start the two-pass protocol over}
                            {--dry-run : Report what would be deleted without writing}';

    protected $description = 'Two-pass sweep of unreferenced rippling_reach_geom rows';

    /** The previous pass: {"at": iso8601, "candidates": [hex hash, ...]}. */
    private const CONFIG_KEY_PASS = 'ripple_gc_reach_geometry_pass';

    public function handle(): int
    {
        if (!GeomShareService::ready()) {
            $this->error('rippling_reach_geom is not migrated yet; nothing to sweep.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $grace = max(1, (int) $this->option('grace-hours'));

        if ($this->option('reset')) {
            $this->savePass(null);
            $this->info('Recorded pass forgotten.');
        }

        $current = $this->unreferenced($limit, $grace);
        $previous = $this->pass();

        $this->info(sprintf(
            'This pass: %d unreferenced candidate(s)%s.',
            count($current),
            $previous === null ? '; no previous pass recorded' : ' recorded ' . $previous['at']
        ));

        $deleted = 0;
        $blocked = 0;

        $previousUsable = $previous !== null
            && strtotime($previous['at']) <= time() - $grace * 3600;

        if ($previous !== null && !$previousUsable) {
            // Two runs seconds apart would defeat the point of two passes. Keep the
            // OLD pass so its clock keeps running; this run still reports.
            $this->info('Previous pass is younger than the grace period - deleting nothing, keeping it.');
        } elseif ($previousUsable) {
            $deletable = array_values(array_intersect($current, $previous['candidates']));
            $this->info(sprintf('%d candidate(s) agreed by both passes.', count($deletable)));

            foreach ($deletable as $hex) {
                if ($dryRun) {
                    $deleted++;

                    continue;
                }
                $outcome = $this->deleteIfStillUnreferenced($hex, $grace);
                if ($outcome === 'deleted') {
                    $deleted++;
                } elseif ($outcome === 'blocked') {
                    $blocked++;
                }
            }
        }

        if (!$dryRun && ($previousUsable || $previous === null)) {
            $this->savePass(['at' => now()->toIso8601String(), 'candidates' => $current]);
        }

        $this->info(sprintf(
            '%s %d geometr%s%s.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $deleted === 1 ? 'y' : 'ies',
            $blocked > 0 ? ", {$blocked} blocked by a new reference (skipped - that is the FK doing its job)" : ''
        ));

        return self::SUCCESS;
    }

    /**
     * Hashes (lowercase hex) of geom rows older than the grace that nothing
     * references. Both anti-joins are index-driven (rippling_reach_polygon_hash
     * / _max_polygon_hash), so this never touches a blob.
     *
     * @return array<int,string>
     */
    private function unreferenced(int $limit, int $graceHours): array
    {
        // keep-raw: double NOT EXISTS anti-join with HEX/interval expressions - the builder cannot render these
        $rows = DB::select(
            'SELECT LOWER(HEX(g.hash)) AS h
               FROM rippling_reach_geom g
              WHERE g.createdat < NOW() - INTERVAL ? HOUR
                AND NOT EXISTS (SELECT 1 FROM rippling_reach r WHERE r.polygon_hash = g.hash)
                AND NOT EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.max_polygon_hash = g.hash)
              ORDER BY g.createdat
              LIMIT ?',
            [$graceHours, $limit]
        );

        return array_map(static fn ($r) => (string) $r->h, $rows);
    }

    /**
     * Delete one agreed hash, re-proving non-reference inside the DELETE's own
     * WHERE. 'blocked' when the FK RESTRICT refuses (a reference appeared since
     * the anti-join - exactly what the constraint exists to catch); 'kept' when
     * the WHERE no longer matches (referenced again, or already gone).
     */
    private function deleteIfStillUnreferenced(string $hex, int $graceHours): string
    {
        try {
            // keep-raw: guarded single-row DELETE re-checking the anti-join atomically - the builder cannot render this
            $n = DB::affectingStatement(
                'DELETE g FROM rippling_reach_geom g
                  WHERE g.hash = UNHEX(?)
                    AND g.createdat < NOW() - INTERVAL ? HOUR
                    AND NOT EXISTS (SELECT 1 FROM rippling_reach r WHERE r.polygon_hash = g.hash)
                    AND NOT EXISTS (SELECT 1 FROM rippling_reach r2 WHERE r2.max_polygon_hash = g.hash)',
                [$hex, $graceHours]
            );

            return $n > 0 ? 'deleted' : 'kept';
        } catch (\Throwable $e) {
            // MySQL 1451: the FK RESTRICT backstop fired. Anything else is logged
            // and skipped the same way - a sweep must never die mid-list.
            Log::warning('ripple: gc delete refused', ['hash' => $hex, 'error' => substr($e->getMessage(), 0, 200)]);

            return 'blocked';
        }
    }

    /** @return array{at:string,candidates:array<int,string>}|null */
    private function pass(): ?array
    {
        $row = DB::table('config')->where('key', self::CONFIG_KEY_PASS)->first();
        if (!$row || !$row->value) {
            return null;
        }

        $data = json_decode((string) $row->value, true);

        return is_array($data) && isset($data['at'], $data['candidates']) && is_array($data['candidates'])
            ? ['at' => (string) $data['at'], 'candidates' => array_map('strval', $data['candidates'])]
            : null;
    }

    private function savePass(?array $pass): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => self::CONFIG_KEY_PASS],
            ['value' => $pass === null ? '' : json_encode($pass)]
        );
    }
}
