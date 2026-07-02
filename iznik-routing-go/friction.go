package main

import (
	"container/heap"
	"math"
)

// FrictionParams configures the connectivity-friction reach model. All fields are
// principled + tunable (the algorithm changes what people see, so it cannot be
// calibrated against historical collection distances — chicken & egg).
type FrictionParams struct {
	Ref      float32 // connectivity where traversal friction = 1 (national midpoint)
	Traverse float32 // traversal-friction exponent on edge cost; 0 = off (plain isochrone)
	Min, Max float32 // clamp on the traversal-friction multiplier (0 = unclamped on that side)
	// Willingness (destination-side budget multiplier keyed on the collector's home
	// connectivity): a node is reached iff cost ≤ limit × willingness(node). Willingness
	// DECREASES with connectivity (rural collectors travel further, urban ones don't),
	// which makes reach asymmetric. Willing==0 ⇒ off.
	Willing    float32
	WMin, WMax float32 // clamp on the willingness multiplier (0 = unclamped on that side)
}

// edgeFriction is the traversal-friction multiplier applied to an edge entering an
// area of connectivity `conn`. Friction INCREASES with connectivity (dense, well-
// connected ground slows the wavefront so reach pools and stays tight; sparse ground
// lets it run). conn==0 (unknown, e.g. Scotland) or Traverse==0 ⇒ 1.0 (plain isochrone).
func edgeFriction(conn uint8, p FrictionParams) float32 {
	if conn == 0 || p.Traverse == 0 || p.Ref <= 0 {
		return 1
	}
	f := float32(math.Pow(float64(conn)/float64(p.Ref), float64(p.Traverse)))
	if p.Min > 0 && f < p.Min {
		f = p.Min
	}
	if p.Max > 0 && f > p.Max {
		f = p.Max
	}
	return f
}

// willingness is the destination-side budget multiplier for a collector whose home
// area has connectivity `conn`. It DECREASES with connectivity: a rural (low-conn)
// collector is willing to travel further, an urban (high-conn) one is not. conn==0
// (unknown) or Willing==0 ⇒ 1.0.
func willingness(conn uint8, p FrictionParams) float32 {
	if conn == 0 || p.Willing == 0 || p.Ref <= 0 {
		return 1
	}
	w := float32(math.Pow(float64(p.Ref)/float64(conn), float64(p.Willing)))
	if p.WMin > 0 && w < p.WMin {
		w = p.WMin
	}
	if p.WMax > 0 && w > p.WMax {
		w = p.WMax
	}
	return w
}

// maxWillingness is the largest budget multiplier any node could have, used to size
// how far Dijkstra must explore before the per-node willingness filter is applied.
func maxWillingness(p FrictionParams) float32 {
	if p.Willing == 0 {
		return 1
	}
	if p.WMax > 0 {
		return p.WMax
	}
	return 3 // sane bound when uncapped
}

// frictionIsochroneFromNodes is the multi-source core: it seeds Dijkstra with every node in
// `origins` at cost 0 and expands with the same traversal-friction + willingness rules as
// FrictionIsochrone. Used for the per-group catchment, which must be seeded from the whole
// group boundary (not a single centroid) so corridor reach into the group's edges is captured.
// (No single-origin physical-distance prune here — there are many sources; the cost budget
// bounds the search.)
func frictionIsochroneFromNodes(g *Graph, origins []NodeID, limitSeconds float32, mode Mode, p FrictionParams) IsochroneResult {
	exploreLimit := limitSeconds * maxWillingness(p)
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
		if cur.cost > exploreLimit {
			break
		}
		for _, e := range g.EdgesFrom(cur.id) {
			base := e.Seconds[mode]
			if base < 0 {
				continue
			}
			newCost := cur.cost + base*edgeFriction(g.Nodes[e.To].Conn, p)
			if newCost > exploreLimit {
				continue
			}
			if prev, seen := dist[e.To]; !seen || newCost < prev {
				dist[e.To] = newCost
				heap.Push(q, &item{id: e.To, cost: newCost})
			}
		}
	}

	reached := make(map[NodeID]float32, len(dist))
	for id, cost := range dist {
		if cost <= limitSeconds*willingness(g.Nodes[id].Conn, p) {
			reached[id] = cost
		}
	}
	return IsochroneResult{ReachedNodes: reached}
}

