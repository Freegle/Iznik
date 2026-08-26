<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\LegacyGeometry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:verify-ring-cells-parity - do the stored ring grids describe the same
 * areas as the ring WKT they replaced?
 *
 * WHY THIS EXISTS SEPARATELY. ripple:verify-cells-parity is the gate for the
 * Stage 3 drop (plans/2026-08-24-rippling-reach-raster-storage.md), and it has
 * eight read cases - seven over polygon_cells and one over max_polygon_cells.
 * NONE of them reads overflow_cells: the string "overflow" does not appear in
 * that command at all, and its whole-word "ring" matches are about polygon
 * EXTERIOR rings (boundary sampling, metresFromEdge), not overflow rings. So
 * before this command, the rings converted with their PRESENCE guarded by the
 * drop migration and their CORRECTNESS guarded by nothing.
 *
 * That gap matters because the drop is irreversible and the rings decide
 * admission. overflow_bounds is what lets the rural and fairness lanes admit
 * members the committed reach does not cover; once it is gone, a wrong grid
 * cannot be detected by comparison or rebuilt from anything.
 *
 * WHAT IT CHECKS, in the order the failures matter:
 *
 *   1. A LOST RING - a lane/band carrying WKT in overflow_bounds with no
 *      matching entry in overflow_cells. After the drop that lane admits
 *      nobody, silently, for the life of the row. This is the failure
 *      ripple:backfill-ring-cells could previously produce by storing the
 *      rings that rasterised and dropping the ones that did not.
 *   2. AN INVENTED RING - an entry in overflow_cells with no WKT behind it.
 *      That lane would admit people no ring ever covered.
 *   3. AN UNREADABLE GRID - stored bytes that will not decode. Post-drop this
 *      is a lane with no recoverable meaning.
 *   4. A DIFFERENT AREA - the stored grid against a fresh rasterise of the
 *      same WKT, measured as the SYMMETRIC DIFFERENCE IN CELLS. Byte equality
 *      is reported when it holds (it is the common case, since both sides go
 *      through the same endpoint) but is not required: what matters is
 *      whether the covered set moved, not how it was packed.
 *
 * Read-only - SELECTs plus the rasterise endpoint - so it is safe to point at
 * production, which is the only place the real rings exist.
 *
 * NOT a substitute for ripple:verify-cells-parity. This command answers "does
 * the stored grid describe the stored ring", which is the right question for a
 * CONVERSION. It deliberately does not re-ask every read case against the
 * rings, because the rings have one read case - does this lane admit this
 * point - and it is answered by the same containment primitive case 1 already
 * covers for the reach.
 */
class VerifyRingCellsParityCommand extends Command
{
    protected $signature = 'ripple:verify-ring-cells-parity
                            {--limit=20 : How many ringed rows to sample}
                            {--after=0 : Start after this msgid}
                            {--max-cell-difference=0 : Symmetric difference in cells counted as a failure}
                            {--json= : Also write the full per-ring report to this path}';

    protected $description = 'Compare every stored overflow ring grid against a fresh rasterise of the ring WKT it replaced';

