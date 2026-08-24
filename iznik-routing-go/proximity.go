package main

import "container/heap"

// ProxPoint is a point (lat/lng) with its road drive-time from the reference, in minutes.
// Postcode/Place are a best-effort reverse-geocode of the point (see geocode.go) and are omitted
// from JSON when unresolved, so existing consumers (group-proximity's closest/furthest) that
// never populate them are unaffected.
type ProxPoint struct {
	Lat      float64 `json:"lat"`
	Lng      float64 `json:"lng"`
	DriveMin float64 `json:"drive_min"`
	Postcode string  `json:"postcode,omitempty"`
	Place    string  `json:"place,omitempty"`
}

// metresPerMile matches the constant already used elsewhere in the Freegle Go codebase
// (iznik-server-go/isochrone/score.go: milesToMetres = 1609.344).
const metresPerMile = 1609.344

// costToTargets runs Dijkstra from the node nearest (lat,lng) and returns the road cost (secs)
// to each node in `targets`, stopping early once every target has been settled. Expansion is
// pruned to `bbox` [minLat,maxLat,minLng,maxLng]: since every target is inside a group, roads
// between them stay within the group's bounding box (plus a margin baked into bbox), so this
// avoids exploring the huge area around a big group's far edge (Hull→Spurn was a 109-min
// isochrone over Leeds/York/Lincoln → 17s; bbox-pruned it's ~sub-second). maxSecs is a ceiling.
// Also returns the shortest-path-tree predecessor map (prev[node] = the node it was relaxed
// from on its best-known path), so callers that need the actual route (not just its cost) can
// walk it back to the origin with pathMetres.
func costToTargets(g *Graph, lat, lng float64, targets []NodeID, maxSecs float32, mode Mode, bbox [4]float64) (map[NodeID]float32, map[NodeID]NodeID) {
	out := make(map[NodeID]float32, len(targets))
	prev := make(map[NodeID]NodeID, 4096)
	origin := nearestNodeForMode(g, lat, lng, mode)
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
	start := initialCostFor(mode)
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
			base := e.Seconds[mode]
			if base < 0 {
				continue
			}
			nc := cur.cost + base
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

// pathMetres sums great-circle distance along the shortest-path TREE recorded in `prev` (as
// built by costToTargets) from `target` back to that sweep's origin node. Because costToTargets
// builds its tree by TIME (Dijkstra on Seconds[mode]), this returns the length of the
// fastest/time-optimal route — deliberately the same route whose time is already reported
// elsewhere, not a separately-shortest route that might follow a different road. haversineM
// between consecutive path nodes reproduces exactly the distM computed once per edge at
// graph-build time (graph.go), so this is exact, not approximate, given OSM ways are already
// split at every node.
func pathMetres(g *Graph, prev map[NodeID]NodeID, target NodeID) float64 {
	total := 0.0
	cur := target
	for {
		p, ok := prev[cur]
		if !ok {
			break
		}
		n1, n2 := g.Nodes[cur], g.Nodes[p]
		total += haversineM(float64(n1.Lat), float64(n1.Lng), float64(n2.Lat), float64(n2.Lng))
		cur = p
	}
	return total
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

// farthestSeedFrom returns the seed with the largest road cost from `origin` (a node), that cost,
// and the shortest-path-tree predecessor map from this sweep (so the caller can reconstruct the
// actual route to the farthest seed with pathMetres), searching only within `bbox`. Used by the
// 2-sweep group-diameter estimate.
func farthestSeedFrom(g *Graph, origin NodeID, seeds []NodeID, mode Mode, maxSecs float32, bbox [4]float64) (NodeID, float32, map[NodeID]NodeID) {
	lat, lng := float64(g.Nodes[origin].Lat), float64(g.Nodes[origin].Lng)
	costs, prev := costToTargets(g, lat, lng, seeds, maxSecs, mode, bbox)
	best := noNode
	var bestCost float32 = -1
	for _, s := range seeds {
		if c, ok := costs[s]; ok && c > bestCost {
			best, bestCost = s, c
		}
	}
	return best, bestCost, prev
}

// groupDiameter estimates a group's own road "width": the largest road drive-time between two of
// its points, via the standard 2-sweep diameter approximation (furthest seed A from any seed,
// then furthest seed B from A). It backs the catchment's "widest span within this group" yardstick
// — a post rippling in from no further than the group already spans internally is unremarkable.
// Pruned to the group bounding box so a big group stays cheap. `from`/`to` are the two endpoints;
// to.DriveMin is the A→B road time (from.DriveMin is 0, the sweep origin). milesBetween is the
// road distance (in miles) along that same A→B route (see pathMetres) — not a separately
// shortest route, so it always describes the same physical path as to.DriveMin.
func groupDiameter(g *Graph, seeds []NodeID, mode Mode, maxSecs float32) (from, to ProxPoint, milesBetween float64, ok bool) {
	s0 := noNode
	for _, s := range seeds {
		if s != noNode {
			s0 = s
			break
		}
	}
	if s0 == noNode {
		return
	}
	bbox := boundingBox(g, seeds, float64(g.Nodes[s0].Lat), float64(g.Nodes[s0].Lng), 0.15)

	aNode, _, _ := farthestSeedFrom(g, s0, seeds, mode, maxSecs, bbox)
	if aNode == noNode {
		return
	}
	bNode, bCost, prev := farthestSeedFrom(g, aNode, seeds, mode, maxSecs, bbox)
	if bNode == noNode {
		return
	}
	from = ProxPoint{Lat: float64(g.Nodes[aNode].Lat), Lng: float64(g.Nodes[aNode].Lng), DriveMin: 0}
	to = ProxPoint{Lat: float64(g.Nodes[bNode].Lat), Lng: float64(g.Nodes[bNode].Lng), DriveMin: float64(bCost) / 60}
	milesBetween = pathMetres(g, prev, bNode) / metresPerMile
	ok = true
	return
}

// groupProximity, for an offer at (offerLat,offerLng) rippling into a group whose candidate
// points are `seeds`, returns:
//
//	closest  — the in-group point nearest the offer by road (P), with the offer→P drive-time.
//	furthest — the in-group point furthest FROM P by road (Q), with the P→Q drive-time.
//
// It backs the moderator line "this post is quicker to get to for Freeglers in {P} than {P} is
// to {Q}", which should only be shown when closest.DriveMin < furthest.DriveMin.
func groupProximity(g *Graph, offerLat, offerLng float64, seeds []NodeID, mode Mode, maxSecs float32) (closest, furthest ProxPoint, ok bool) {
	if len(seeds) == 0 {
		return
	}
	// Prune both searches to the group + offer bounding box (+~15km) so we don't explore the
	// huge area around a big group's far edge. Roads between in-group points stay inside it.
	bbox := boundingBox(g, seeds, offerLat, offerLng, 0.15)

	// P: the group point with the smallest road time from the offer.
	toGroup, _ := costToTargets(g, offerLat, offerLng, seeds, maxSecs, mode, bbox)
	pNode := noNode
	var pCost float32
	for _, s := range seeds {
		if c, r := toGroup[s]; r && (pNode == noNode || c < pCost) {
			pNode, pCost = s, c
		}
	}
	if pNode == noNode {
		return // offer can't reach the group within maxSecs
	}
	closest = ProxPoint{
		Lat: float64(g.Nodes[pNode].Lat), Lng: float64(g.Nodes[pNode].Lng),
		DriveMin: float64(pCost) / 60,
	}

	// Q: the group point with the largest road time FROM P.
	fromP, _ := costToTargets(g, closest.Lat, closest.Lng, seeds, maxSecs, mode, bbox)
	qNode := noNode
	var qCost float32 = -1
	for _, s := range seeds {
		if c, r := fromP[s]; r && c > qCost {
			qNode, qCost = s, c
		}
	}
	if qNode == noNode {
		return
	}
	furthest = ProxPoint{
		Lat: float64(g.Nodes[qNode].Lat), Lng: float64(g.Nodes[qNode].Lng),
		DriveMin: float64(qCost) / 60,
	}
	ok = true
	return
}
