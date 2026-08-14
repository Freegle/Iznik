package main

import "math"

// Display polygons: the same traced isochrone boundary, cut down to something a browser
// can fetch and draw.
//
// IsochronePolygon deliberately does no approximating simplification, because the reach
// containment tests must agree with the traced boundary exactly (see the comment there).
// A map overlay has no such duty: it only has to look like the reach at map zoom. The
// difference is large. A 45-minute drive isochrone traces ~27,000 vertices and encodes to
// ~1.2MB of GeoJSON at full float64 precision; at a 100m tolerance and 5dp it is ~2,000
// vertices and ~40KB, under 10KB gzipped, and indistinguishable on screen.
//
// Simplification is approximating, so the boundary MOVES - by up to the tolerance, which
// near a barrier can mean cutting across it. That is the reason the exact polygon has none,
// and the reason callers pick the tolerance deliberately rather than taking a default here.
//
// So display simplification is strictly opt-in, and lives here rather than in
// IsochronePolygon, so that nothing which needs the exact boundary can pick it up by
// accident.

// displayCoordDP is the decimal places display coordinates are rounded to. 5dp is ~1m at
// UK latitudes: far finer than any simplification tolerance worth using, and it halves
// the encoded size, because Go otherwise prints float64 grid corners like
// -3.4219791980743413 (19 characters to say -3.42198).
const displayCoordDP = 5

// DisplayRing prepares a traced ring for display: Douglas-Peucker at tolDegrees (0 or less
// to skip), then coordinate rounding. The result is closed, has no zero-length segments,
// and never departs from the input by more than tolDegrees plus the rounding step.
func DisplayRing(ring [][2]float64, tolDegrees float64) [][2]float64 {
	return roundRing(simplifyRing(ring, tolDegrees), displayCoordDP)
}

// MetresToDegrees converts a tolerance in metres to degrees of latitude, the unit the
// tracer and the simplifier both work in. Longitude degrees are shorter than this away
// from the equator, so a tolerance expressed this way is at its most generous
// north-south and tighter east-west - which is the safe direction to be wrong in for a
// tolerance, and avoids making it latitude-dependent.
func MetresToDegrees(m float64) float64 {
	return m / 111320.0
}

// displayPolygonFor traces the reach at its natural resolution and returns it ready to
// draw, or nil when the reach traced nothing drawable (an origin with no roads near it).
// The resolution is unchanged from the exact polygon's, so the shape is the real one and
// only the vertex count is reduced; coarsening the grid instead would alter which gaps
// get closed, and so would change what the overlay claims is reachable.
func displayPolygonFor(g *Graph, reached map[NodeID]float32, mode Mode, simplifyM float64) *GeoJSONPolygon {
	if len(reached) == 0 {
		return nil
	}
	res := NetworkResolution(g, reached, mode)
	poly := IsochronePolygon(g, reached, res)
	if len(poly.Geometry.Coordinates) == 0 || len(poly.Geometry.Coordinates[0]) < 4 {
		return nil
	}
	ring := DisplayRing(poly.Geometry.Coordinates[0], MetresToDegrees(simplifyM))
	if len(ring) < 4 {
		return nil
	}
	return &GeoJSONPolygon{
		Type:     "Feature",
		Geometry: geoGeometry{Type: "Polygon", Coordinates: [][][2]float64{ring}},
	}
}

// roundRing rounds every coordinate to dp decimal places, dropping any consecutive
// duplicates that rounding creates while keeping the ring closed. A ring that collapses
// to fewer than a triangle is returned unrounded rather than emitted as something
// undrawable.
func roundRing(ring [][2]float64, dp int) [][2]float64 {
	if len(ring) < 4 {
		return ring
	}
	scale := math.Pow(10, float64(dp))
	round := func(p [2]float64) [2]float64 {
		return [2]float64{math.Round(p[0]*scale) / scale, math.Round(p[1]*scale) / scale}
	}

	out := make([][2]float64, 0, len(ring))
	for i, p := range ring {
		r := round(p)
		if i > 0 && r == out[len(out)-1] {
			continue
		}
		out = append(out, r)
	}
	// Rounding can collapse the closing point onto its predecessor, or the ring onto
	// too few distinct points to draw. Re-close, and fall back if there is nothing left.
	if len(out) > 1 && out[0] == out[len(out)-1] {
		out = out[:len(out)-1]
	}
	if len(out) < 3 {
		return ring
	}
	return append(out, out[0])
}
