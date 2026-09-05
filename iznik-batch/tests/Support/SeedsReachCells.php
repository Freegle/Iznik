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

    /**
     * Stubs accumulated across every fakeSpatialHttp() call in a test.
     *
     * Laravel's Http::fake() appends stubs and the FIRST match wins, so a test that
     * calls this twice - which several do, once to fake density and once to fake
     * routing - had its second set of patterns shadowed by the first closure. The
     * first closure answers rasterise, vectorise and groups/intersecting and returns
     * null for anything else, and a null from a stub callback means "not faked", so
     * the second call's patterns never got a chance and those requests went to the
     * real network. Accumulating means the last call sees every pattern.
     */
    private array $seedsReachExtraStubs = [];

    private function reachCellService(): CellSetService
    {
        return $this->seedsReachCellSets ??= new CellSetService();
    }

    /**
     * The encoded cell grid for an axis-aligned rectangle WKT - the shape
     * every fixture here plants. A cell is set when its centre lies inside
     * the rectangle, which is the rasteriser's own rule for a shape with no
     * boundary curvature; the grid is trimmed to the covered cells, and the
     * run stream is emitted directly (a covered rectangle is one ON run), so
     * this works under any Http::fake, costs no network call, and never
     * materialises a per-cell array however large the box.
     *
     * Byte format per CellSetService: 20-byte header (magic, minCol, minRow,
     * cols, rows as uint32 LE) then LEB128 varint runs alternating starting
     * with an OFF run - here varint(0) then varint(total), the degenerate
     * all-covered case, which the decoder and the streaming probe both
     * accept.
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
        // First and last cell whose CENTRE lies strictly inside the box.
        $firstCol = (int) ceil($minLng / $cell - 0.5);
        if (($firstCol + 0.5) * $cell <= $minLng) {
            $firstCol++;
        }
        $lastCol = (int) floor($maxLng / $cell - 0.5);
        if (($lastCol + 0.5) * $cell >= $maxLng) {
            $lastCol--;
        }
        $firstRow = (int) ceil($minLat / $cell - 0.5);
        if (($firstRow + 0.5) * $cell <= $minLat) {
            $firstRow++;
        }
        $lastRow = (int) floor($maxLat / $cell - 0.5);
        if (($lastRow + 0.5) * $cell >= $maxLat) {
            $lastRow--;
        }
        if ($lastCol < $firstCol || $lastRow < $firstRow) {
            $this->fail("reachCellsFor: box too thin to cover any cell centre: {$wkt}");
        }

        $cols = $lastCol - $firstCol + 1;
        $rows = $lastRow - $firstRow + 1;

        $varint = static function (int $v): string {
            $out = '';
            while ($v >= 0x80) {
                $out .= chr(($v & 0x7f) | 0x80);
                $v >>= 7;
            }

            return $out . chr($v);
        };

        return pack('VVVVV', 0x31534343, $firstCol & 0xFFFFFFFF, $firstRow & 0xFFFFFFFF, $cols, $rows)
            . $varint(0)
            . $varint($cols * $rows);
    }

    /**
     * An Http fake that answers /v1/groups/intersecting from the TEST
     * database's own groups (the real index is built from a different
     * database, so it cannot see test fixtures), dispatches any extra
     * pattern stubs, and passes everything else through to the real network -
     * the same semantics Laravel's array-form Http::fake has for unmatched
     * requests, so the rasterise/vectorise calls every reach write now makes
     * reach the real service. Replaces a bare Http::fake() in tests of those
     * write paths.
     *
     * The intersecting answer compares the grid's bounding box against each
     * group's polyindex - exact for the rectangles test fixtures plant.
     *
     * @param array<string, mixed> $extraStubs pattern => Http::response or callable
     */
    protected function fakeSpatialHttp(array $extraStubs = []): void
    {
        $this->seedsReachExtraStubs = array_merge($this->seedsReachExtraStubs, $extraStubs);
        $stubs = &$this->seedsReachExtraStubs;

        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$stubs) {
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
            foreach ($stubs as $pattern => $stub) {
                if (\Illuminate\Support\Str::is($pattern, $url)) {
                    return is_callable($stub) ? $stub($request) : $stub;
                }
            }

            // Unmatched: the real network answers, exactly as the array-form
            // fake behaves for unmatched requests.
            return null;
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
