package main

import (
	"container/heap"
	"math"
)

// IsochroneResult is the set of nodes reachable within a time budget.
type IsochroneResult struct {
	ReachedNodes map[NodeID]float32
	// DistM is the road distance in metres along the time-optimal path to each reached node,
	// accumulated in the SAME Dijkstra pass (great-circle per edge, matching pathMetres). Lets
	// callers read how far a node is BY ROAD, not just by time, for free - a nearby node reads
	// as a small distance even if the isochrone sprawls much further in other directions.
	DistM map[NodeID]float32
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

// modeMaxSpeed returns the maximum physically possible speed in m/s for a mode.
func modeMaxSpeed(mode Mode) float64 {
	switch mode {
	case Walk:
		return 3.0
	case Cycle:
		return 12.0
	default:
		return 32.0
	}
}

// Isochrone runs Dijkstra from the node nearest to (lat, lng) and returns all
// nodes reachable within limitSeconds for the given mode.
func Isochrone(g *Graph, lat, lng float64, limitSeconds float32, mode Mode) IsochroneResult {
	origin := nearestNodeForMode(g, lat, lng, mode)
	if origin == noNode {
		return IsochroneResult{ReachedNodes: map[NodeID]float32{}, DistM: map[NodeID]float32{}}
	}

	startLat := float64(g.Nodes[origin].Lat)
	startLng := float64(g.Nodes[origin].Lng)
	maxReachM := modeMaxSpeed(mode) * float64(limitSeconds)

	dist := make(map[NodeID]float32)
	distM := make(map[NodeID]float32)
	dist[origin] = 0
	distM[origin] = 0

	q := &pq{}
	heap.Push(q, &item{id: origin, cost: 0})

	for q.Len() > 0 {
		cur := heap.Pop(q).(*item)
		if cur.cost > dist[cur.id] {
			continue
		}
		if cur.cost > limitSeconds {
			break
		}
		curNode := g.Nodes[cur.id]
		for _, e := range g.EdgesFrom(cur.id) {
			edgeCost := e.Seconds[mode]
			if edgeCost < 0 {
				continue
			}
			newCost := cur.cost + edgeCost
			if newCost > limitSeconds {
				continue
			}
			n := g.Nodes[e.To]
			if haversineM(startLat, startLng, float64(n.Lat), float64(n.Lng)) > maxReachM {
				continue
			}
			if prev, seen := dist[e.To]; !seen || newCost < prev {
				dist[e.To] = newCost
				// Carry road distance along the same time-optimal path (great-circle per edge).
				distM[e.To] = distM[cur.id] + float32(haversineM(float64(curNode.Lat), float64(curNode.Lng), float64(n.Lat), float64(n.Lng)))
				heap.Push(q, &item{id: e.To, cost: newCost})
			}
		}
	}

	return IsochroneResult{ReachedNodes: dist, DistM: distM}
}

// nearestNodeForMode returns the NodeID closest to (lat, lng) with an edge usable by mode.
func nearestNodeForMode(g *Graph, lat, lng float64, mode Mode) NodeID {
	if g.Grid != nil {
		return nearestNodeGrid(g, lat, lng, mode)
	}
	return nearestNodeLinear(g, lat, lng, mode)
}

// nearestNodeGrid searches the spatial grid, expanding outward until a valid node is found.
func nearestNodeGrid(g *Graph, lat, lng float64, mode Mode) NodeID {
	baseRow := int16(lat / gridRes)
	baseCol := int16(lng / gridRes)

	var best NodeID
	bestDist := math.MaxFloat64

	for radius := int16(0); radius <= 10; radius++ {
		for dr := -radius; dr <= radius; dr++ {
			for dc := -radius; dc <= radius; dc++ {
				if dr != -radius && dr != radius && dc != -radius && dc != radius {
					continue
				}
				ids := g.Grid.cells[[2]int16{baseRow + dr, baseCol + dc}]
				for _, id := range ids {
					if !hasEdgeForMode(g, id, mode) {
						continue
					}
					n := g.Nodes[id]
					d := haversineM(lat, lng, float64(n.Lat), float64(n.Lng))
					if d < bestDist {
						bestDist = d
						best = id
					}
				}
			}
		}
		if best != noNode {
			break
		}
	}
	return best
}

// nearestNodeLinear is the O(N) fallback scan when no grid is present.
func nearestNodeLinear(g *Graph, lat, lng float64, mode Mode) NodeID {
	var best NodeID
	bestDist := math.MaxFloat64
	for id := NodeID(1); id < NodeID(len(g.Nodes)); id++ {
		if !hasEdgeForMode(g, id, mode) {
			continue
		}
		n := g.Nodes[id]
		d := haversineM(lat, lng, float64(n.Lat), float64(n.Lng))
		if d < bestDist {
			bestDist = d
			best = id
		}
	}
	return best
}

func hasEdgeForMode(g *Graph, id NodeID, mode Mode) bool {
	for _, e := range g.EdgesFrom(id) {
		if e.Seconds[mode] >= 0 {
			return true
		}
	}
	return false
}
