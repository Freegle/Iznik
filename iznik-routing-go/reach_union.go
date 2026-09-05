package main

// Road-native origin-group union - the grid-removal endgame's replacement for
// ExpandService::unionWithOriginGroupArea's per-tick geometry.
//
// The stored reach deliberately includes the post's origin group's whole area
// once the isochrone covers most of it (a group's own community sees its
// posts). Geometrically that was recomputed every tick and materialised into
// the cell grid; road-natively it is ONE number per post: the smallest budget
// at which the stored label reaches 90% of the road nodes inside the group's
// area. Below that budget the area is not union-admitted (matching the grid,
// which excluded it); at or above it, a member standing in the area is IN
// whatever their own road time says. Computed once when the label is stored,
// exact at every tick after.

import (
	"database/sql"
	"sort"
)

// unionCoverage is the fraction of the group's sampled road nodes the label
// must reach - the same 90% the geometric rule used on area.
const unionCoverage = 0.90

// unionSampleCap bounds the node sample per group; above it a deterministic
// stride keeps the sample spatially uniform.
const unionSampleCap = 2000

// unionNever marks "computed, and the union never activates within this
// label's budget" - distinct from NULL/absent ("not computed yet"), which
// keeps the transitional origin_area behaviour.
const unionNever = -1

// originGroupForMsgidFn is the same "first group by arrival" the geometric
// union used; a var so tests can inject without a database. 0 = unknown.
var originGroupForMsgidFn = func(msgid uint64) int64 {
	db := groupsDB
	if db == nil {
		return 0
	}
	var gid sql.NullInt64
	_ = db.QueryRow(
		"SELECT mg.groupid FROM messages_groups mg WHERE mg.msgid = ? AND mg.deleted = 0 ORDER BY mg.arrival ASC LIMIT 1",
		msgid).Scan(&gid)
	return gid.Int64
}

// sampleNodesInRings collects drive-snappable road nodes inside the rings
// (even-odd over the flattened POLYGON/MULTIPOLYGON ring list), capped by a
// deterministic stride so the sample stays spatially uniform.
func sampleNodesInRings(g *Graph, rings [][][2]float64) []NodeID {
	if len(rings) == 0 {
		return nil
	}
	minLat, maxLat := 91.0, -91.0
	minLng, maxLng := 181.0, -181.0
	for _, ring := range rings {
		for _, pt := range ring {
			if pt[1] < minLat {
				minLat = pt[1]
			}
			if pt[1] > maxLat {
				maxLat = pt[1]
			}
			if pt[0] < minLng {
				minLng = pt[0]
			}
			if pt[0] > maxLng {
				maxLng = pt[0]
			}
		}
	}
	inside := func(lng, lat float64) bool {
		return pointInRings(lng, lat, rings)
	}
	var nodes []NodeID
	for ci := int16(minLat / gridRes); ci <= int16(maxLat/gridRes); ci++ {
		for cj := int16(minLng / gridRes); cj <= int16(maxLng/gridRes); cj++ {
			for _, id := range g.Grid.at(ci, cj) {
				if g.DriveSnappable != nil && !g.DriveSnappable.Get(int(id)) {
					continue
				}
				n := g.Nodes[id]
				if inside(float64(n.Lng), float64(n.Lat)) {
					nodes = append(nodes, id)
				}
			}
		}
	}
	if len(nodes) > unionSampleCap {
		stride := len(nodes) / unionSampleCap
		sampled := make([]NodeID, 0, unionSampleCap)
		for i := 0; i < len(nodes); i += stride {
			sampled = append(sampled, nodes[i])
		}
		nodes = sampled
	}
	return nodes
}

// unionSecsForLabel answers, for one stored label and one group area: the
// smallest budget at which the label reaches unionCoverage of the area's
// road nodes (unionNever if it never does within the label's own budget),
// plus the partition regions the area's nodes belong to - merged into the
// post's stored leaves so union-admitted members DISCOVER the post even when
// their own road time would not have.
func unionSecsForLabel(e *ReachEngine, lbl *ReachLabels, rings [][][2]float64) (float32, []int32) {
	nodes := sampleNodesInRings(e.G, rings)
	if len(nodes) == 0 {
		return unionNever, nil
	}

	leafSeen := map[int32]bool{}
	var leaves []int32
	addLeaf := func(v NodeID) {
		if oi := e.Ov.IdxOf(v); oi != 0 {
			if l := e.Part.LeafAt(oi); l >= 0 && !leafSeen[l] {
				leafSeen[l] = true
				leaves = append(leaves, l)
			}
		} else {
			for _, j := range [2]NodeID{e.Ov.ChainA(v), e.Ov.ChainEndB[v]} {
				if j != 0 {
					if oi := e.Ov.IdxOf(j); oi != 0 {
						if l := e.Part.LeafAt(oi); l >= 0 && !leafSeen[l] {
							leafSeen[l] = true
							leaves = append(leaves, l)
						}
					}
				}
			}
		}
	}

	arrivals := make([]float32, 0, len(nodes))
	for _, v := range nodes {
		addLeaf(v)
		arrivals = append(arrivals, e.ArrivalAtBaseNode(lbl, v))
	}
	sort.Slice(arrivals, func(i, j int) bool { return arrivals[i] < arrivals[j] })
	sort.Slice(leaves, func(i, j int) bool { return leaves[i] < leaves[j] })

	// The coverage-crossing arrival: the smallest t with >=90% of nodes
	// reached is the 90th-percentile arrival itself.
	idx := int(float64(len(arrivals))*unionCoverage+0.9999999) - 1
	if idx < 0 {
		idx = 0
	}
	if idx >= len(arrivals) {
		idx = len(arrivals) - 1
	}
	at := arrivals[idx]
	if !(at <= lbl.T) { // +Inf (unreached) or beyond the label's own budget
		return unionNever, leaves
	}
	return at, leaves
}
