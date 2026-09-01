package main

import (
	"math"
	"sort"
	"strconv"
)

// The cluster-anchor overflow lane.
//
// A sparse-origin post's isochrone can hold only a few hundred members (a measured example:
// Hawes, 427 within 45 minutes) while a real town can sit just past the edge of that ceiling
// (Kendal, 47 minutes from Hawes). The rural lane (ruraloverflow.go) and fairness lane
// (fairnessoverflow.go) both widen a RING - every direction gets more reach. That is the wrong
// shape here: stretching the whole ring out to 47 minutes to catch one town also claims a huge
// area of empty fell that has no one in it. This lane instead looks in the shell beyond the
// committed reach for genuine population CLUSTERS - never a centroid, because a multi-centre
// shell averages to a point in an empty field - and extends reach as a handful of narrow
// bearing wedges AT those clusters, leaving the rest of the ring untouched.
//
// Like the other two lanes this changes nothing that is pushed: no extra mail, no extra group
// copies. It only lets a member who goes looking find a post their own cluster's members
// already found through the pool.
//
// Fires only where the audience cap did NOT bind (same side as the fairness lane) AND the pool
// at the ceiling is thin (below cluster_floor) - see the caller in ripple.go. A capped post
// already has more members than it is short of; this lane is for when the whole reachable pool
// itself is small.

// clusterCellKm is the grid cell size used to find population clusters in the shell, in
// kilometres. Coarse enough that a town registers as several adjoining dense cells rather than
// one member per cell; fine enough that two villages several kilometres apart don't merge into
// a single false cluster between them.
const clusterCellKm = 1.0

// clusterSuppressKm is how far (cell-centre to cell-centre) admitting one cluster suppresses
// its neighbours from also being admitted, so one town's spread of dense cells cannot spend
// every wedge on itself - see the "single town, three wedges" note in the caller's contract.
const clusterSuppressKm = 3.0

// clusterMarginKm is added on both the angular and drive-time edges of every wedge:
//   - angularly, past the cluster cell's own half-width, so the sector is not razored exactly
//     to the cell that happened to score highest;
//   - past the cluster's own far edge, so the wedge doesn't clip the town it was drawn for;
//   - and pulled back under the committed ceiling, so the wedge's node set overlaps rather than
//     merely touches the committed reach's own cells. buildIsochroneGrid (polygon.go) only
//     stamps an edge between two nodes it was BOTH given, so a wedge built from shell nodes
//     alone traces a boundary a whole grid cell short of the committed reach and reads as a
//     detached island rather than a flare off it; a couple of kilometres of overlap fixes that
//     on any resolution this lane runs at.
const clusterMarginKm = 2.0

// clusterCandidateFallbackFactor is how many ranked, suppression-filtered candidates to keep
// beyond maxWedges. Tracing a wedge can come back with nothing (a cluster whose members sit
// just outside the wedge's own time window, or a sector too narrow to catch a road at range),
// and without spares the lane would return nothing at all rather than falling through to the
// next town. Bounded rather than unlimited because each attempt scans the reached-node set.
const clusterCandidateFallbackFactor = 3

// clusterMaxHalfWidthRad caps the sector half-width computed from clusterMarginKm/distance: the
// small-angle approximation that derives it blows up for a cluster close to the origin (a tight
// committed ceiling can put one only a little beyond it), and a wedge is supposed to be narrow
// regardless of range. 30 degrees either side is still comfortably a wedge, not a half-circle.
const clusterMaxHalfWidthRad = math.Pi / 6

// clusterMember is one freegler's position and drive-time-from-the-post in the shell, used
// only to FIND clusters. Wedge polygons themselves are traced from road NODES
// (iso2.ReachedNodes), the same as every other overflow lane - see clusterOverflowWedges.
type clusterMember struct {
	Lat, Lng float64
	Secs     float32
}

// clusterCandidate is one occupied ~1km cell scored by its own count plus its 8 neighbours.
type clusterCandidate struct {
	row, col    int
	lat, lng    float64 // cell centre (absolute grid, not relative to the post)
	score       int
	nearestSecs float32 // min member drive-time in the 3x3 window - the tie-break
	farSecs     float32 // max member drive-time in the 3x3 window - the wedge's far edge
}