    public function __construct(private CellSetService $cellSets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Both forms have to exist for there to be anything to compare, and
        // this belongs BEFORE the drop rather than after it.
        if (!LegacyGeometry::overflowReady()) {
            $this->error('rippling_reach.overflow_bounds has been dropped, so there is nothing left to compare the ring grids against. '
                . 'This check belongs BEFORE the drop.');

            return self::FAILURE;
        }

        $rows = DB::table('rippling_reach')
            ->select('msgid', 'overflow_bounds', 'overflow_cells')
            ->where('msgid', '>', (int) $this->option('after'))
            ->whereNotNull('overflow_bounds')
            ->whereNotNull('overflow_cells')
            ->orderBy('msgid')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        // A check that compared nothing must not report success: an empty
        // sample and a clean sample read identically otherwise, and this one
        // is quoted as evidence for an irreversible DDL.
        if ($rows->isEmpty()) {
            $this->error('No rows carry both overflow_bounds and overflow_cells - nothing to compare. '
                . 'Run ripple:backfill-ring-cells first, or point this at a database that has both.');

            return self::FAILURE;
        }

        $maxDiff = max(0, (int) $this->option('max-cell-difference'));

        $report = [
            'rows' => 0,
            'rings' => 0,
            'byteIdentical' => 0,
            'sameCoverage' => 0,
            'lostRings' => 0,
            'inventedRings' => 0,
            'unreadableGrids' => 0,
            'rasteriseFailures' => 0,
            'worstCellDifference' => 0,
            'failures' => [],
        ];
        $detail = [];

        foreach ($rows as $row) {
            $report['rows']++;
            $detail[] = $this->checkRow($row, $maxDiff, $report);
            $this->output->write('.');
        }
        $this->newLine(2);

        $this->render($report);

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode([
                'summary' => $report,
                'rows' => $detail,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Full per-ring report written to ' . $this->option('json'));
        }

        return empty($report['failures']) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function checkRow(object $row, int $maxDiff, array &$report): array
    {
        $msgid = (int) $row->msgid;
        $rowDetail = ['msgid' => $msgid, 'rings' => []];

        $bounds = json_decode((string) $row->overflow_bounds, true);
        $cells = json_decode((string) $row->overflow_cells, true);
        if (!is_array($bounds) || !is_array($cells)) {
            $report['failures'][] = sprintf('msgid %d: overflow_bounds or overflow_cells is not decodable JSON', $msgid);

            return $rowDetail;
        }

        // Walk the WKT side first: anything here without a grid is a LOST ring,
        // which is the failure that survives the drop.
        foreach ($bounds as $lane => $ringsForLane) {
            if (!is_array($ringsForLane)) {
                continue; // fairness_budget_min and friends: scalars, not rings
            }
            foreach ($ringsForLane as $band => $wkt) {
                // Mirrors the backfill's own filter: bbox IS an array of floats
                // and gets this far, so the string test is what excludes it.
                if (!is_string($wkt) || $wkt === '') {
                    continue;
                }
                $report['rings']++;
                $rowDetail['rings'][] = $this->checkRing($msgid, (string) $lane, (string) $band, $wkt, $cells, $maxDiff, $report);
            }
        }

        // Then the grid side, for entries with nothing behind them.
        foreach ($cells as $lane => $bandsForLane) {
            if (!is_array($bandsForLane)) {
                continue;
            }
            foreach (array_keys($bandsForLane) as $band) {
                $wkt = $bounds[$lane][$band] ?? null;
                if (!is_string($wkt) || $wkt === '') {
                    $report['inventedRings']++;
                    $report['failures'][] = sprintf(
                        'msgid %d lane %s band %s: a stored grid with no ring WKT behind it - that lane would admit people no ring covers',
                        $msgid, $lane, $band
                    );
                }
            }
        }

        return $rowDetail;
    }

    /** @return array<string,mixed> */
    private function checkRing(
        int $msgid,
        string $lane,
        string $band,
        string $wkt,
        array $cells,
        int $maxDiff,
        array &$report
    ): array {
        $detail = ['lane' => $lane, 'band' => $band];

        $storedB64 = $cells[$lane][$band] ?? null;
        if (!is_string($storedB64) || $storedB64 === '') {
            $report['lostRings']++;
            $report['failures'][] = sprintf(
                'msgid %d lane %s band %s: ring WKT with NO stored grid - after the drop this lane admits nobody',
                $msgid, $lane, $band
            );
            $detail['result'] = 'lost';

            return $detail;
        }

        $stored = base64_decode($storedB64, true);
        if ($stored === false || $stored === '') {
            $report['unreadableGrids']++;
            $report['failures'][] = sprintf('msgid %d lane %s band %s: stored grid is not valid base64', $msgid, $lane, $band);
            $detail['result'] = 'unreadable';

            return $detail;
        }

        $fresh = $this->cellSets->rasterize($wkt);
        if ($fresh === null) {
            // The rasteriser could not answer, so THIS run cannot judge the
            // ring. Counted and surfaced rather than passed over: a run that
            // could not check anything must not read as a run that found
            // nothing wrong.
            $report['rasteriseFailures']++;
            $report['failures'][] = sprintf(
                'msgid %d lane %s band %s: could not re-rasterise the ring WKT, so this ring is UNCHECKED',
                $msgid, $lane, $band
            );
            $detail['result'] = 'unchecked';

            return $detail;
        }

        if ($stored === $fresh) {
            $report['byteIdentical']++;
            $detail['result'] = 'byte-identical';

            return $detail;
        }

        // Different bytes are not automatically wrong - what matters is whether
        // the COVERED SET moved. Decoding is the expensive path (allocation is
        // proportional to covered area), which is why it only runs when the
        // cheap byte comparison has already failed.
        try {
            $a = $this->cellSets->decode($stored);
            $b = $this->cellSets->decode($fresh);
        } catch (\Throwable $e) {
            $report['unreadableGrids']++;
            $report['failures'][] = sprintf('msgid %d lane %s band %s: stored grid will not decode (%s)', $msgid, $lane, $band, $e->getMessage());
            $detail['result'] = 'unreadable';

            return $detail;
        }

        $onlyStored = count($this->cellSets->subtract($a, $b)['set']);
        $onlyFresh = count($this->cellSets->subtract($b, $a)['set']);
        $diff = $onlyStored + $onlyFresh;
        $detail['cellDifference'] = $diff;
        $detail['onlyStored'] = $onlyStored;
        $detail['onlyFresh'] = $onlyFresh;
        $report['worstCellDifference'] = max($report['worstCellDifference'], $diff);

        if ($diff > $maxDiff) {
            $report['failures'][] = sprintf(
                'msgid %d lane %s band %s: stored grid covers a different area - %d cell(s) differ (%d only stored, %d only in a fresh rasterise)',
                $msgid, $lane, $band, $diff, $onlyStored, $onlyFresh
            );
            $detail['result'] = 'different';

            return $detail;
        }

        $report['sameCoverage']++;
        $detail['result'] = 'same-coverage';

        return $detail;
    }

    private function render(array $r): void
    {
        $this->line(sprintf('Rows compared: %d   rings compared: %d', $r['rows'], $r['rings']));
        $this->newLine();

        $this->line('Stored ring grid against a fresh rasterise of the same WKT');
        $this->line(sprintf('  byte-identical %d   same coverage, different packing %d   worst difference %d cell(s)',
            $r['byteIdentical'], $r['sameCoverage'], $r['worstCellDifference']));
        $this->newLine();

        $this->line('Ring presence (what the drop migration cannot check for itself)');
        $this->line(sprintf('  lost rings %d   invented rings %d   unreadable grids %d   unchecked (rasterise failed) %d',
            $r['lostRings'], $r['inventedRings'], $r['unreadableGrids'], $r['rasteriseFailures']));
        $this->newLine();

        if (empty($r['failures'])) {
            $this->info('No failures: every stored ring grid matches the ring it replaced.');

            return;
        }

        $this->error(sprintf('FAILURES (%d):', count($r['failures'])));
        foreach (array_slice($r['failures'], 0, 40) as $f) {
            $this->line('  - ' . $f);
        }
        if (count($r['failures']) > 40) {
            $this->line(sprintf('  ... and %d more (use --json for all of them)', count($r['failures']) - 40));
        }
    }
}
