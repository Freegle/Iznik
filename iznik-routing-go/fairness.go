package main

import "container/heap"

// FairnessResult holds the output of a fairness-adjusted isochrone computation.
type FairnessResult struct {
	// Standard is the baseline isochrone with no fairness adjustment (W=0).
	Standard GeoJSONPolygon `json:"standard"`
	// Quintiles[1..5]: per-quintile reachability under the fairness adjustment.
	// Index 0 is unused. nil Polygon means that quintile was not reached.
	Quintiles [6]QuintileResult `json:"quintiles"`
	// FairnessScore is the fraction of fairness-included nodes that are Q1–Q3
	// (0 = entirely affluent; 1 = entirely deprived). -1 if no deprivation data.
	FairnessScore float32 `json:"fairness_score"`
	// NodesTouched is total nodes explored by Dijkstra (useful for debugging).
	NodesTouched int `json:"nodes_touched"`
	// SnapLat/SnapLng is the actual road node used as the routing origin.
	// May differ from the requested lat/lng when the click is off-road.
	SnapLat float32 `json:"snap_lat"`
	SnapLng float32 `json:"snap_lng"`
}

// QuintileResult holds per-quintile data for one fairness isochrone.
type QuintileResult struct {
	Polygon       *GeoJSONPolygon  `json:"polygon"`
	Islands       []GeoJSONPolygon `json:"islands,omitempty"` // disconnected fairness-reach areas
	Count         int              `json:"count"`
	TimeBudgetMin float32          `json:"time_budget_min"` // effective time budget in minutes
}

// quintileMultiplier returns the time-budget multiplier for a node of quintile q
// at the given fairness weight W ∈ [0,1].
//
//	W=0: all quintiles get multiplier 1.0 (standard isochrone)
//	W=1: Q1 gets 2.0, Q2 gets 1.75, Q3 gets 1.5, Q4 gets 1.25, Q5 gets 1.0
//
// Unknown quintile (0) is treated as middle (Q3 equivalent).
func quintileMultiplier(q Quintile, W float32) float32 {
	switch {
	case q < 1 || q > 5:
		return 1 + W*0.5 // unknown: middle treatment
	default:
		return 1 + W*float32(5-q)/4
	}
}

// FairnessIsochrone computes a fairness-adjusted isochrone from (lat, lng).
//
// Algorithm:
//  1. Run Dijkstra from the nearest node up to base_time × (1 + fairnessWeight)
//     (the maximum possible time budget, for Q1 at full fairness weight).
//  2. For each reached node, include it in quintile q's polygon iff
//     travel_time ≤ base_time × quintileMultiplier(q, fairnessWeight).
//  3. Build per-quintile polygons and a standard (W=0) baseline polygon.
//
// When g.Deprivation is nil, only the standard polygon is populated.
func FairnessIsochrone(g *Graph, lat, lng float64, limitSecs float32, fairnessWeight float32) FairnessResult {
	// Clamp fairness weight.
	fairnessWeight = float32(clampFairnessWeight(float64(fairnessWeight)))

	// Max exploration limit: for Q1 at full fairness weight, time = base × (1 + W×1.0).
	maxMult := float32(1.0) + fairnessWeight
	maxLimit := limitSecs * maxMult

	// ── Dijkstra ──────────────────────────────────────────────────────────────
	origin := nearestDriveNode(g, lat, lng)
	if origin == noNode {
		return FairnessResult{FairnessScore: -1}
	}

	startLat := float64(g.Nodes[origin].Lat)
	startLng := float64(g.Nodes[origin].Lng)
	maxReachM := driveMaxSpeedMS * float64(maxLimit)

	dist := make(map[NodeID]float32, 4096)
	start := driveStartupSecs
	dist[origin] = start
	pqueue := &pq{}
	heap.Push(pqueue, &item{id: origin, cost: start})

	for pqueue.Len() > 0 {
		cur := heap.Pop(pqueue).(*item)
		if cur.cost > dist[cur.id] {
			continue
		}
		if cur.cost > maxLimit {
			break
		}
		for _, e := range g.EdgesFrom(cur.id) {
			nc := cur.cost + e.Sec()
			if nc > maxLimit {
				continue
			}
			n := g.Nodes[e.To]
			if haversineM(startLat, startLng, float64(n.Lat), float64(n.Lng)) > maxReachM {
				continue
			}
			if prev, seen := dist[e.To]; !seen || nc < prev {
				dist[e.To] = nc
				heap.Push(pqueue, &item{id: e.To, cost: nc})
			}
		}
	}

	return fairnessFromReached(g, origin, dist, limitSecs, fairnessWeight)
}

// fairnessFromReached is everything after the reached-set: quintile
// partitioning, polygons and the score. Split out so the reach engine can
// supply the arrivals from a label query instead of the full-graph Dijkstra -
// the weighting logic itself has exactly one implementation either way.
func fairnessFromReached(g *Graph, origin NodeID, dist map[NodeID]float32, limitSecs float32, fairnessWeight float32) FairnessResult {
	// ── Partition nodes ───────────────────────────────────────────────────────
	standardNodes := make(map[NodeID]float32, len(dist))
	var qNodes [6]map[NodeID]float32
	for i := range qNodes {
		qNodes[i] = make(map[NodeID]float32)
	}

	for id, t := range dist {
		// Standard: within base time regardless of quintile.
		if t <= limitSecs {
			standardNodes[id] = t
		}
		// Per-quintile: within quintile-specific extended time.
		qv := g.QuintileOf(id)
		mult := quintileMultiplier(qv, fairnessWeight)
		if t <= limitSecs*mult {
			q := int(qv)
			if q >= 1 && q <= 5 {
				qNodes[q][id] = t
			}
		}
	}

	// ── Build polygons ────────────────────────────────────────────────────────
	res := NetworkResolution(g, standardNodes)

	result := FairnessResult{
		Standard:      IsochronePolygon(g, standardNodes, res),
		NodesTouched:  len(dist),
		FairnessScore: -1,
		SnapLat:       g.Nodes[origin].Lat,
		SnapLng:       g.Nodes[origin].Lng,
	}

	if g.Deprivation == nil {
		return result
	}

	deprivedCount, totalTagged := 0, 0
	for q := 1; q <= 5; q++ {
		count := len(qNodes[q])
		if count == 0 {
			continue
		}
		totalTagged += count
		if q <= 3 {
			deprivedCount += count
		}

		mult := quintileMultiplier(Quintile(q), fairnessWeight)
		qr := QuintileResult{
			Count:         count,
			TimeBudgetMin: limitSecs * mult / 60,
		}
		poly := IsochronePolygon(g, qNodes[q], res)
		if len(poly.Geometry.Coordinates[0]) >= 4 {
			qr.Polygon = &poly
		}
		qr.Islands = IsochroneIslands(g, qNodes[q], res)
		result.Quintiles[q] = qr
	}

	if totalTagged > 0 {
		result.FairnessScore = float32(deprivedCount) / float32(totalTagged)
	}

	return result
}

// clampFairnessWeight holds the fairness weight to [0,1]. One place, so the isochrone endpoint
// and the ripple overflow lane cannot drift apart on what an out-of-range weight means.
func clampFairnessWeight(w float64) float64 {
	if w < 0 {
		return 0
	}
	if w > 1 {
		return 1
	}
	return w
}
