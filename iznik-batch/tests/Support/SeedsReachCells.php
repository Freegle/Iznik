<?php

namespace Tests\Support;

use App\Services\Ripple\CellSetService;

/**
 * Cell-grid fixtures for rippling_reach rows. The grid is the only stored
 * form of a reach, so a test that used to plant polygon WKT plants the same
 * shape's cells instead - built by the REAL rasteriser (the spatial server in
 * the test stack), so these are the bytes production would hold.
 */
trait SeedsReachCells
{
    private ?CellSetService $seedsReachCellSets = null;

    private function reachCellService(): CellSetService
    {
        return $this->seedsReachCellSets ??= new CellSetService();
    }

    /**
     * The encoded cell grid for an axis-aligned rectangle WKT - the shape
     * every fixture here plants. Built locally with CellSetService::encode
     * (proven byte-identical to the real Go encoder in CellSetServiceTest),
     * so it works under any Http::fake and costs no network call. A cell is
     * set when its centre lies inside the rectangle, which is the rasteriser's
     * own rule for a shape with no boundary curvature.
     */
    protected function reachCellsFor(string $wkt): string
    {
        if (!preg_match_all('/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/', $wkt, $m, PREG_SET_ORDER)) {
            $this->fail("reachCellsFor needs a rectangle WKT, got: {$wkt}");
        }
        $xs = array_map(static fn ($p) => (float) $p[1], $m);
        $ys = array_map(static fn ($p) => (float) $p[2], $m);
        [$minLng, $maxLng] = [min($xs), max($xs)];
        [$minLat, $maxLat] = [min($ys), max($ys)];

        $cell = 0.0003;
        $minCol = (int) floor($minLng / $cell);
        $minRow = (int) floor($minLat / $cell);
        $cols = max(1, (int) ceil($maxLng / $cell) - $minCol);
        $rows = max(1, (int) ceil($maxLat / $cell) - $minRow);

        $set = [];
        for ($r = 0; $r < $rows; $r++) {
            $latC = ($minRow + $r + 0.5) * $cell;
            if ($latC <= $minLat || $latC >= $maxLat) {
                continue;
            }
            for ($c = 0; $c < $cols; $c++) {
                $lngC = ($minCol + $c + 0.5) * $cell;
                if ($lngC > $minLng && $lngC < $maxLng) {
                    $set[$r * $cols + $c] = true;
                }
            }
        }

        return $this->reachCellService()->encode([
            'minCol' => $minCol,
            'minRow' => $minRow,
            'cols' => $cols,
            'rows' => $rows,
            'set' => $set,
        ]);
    }

    /**
     * An Http fake that lets the spatial server's rasterise/vectorise calls
     * through to the REAL service (the one canonical encoder), answers
     * /v1/groups/intersecting from the TEST database's own groups (the real
     * index is built from a different database, so it cannot see test
     * fixtures), dispatches any extra pattern stubs, and stubs everything
     * else with an empty 200. Replaces a bare Http::fake() in tests of write
     * paths that now rasterise as part of every store.
     *
     * The intersecting answer compares the grid's bounding box against each
     * group's polyindex - exact for the rectangles test fixtures plant.
     *
     * @param array<string, mixed> $extraStubs pattern => Http::response or callable
     */
    protected function fakeSpatialHttp(array $extraStubs = []): void
    {
        \Illuminate\Support\Facades\Http::fake(function ($request) use ($extraStubs) {
            $url = (string) $request->url();
            if (str_contains($url, '/v1/reach/rasterize') || str_contains($url, '/v1/reach/vectorize')) {
                return null;
            }
            if (str_contains($url, '/v1/groups/intersecting')) {
                $bbox = $this->reachCellService()->boundsWkt((string) $request->body());
                if ($bbox === null) {
                    return \Illuminate\Support\Facades\Http::response(['groups' => []], 200);
                }
                $groups = [];
                // keep-raw equivalent lives in tests: MBR test against every fixture group.
                $rows = \Illuminate\Support\Facades\DB::select(
                    'SELECT id,
                            MBRWithin(polyindex, ST_GeomFromText(?, 3857)) AS w
                       FROM `groups`
                      WHERE polyindex IS NOT NULL
                        AND ST_GeometryType(polyindex) <> \'POINT\'
                        AND publish = 1
                        AND MBRIntersects(polyindex, ST_GeomFromText(?, 3857))',
                    [$bbox, $bbox]
                );
                foreach ($rows as $row) {
                    $groups[] = ['id' => (int) $row->id, 'within' => (bool) $row->w];
                }

                return \Illuminate\Support\Facades\Http::response(['groups' => $groups], 200);
            }
            foreach ($extraStubs as $pattern => $stub) {
                if (\Illuminate\Support\Str::is($pattern, $url)) {
                    return is_callable($stub) ? $stub($request) : $stub;
                }
            }

            return \Illuminate\Support\Facades\Http::response([], 200);
        });
    }

    /**
     * An overflow_cells document: the same JSON nesting as the retired ring
     * WKT (lanes of base64 cells), with any scalars (bbox,
     * fairness_budget_min) passed through.
     *
     * @param array<string, array<string, string>> $lanes family => band => ring WKT
     * @param array<string, mixed> $scalars e.g. ['bbox' => [...], 'fairness_budget_min' => 45.0]
     */
    protected function overflowCellsDoc(array $lanes, array $scalars = []): string
    {
        $doc = $scalars;
        foreach ($lanes as $family => $bands) {
            foreach ($bands as $band => $wkt) {
                $doc[$family][$band] = base64_encode($this->reachCellsFor($wkt));
            }
        }

        return json_encode($doc);
    }
}
