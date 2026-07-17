package main

import "math"

// Sandwich bounds for a catchment polygon (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
//
// The exact isochrone polygon is a grid-fill outline averaging thousands of vertices;
// the reach containment queries in MySQL consult two SMALL conservative polygons first
// and only touch the exact one for the thin band between them. Deriving those bounds
// HERE — on the same rasterisation grid the exact polygon is traced from — makes the
// superset/subset guarantees hold by construction:
//
//   Outer = the grid dilated by boundsMarginCells, traced, then simplified. Dilation
//           moves the boundary outward by at least marginCells·res/√2 everywhere, and
//           the simplification tolerance stays well inside that margin, so the result
//           can never dip inside the exact reach.
//   Inner = the grid eroded by boundsMarginCells, traced, then simplified — the same
//           argument mirrored inward. A small reach can erode to nothing, in which
//           case there is simply no inner bound (readers then do the exact test).
//
// The consumer (iznik-batch ReachBoundsService) still verifies both bounds against the
// stored polygon at write time — these are belt, that is braces.

// IsochroneBoundsResult carries the optional derived bounds; either may be nil.
type IsochroneBoundsResult struct {
	Outer *GeoJSONPolygon
	Inner *GeoJSONPolygon
}

// boundsMarginCells is the dilation/erosion distance in grid cells. With simplification
// tolerance = 1 grid cell, safety needs margin·res/√2 > tol·res + res/√2 (quantisation):
// 3/√2 ≈ 2.12 > 1 + 0.71 ✓.
const boundsMarginCells = 3

// boundsSimplifyCells is the Douglas-Peucker tolerance in grid cells.
const boundsSimplifyCells = 1.0

// IsochroneBounds derives the sandwich bounds for the reach of the given node set, on
// the same grid IsochronePolygon uses for the exact outline.
func IsochroneBounds(g *Graph, reached map[NodeID]float32, resolution float64) IsochroneBoundsResult {
	grid, rows, cols, minLat, minLng, ok := buildIsochroneGrid(g, reached, resolution)
	if !ok {
		return IsochroneBoundsResult{}
	}

	tol := boundsSimplifyCells * resolution

	// Outer: embed in a larger grid first so dilation never clips at the array edge —
	// a clipped dilation would locally shrink the safety margin below the
	// simplification tolerance.
	pad := boundsMarginCells + 1
	dilated := make([][]bool, rows+2*pad)
	for r := range dilated {
		dilated[r] = make([]bool, cols+2*pad)
	}
	for r := 0; r < rows; r++ {
		for c := 0; c < cols; c++ {
			dilated[r+pad][c+pad] = grid[r][c]
		}
	}
	for i := 0; i < boundsMarginCells; i++ {
		dilate(dilated)
	}
	outerRings := traceBoundary(dilated, rows+2*pad, cols+2*pad,
		minLat-float64(pad)*resolution, minLng-float64(pad)*resolution, resolution)
	var outer *GeoJSONPolygon
	if len(outerRings) > 0 {
		if ring := simplifyRing(outerRings[0], tol); len(ring) >= 4 {
			outer = &GeoJSONPolygon{
				Type:     "Feature",
				Geometry: geoGeometry{Type: "Polygon", Coordinates: [][][2]float64{ring}},
			}
		}
	}

	// Inner: erosion shrinks inward, so no padding is needed.
	eroded := make([][]bool, rows)
	for r := range eroded {
		eroded[r] = make([]bool, cols)
		copy(eroded[r], grid[r])
	}
	for i := 0; i < boundsMarginCells; i++ {
		erode(eroded)
	}
	var inner *GeoJSONPolygon
	if innerRings := traceBoundary(eroded, rows, cols, minLat, minLng, resolution); len(innerRings) > 0 {
		if ring := simplifyRing(innerRings[0], tol); len(ring) >= 4 {
			// Erosion can leave the largest surviving fragment belonging to a DIFFERENT
			// blob than the exact polygon (which is the largest ring of the un-eroded
			// grid). A cheap vertex-containment check against the exact outline drops
			// such an inner rather than shipping a wrong cheap-accept; the DB-side
			// write verification would also catch it, but there is no reason to ship it.
			exactRings := traceBoundary(grid, rows, cols, minLat, minLng, resolution)
			if len(exactRings) > 0 && ringVerticesInside(ring, exactRings[0]) {
				inner = &GeoJSONPolygon{
					Type:     "Feature",
					Geometry: geoGeometry{Type: "Polygon", Coordinates: [][][2]float64{ring}},
				}
			}
		}
	}

	return IsochroneBoundsResult{Outer: outer, Inner: inner}
}

