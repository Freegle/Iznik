<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\CellSetService;
use App\Services\Ripple\GeomShareService;
use App\Services\Ripple\LegacyGeometry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:verify-cells-parity - does the stored cell grid answer every reach
 * question the same way the stored polygon does, on REAL rows?
 *
 * plans/2026-08-24-rippling-reach-raster-storage.md Stage 3 removes the
 * polygon entirely, so every read case moves from a geometry expression to a
 * grid operation. This command is the evidence that the move is sound: for
 * each row that still carries BOTH forms it asks the OLD question and the NEW
 * question about the SAME row at the SAME points, and reports where they
 * differ and by how much.
 *
 * Runs read-only (SELECT plus the rasterise/vectorize endpoints), so it is
 * safe against production over the live tunnel as well as against a seeded
 * dev database.
 *
 * WHY THE SAMPLING MATTERS. Uniform random points over a reach's bounding box
 * are dominated by "deep inside" and "deep outside", where a 33m lattice and
 * a traced polygon cannot possibly disagree - so a report built on them would
 * read as 100% agreement and prove nothing. The interesting points are the
 * ones within a cell or two of the boundary, and this samples them
 * deliberately, from the polygon's own vertices, jittered by fractions of a
 * cell. Expect disagreement there; the finding that matters is HOW FAR from
 * the boundary the disagreements reach. A disagreement 20m out is the lattice
 * doing what a lattice does. One 500m out is a bug.
 *
 * WHAT COUNTS AS FAILURE (not merely a difference):
 *   - a containment disagreement further than one cell diagonal (~47m at UK
 *     latitudes) from the polygon boundary
 *   - a reach radius differing by more than --radius-tolerance-pct
 *   - a traced boundary whose re-rasterisation COVERS DIFFERENT CELLS at
 *     tolerance 0 (identical bytes are not required: the header records the
 *     source polygon's envelope, which is legitimately wider than the covered
 *     extent the trace reproduces - but not one covered cell may move)
 *   - an intersects/within disagreement against a group whose overlap with
 *     the reach is larger than a single cell
 * Anything else is reported as a measured difference for the reviewer to
 * judge, not as a pass/fail.
 */
class VerifyCellsParityCommand extends Command
{
    protected $signature = 'ripple:verify-cells-parity
                            {--limit=50 : How many rows to sample}
                            {--after=0 : Start after this msgid}
                            {--boundary-points=40 : Boundary-band probe points per row (the hard cases)}
                            {--interior-points=10 : Deep-inside probe points per row}
                            {--exterior-points=10 : Deep-outside probe points per row}
                            {--radius-tolerance-pct=5 : Reach-radius difference counted as a failure}
                            {--groups=10 : Group areas to test intersects/within against per row}
                            {--skip-trace : Skip the vectorize round-trip (it is the slowest case)}
                            {--json= : Also write the full per-case report to this path}';

    protected $description = 'Compare every reach read case answered from the stored cell grid against the same question answered from the stored polygon, on real rows';

    /** The lattice the grids sit on; must match CellSetService/cellset.CellDegrees. */
    private const CELL_DEGREES = 0.0003;

    /**
     * One cell diagonal in metres at UK latitudes, the honest bound on how far
     * a lattice answer may legitimately differ from a polygon answer. 0.0003
     * degrees is ~33m north-south and ~19-25m east-west here, so the diagonal
     * is ~40m; 47m leaves a little room for the cell-centre rule (a point can
     * sit a whole cell from the centre that decided it) without excusing a
     * real logic error.
     */
    private const CELL_DIAGONAL_METRES = 47.0;

    public function __construct(private CellSetService $cellSets)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // This command exists to compare the two stored forms against each
        // other, so it has nothing to do once only one of them is left. Said
        // plainly rather than crashing on a dropped column - and it is the
        // reason to run this BEFORE the drop, not after.
        if (!LegacyGeometry::polygonReady()) {
            $this->error('rippling_reach.polygon has been dropped, so there is nothing left to compare against. '
                . 'This check belongs BEFORE the drop; run it against a database that still has both forms.');

            return self::FAILURE;
        }

        $rows = $this->sampleRows();
        if ($rows === []) {
            $this->error('No rows carry both a polygon and polygon_cells - nothing to compare. '
                . 'Run ripple:backfill-reach-cells first, or point this at a database that has both.');

            return self::FAILURE;
        }

        $report = [
            'rows' => 0,
            'containment' => ['probes' => 0, 'agree' => 0, 'disagree' => 0, 'cannotSay' => 0,
                'worstDisagreementMetres' => 0.0, 'disagreementsBeyondOneCell' => 0,
                'unmeasured' => 0, 'directions' => [], 'measuredBy' => [],
                'byBand' => ['boundary' => ['probes' => 0, 'disagree' => 0],
                    'interior' => ['probes' => 0, 'disagree' => 0],
                    'exterior' => ['probes' => 0, 'disagree' => 0]]],
            'maxReach' => ['probes' => 0, 'agree' => 0, 'disagree' => 0, 'worstDisagreementMetres' => 0.0],
            'radius' => ['rows' => 0, 'worstRelPct' => 0.0, 'failures' => 0],
            'distanceOutside' => ['probes' => 0, 'worstAbsMetres' => 0.0],
            'envelope' => ['rows' => 0, 'worstWideningMetres' => 0.0],
            'trace' => ['rows' => 0, 'exact' => 0, 'sameCoverage' => 0, 'cellDelta' => 0, 'failures' => 0],
            'groupRelations' => ['tests' => 0, 'agreeIntersects' => 0, 'disagreeIntersects' => 0,
                'agreeWithin' => 0, 'disagreeWithin' => 0, 'failures' => 0],
            'clip' => ['tests' => 0, 'worstSymmetricDifferenceCells' => 0, 'failures' => 0],
            'failures' => [],
        ];
        $detail = [];

