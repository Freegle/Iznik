package main

import "container/heap"

// metresPerMile matches the constant already used elsewhere in the Freegle Go codebase
// (iznik-server-go/isochrone/score.go: milesToMetres = 1609.344).
const metresPerMile = 1609.344

// costToTargets runs Dijkstra from the node nearest (lat,lng) and returns the road cost (secs)
// to each node in `targets`, stopping early once every target has been settled. Expansion is
// pruned to `bbox` [minLat,maxLat,minLng,maxLng]: when every target sits within a known area,
// roads between them stay within that area's bounding box (plus a margin baked into bbox), so
// this avoids exploring a huge surrounding area (Hull→Spurn was a 109-min isochrone over
// Leeds/York/Lincoln → 17s; bbox-pruned it's ~sub-second). maxSecs is a ceiling. Also returns the
// shortest-path-tree predecessor map (prev[node] = the node it was relaxed from on its
// best-known path), so callers that need the actual route (not just its cost) can walk it back
// to the origin.
func costToTargets(g *Graph, lat, lng float64, targets []NodeID, maxSecs float32, bbox [4]float64) (map[NodeID]float32, map[NodeID]NodeID) {
	out := make(map[NodeID]float32, len(targets))
	prev := make(map[NodeID]NodeID, 4096)
	origin := nearestDriveNode(g, lat, lng)
	if origin == noNode || len(targets) == 0 {
		return out, prev
	}
	need := make(map[NodeID]bool, len(targets))
	for _, t := range targets {
		if t != noNode {
			need[t] = true
		}
	}
	remaining := len(need)

	inBox := func(n NodeID) bool {
		la, ln := float64(g.Nodes[n].Lat), float64(g.Nodes[n].Lng)
		return la >= bbox[0] && la <= bbox[1] && ln >= bbox[2] && ln <= bbox[3]
	}

	dist := make(map[NodeID]float32, 4096)
	// Seed the per-trip startup overhead exactly like Isochrone does: the
	// reach tick polygons come from Isochrone, and /v1/drive-time's contract
	// is to MATCH the reach, so both must count time from the same zero.
	start := driveStartupSecs
	dist[origin] = start
	q := &pq{}
	heap.Push(q, &item{id: origin, cost: start})

	for q.Len() > 0 && remaining > 0 {
		cur := heap.Pop(q).(*item)
		if cur.cost > dist[cur.id] {
			continue
		}
		if cur.cost > maxSecs {
			break
		}
		if need[cur.id] { // a target is now settled (shortest path found)
			out[cur.id] = cur.cost
			delete(need, cur.id)
			remaining--
		}
		for _, e := range g.EdgesFrom(cur.id) {
			nc := cur.cost + e.Sec()
			if nc > maxSecs || !inBox(e.To) {
				continue
			}
			if prevDist, seen := dist[e.To]; !seen || nc < prevDist {
				dist[e.To] = nc
				prev[e.To] = cur.id
				heap.Push(q, &item{id: e.To, cost: nc})
			}
		}
	}
	return out, prev
}

// boundingBox returns [minLat,maxLat,minLng,maxLng] over the given nodes plus (lat,lng),
// expanded by `margin` degrees so roads that bulge slightly outside are still followed.
func boundingBox(g *Graph, nodes []NodeID, lat, lng, margin float64) [4]float64 {
	bb := [4]float64{lat, lat, lng, lng}
	for _, n := range nodes {
		if n == noNode {
			continue
		}
		la, ln := float64(g.Nodes[n].Lat), float64(g.Nodes[n].Lng)
		if la < bb[0] {
			bb[0] = la
		}
		if la > bb[1] {
			bb[1] = la
		}
		if ln < bb[2] {
			bb[2] = ln
		}
		if ln > bb[3] {
			bb[3] = ln
		}
	}
	return [4]float64{bb[0] - margin, bb[1] + margin, bb[2] - margin, bb[3] + margin}
}

// reachedBBox is the lat/lng bounding box of a reached node set, used to pre-filter a
// candidate query cheaply before an exact nearest-node test.
func reachedBBox(g *Graph, reached map[NodeID]float32) (minLat, maxLat, minLng, maxLng float64) {
	minLat, minLng = 90, 180
	maxLat, maxLng = -90, -180
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
	return
}
