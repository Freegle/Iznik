package main

import "container/heap"

// ProxPoint is a point (lat/lng) with its road drive-time from the reference, in minutes.
type ProxPoint struct {
	Lat      float64 `json:"lat"`
	Lng      float64 `json:"lng"`
	DriveMin float64 `json:"drive_min"`
}

// costToTargets runs Dijkstra from the node nearest (lat,lng) and returns the road cost (secs)
// to each node in `targets`, stopping early once every target has been settled (so it only
// explores as far as the group extends, not the whole maxSecs budget). Unreachable targets are
// absent from the result. maxSecs is a safety ceiling.
func costToTargets(g *Graph, lat, lng float64, targets []NodeID, maxSecs float32, mode Mode) map[NodeID]float32 {
	out := make(map[NodeID]float32, len(targets))
	origin := nearestNodeForMode(g, lat, lng, mode)
	if origin == noNode || len(targets) == 0 {
		return out
	}
	need := make(map[NodeID]bool, len(targets))
	for _, t := range targets {
		if t != noNode {
			need[t] = true
		}
	}
	remaining := len(need)

	dist := make(map[NodeID]float32, 4096)
	dist[origin] = 0
	q := &pq{}
	heap.Push(q, &item{id: origin, cost: 0})

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
			if nc > maxSecs {
				continue
			}
			if prev, seen := dist[e.To]; !seen || nc < prev {
				dist[e.To] = nc
				heap.Push(q, &item{id: e.To, cost: nc})
			}
		}
	}
	return out
}

// groupProximity, for an offer at (offerLat,offerLng) rippling into a group whose candidate
// points are `seeds`, returns:
//   closest  — the in-group point nearest the offer by road (P), with the offer→P drive-time.
//   furthest — the in-group point furthest FROM P by road (Q), with the P→Q drive-time.
// It backs the moderator line "this post is quicker to get to for Freeglers in {P} than {P} is
// to {Q}", which should only be shown when closest.DriveMin < furthest.DriveMin.
func groupProximity(g *Graph, offerLat, offerLng float64, seeds []NodeID, mode Mode, maxSecs float32) (closest, furthest ProxPoint, ok bool) {
	if len(seeds) == 0 {
		return
	}
	// P: the group point with the smallest road time from the offer.
	toGroup := costToTargets(g, offerLat, offerLng, seeds, maxSecs, mode)
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
	fromP := costToTargets(g, closest.Lat, closest.Lng, seeds, maxSecs, mode)
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
