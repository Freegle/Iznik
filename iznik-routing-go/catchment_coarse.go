package main

// Coarse catchment: the same reach, drawn on a grid whose cost does not grow with
// the area covered.
//
// The exact path traces the isochrone on a grid whose cell size comes from the road
// network (NetworkResolution), so the cell count - and with it the boundary trace and
// the GeoJSON that carries it - grows with the reached area, which grows roughly with
// the square of the drive-time budget. Measured on an idle node: 0.99s/36KB at 5
// minutes against 6.33s/2.5MB at 45. Ripple expansion walks every post up a schedule
// of growing budgets, so its demand peaks exactly where the per-call cost does.
//
// Expansion does not need a street-resolution outline. It asks three region-scale
// questions of the answer - which groups the reach now touches, what the sandwich
// bounds are, and the union with the origin group's area - and none of them can see
// the difference between a boundary traced at 30m and one traced at 300m.
//
// So a caller that only asks those questions can ask for the coarse form, and the
// cost stops scaling: the grid is sized to a fixed cell budget, so a 45-minute reach
// rasterises onto the same number of cells as a 5-minute one, just bigger ones.
//
// What the coarse form guarantees:
//
//   - Its cell size is never finer than NetworkResolution's own ceiling, so the
//     rasterised region is never tighter than the exact one. The outline can differ
//     from the exact one by about a cell along the boundary, always outward.
//   - The sandwich bounds keep their meaning. bounds.go argues outer ⊇ reach ⊇ inner
//     in units of CELLS (margin·res/√2 > tol·res + res/√2), so the argument is
//     scale-invariant and holds on this grid exactly as it does on the fine one. A
//     coarse inner erodes further in absolute terms and so is more likely to vanish
//     entirely, which is a case bounds.go already handles by omitting it.
//
// It is opt-in per call. The ModTools reach map and the rippling explorer's catchment
// tab are human-paced and want the real outline, so they keep asking for it.

// coarseGridCells is the target span of the coarse grid in cells. The reach is
// rasterised onto roughly this many cells across its longer side whatever its area,
// which is what makes the cost flat in the budget rather than quadratic.
const coarseGridCells = 200

// coarseFloorResolution is the finest a coarse cell may be, in degrees of latitude
// (~330m). It matches the ceiling in NetworkResolution, so a coarse grid is always at
// least as coarse as the exact one and the rasterised region is never tighter.
const coarseFloorResolution = 0.003

// coarseResolution sizes the grid from the reach's own bounding box, so the cell count
// stays put as the reach grows. Only the node coordinates are read - deliberately not
// the edges, because walking every reached node's edge list is the other half of what
// makes the exact path expensive at large budgets.
func coarseResolution(g *Graph, reached map[NodeID]float32) float64 {
	if len(reached) == 0 {
		return coarseFloorResolution
	}

	minLat, minLng := 90.0, 180.0
	maxLat, maxLng := -90.0, -180.0
	for id := range reached {
		n := g.Nodes[id]
		lat, lng := float64(n.Lat), float64(n.Lng)
		if lat < minLat {
			minLat = lat
		}
		if lat > maxLat {
			maxLat = lat
		}
		if lng < minLng {
			minLng = lng
		}
		if lng > maxLng {
			maxLng = lng
		}
	}

	span := maxLat - minLat
	if w := maxLng - minLng; w > span {
		span = w
	}

	res := span / coarseGridCells
	if res < coarseFloorResolution {
		res = coarseFloorResolution
	}

	return res
}

// CoarseCatchment traces the reach and derives its sandwich bounds on one coarse grid.
//
// Both come off the same rasterisation, as they do on the exact path, so the bounds
// bracket the outline they ship with rather than some other shape.
func CoarseCatchment(g *Graph, reached map[NodeID]float32) (GeoJSONPolygon, IsochroneBoundsResult, float64) {
	res := coarseResolution(g, reached)

	return IsochronePolygon(g, reached, res), IsochroneBounds(g, reached, res), res
}