// clusterOverflowWedges finds up to maxWedges genuine population clusters in the shell beyond
// the committed reach and returns one wedge polygon per cluster, keyed "w1".."wN", best
// cluster first (by score, nearer drive-time then cell position breaking ties). It stops as
// soon as poolAtCeiling plus the members of the wedges it has actually DRAWN reaches floor:
// the lane exists to top up a thin pool, not to maximise reach for its own sake. Counting
// drawn wedges rather than admitted candidates is what stops one untraceable cluster from
// silently costing the lane its whole output.
//
// iso2 must be the SAME origin's Isochrone run out to clusterMaxSecs - the caller pays for
// that second Dijkstra only once the firing condition already held (see handleRippleSchedule).
// Its reached-node set is what wedge polygons are traced from. shellMembers is the freegler
// positions used only to FIND the clusters: every member with committedSecs < Secs <=
// clusterMaxSecs.
//
// Returns nil when nothing qualifies (no shell members, no cell reaches cellK, or the pool was
// already at or past floor), so the caller can omit the field entirely.
func clusterOverflowWedges(g *Graph, iso2 IsochroneResult, lat, lng float64,
	shellMembers []clusterMember, committedSecs, clusterMaxSecs float32,
	cellK, maxWedges, floor, poolAtCeiling int) map[string]*GeoJSONPolygon {

	if len(shellMembers) == 0 || len(iso2.ReachedNodes) == 0 || maxWedges <= 0 {
		return nil
	}
	if poolAtCeiling >= floor {
		return nil // nothing to top up
	}

	cosLat := math.Cos(lat * math.Pi / 180)
	if cosLat < 0.01 {
		cosLat = 0.01 // guard near the poles; UK posts never get close
	}
	latCellDeg := clusterCellKm / 111.0
	lngCellDeg := clusterCellKm / (111.0 * cosLat)

	admitted := admitClusterCandidates(scoreClusterCells(shellMembers, latCellDeg, lngCellDeg, cellK),
		maxWedges, floor, poolAtCeiling)
	if len(admitted) == 0 {
		return nil
	}

	res := NetworkResolution(g, iso2.ReachedNodes)
	marginSecs := float32(clusterMarginKm*1000) / float32(driveMaxSpeedMS)
	lowSecs := committedSecs - marginSecs
	if lowSecs < 0 {
		lowSecs = 0
	}

	out := make(map[string]*GeoJSONPolygon, maxWedges)
	built := 0
	builtMembers := 0
	for _, cl := range admitted {
		if built >= maxWedges {
			break
		}
		distKm := haversineM(lat, lng, cl.lat, cl.lng) / 1000.0
		if distKm < 0.1 {
			distKm = 0.1 // guard a cluster cell almost on top of the origin
		}
		bearing := math.Atan2((cl.lng-lng)*cosLat, cl.lat-lat)
		if bearing < 0 {
			bearing += 2 * math.Pi
		}
		// Small-angle approximation: half-width in radians ≈ half-width in km / range in km.
		// Fine at the distances this lane runs at (a handful to a few tens of km) - but a
		// cluster cell only just beyond a very tight committed ceiling can sit close enough
		// that this blows up past a right angle, at which point "a sector around the cluster
		// bearing" would admit half the compass and stop being a wedge at all. Capped at a
		// generous but still genuinely narrow 30 degrees either side.
		halfWidth := (clusterCellKm/2 + clusterMarginKm) / distKm
		if halfWidth > clusterMaxHalfWidthRad {
			halfWidth = clusterMaxHalfWidthRad
		}

		limitSecs := cl.farSecs + marginSecs
		if limitSecs > clusterMaxSecs {
			limitSecs = clusterMaxSecs // never claim further than iso2 actually routed
		}

		wedgeNodes := make(map[NodeID]float32)
		for nid, t := range iso2.ReachedNodes {
			if t < lowSecs || t > limitSecs {
				continue
			}
			n := g.Nodes[nid]
			ang := math.Atan2((float64(n.Lng)-lng)*cosLat, float64(n.Lat)-lat)
			if ang < 0 {
				ang += 2 * math.Pi
			}
			if !withinSector(ang, bearing, halfWidth) {
				continue
			}
			wedgeNodes[nid] = t
		}
		if len(wedgeNodes) == 0 {
			continue
		}
		poly := IsochronePolygon(g, wedgeNodes, res)
		if len(poly.Geometry.Coordinates) == 0 || len(poly.Geometry.Coordinates[0]) < 4 {
			continue
		}
		built++
		p := poly
		out[clusterWedgeKey(built)] = &p

		// Only NOW does this town count towards the floor: it has a drawable wedge, so it
		// will actually admit the people its score promised. A candidate that traced nothing
		// falls through to the next town above instead of ending the lane.
		builtMembers += cl.score
		if poolAtCeiling+builtMembers >= floor {
			break
		}
	}

	if len(out) == 0 {
		return nil
	}
	return out
}

