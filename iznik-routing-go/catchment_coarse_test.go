package main

import (
	"math"
	"testing"
)

// The coarse catchment exists so ripple expansion stops paying for a street-resolution
// outline it never looks at. These pin the two properties expansion actually relies on:
// the cost stops growing with the budget, and the shape it gets back still contains the
// reach (so the group prefilter cannot lose a group the exact outline would have found).

// coarseCase runs a point catchment on the Bristol fixture at the given budget.
func coarseCase(t *testing.T, g *Graph, secs float32) (map[NodeID]float32, GeoJSONPolygon, IsochroneBoundsResult, float64) {
	t.Helper()
	iso := Isochrone(g, 51.4545, -2.5879, secs, Drive)
	poly, bounds, res := CoarseCatchment(g, iso.ReachedNodes)

	return iso.ReachedNodes, poly, bounds, res
}

// ringOf returns the outer ring of a traced polygon.
func ringOf(p GeoJSONPolygon) [][2]float64 {
	if len(p.Geometry.Coordinates) == 0 {
		return nil
	}

	return p.Geometry.Coordinates[0]
}

// TestCoarseResolutionIsNeverFinerThanTheExactCeiling is the property the superset
// argument rests on: a coarse cell is at least as big as the biggest cell the exact
// path would ever use, so the coarse rasterisation is never tighter than the exact one.
func TestCoarseResolutionIsNeverFinerThanTheExactCeiling(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)

	for _, secs := range []float32{5 * 60, 15 * 60, 30 * 60} {
		iso := Isochrone(g, 51.4545, -2.5879, secs, Drive)
		if got := coarseResolution(g, iso.ReachedNodes); got < coarseFloorResolution {
			t.Fatalf("at %.0fs coarse resolution %g is finer than the floor %g",
				secs, got, coarseFloorResolution)
		}
	}
}

// TestCoarseGridStopsGrowingWithTheBudget is the whole point. The exact path's grid
// gets finer AND wider as the reach grows, so its cell count climbs with the area; the
// coarse grid is sized from the reach's own extent, so a bigger reach buys bigger cells
// rather than more of them.
func TestCoarseGridStopsGrowingWithTheBudget(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)

	cells := func(secs float32) int {
		iso := Isochrone(g, 51.4545, -2.5879, secs, Drive)
		res := coarseResolution(g, iso.ReachedNodes)
		_, rows, cols, _, _, ok := buildIsochroneGrid(g, iso.ReachedNodes, res)
		if !ok {
			t.Fatalf("no grid at %.0fs", secs)
		}

		return rows * cols
	}

	small := cells(5 * 60)
	large := cells(20 * 60)

	// The cap is generous - this is checking the growth is bounded, not that the two
	// are equal, since a reach that is still smaller than the resolution floor sits on
	// the floor rather than on the cell budget.
	if large > 4*small+coarseGridCells*coarseGridCells {
		t.Fatalf("coarse grid grew from %d cells to %d with the budget; it should stay flat", small, large)
	}
}

// TestCoarseCatchmentCoversTheReachedNodes is the containment that makes the coarse
// outline safe as the group prefilter: every node the reach actually touched has to be
// inside the outline, or expansion could miss a group the exact outline would have hit.
func TestCoarseCatchmentCoversTheReachedNodes(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)

	reached, poly, _, res := coarseCase(t, g, 15*60)
	ring := ringOf(poly)
	if len(ring) < 4 {
		t.Fatalf("coarse catchment has no usable ring (%d points)", len(ring))
	}

	// The traced boundary follows cell edges, so a node in a boundary cell can sit up
	// to a cell outside the ring's own vertices. Allow that, and only that.
	var outside int
	for id := range reached {
		n := g.Nodes[id]
		if !pointInRingWithin(ring, float64(n.Lng), float64(n.Lat), res) {
			outside++
		}
	}

	if outside > 0 {
		t.Fatalf("%d of %d reached nodes fall outside the coarse catchment", outside, len(reached))
	}
}

// TestCoarseBoundsStillSandwichTheCatchment pins the guarantee bounds.go argues in
// units of cells: it is scale-invariant, so it has to survive being derived on the
// coarse grid rather than the exact one. Outer must contain the outline; inner, when
// there is one, must sit inside it.
func TestCoarseBoundsStillSandwichTheCatchment(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)

	_, poly, bounds, res := coarseCase(t, g, 15*60)
	ring := ringOf(poly)
	if len(ring) < 4 {
		t.Fatalf("coarse catchment has no usable ring")
	}

	if bounds.Outer == nil {
		t.Fatal("coarse bounds have no outer; the containment queries need one")
	}
	outer := ringOf(*bounds.Outer)
	for _, pt := range ring {
		if !pointInRingWithin(outer, pt[0], pt[1], res) {
			t.Fatalf("catchment vertex %v escapes the outer bound", pt)
		}
	}

	// A small reach can erode away entirely, which bounds.go handles by omitting the
	// inner bound - so only check it when there is one.
	if bounds.Inner != nil {
		inner := ringOf(*bounds.Inner)
		for _, pt := range inner {
			if !pointInRingWithin(ring, pt[0], pt[1], res) {
				t.Fatalf("inner bound vertex %v escapes the catchment", pt)
			}
		}
	}
}

// pointInRingWithin is point-in-polygon with a tolerance: true when the point is inside
// the ring, or outside it by no more than tol (the slack a cell-edge trace needs). Built
// on bounds.go's pointInRing so the test judges containment exactly as the code does.
func pointInRingWithin(ring [][2]float64, lng, lat, tol float64) bool {
	if pointInRing(lng, lat, ring) {
		return true
	}

	// Nudge towards the ring's centroid by the tolerance and retry, which forgives a
	// point sitting just outside a boundary cell without forgiving one genuinely away
	// from the shape.
	var cx, cy float64
	for _, p := range ring {
		cx += p[0]
		cy += p[1]
	}
	cx /= float64(len(ring))
	cy /= float64(len(ring))

	dx, dy := cx-lng, cy-lat
	dist := math.Hypot(dx, dy)
	if dist == 0 {
		return true
	}
	scale := tol / dist

	return pointInRing(lng+dx*scale, lat+dy*scale, ring)
}
