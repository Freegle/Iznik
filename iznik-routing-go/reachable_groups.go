package main

import (
	"math"
	"strings"
)

// parseGroupPolys turns a group's polyindex WKT (POLYGON or MULTIPOLYGON) into
// one or more groupPoly values. A MULTIPOLYGON yields several groupPoly sharing
// the same ID; reachableGroupIDs dedupes them. Non-polygon WKT yields nothing.
func parseGroupPolys(id int64, wkt string) []groupPoly {
	trimmed := strings.TrimSpace(wkt)
	upper := strings.ToUpper(trimmed)
	var out []groupPoly

	switch {
	case strings.HasPrefix(upper, "MULTIPOLYGON"):
		start := strings.Index(trimmed, "(")
		end := strings.LastIndex(trimmed, ")")
		if start < 0 || end <= start {
			return out
		}
		// inner = ((ring)),((ring),(hole)),... — split into per-polygon groups.
		for _, part := range splitRings(trimmed[start+1 : end]) {
			rings, err := wktPolygonToCoords("POLYGON" + part)
			if err == nil && len(rings) > 0 {
				out = append(out, newGroupPoly(id, rings))
			}
		}
	case strings.HasPrefix(upper, "POLYGON"):
		if rings, err := wktPolygonToCoords(trimmed); err == nil && len(rings) > 0 {
			out = append(out, newGroupPoly(id, rings))
		}
	}
	return out
}

// groupPoly holds a group's polygon parsed into [lng,lat] rings, ready for
// point-in-polygon classification of reached road nodes. A MULTIPOLYGON group is
// represented as several groupPoly values that share the same ID.
type groupPoly struct {
	ID    int64
	rings [][][2]float64 // outer ring first, holes follow; [lng,lat] per point

	// Outer-ring bounding box, precomputed for fast rejection.
	minLng, maxLng, minLat, maxLat float64
}

// newGroupPoly builds a groupPoly and precomputes the outer ring's bounding box.
func newGroupPoly(id int64, rings [][][2]float64) groupPoly {
	gp := groupPoly{
		ID:     id,
		rings:  rings,
		minLng: math.MaxFloat64,
		minLat: math.MaxFloat64,
		maxLng: -math.MaxFloat64,
		maxLat: -math.MaxFloat64,
	}
	if len(rings) > 0 {
		for _, p := range rings[0] {
			lng, lat := p[0], p[1]
			if lng < gp.minLng {
				gp.minLng = lng
			}
			if lng > gp.maxLng {
				gp.maxLng = lng
			}
			if lat < gp.minLat {
				gp.minLat = lat
			}
			if lat > gp.maxLat {
				gp.maxLat = lat
			}
		}
	}
	return gp
}

// pointInPolygon uses ray casting. Coords are [lng, lat].
func pointInPolygon(lng, lat float64, ring [][2]float64) bool {
	inside := false
	n := len(ring)
	j := n - 1
	for i := 0; i < n; i++ {
		xi, yi := ring[i][0], ring[i][1]
		xj, yj := ring[j][0], ring[j][1]
		if ((yi > lat) != (yj > lat)) && (lng < (xj-xi)*(lat-yi)/(yj-yi)+xi) {
			inside = !inside
		}
		j = i
	}
	return inside
}

// reachableGroupIDs returns the IDs of groups that contain at least one reached
// road node. This is the plan's threshold-free, water/toll-correct targeting
// signal: no reached node can cross a river (no edge does) or use an excluded
// toll tunnel, so a group with zero reached nodes inside it is genuinely
// unreachable and is dropped. Each ID is returned at most once (dedupes a
// MULTIPOLYGON group split across several groupPoly values). The returned slice
// is non-nil even when empty, so callers can distinguish "computed zero groups"
// from "not computed".
func reachableGroupIDs(g *Graph, reached map[NodeID]float32, groups []groupPoly) []int64 {
	seen := make(map[int64]bool)
	out := make([]int64, 0)
	for _, gp := range groups {
		if seen[gp.ID] || len(gp.rings) == 0 {
			continue
		}
		for id := range reached {
			n := g.Nodes[id]
			lng, lat := float64(n.Lng), float64(n.Lat)
			if lng < gp.minLng || lng > gp.maxLng || lat < gp.minLat || lat > gp.maxLat {
				continue
			}
			if pointInPolygon(lng, lat, gp.rings[0]) {
				out = append(out, gp.ID)
				seen[gp.ID] = true
				break
			}
		}
	}
	return out
}