// CatchmentFromNodes computes a group's inbound catchment, seeded from `seeds` (the group
// boundary). The willingness BASIS is flippable:
//
//   flipCommunity=false ("traveller" — default): gate each incomer by ITS OWN willingness, so
//     far rural residents who happily travel are included. This is "who is able/willing to come".
//   flipCommunity=true ("community"): gate every incomer by the GROUP's own (uniform) willingness
//     — a dense group's short travel norm — so far incomers fall outside it. This simulates
//     dense-area residents finding far travellers-in unexpected/"suspicious".
func CatchmentFromNodes(g *Graph, seeds []NodeID, groupConn uint8, limitSeconds float32, mode Mode, p FrictionParams, flipCommunity bool) IsochroneResult {
	if flipCommunity {
		budget := limitSeconds * willingness(groupConn, p)
		pu := p
		pu.Willing = 0 // uniform group norm applied to the budget, not per-incomer
		return frictionIsochroneFromNodes(g, seeds, budget, mode, pu)
	}
	// Per-incomer willingness (p.Willing stays on): each reached node is kept iff
	// cost ≤ limit × willingness(that node), so willing rural incomers reach from further.
	return frictionIsochroneFromNodes(g, seeds, limitSeconds, mode, p)
}

// CatchmentIsochrone is the per-group inbound catchment: the area from which posts would
// ripple far enough to reach a group at (lat,lng). An origin O is in the catchment iff a
// collector at the group would travel to O — i.e. cost(O→group) ≤ base × willingness(group).
// Since the collector is the GROUP, willingness is the group's own (uniform), not per-node.
// On the ~bidirectional road graph cost(O→group) ≈ cost(group→O), so we run one traversal-
// friction wavefront out from the group with the budget inflated by the group's willingness.
// (A true directed reverse would need incoming-edge adjacency; deferred.)
func CatchmentIsochrone(g *Graph, lat, lng float64, limitSeconds float32, mode Mode, p FrictionParams) IsochroneResult {
	origin := nearestNodeForMode(g, lat, lng, mode)
	if origin == noNode {
		return IsochroneResult{ReachedNodes: map[NodeID]float32{}}
	}
	wG := willingness(g.Nodes[origin].Conn, p)
	pp := p
	pp.Willing = 0 // group budget is uniform (the group's), not each destination's
	return FrictionIsochrone(g, lat, lng, limitSeconds*wG, mode, pp)
}

// FrictionIsochrone runs Dijkstra from the node nearest (lat,lng) with two connectivity
// effects layered on the plain isochrone:
//
//   - Traversal friction scales each edge's travel time by the friction of the area it
//     enters, so friction integrates ALONG the path (a high-friction area slows
//     everything beyond it) → anisotropic, corridor-following reach.
//   - Willingness is a destination-side budget multiplier keyed on the COLLECTOR's home
//     connectivity, so a node is reached iff cost ≤ limit × willingness(node). Because
//     the budget rides on the collector, reach becomes asymmetric.
//
// With no connectivity data (Conn==0 everywhere) or Traverse==Willing==0 it reproduces
// the plain Isochrone exactly.
func FrictionIsochrone(g *Graph, lat, lng float64, limitSeconds float32, mode Mode, p FrictionParams) IsochroneResult {
	origin := nearestNodeForMode(g, lat, lng, mode)
	if origin == noNode {
		return IsochroneResult{ReachedNodes: map[NodeID]float32{}}
	}

	startLat := float64(g.Nodes[origin].Lat)
	startLng := float64(g.Nodes[origin].Lng)

	// Explore to the largest budget any collector could have (willingness can extend it).
	exploreLimit := limitSeconds * maxWillingness(p)

	// Physical-distance prune. Friction can speed the wavefront up (multiplier < 1),
	// letting it cover more ground than modeMaxSpeed×limit, so widen the bound by the
	// smallest possible friction. This only prunes nodes that cannot be within budget.
	effMin := p.Min
	if effMin < 0.05 {
		effMin = 0.05
	}
	maxReachM := modeMaxSpeed(mode) * float64(exploreLimit) / float64(effMin)

	dist := make(map[NodeID]float32)
	dist[origin] = 0

	q := &pq{}
	heap.Push(q, &item{id: origin, cost: 0})

	for q.Len() > 0 {
		cur := heap.Pop(q).(*item)
		if cur.cost > dist[cur.id] {
			continue
		}
		if cur.cost > exploreLimit {
			break
		}
		for _, e := range g.EdgesFrom(cur.id) {
			base := e.Seconds[mode]
			if base < 0 {
				continue
			}
			edgeCost := base * edgeFriction(g.Nodes[e.To].Conn, p)
			newCost := cur.cost + edgeCost
			if newCost > exploreLimit {
				continue
			}
			n := g.Nodes[e.To]
			if haversineM(startLat, startLng, float64(n.Lat), float64(n.Lng)) > maxReachM {
				continue
			}
			if prev, seen := dist[e.To]; !seen || newCost < prev {
				dist[e.To] = newCost
				heap.Push(q, &item{id: e.To, cost: newCost})
			}
		}
	}

	// Include each node under its own willingness-scaled budget.
	reached := make(map[NodeID]float32, len(dist))
	for id, cost := range dist {
		if cost <= limitSeconds*willingness(g.Nodes[id].Conn, p) {
			reached[id] = cost
		}
	}

	return IsochroneResult{ReachedNodes: reached}
}
