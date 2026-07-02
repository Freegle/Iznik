package main

import "container/heap"

// catchmentBand is one drive-time contour of a catchment: everywhere within `Minutes` road
// drive-time of the group. Bands nest (each contains the smaller ones); rendered hottest→coolest
// they colour the catchment by how RAPIDLY a post from each area would ripple in.
type catchmentBand struct {
	Minutes float64        `json:"minutes"`
	Polygon GeoJSONPolygon `json:"polygon"`
}

// catchmentBands slices a reached-set into n nested drive-time contours (equal time steps).
func catchmentBands(g *Graph, iso IsochroneResult, maxSecs float32, mode Mode, n int) []catchmentBand {
	res := AutoResolution(maxSecs, mode)
	bands := make([]catchmentBand, 0, n)
	for k := 1; k <= n; k++ {
		t := maxSecs * float32(k) / float32(n)
		sub := make(map[NodeID]float32, len(iso.ReachedNodes))
		for id, c := range iso.ReachedNodes {
			if c <= t {
				sub[id] = c
			}
		}
		bands = append(bands, catchmentBand{Minutes: float64(t) / 60, Polygon: IsochronePolygon(g, sub, res)})
	}
	return bands
}

// multiSourceIsochrone runs a plain Dijkstra seeded from every node in `origins` at cost 0,
// returning all nodes reachable within limitSeconds for the given mode. It's the core of the
// per-group catchment: seeding from the whole group BOUNDARY (not a single centroid) is what
// lets the catchment capture corridor reach into the group's edges — e.g. an M62 offer that
// reaches HullFreegle's western strip near Goole, ~64km from the Hull centroid.
func multiSourceIsochrone(g *Graph, origins []NodeID, limitSeconds float32, mode Mode) IsochroneResult {
	dist := make(map[NodeID]float32, 4096)
	q := &pq{}
	for _, o := range origins {
		if o == noNode {
			continue
		}
		if _, seen := dist[o]; !seen {
			dist[o] = 0
			heap.Push(q, &item{id: o, cost: 0})
		}
	}

	for q.Len() > 0 {
		cur := heap.Pop(q).(*item)
		if cur.cost > dist[cur.id] {
			continue
		}
		if cur.cost > limitSeconds {
			break
		}
		for _, e := range g.EdgesFrom(cur.id) {
			base := e.Seconds[mode]
			if base < 0 {
				continue
			}
			newCost := cur.cost + base
			if newCost > limitSeconds {
				continue
			}
			if prev, seen := dist[e.To]; !seen || newCost < prev {
				dist[e.To] = newCost
				heap.Push(q, &item{id: e.To, cost: newCost})
			}
		}
	}

	return IsochroneResult{ReachedNodes: dist}
}
