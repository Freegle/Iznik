package main

import (
	"container/heap"
	"math"
)

// IsochroneResult is the set of nodes reachable within the time budget.
type IsochroneResult struct {
	// ReachedNodes maps node ID → travel time in seconds from origin.
	ReachedNodes map[NodeID]float32
}

// item is a priority queue entry.
type item struct {
	id   NodeID
	cost float32
	idx  int
}

type pq []*item

func (q pq) Len() int            { return len(q) }
func (q pq) Less(i, j int) bool  { return q[i].cost < q[j].cost }
func (q pq) Swap(i, j int)       { q[i], q[j] = q[j], q[i]; q[i].idx = i; q[j].idx = j }
func (q *pq) Push(x interface{}) { it := x.(*item); it.idx = len(*q); *q = append(*q, it) }
func (q *pq) Pop() interface{}   { old := *q; n := len(old); it := old[n-1]; *q = old[:n-1]; return it }

// modeMaxSpeed returns the maximum physically possible speed in m/s for a mode,
// used to compute a geographic bounding radius for Dijkstra pruning.
func modeMaxSpeed(mode Mode) float64 {
	switch mode {
	case Walk:
		return 3.0 // brisk walk
	case Cycle:
		return 15.0 // fast cycle
	default:
		return 40.0 // ~140 km/h, above any legal UK road speed
	}
}

// Isochrone runs Dijkstra from the node nearest to (lat, lng) that has at
// least one edge usable by mode, and returns all nodes reachable within
// limitSeconds.
func Isochrone(g *Graph, lat, lng float64, limitSeconds float32, mode Mode) IsochroneResult {
	origin := nearestNodeForMode(g, lat, lng, mode)
	if origin == 0 {
		return IsochroneResult{ReachedNodes: map[NodeID]float32{}}
	}

	startLat := g.Nodes[origin].Lat
	startLng := g.Nodes[origin].Lng
	maxReachM := modeMaxSpeed(mode) * float64(limitSeconds)

	dist := make(map[NodeID]float32)
	dist[origin] = 0

	q := &pq{}
	heap.Push(q, &item{id: origin, cost: 0})

	for q.Len() > 0 {
		cur := heap.Pop(q).(*item)
		if cur.cost > dist[cur.id] {
			continue // stale entry
		}
		if cur.cost > limitSeconds {
			break
		}
		for _, e := range g.Edges[cur.id] {
			edgeCost := e.Seconds[mode]
			if edgeCost < 0 {
				continue // mode cannot use this edge
			}
			newCost := cur.cost + edgeCost
			if newCost > limitSeconds {
				continue
			}
			// Geographic pruning: any node further than max_speed × limit from
			// the origin is physically unreachable regardless of the road network.
			n := g.Nodes[e.To]
			if haversineM(startLat, startLng, n.Lat, n.Lng) > maxReachM {
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

// nearestNodeForMode returns the NodeID closest to (lat, lng) that has at
// least one outgoing edge usable by mode.
// Uses the grid index when available, falls back to linear scan.
func nearestNodeForMode(g *Graph, lat, lng float64, mode Mode) NodeID {
	if g.Grid != nil {
		return nearestNodeGrid(g, lat, lng, mode)
	}
	return nearestNodeLinear(g, lat, lng, mode)
}

// nearestNodeGrid searches the spatial grid, expanding outward until a
// mode-valid node is found.
func nearestNodeGrid(g *Graph, lat, lng float64, mode Mode) NodeID {
	baseRow := int16(lat / gridRes)
	baseCol := int16(lng / gridRes)

	var best NodeID
	bestDist := math.MaxFloat64

	for radius := int16(0); radius <= 10; radius++ {
		for dr := -radius; dr <= radius; dr++ {
			for dc := -radius; dc <= radius; dc++ {
				// Only visit the border of the expanding square.
				if dr != -radius && dr != radius && dc != -radius && dc != radius {
					continue
				}
				ids := g.Grid.cells[[2]int16{baseRow + dr, baseCol + dc}]
				for _, id := range ids {
					if !hasEdgeForMode(g, id, mode) {
						continue
					}
					n := g.Nodes[id]
					d := haversineM(lat, lng, n.Lat, n.Lng)
					if d < bestDist {
						bestDist = d
						best = id
					}
				}
			}
		}
		if best != 0 {
			break
		}
	}
	return best
}

// nearestNodeLinear is the fallback O(N) scan used when no grid is present.
func nearestNodeLinear(g *Graph, lat, lng float64, mode Mode) NodeID {
	var best NodeID
	bestDist := math.MaxFloat64
	for id, n := range g.Nodes {
		if !hasEdgeForMode(g, id, mode) {
			continue
		}
		d := haversineM(lat, lng, n.Lat, n.Lng)
		if d < bestDist {
			bestDist = d
			best = id
		}
	}
	return best
}

func hasEdgeForMode(g *Graph, id NodeID, mode Mode) bool {
	for _, e := range g.Edges[id] {
		if e.Seconds[mode] >= 0 {
			return true
		}
	}
	return false
}