// dilate grows the true region by one cell (4-neighbourhood), in place.
func dilate(grid [][]bool) {
	rows := len(grid)
	if rows == 0 {
		return
	}
	cols := len(grid[0])
	snap := make([][]bool, rows)
	for r := range snap {
		snap[r] = make([]bool, cols)
		copy(snap[r], grid[r])
	}
	for r := 0; r < rows; r++ {
		for c := 0; c < cols; c++ {
			if snap[r][c] ||
				(r > 0 && snap[r-1][c]) || (r < rows-1 && snap[r+1][c]) ||
				(c > 0 && snap[r][c-1]) || (c < cols-1 && snap[r][c+1]) {
				grid[r][c] = true
			}
		}
	}
}

// erode shrinks the true region by one cell (4-neighbourhood), in place. Cells on the
// array border are treated as having false neighbours beyond it, so erosion always
// pulls inward there too.
func erode(grid [][]bool) {
	rows := len(grid)
	if rows == 0 {
		return
	}
	cols := len(grid[0])
	snap := make([][]bool, rows)
	for r := range snap {
		snap[r] = make([]bool, cols)
		copy(snap[r], grid[r])
	}
	for r := 0; r < rows; r++ {
		for c := 0; c < cols; c++ {
			keep := snap[r][c] &&
				r > 0 && snap[r-1][c] && r < rows-1 && snap[r+1][c] &&
				c > 0 && snap[r][c-1] && c < cols-1 && snap[r][c+1]
			grid[r][c] = keep
		}
	}
}

// simplifyRing runs Douglas-Peucker on a closed ring (first point == last point),
// keeping the result closed. The ring is split at its first vertex and the vertex
// farthest from it, and each open chain is simplified independently, which is the
// standard way to apply DP to a closed ring without collapsing it.
func simplifyRing(ring [][2]float64, tol float64) [][2]float64 {
	if len(ring) <= 4 {
		return ring
	}
	// Drop the closing duplicate for processing.
	open := ring[:len(ring)-1]

	// Find the vertex farthest from vertex 0 as the second anchor.
	far, farDist := 0, -1.0
	for i, p := range open {
		if d := dist2(open[0], p); d > farDist {
			farDist = d
			far = i
		}
	}
	if far == 0 {
		return ring
	}

	first := douglasPeucker(open[:far+1], tol)
	second := douglasPeucker(open[far:], tol)
	out := make([][2]float64, 0, len(first)+len(second))
	out = append(out, first...)
	out = append(out, second[1:]...) // 'far' vertex is the last of first and first of second
	// second ends at open[len-1]; close the ring back to the start.
	if out[len(out)-1] != open[0] {
		out = append(out, open[0])
	}
	return out
}

// douglasPeucker simplifies an open polyline, always keeping both endpoints.
func douglasPeucker(pts [][2]float64, tol float64) [][2]float64 {
	if len(pts) <= 2 {
		return pts
	}
	// Find the point with the maximum perpendicular distance from the chord.
	maxDist, maxIdx := -1.0, -1
	a, b := pts[0], pts[len(pts)-1]
	for i := 1; i < len(pts)-1; i++ {
		if d := perpendicularDistance(pts[i], a, b); d > maxDist {
			maxDist = d
			maxIdx = i
		}
	}
	if maxDist <= tol {
		return [][2]float64{a, b}
	}
	left := douglasPeucker(pts[:maxIdx+1], tol)
	right := douglasPeucker(pts[maxIdx:], tol)
	return append(left[:len(left)-1], right...)
}

// perpendicularDistance is the distance from p to the segment ab (degrees-plane, the
// same Cartesian treatment the grid itself uses).
func perpendicularDistance(p, a, b [2]float64) float64 {
	dx, dy := b[0]-a[0], b[1]-a[1]
	lenSq := dx*dx + dy*dy
	if lenSq == 0 {
		return math.Sqrt(dist2(a, p))
	}
	t := ((p[0]-a[0])*dx + (p[1]-a[1])*dy) / lenSq
	if t < 0 {
		t = 0
	} else if t > 1 {
		t = 1
	}
	px, py := a[0]+t*dx, a[1]+t*dy
	ddx, ddy := p[0]-px, p[1]-py
	return math.Sqrt(ddx*ddx + ddy*ddy)
}

// ringVerticesInside reports whether every vertex of ring lies inside (or on) outline,
// by ray casting. Vertex sampling is not a full containment proof, but combined with
// the construction margin and the DB-side write verification it is ample.
func ringVerticesInside(ring, outline [][2]float64) bool {
	for _, pt := range ring {
		if !pointInRing(pt[0], pt[1], outline) {
			return false
		}
	}
	return true
}

// pointInRing is a standard ray-casting point-in-polygon test on [lng, lat] rings.
func pointInRing(lng, lat float64, ring [][2]float64) bool {
	inside := false
	n := len(ring)
	for i, j := 0, n-1; i < n; j, i = i, i+1 {
		xi, yi := ring[i][0], ring[i][1]
		xj, yj := ring[j][0], ring[j][1]
		if (yi > lat) != (yj > lat) &&
			lng < (xj-xi)*(lat-yi)/(yj-yi)+xi {
			inside = !inside
		}
	}
	return inside
}