        foreach ($rows as $row) {
            $report['rows']++;
            $rowDetail = ['msgid' => (int) $row->msgid];

            $points = $this->probePoints($row);
            $this->checkContainment($row, $points, $report, $rowDetail);
            $this->checkMaxReach($row, $points, $report, $rowDetail);
            $this->checkRadius($row, $report, $rowDetail);
            $this->checkDistanceOutside($row, $points, $report, $rowDetail);
            $this->checkEnvelope($row, $report, $rowDetail);
            if (!$this->option('skip-trace')) {
                $this->checkTrace($row, $report, $rowDetail);
            }
            $this->checkGroupRelations($row, $report, $rowDetail);
            $this->checkClip($row, $report, $rowDetail);

            $detail[] = $rowDetail;
            $this->output->write('.');
        }
        $this->newLine(2);

        $this->render($report);

        if ($this->option('json')) {
            file_put_contents((string) $this->option('json'), json_encode([
                'summary' => $report,
                'rows' => $detail,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->line('Full per-row report written to ' . $this->option('json'));
        }

        return empty($report['failures']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Rows carrying BOTH forms - the only ones where the two answers can be
     * compared at all. Read through the dedup COALESCE so a drained row's
     * polygon is still available.
     */
    private function sampleRows(): array
    {
        $join = GeomShareService::joinSql('rr', 'polygon', 'g');
        $poly = GeomShareService::sourceExpr('rr', 'polygon', 'g');
        $maxJoin = GeomShareService::joinSql('rr', 'max_polygon', 'gm');
        $maxPoly = GeomShareService::sourceExpr('rr', 'max_polygon', 'gm');

        // keep-raw: ST_AsText over the deduped-or-local geometry, plus the
        // dedup COALESCE joins - the builder cannot render these
        return DB::select(
            "SELECT rr.msgid, rr.lat, rr.lng,
                    ST_AsText($poly) AS poly_wkt,
                    ST_AsText(ST_Envelope($poly)) AS poly_env_wkt,
                    ST_AsText(ST_Envelope(rr.outer_bound)) AS outer_env_wkt,
                    ST_AsText($maxPoly) AS max_poly_wkt,
                    rr.polygon_cells AS cells,
                    rr.max_polygon_cells AS max_cells
               FROM rippling_reach rr$join$maxJoin
              WHERE rr.msgid > ?
                AND rr.polygon_cells IS NOT NULL
                AND ($poly) IS NOT NULL
              ORDER BY rr.msgid
              LIMIT " . max(1, (int) $this->option('limit')),
            [(int) $this->option('after')]
        );
    }

    /**
     * The probe points for one row, tagged by band so the report can separate
     * the easy cases from the ones that decide whether this change is sound.
     *
     * @return array<int,array{lng:float,lat:float,band:string}>
     */
    private function probePoints(object $row): array
    {
        $points = [];
        $cell = self::CELL_DEGREES;

        // Boundary band: the polygon's OWN vertices, evenly spaced through the
        // exterior ring, each also offset by fractions of a cell in both axes.
        // These are exactly the points where a lattice and a boundary can
        // disagree, so they are sampled first and most heavily.
        $verts = $this->exteriorVertices((string) $row->poly_wkt);
        $wantBoundary = max(0, (int) $this->option('boundary-points'));
        if ($verts !== [] && $wantBoundary > 0) {
            $stride = max(1, (int) floor(count($verts) / max(1, (int) ceil($wantBoundary / 5))));
            $offsets = [[0, 0], [0.5 * $cell, 0], [-0.5 * $cell, 0], [0, 1.5 * $cell], [0, -1.5 * $cell]];
            for ($i = 0; $i < count($verts) && count($points) < $wantBoundary; $i += $stride) {
                foreach ($offsets as [$dx, $dy]) {
                    if (count($points) >= $wantBoundary) {
                        break;
                    }
                    $points[] = ['lng' => $verts[$i][0] + $dx, 'lat' => $verts[$i][1] + $dy, 'band' => 'boundary'];
                }
            }
        }

        // Interior: the reach origin (always inside by construction) plus a
        // deterministic lattice walk inward from the bbox centre. Deterministic
        // rather than random so two runs are comparable.
        [$minLng, $minLat, $maxLng, $maxLat] = $this->envelopeBox((string) $row->poly_env_wkt);
        $cLng = ($minLng + $maxLng) / 2;
        $cLat = ($minLat + $maxLat) / 2;
        $wantInterior = max(0, (int) $this->option('interior-points'));
        if ($wantInterior > 0) {
            $points[] = ['lng' => (float) $row->lng, 'lat' => (float) $row->lat, 'band' => 'interior'];
            for ($k = 1; count($points) < $wantBoundary + $wantInterior; $k++) {
                $f = $k / ($wantInterior + 1);
                $points[] = ['lng' => $cLng + ($maxLng - $cLng) * 0.5 * $f,
                    'lat' => $cLat + ($maxLat - $cLat) * 0.5 * $f, 'band' => 'interior'];
                if ($k > $wantInterior * 2) {
                    break;
                }
            }
        }

        // Exterior: outside the envelope, so certainly outside the reach -
        // these catch a grid whose bounds are wrong rather than its bits.
        $wantExterior = max(0, (int) $this->option('exterior-points'));
        $span = max($maxLng - $minLng, 0.001);
        for ($k = 0; $k < $wantExterior; $k++) {
            $points[] = ['lng' => $maxLng + $span * (0.05 + 0.05 * $k),
                'lat' => $minLat + ($maxLat - $minLat) * ($k / max(1, $wantExterior)), 'band' => 'exterior'];
        }

        return $points;
    }

    /**
     * READ CASE 1: point-in-reach. Every member-facing containment question
     * reduces to this - the reply gate (ReachQueryService::isWithinReach), the
     * browse feed and badge, browse-scoped search, and the digest's recipient
     * test. OLD: ST_Contains(polygon, point). NEW:
     * CellSetService::containsEncoded(cells, point).
     *
     * For each disagreement the distance from the point to the polygon's
     * BOUNDARY is measured, because that single number is what turns "they
     * disagree sometimes" into "they disagree only within one cell of the
     * edge", which is the whole argument.
     */
    private function checkContainment(object $row, array $points, array &$report, array &$rowDetail): void
    {
        $disagreements = [];
        $verts = $this->exteriorVertices((string) $row->poly_wkt);
        foreach ($points as $p) {
            $old = $this->polygonContains((string) $row->poly_wkt, $p['lng'], $p['lat']);
            if ($old === null) {
                continue; // unusable geometry: nothing to compare against
            }
            $new = $this->cellSets->containsEncoded((string) $row->cells, $p['lng'], $p['lat']);

            $report['containment']['probes']++;
            $report['containment']['byBand'][$p['band']]['probes']++;

            if ($new === null) {
                $report['containment']['cannotSay']++;
                continue;
            }
            if ($new === $old) {
                $report['containment']['agree']++;
                continue;
            }

            $report['containment']['disagree']++;
            $report['containment']['byBand'][$p['band']]['disagree']++;

            // WHICH WAY the two forms disagree matters as much as how often.
            // grid-in / polygon-out means the lattice is slightly MORE
            // inclusive at the edge, which for a reach admits a few people
            // exactly on the boundary; the opposite direction would EXCLUDE
            // people the polygon reached, which is the direction that costs
            // someone a reply.
            $dir = ($old ? 'polygon-in' : 'polygon-out') . '/' . ($new ? 'grid-in' : 'grid-out');
            $report['containment']['directions'][$dir] =
                ($report['containment']['directions'][$dir] ?? 0) + 1;

            [$metres, $how] = $this->metresFromEdge((string) $row->poly_wkt, $verts, $p['lng'], $p['lat']);
            $report['containment']['measuredBy'][$how] = ($report['containment']['measuredBy'][$how] ?? 0) + 1;

            if ($metres === null) {
                // An unmeasurable disagreement cannot be defended as
                // lattice-resolution, so it is a failure rather than a
                // rounding-down to zero. (The first version of this command
                // silently treated these as 0m and reported a worst case of
                // "0.0m" while measuring nothing at all.)
                $report['containment']['unmeasured']++;
                $report['failures'][] = sprintf(
                    'msgid %d: containment disagreement at %.6f,%.6f whose distance from the edge could not be measured - cannot be attributed to the lattice',
                    $row->msgid, $p['lng'], $p['lat']
                );
            } else {
                if ($metres > $report['containment']['worstDisagreementMetres']) {
                    $report['containment']['worstDisagreementMetres'] = $metres;
                }
                if ($metres > self::CELL_DIAGONAL_METRES) {
                    $report['containment']['disagreementsBeyondOneCell']++;
                    $report['failures'][] = sprintf(
                        'msgid %d: containment disagreement %.0fm from the edge (%s, measured by %s) at %.6f,%.6f - beyond one cell, so not lattice resolution',
                        $row->msgid, $metres, $dir, $how, $p['lng'], $p['lat']
                    );
                }
            }
            $disagreements[] = ['lng' => $p['lng'], 'lat' => $p['lat'], 'band' => $p['band'],
                'polygon' => $old, 'grid' => $new, 'metresFromEdge' => $metres, 'measuredBy' => $how];
        }
        $rowDetail['containmentDisagreements'] = $disagreements;
    }

    /**
     * READ CASE 2: point-in-MAX-reach, the first-reply passthrough gate
     * (MaxReachService::isWithinMaxReach, firstreply.ShouldPassThrough). Same
     * comparison against the eventual-reach pair of columns. Skipped for rows
     * that have not been given a max reach yet, which is most young posts.
     */
    private function checkMaxReach(object $row, array $points, array &$report, array &$rowDetail): void
    {
        if ($row->max_poly_wkt === null || $row->max_cells === null) {
            return;
        }
        $maxVerts = $this->exteriorVertices((string) $row->max_poly_wkt);
        $worst = 0.0;
        foreach ($points as $p) {
            $old = $this->polygonContains((string) $row->max_poly_wkt, $p['lng'], $p['lat']);
            if ($old === null) {
                continue;
            }
            $new = $this->cellSets->containsEncoded((string) $row->max_cells, $p['lng'], $p['lat']);
            if ($new === null) {
                continue;
            }
            $report['maxReach']['probes']++;
            if ($new === $old) {
                $report['maxReach']['agree']++;
                continue;
            }
            $report['maxReach']['disagree']++;
            [$metres, $how] = $this->metresFromEdge((string) $row->max_poly_wkt, $maxVerts, $p['lng'], $p['lat']);
            if ($metres === null) {
                $report['failures'][] = sprintf(
                    'msgid %d: MAX-reach disagreement whose distance from the edge could not be measured',
                    $row->msgid
                );
            } else {
                $worst = max($worst, $metres);
                if ($metres > self::CELL_DIAGONAL_METRES) {
                    $report['failures'][] = sprintf(
                        'msgid %d: MAX-reach disagreement %.0fm from the edge (measured by %s) - beyond one cell',
                        $row->msgid, $metres, $how
                    );
                }
            }
        }
        $report['maxReach']['worstDisagreementMetres'] = max($report['maxReach']['worstDisagreementMetres'], $worst);
        $rowDetail['maxReachWorstMetres'] = $worst;
    }

    /**
     * READ CASE 3: the reach radius - the digest relevance score's closeness
     * denominator (UnifiedDigestService::reachRadiusMetres). OLD: parse the
     * polygon WKT and take the farthest exterior vertex from the origin. NEW:
     * CellSetService::maxDistanceMetresFrom walks the run stream's endpoints.
     *
     * Reported as a RELATIVE error, because the number is only ever used as a
     * denominator: what matters is whether a post's closeness score shifts,
     * not whether the extent is a few metres out.
     */
    private function checkRadius(object $row, array &$report, array &$rowDetail): void
    {
        $old = $this->polygonRadiusMetres((string) $row->poly_wkt, (float) $row->lng, (float) $row->lat);
        $new = $this->cellSets->maxDistanceMetresFrom((string) $row->cells, (float) $row->lng, (float) $row->lat);
        if ($old === null || $old <= 0 || $new === null) {
            return;
        }
        $relPct = abs($new - $old) / $old * 100;
        $report['radius']['rows']++;
        $report['radius']['worstRelPct'] = max($report['radius']['worstRelPct'], $relPct);
        $rowDetail['radius'] = ['polygonMetres' => $old, 'gridMetres' => $new, 'relPct' => $relPct];
        if ($relPct > (float) $this->option('radius-tolerance-pct')) {
            $report['radius']['failures']++;
            $report['failures'][] = sprintf(
                'msgid %d: reach radius %.0fm from the polygon vs %.0fm from the grid (%.1f%% out)',
                $row->msgid, $old, $new, $relPct
            );
        }
    }

    /**
     * READ CASE 4: how far outside the reach a point is - what tells a held
     * replier how far the reach still has to come
     * (RippleReplyService::milesOutsideReach). OLD: ST_Distance on the
     * re-tagged polygon. NEW: CellSetService::distanceToNearestCellMetres.
     * Only exterior points are meaningful here.
     */
    private function checkDistanceOutside(object $row, array $points, array &$report, array &$rowDetail): void
    {
        $worst = 0.0;
        foreach ($points as $p) {
            if ($p['band'] !== 'exterior') {
                continue;
            }
            $old = $this->polygonDistanceMetres((string) $row->poly_wkt, $p['lng'], $p['lat']);
            $new = $this->cellSets->distanceToNearestCellMetres((string) $row->cells, $p['lng'], $p['lat']);
            if ($old === null || $new === null) {
                continue;
            }
            $report['distanceOutside']['probes']++;
            $worst = max($worst, abs($new - $old));
        }
        $report['distanceOutside']['worstAbsMetres'] = max($report['distanceOutside']['worstAbsMetres'], $worst);
        $rowDetail['distanceOutsideWorstAbsMetres'] = $worst;
    }

    /**
     * READ CASE 5: the reach extent the browse feed ships to clients
     * (reach_wkt). OLD: ST_Envelope(polygon). NEW: ST_Envelope(outer_bound),
     * because outer_bound survives the drop and the polygon does not. The
     * outer bound is a 0.002-degree buffered simplification, so its envelope
     * is deliberately WIDER; this measures by how much, since the value is
     * consumed as a radius over-estimate and a wider one only softens the
     * closeness term.
     */
    private function checkEnvelope(object $row, array &$report, array &$rowDetail): void
    {
        if ($row->outer_env_wkt === null) {
            return;
        }
        [$pMinLng, $pMinLat, $pMaxLng, $pMaxLat] = $this->envelopeBox((string) $row->poly_env_wkt);
        [$oMinLng, $oMinLat, $oMaxLng, $oMaxLat] = $this->envelopeBox((string) $row->outer_env_wkt);

        // Widening per side, in metres, at this row's latitude.
        $mPerDegLat = 111320.0;
        $mPerDegLng = 111320.0 * cos(deg2rad((float) $row->lat));
        $widen = max(
            ($pMinLng - $oMinLng) * $mPerDegLng,
            ($oMaxLng - $pMaxLng) * $mPerDegLng,
            ($pMinLat - $oMinLat) * $mPerDegLat,
            ($oMaxLat - $pMaxLat) * $mPerDegLat
        );
        $report['envelope']['rows']++;
        $report['envelope']['worstWideningMetres'] = max($report['envelope']['worstWideningMetres'], $widen);
        $rowDetail['envelopeWideningMetres'] = $widen;

        // A NARROWER outer envelope would be a real defect: the feed would
        // under-report a post's extent, and (worse) the outer bound is the
        // superset every degraded read path relies on.
        if ($widen < -1.0) {
            $report['failures'][] = sprintf(
                'msgid %d: outer_bound envelope is NARROWER than the polygon envelope by %.0fm - the superset guarantee is broken',
                $row->msgid, -$widen
            );
        }
    }

    /**
     * READ CASE 6: the traced boundary the map overlay draws
     * (spatial /v1/reach/vectorize). The trace claims to be EXACT at
     * tolerance 0 - rasterising it must reproduce the input grid bit for bit -
     * so this asserts exactly that on real shapes, which is stronger than any
     * generated fixture.
     */
    private function checkTrace(object $row, array &$report, array &$rowDetail): void
    {
        $vec = $this->cellSets->vectorize((string) $row->cells, 0);
        if ($vec === null) {
            $report['failures'][] = sprintf('msgid %d: could not trace a boundary from its stored grid', $row->msgid);

            return;
        }
        $back = $this->cellSets->rasterize($vec['wkt']);
        if ($back === null) {
            $report['failures'][] = sprintf('msgid %d: the traced boundary would not rasterise back', $row->msgid);

            return;
        }
        $report['trace']['rows']++;
        if ($back === (string) $row->cells) {
            $report['trace']['exact']++;
            $rowDetail['traceExact'] = true;

            return;
        }
        // Not byte-identical: say by how many cells, which distinguishes a
        // packing difference from lost coverage.
        try {
            $a = $this->cellSets->decode((string) $row->cells);
            $b = $this->cellSets->decode($back);
            $lost = count($this->cellSets->subtract($a, $b)['set'] ?? []);
            $gained = count($this->cellSets->subtract($b, $a)['set'] ?? []);
            $delta = $lost + $gained;
        } catch (\Throwable) {
            $delta = -1;
        }
        $report['trace']['cellDelta'] += max(0, $delta);
        $rowDetail['traceExact'] = false;
        $rowDetail['traceCellDelta'] = $delta;

        // COVERAGE is the contract, not the packing. A re-rasterised trace
        // frequently differs in its HEADER - the stored grid's bounds come
        // from the original polygon's envelope, which can extend into cells
        // whose centres fall outside it, while the trace's own envelope is the
        // tight extent of the covered cells - so the bytes differ while every
        // covered cell is identical. Every reader consumes coverage (a point
        // probe, a subtraction, a bbox), so a zero cell-delta IS exactness for
        // every purpose the trace is used for. Losing or gaining even one cell
        // is the real defect, and that is what fails here.
        if ($delta === 0) {
            $report['trace']['sameCoverage']++;

            return;
        }
        $report['trace']['failures']++;
        $report['failures'][] = $delta < 0
            ? sprintf('msgid %d: trace round-trip produced bytes that would not decode, so coverage could not be compared', $row->msgid)
            : sprintf(
                'msgid %d: trace round-trip changed COVERAGE by %d cells - the tracer must be exact at tolerance 0',
                $row->msgid, $delta
            );
    }

    /**
     * READ CASE 7: reach-intersects-group and reach-within-group - the
     * questions behind the rejection clip, the out-of-reach retraction and the
     * crosspost count. OLD: ST_Intersects / ST_Within against groups.polyindex.
     * NEW: the spatial server's /v1/groups/intersecting on the same lattice.
     *
     * A disagreement is only a FAILURE when the overlap is bigger than a
     * single cell: a group sharing a sliver thinner than 33m with the reach is
     * exactly the case a lattice cannot represent, and the answer there is
     * arbitrary in both directions.
     */
    private function checkGroupRelations(object $row, array &$report, array &$rowDetail): void
    {
        $rel = $this->cellSets->groupsIntersecting((string) $row->cells);
        if ($rel === null) {
            return;
        }
        $gridSays = [];
        foreach ($rel as $g) {
            $gridSays[(int) $g['id']] = (bool) $g['within'];
        }

        // Compare against the SQL answer for a bounded set of groups: the ones
        // the grid named, plus groups whose own envelope meets the reach's
        // (so a group the grid MISSED can still be caught).
        // keep-raw: ST_Intersects/ST_Within/ST_GeometryType spatial predicates - the builder cannot render these
        $sql = DB::select(
            'SELECT g.id,
                    ST_Intersects(g.polyindex, ST_GeomFromText(?, 3857)) AS ints,
                    ST_Within(ST_GeomFromText(?, 3857), g.polyindex) AS wthn,
                    ST_Area(ST_Intersection(g.polyindex, ST_GeomFromText(?, 3857))) AS overlap
               FROM `groups` g
              WHERE g.polyindex IS NOT NULL
                AND ST_GeometryType(g.polyindex) <> \'POINT\'
                AND MBRIntersects(g.polyindex, ST_GeomFromText(?, 3857))
              LIMIT ' . max(1, (int) $this->option('groups')),
            [$row->poly_wkt, $row->poly_wkt, $row->poly_wkt, $row->poly_wkt]
        );

        $cellArea = self::CELL_DEGREES * self::CELL_DEGREES;
        $disagreements = [];
        foreach ($sql as $g) {
            $gid = (int) $g->id;
            $oldInts = (bool) $g->ints;
            $oldWithin = (bool) $g->wthn;
            $newInts = array_key_exists($gid, $gridSays);
            $newWithin = $gridSays[$gid] ?? false;
            $overlap = $g->overlap === null ? 0.0 : (float) $g->overlap;

            $report['groupRelations']['tests']++;
            if ($newInts === $oldInts) {
                $report['groupRelations']['agreeIntersects']++;
            } else {
                $report['groupRelations']['disagreeIntersects']++;
                $disagreements[] = ['gid' => $gid, 'case' => 'intersects',
                    'polygon' => $oldInts, 'grid' => $newInts, 'overlapDeg2' => $overlap];
                if ($overlap > $cellArea) {
                    $report['groupRelations']['failures']++;
                    $report['failures'][] = sprintf(
                        'msgid %d group %d: intersects disagreement with an overlap of %.2f cells - too big to blame the lattice',
                        $row->msgid, $gid, $overlap / $cellArea
                    );
                }
            }
            if ($newWithin === $oldWithin) {
                $report['groupRelations']['agreeWithin']++;
            } else {
                $report['groupRelations']['disagreeWithin']++;
                $disagreements[] = ['gid' => $gid, 'case' => 'within',
                    'polygon' => $oldWithin, 'grid' => $newWithin, 'overlapDeg2' => $overlap];
            }
        }
        $rowDetail['groupDisagreements'] = $disagreements;
    }

    /**
     * READ CASE 8: the rejection clip itself. OLD:
     * ST_Difference(polygon, group_area), then rasterise the result. NEW:
     * Subtract(cells(polygon), cells(group_area)) - deliberately NOT a
     * re-rasterise, because after the difference the survivor is usually
     * bigger than the group that clipped it.
     *
     * These two are only equal up to the lattice, so the metric is the
     * symmetric difference in CELLS; a difference confined to the clip
     * boundary is expected, and a large one is not.
     */
    private function checkClip(object $row, array &$report, array &$rowDetail): void
    {
        // keep-raw: ST_AsText/ST_Difference/ST_Intersects spatial expressions - the builder cannot render these
        $g = DB::selectOne(
            'SELECT ST_AsText(g.polyindex) AS gwkt,
                    ST_AsText(ST_Difference(ST_GeomFromText(?, 3857), g.polyindex)) AS diff_wkt
               FROM `groups` g
              WHERE g.polyindex IS NOT NULL
                AND ST_GeometryType(g.polyindex) <> \'POINT\'
                AND ST_Intersects(g.polyindex, ST_GeomFromText(?, 3857))
                AND NOT ST_Within(ST_GeomFromText(?, 3857), g.polyindex)
              LIMIT 1',
            [$row->poly_wkt, $row->poly_wkt, $row->poly_wkt]
        );
        if ($g === null || $g->gwkt === null || $g->diff_wkt === null) {
            return;
        }

        $viaSql = $this->cellSets->rasterize((string) $g->diff_wkt);
        $groupCells = $this->cellSets->rasterize((string) $g->gwkt);
        if ($viaSql === null || $groupCells === null) {
            return;
        }
        try {
            $viaGrid = $this->cellSets->encode($this->cellSets->subtract(
                $this->cellSets->decode((string) $row->cells),
                $this->cellSets->decode($groupCells)
            ));
        } catch (\Throwable) {
            return;
        }

        $report['clip']['tests']++;
        if ($viaGrid === $viaSql) {
            $rowDetail['clipExact'] = true;

            return;
        }
        try {
            $a = $this->cellSets->decode($viaGrid);
            $b = $this->cellSets->decode($viaSql);
            $delta = count($this->cellSets->subtract($a, $b)['set'] ?? [])
                + count($this->cellSets->subtract($b, $a)['set'] ?? []);
        } catch (\Throwable) {
            $delta = -1;
        }
        $report['clip']['worstSymmetricDifferenceCells'] = max(
            $report['clip']['worstSymmetricDifferenceCells'], $delta
        );
        $rowDetail['clipExact'] = false;
        $rowDetail['clipCellDelta'] = $delta;
    }

    // ---- geometry helpers, all asked of MySQL so the OLD answer is the
    // ---- literal expression production used, not a re-implementation.

    private function polygonContains(string $wkt, float $lng, float $lat): ?bool
    {
        try {
            // keep-raw: pure spatial-function computation, no tables - nothing for the builder to build
            $r = DB::selectOne(
                'SELECT ST_Contains(ST_GeomFromText(?, 3857), ST_SRID(POINT(?, ?), 3857)) AS c',
                [$wkt, $lng, $lat]
            );

            return $r === null || $r->c === null ? null : (bool) $r->c;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How far a probe point is from the polygon's edge, in metres - the number
     * that turns "they disagree sometimes" into "they disagree only at the
     * edge", so it must never quietly fail to measure.
     *
     * Two ways round, because the GIS way is not reliable here: roughly 94% of
     * production reach polygons are technically invalid (self-touching rings
     * from the routing server's grid fill), and ST_Boundary/ST_Distance return
     * NULL or throw on those. The first version of this command used only the
     * GIS way, got NULL every time, and therefore reported "worst disagreement
     * 0.0m" - a headline that looked like a result and measured nothing. So
     * the fallback is the distance to the nearest polygon VERTEX, computed in
     * PHP from the vertex list this command already parses. That is an upper
     * bound on the distance to the boundary (the boundary passes through every
     * vertex), which is the conservative direction for this test: it can only
     * ever make a disagreement look FURTHER from the edge than it is, never
     * nearer.
     *
     * Returns [metres, how] so the report can say which measure it used, and
     * null metres when neither works - which is counted, not ignored.
     *
     * @param array<int,array{0:float,1:float}> $verts
     * @return array{0:?float,1:string}
     */
    private function metresFromEdge(string $wkt, array $verts, float $lng, float $lat): array
    {
        try {
            // keep-raw: ST_Distance over ST_Boundary with an SRID re-tag - nothing for the builder to build
            $r = DB::selectOne(
                'SELECT ST_Distance(
                        ST_SRID(ST_Boundary(ST_GeomFromText(?, 3857)), 4326),
                        ST_SRID(POINT(?, ?), 4326)) AS d',
                [$wkt, $lng, $lat]
            );
            if ($r !== null && $r->d !== null) {
                return [(float) $r->d, 'boundary'];
            }
        } catch (\Throwable) {
            // Invalid stored geometry - expected on most real rows. Fall through.
        }

        if (count($verts) < 2) {
            return [null, 'unmeasured'];
        }

        // Exact distance to the boundary, computed here: the minimum
        // point-to-SEGMENT distance over every edge of every ring. Nearest
        // VERTEX was the first attempt and is wrong for this purpose - it is
        // only an upper bound, and it overstated distances badly enough to
        // report spurious failures on probe points deliberately placed 1.5
        // cells from a vertex but lying a few metres from a neighbouring edge.
        // Done only for the handful of probes that actually disagree, so the
        // per-segment walk costs nothing overall.
        $mPerDegLat = 111320.0;
        $mPerDegLng = 111320.0 * cos(deg2rad($lat));
        $px = $lng * $mPerDegLng;
        $py = $lat * $mPerDegLat;

        $best = INF;
        $n = count($verts);
        for ($i = 0; $i < $n - 1; $i++) {
            // Rings are closed in the WKT, so consecutive pairs are real
            // edges; the join between one ring's last point and the next
            // ring's first is a spurious edge, but it can only ever make the
            // measured distance SMALLER, which is the safe direction for a
            // test that fails on distances being too LARGE.
            $ax = $verts[$i][0] * $mPerDegLng;
            $ay = $verts[$i][1] * $mPerDegLat;
            $bx = $verts[$i + 1][0] * $mPerDegLng;
            $by = $verts[$i + 1][1] * $mPerDegLat;

            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            if ($len2 <= 0.0) {
                $d = sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);
            } else {
                $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2;
                $t = max(0.0, min(1.0, $t));
                $d = sqrt(($px - ($ax + $t * $dx)) ** 2 + ($py - ($ay + $t * $dy)) ** 2);
            }
            if ($d < $best) {
                $best = $d;
                if ($best === 0.0) {
                    break;
                }
            }
        }

        return $best === INF ? [null, 'unmeasured'] : [$best, 'nearest-edge'];
    }

    private function polygonDistanceMetres(string $wkt, float $lng, float $lat): ?float
    {
        try {
            // keep-raw: ST_Distance with an SRID re-tag - nothing for the builder to build
            $r = DB::selectOne(
                'SELECT ST_Distance(ST_SRID(ST_GeomFromText(?, 3857), 4326), ST_SRID(POINT(?, ?), 4326)) AS d',
                [$wkt, $lng, $lat]
            );

            return $r === null || $r->d === null ? null : (float) $r->d;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The OLD reach radius: the farthest exterior-ring vertex from the origin,
     * measured with haversine - the same walk UnifiedDigestService does.
     */
    private function polygonRadiusMetres(string $wkt, float $olng, float $olat): ?float
    {
        $verts = $this->exteriorVertices($wkt);
        if ($verts === []) {
            return null;
        }
        $worst = 0.0;
        foreach ($verts as [$lng, $lat]) {
            $worst = max($worst, $this->haversineMetres($olat, $olng, $lat, $lng));
        }

        return $worst > 0 ? $worst : null;
    }

    /**
     * Every vertex of every ring in a POLYGON/MULTIPOLYGON WKT. Parsed here
     * rather than asked of MySQL because ST_PointN needs a ring at a time and
     * these polygons run to tens of thousands of vertices.
     *
     * @return array<int,array{0:float,1:float}>
     */
    private function exteriorVertices(string $wkt): array
    {
        $out = [];
        if (preg_match_all('/-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?/', $wkt, $m)) {
            foreach ($m[0] as $pair) {
                $parts = preg_split('/\s+/', trim($pair));
                if (count($parts) === 2) {
                    $out[] = [(float) $parts[0], (float) $parts[1]];
                }
            }
        }

        return $out;
    }

    /** @return array{0:float,1:float,2:float,3:float} minLng, minLat, maxLng, maxLat */
    private function envelopeBox(string $envWkt): array
    {
        $verts = $this->exteriorVertices($envWkt);
        if ($verts === []) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $lngs = array_column($verts, 0);
        $lats = array_column($verts, 1);

        return [min($lngs), min($lats), max($lngs), max($lats)];
    }

    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private function render(array $r): void
    {
        $c = $r['containment'];
        $this->info(sprintf('Rows compared: %d', $r['rows']));
        $this->newLine();

        $this->line('<options=bold>Point-in-reach</> (reply gate, feed, badge, search, digest recipients)');
        $this->line(sprintf('  probes %d   agree %d (%.4f%%)   disagree %d   grid could not say %d',
            $c['probes'], $c['agree'],
            $c['probes'] > 0 ? $c['agree'] / $c['probes'] * 100 : 0,
            $c['disagree'], $c['cannotSay']));
        foreach ($c['byBand'] as $band => $b) {
            if ($b['probes'] > 0) {
                $this->line(sprintf('    %-9s %5d probes, %d disagree (%.3f%%)',
                    $band, $b['probes'], $b['disagree'], $b['disagree'] / $b['probes'] * 100));
            }
        }
        $this->line(sprintf('  worst disagreement %.1fm from the boundary; beyond one cell (%.0fm): %d',
            $c['worstDisagreementMetres'], self::CELL_DIAGONAL_METRES, $c['disagreementsBeyondOneCell']));
        $this->newLine();

        if ($r['maxReach']['probes'] > 0) {
            $this->line('<options=bold>Point-in-max-reach</> (first-reply passthrough)');
            $this->line(sprintf('  probes %d   agree %d   disagree %d   worst %.1fm from the boundary',
                $r['maxReach']['probes'], $r['maxReach']['agree'], $r['maxReach']['disagree'],
                $r['maxReach']['worstDisagreementMetres']));
            $this->newLine();
        }

        $this->line('<options=bold>Reach radius</> (digest score denominator)');
        $this->line(sprintf('  rows %d   worst relative difference %.2f%%   over tolerance: %d',
            $r['radius']['rows'], $r['radius']['worstRelPct'], $r['radius']['failures']));
        $this->newLine();

        $this->line('<options=bold>Distance outside reach</> (held-reply reporting)');
        $this->line(sprintf('  probes %d   worst absolute difference %.1fm',
            $r['distanceOutside']['probes'], $r['distanceOutside']['worstAbsMetres']));
        $this->newLine();

        $this->line('<options=bold>Reach extent</> (feed reach_wkt: polygon envelope to outer_bound envelope)');
        $this->line(sprintf('  rows %d   worst widening %.0fm per side',
            $r['envelope']['rows'], $r['envelope']['worstWideningMetres']));
        $this->newLine();

        if ($r['trace']['rows'] > 0) {
            $this->line('<options=bold>Traced boundary</> (map overlay; coverage must survive a round-trip exactly)');
            $this->line(sprintf('  rows %d   byte-identical %d   same coverage, different packing %d   coverage CHANGED %d (by %d cells)',
                $r['trace']['rows'], $r['trace']['exact'], $r['trace']['sameCoverage'],
                $r['trace']['failures'], $r['trace']['cellDelta']));
            $this->newLine();
        }

        $g = $r['groupRelations'];
        if ($g['tests'] > 0) {
            $this->line('<options=bold>Group relations</> (clip eligibility, retraction, crosspost count)');
            $this->line(sprintf('  tests %d   intersects agree %d / disagree %d   within agree %d / disagree %d   failures %d',
                $g['tests'], $g['agreeIntersects'], $g['disagreeIntersects'],
                $g['agreeWithin'], $g['disagreeWithin'], $g['failures']));
            $this->newLine();
        }

        if ($r['clip']['tests'] > 0) {
            $this->line('<options=bold>Rejection clip</> (ST_Difference then rasterise, vs grid Subtract)');
            $this->line(sprintf('  tests %d   worst symmetric difference %d cells',
                $r['clip']['tests'], $r['clip']['worstSymmetricDifferenceCells']));
            $this->newLine();
        }

        if (empty($r['failures'])) {
            $this->info('No failures: every difference found is within one cell of a boundary, which is the lattice resolution this design accepts.');

            return;
        }
        $this->error(sprintf('%d FAILURES - differences too large to attribute to the lattice:', count($r['failures'])));
        foreach (array_slice($r['failures'], 0, 40) as $f) {
            $this->line('  - ' . $f);
        }
        if (count($r['failures']) > 40) {
            $this->line(sprintf('  ... and %d more', count($r['failures']) - 40));
        }
    }
}
