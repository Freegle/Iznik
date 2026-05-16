package main

import "container/heap"

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

// Isochrone runs Dijkstra from the node nearest to (lat, lng) that has at
// least one edge usable by mode, and returns all nodes reachable within
// limitSeconds.
func Isochrone(g *Graph, lat, lng float64, limitSeconds float32, mode Mode) IsochroneResult {
	origin := nearestNodeForMode(g, lat, lng, mode)
	if origin == 0 {
		return IsochroneResult{ReachedNodes: map[NodeID]float32{}}
	}

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
			if prev, seen := dist[e.To]; !seen || newCost < prev {
				dist[e.To] = newCost
				heap.Push(q, &item{id: e.To, cost: newCost})
			}
		}
	}

	return IsochroneResult{ReachedNodes: dist}
}

// nearestNodeForMode returns the NodeID closest to (lat, lng) that has at
// least one outgoing edge usable by mode. Linear scan — correct and simple.
func nearestNodeForMode(g *Graph, lat, lng float64, mode Mode) NodeID {
	var best NodeID
	bestDist := float64(1e18)
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