// scoreClusterCells bins shellMembers onto a ~1km grid and scores every occupied cell by its
// own count plus its 8 neighbours, returning the cells that reach cellK, sorted best first
// (score descending, nearer drive-time breaking ties). The cell itself - the discrete grid
// square with the most members, not an average of their positions - stands in for the "mode"
// the caller's contract asks for: it never collapses a multi-centre shell to an empty
// mid-point the way a centroid would.
func scoreClusterCells(shellMembers []clusterMember, latCellDeg, lngCellDeg float64, cellK int) []clusterCandidate {
	type acc struct {
		count                int
		nearestSecs, farSecs float32
	}
	cells := make(map[[2]int]*acc)
	for _, m := range shellMembers {
		key := [2]int{int(math.Round(m.Lat / latCellDeg)), int(math.Round(m.Lng / lngCellDeg))}
		a := cells[key]
		if a == nil {
			a = &acc{nearestSecs: m.Secs, farSecs: m.Secs}
			cells[key] = a
		}
		a.count++
		if m.Secs < a.nearestSecs {
			a.nearestSecs = m.Secs
		}
		if m.Secs > a.farSecs {
			a.farSecs = m.Secs
		}
	}

	var candidates []clusterCandidate
	for key := range cells {
		r, c := key[0], key[1]
		score := 0
		nearestSecs := float32(math.MaxFloat32)
		farSecs := float32(0)
		for dr := -1; dr <= 1; dr++ {
			for dc := -1; dc <= 1; dc++ {
				nb, ok := cells[[2]int{r + dr, c + dc}]
				if !ok {
					continue
				}
				score += nb.count
				if nb.nearestSecs < nearestSecs {
					nearestSecs = nb.nearestSecs
				}
				if nb.farSecs > farSecs {
					farSecs = nb.farSecs
				}
			}
		}
		if score < cellK {
			continue
		}
		candidates = append(candidates, clusterCandidate{
			row: r, col: c,
			lat: float64(r) * latCellDeg, lng: float64(c) * lngCellDeg,
			score:       score,
			nearestSecs: nearestSecs,
			farSecs:     farSecs,
		})
	}

	// Ordered to a TOTAL order, deliberately: candidates are collected by ranging over a map
	// (randomised by Go) and sort.Slice is not stable, so score and nearestSecs alone leave
	// genuine ties free to land either way between runs. That is not academic here - the
	// batch re-runs computeSchedule on the SAME stored msgid/lat/lng from its recompute and
	// backfill passes, so a flipped tie would silently swap which town a post's wedge points
	// at, un-reaching a member for a reason that has nothing to do with geography. The cell
	// coordinates break the remaining ties and cannot themselves tie.
	sort.Slice(candidates, func(i, j int) bool {
		if candidates[i].score != candidates[j].score {
			return candidates[i].score > candidates[j].score
		}
		if candidates[i].nearestSecs != candidates[j].nearestSecs {
			return candidates[i].nearestSecs < candidates[j].nearestSecs
		}
		if candidates[i].row != candidates[j].row {
			return candidates[i].row < candidates[j].row
		}
		return candidates[i].col < candidates[j].col
	})
	return candidates
}

// admitClusterCandidates greedily walks candidates best-first, suppressing every other
// candidate within clusterSuppressKm of one already admitted so a single town's spread of
// dense cells cannot spend every wedge on itself.
//
// It returns MORE than maxWedges on purpose (see clusterCandidateFallbackFactor) and does not
// stop on the floor: a candidate is only a wedge once its geometry has actually been traced,
// and tracing can come back empty. Deciding "enough" here, on scores alone, meant a single
// unlucky trace on the best-scoring town silently cost the whole lane its output - so both
// the cap and the floor are enforced by the build loop in clusterOverflowWedges instead.
func admitClusterCandidates(candidates []clusterCandidate, maxWedges, floor, poolAtCeiling int) []clusterCandidate {
	pool := maxWedges * clusterCandidateFallbackFactor
	suppressCells := clusterSuppressKm / clusterCellKm
	suppressCells2 := suppressCells * suppressCells

	admitted := make([]clusterCandidate, 0, pool)
	for _, cand := range candidates {
		if len(admitted) >= pool {
			break
		}
		suppressed := false
		for _, a := range admitted {
			dr := float64(cand.row - a.row)
			dc := float64(cand.col - a.col)
			if dr*dr+dc*dc <= suppressCells2 {
				suppressed = true
				break
			}
		}
		if suppressed {
			continue
		}
		admitted = append(admitted, cand)
	}
	return admitted
}

// withinSector reports whether ang lies within halfWidth radians of center, both in [0, 2π)
// with 0 = north increasing clockwise (the same atan2(east, north) convention used throughout
// this file and by frontierMilesMedianMax in ripple.go), correctly handling the wrap at 0/2π.
func withinSector(ang, center, halfWidth float64) bool {
	d := math.Abs(ang - center)
	if d > math.Pi {
		d = 2*math.Pi - d
	}
	return d <= halfWidth
}

// clusterWedgeKey is the JSON key for the i-th built wedge (1-based): "w1".."w3". Numbered by
// build order among the wedges that actually produced drawable geometry, not by rank among
// admitted candidates, so a candidate whose wedge traced nothing does not leave a gap.
func clusterWedgeKey(i int) string {
	return "w" + strconv.Itoa(i)
}
