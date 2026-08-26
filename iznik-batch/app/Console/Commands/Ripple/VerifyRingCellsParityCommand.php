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
 *      same WKT. Byte equality is reported when it holds (it is the common
 *      case, since both sides go through the same endpoint) but is not
 *      required: what matters is whether the covered area moved, not how it
 *      was packed. When the bytes differ the two are compared by PROBING a
 *      bounded sample of points, never by decoding them - see the note in
 *      checkRing for why decoding these particular grids runs out of memory.
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
                            {--max-disagreements=0 : Probe points that may disagree before it is a failure}
                            {--probe-points=400 : Points sampled per ring when the bytes differ}
                            {--json= : Also write the full per-ring report to this path}';

    protected $description = 'Compare every stored overflow ring grid against a fresh rasterise of the ring WKT it replaced';

    /** Mirrors CellSetService's own private constants - the wire format is fixed. */
    private const CELL_DEGREES = 0.0003;

    private const FORMAT_MAGIC = 0x31534343;

    private const HEADER_SIZE = 20;

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

        $maxDiff = max(0, (int) $this->option('max-disagreements'));
        $probePoints = max(16, (int) $this->option('probe-points'));

        $report = [
            'rows' => 0,
            'rings' => 0,
            'byteIdentical' => 0,
            'sameCoverage' => 0,
            'lostRings' => 0,
            'inventedRings' => 0,
            'unreadableGrids' => 0,
            'rasteriseFailures' => 0,
            'worstDisagreements' => 0,
            'failures' => [],
        ];
        $detail = [];

        foreach ($rows as $row) {
            $report['rows']++;
            $detail[] = $this->checkRow($row, $maxDiff, $probePoints, $report);
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
    private function checkRow(object $row, int $maxDiff, int $probePoints, array &$report): array
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
                $rowDetail['rings'][] = $this->checkRing($msgid, (string) $lane, (string) $band, $wkt, $cells, $maxDiff, $probePoints, $report);
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
        int $probePoints,
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
        // the COVERED AREA moved. That comparison is made by PROBING both
        // blobs, never by decoding them.
        //
        // decode() + subtract() is the obvious way to write this and it cannot
        // be used here. decode() allocates one PHP array entry per COVERED
        // CELL, so the cost follows the area rather than the compressed size,
        // and rings are the largest geometries on the table. A ring spanning
        // 3 x 2 degrees is 10,000 x 6,667 cells - about 67 million entries.
        // Measured: the first version of this command died with "Allowed
        // memory size of 2147483648 bytes exhausted" inside
        // CellSetService::subtract, on a ring smaller than production carries.
        //
        // containsEncoded() walks the run stream for one point and allocates
        // nothing, so a bounded sample of points costs a bounded amount of
        // memory whatever the ring's size.
        $probes = $this->probePoints($stored, $fresh, $probePoints);
        if ($probes === []) {
            $report['unreadableGrids']++;
            $report['failures'][] = sprintf(
                'msgid %d lane %s band %s: neither grid has a readable header, so the areas cannot be compared',
                $msgid, $lane, $band
            );
            $detail['result'] = 'unreadable';

            return $detail;
        }

        $disagree = 0;
        $cannotSay = 0;
        foreach ($probes as [$lng, $lat]) {
            $inStored = $this->cellSets->containsEncoded($stored, $lng, $lat);
            $inFresh = $this->cellSets->containsEncoded($fresh, $lng, $lat);
            if ($inStored === null || $inFresh === null) {
                $cannotSay++;

                continue;
            }
            if ($inStored !== $inFresh) {
                $disagree++;
            }
        }

        $detail['probes'] = count($probes);
        $detail['disagree'] = $disagree;
        $detail['cannotSay'] = $cannotSay;
        $report['worstDisagreements'] = max($report['worstDisagreements'], $disagree);

        // A point neither grid can answer is not agreement. Post-drop the
        // stored bytes are all there is, so "cannot say" is a lane that cannot
        // decide - counted as a failure rather than passed over.
        if ($cannotSay > 0) {
            $report['unreadableGrids']++;
            $report['failures'][] = sprintf(
                'msgid %d lane %s band %s: %d probe(s) could not be answered from the stored bytes',
                $msgid, $lane, $band, $cannotSay
            );
            $detail['result'] = 'unreadable';

            return $detail;
        }

        if ($disagree > $maxDiff) {
            $report['failures'][] = sprintf(
                'msgid %d lane %s band %s: stored grid covers a different area - %d of %d probe(s) disagree with a fresh rasterise',
                $msgid, $lane, $band, $disagree, count($probes)
            );
            $detail['result'] = 'different';

            return $detail;
        }

        $report['sameCoverage']++;
        $detail['result'] = 'same-coverage';

        return $detail;
    }

    /**
     * Points to probe both grids at, spread over the union of their extents.
     *
     * Read from the 20-byte HEADERS only (magic, minCol, minRow, cols, rows),
     * which is the whole reason this is affordable: the header gives the
     * extent without touching the run stream, so nothing here scales with the
     * covered area. The union rather than either grid alone, because a grid
     * that has SHRUNK is only visible by probing where the other one still
     * covers.
     *
     * A deterministic lattice, not random points: this output is quoted as
     * evidence, and a check that samples somewhere different on every run
     * cannot be re-run to confirm a fix.
     *
     * @return array<int,array{0:float,1:float}>
     */
    private function probePoints(string $a, string $b, int $wanted): array
    {
        $ext = [];
        foreach ([$a, $b] as $bytes) {
            if (strlen($bytes) < self::HEADER_SIZE) {
                continue;
            }
            $h = unpack('Vmagic/VminCol/VminRow/Vcols/Vrows', $bytes);
            if ($h === false || $h['magic'] !== self::FORMAT_MAGIC || $h['cols'] === 0 || $h['rows'] === 0) {
                continue;
            }
            // minCol/minRow are signed 32-bit written as unsigned.
            $minCol = $h['minCol'] >= 0x80000000 ? $h['minCol'] - 0x100000000 : $h['minCol'];
            $minRow = $h['minRow'] >= 0x80000000 ? $h['minRow'] - 0x100000000 : $h['minRow'];
            $ext[] = [
                $minCol * self::CELL_DEGREES,
                $minRow * self::CELL_DEGREES,
                ($minCol + $h['cols']) * self::CELL_DEGREES,
                ($minRow + $h['rows']) * self::CELL_DEGREES,
            ];
        }
        if ($ext === []) {
            return [];
        }

        $minLng = min(array_column($ext, 0));
        $minLat = min(array_column($ext, 1));
        $maxLng = max(array_column($ext, 2));
        $maxLat = max(array_column($ext, 3));

        $side = max(4, (int) floor(sqrt($wanted)));
        $points = [];
        for ($i = 0; $i < $side; $i++) {
            for ($j = 0; $j < $side; $j++) {
                // Cell CENTRES ((i + 0.5) / side), so no probe lands exactly on
                // a lattice boundary where the two grids could round apart for
                // reasons that say nothing about whether the areas match.
                $points[] = [
                    $minLng + ($maxLng - $minLng) * (($i + 0.5) / $side),
                    $minLat + ($maxLat - $minLat) * (($j + 0.5) / $side),
                ];
            }
        }

        return $points;
    }

    private function render(array $r): void
    {
        $this->line(sprintf('Rows compared: %d   rings compared: %d', $r['rows'], $r['rings']));
        $this->newLine();

        $this->line('Stored ring grid against a fresh rasterise of the same WKT');
        $this->line(sprintf('  byte-identical %d   same coverage, different packing %d   worst disagreement %d probe(s)',
            $r['byteIdentical'], $r['sameCoverage'], $r['worstDisagreements']));
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
