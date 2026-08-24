<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\GeomShareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:verify-geometry-dedup — the checker for the reach geometry dedup
 * (plans/2026-08-23-rippling-reach-polygon-dedup.md).
 *
 * Content addressing self-verifies: the hash IS the MD5 of the bytes, so this
 * recomputes it from both ends and compares. For a sample of rows with hashes
 * set, per shared column:
 *
 *   dangling  - the hash points at no rippling_reach_geom row. Harmless before
 *               the drain (readers COALESCE to the blob) but must be zero
 *               before ripple:drain-deduped-blobs runs.
 *   geom_bad  - the shared row's bytes do not hash to their own key. Should be
 *               impossible (nothing ever mutates a geom row); any hit means
 *               corruption and the sweep/drain must stop.
 *   blob_bad  - an undrained blob does not match its own hash column. Means a
 *               writer changed the blob without updating/nulling the hash -
 *               a missed write site, exactly what this checker exists to catch.
 *
 * Exits non-zero on ANY mismatch, and ALSO when it compared nothing: a checker
 * that silently examined an empty set is worse than none (the plan's words).
 */
class VerifyGeometryDedupCommand extends Command
{
    protected $signature = 'ripple:verify-geometry-dedup
                            {--limit=200 : Max rows to verify this run}
                            {--after=0 : Start after this msgid}';

    protected $description = 'Recompute and cross-check rippling_reach geometry hashes against rippling_reach_geom';

    public function handle(): int
    {
        if (!GeomShareService::ready()) {
            $this->error('rippling_reach_geom is not migrated yet; nothing to verify.');

            return self::FAILURE;
        }

        $after = (int) $this->option('after');
        $limit = max(1, (int) $this->option('limit'));

        $msgids = DB::table('rippling_reach')
            ->where('msgid', '>', $after)
            ->whereRaw('(polygon_hash IS NOT NULL OR max_polygon_hash IS NOT NULL)')
            ->orderBy('msgid')
            ->limit($limit)
            ->pluck('msgid');

        $compared = 0;
        $bad = ['dangling' => 0, 'geom_bad' => 0, 'blob_bad' => 0];

        foreach ($msgids as $msgid) {
            foreach (['polygon', 'max_polygon'] as $col) {
                $verdict = $this->verifyColumn((int) $msgid, $col);
                if ($verdict === null) {
                    continue; // no hash on this column - nothing claimed, nothing to check
                }
                $compared++;
                foreach ($verdict as $kind) {
                    $bad[$kind]++;
                    $this->warn("msgid {$msgid} {$col}: {$kind}");
                }
            }
        }

        $failures = array_sum($bad);
        $this->info(sprintf(
            'Verified %d hash(es) across %d row(s): %d dangling, %d shared-row mismatch(es), %d blob mismatch(es).',
            $compared,
            $msgids->count(),
            $bad['dangling'],
            $bad['geom_bad'],
            $bad['blob_bad']
        ));

        if ($compared === 0) {
            // An empty comparison must fail loudly: green output over nothing is how
            // a mis-scoped checker hides a broken backfill.
            $this->error('Compared nothing - no rows in range carry a hash. Not a pass.');

            return self::FAILURE;
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The failure kinds for one row's column, [] when all checks pass, or null
     * when the column carries no hash (nothing is claimed, so nothing can be
     * wrong). One SELECT per (row, column): the blob MD5 is the expensive part
     * and only rows a checker was pointed at pay it.
     *
     * @return array<int,string>|null
     */
    private function verifyColumn(int $msgid, string $col): ?array
    {
        $drained = GeomShareService::drainedExpr('r', $col);

        // keep-raw: MD5/ST_AsBinary hash recomputation on both sides of the join - the builder cannot render these
        $row = DB::selectOne(
            "SELECT r.{$col}_hash IS NULL AS no_hash,
                    g.hash IS NULL AS dangling,
                    (g.hash IS NOT NULL AND UNHEX(MD5(ST_AsBinary(g.geom))) = r.{$col}_hash) AS geom_ok,
                    (r.{$col} IS NULL OR $drained) AS blob_gone,
                    (r.{$col} IS NOT NULL AND UNHEX(MD5(ST_AsBinary(r.{$col}))) = r.{$col}_hash) AS blob_ok
               FROM rippling_reach r
               LEFT JOIN rippling_reach_geom g ON g.hash = r.{$col}_hash
              WHERE r.msgid = ?",
            [$msgid]
        );

        if ($row === null || (int) $row->no_hash === 1) {
            return null;
        }

        $kinds = [];
        if ((int) $row->dangling === 1) {
            $kinds[] = 'dangling';
        } elseif ((int) $row->geom_ok !== 1) {
            $kinds[] = 'geom_bad';
        }
        // A drained (or NULLed) blob makes no claim; an undrained one must match.
        if ((int) $row->blob_gone !== 1 && (int) $row->blob_ok !== 1) {
            $kinds[] = 'blob_bad';
        }

        return $kinds;
    }
}
