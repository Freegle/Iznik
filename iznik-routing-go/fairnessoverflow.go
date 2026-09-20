package main

// The demographic-fairness overflow lane.
//
// Measured on live 2026-08-14, the reach system under-serves the most deprived quintile: members
// in IMD Q1 are reached by about 457 posts per 30 days against roughly 574 for every other
// quintile, while membership itself is flat across quintiles (Q1 20.2%, Q5 19.6%).
//
// Two things about that deficit shape this design, and both are counter-intuitive:
//
//  1. It is NOT caused by the audience cap. Splitting reaches by whether the cap bound: capped
//     reaches are at parity (Q1 20.7% against a 20.2% population share), while reaches that ran
//     their full course to the travel-time ceiling sit at Q1 11.9%. The deficit lives entirely
//     in the UNCAPPED reaches. Widening the cap would not touch it. So this lane applies only
//     where the cap did NOT bind, and the rural lane (ruraloverflow.go) takes the other case.
//     The two never apply to the same post.
//
//  2. It is a Q1-specific knee, not a deprivation gradient. Q2, Q3, Q4 and Q5 sit within about
//     7% of each other. A straight-line multiplier across all five bands would therefore spend
//     most of its stretch on quintiles the data says are already fine.
//
// The cause is geography rather than any governor: ceiling-bound reaches are mostly rural-origin
// posts, and rural origins are themselves disproportionately Q4/Q5 because IMD composite scoring
// rarely marks rural areas as most-deprived. A 45-minute isochrone from a rural origin does not
// reach many Q1 members because few live within reach of it. Stretching the budget for deprived
// RECIPIENTS is the lever that addresses that directly.
//
// Like the rural lane this changes nothing that is pushed: no extra mail, no extra group copies.
// It only lets a member who goes looking find a post.

// fairnessOverflowResult carries the stretched rings, one per quintile that earns a stretch.
type fairnessOverflowResult struct {
	// Rings is keyed by quintile ("1".."4"). Q5's multiplier is always 1.0, so it never earns
	// a ring: it is already fully covered by the committed reach.
	Rings map[string]*GeoJSONPolygon
	// BudgetMinutes is the widest stretched budget actually routed, for cost accounting and so
	// the stored row can record what was done rather than what was configured.
	BudgetMinutes float64
}

// fairnessOverflowRings runs ONE extra Dijkstra to the most generous stretched budget and slices
// a ring per eligible quintile out of it.
//
// maxQuintile is how far down the deprivation scale the stretch reaches. The default the batch
// should send is 1, i.e. Q1 only, for two independent reasons that happen to agree:
//
//   - the measured deficit is a Q1 knee, so stretching Q2-Q4 spends budget where the data shows
//     no shortfall, and
//   - Q1-only needs ONE polygon rather than four, and polygon building is the expensive part
//     (about 2.5s for a 45-minute ring on the UK graph, more once stretched).
//
// Pass a higher maxQuintile to get the full linear gradient instead; that is a values judgement
// about who deserves extra reach, so it is a parameter rather than a decision baked in here.
//
// Returns nil when the weight is zero or nothing drawable came back, so the caller omits the
// field entirely and the response stays byte-identical with the feature off.
func fairnessOverflowRings(g *Graph, lat, lng float64, ceilingMinutes, weight float64, maxQuintile int) *fairnessOverflowResult {
	w := float32(clampFairnessWeight(weight))
	if w <= 0 {
		return nil
	}
	if maxQuintile < 1 {
		maxQuintile = 1
	}
	if maxQuintile > 4 {
		maxQuintile = 4 // Q5's multiplier is 1.0; a "stretch" of 1.0 is the committed reach
	}

	// The widest budget any eligible quintile earns. Q1 has the largest multiplier, so routing
	// to Q1's budget once gives every narrower ring for free by filtering the same node set.
	widest := ceilingMinutes * float64(quintileMultiplier(1, w))
	iso := Isochrone(g, lat, lng, float32(widest*60))
	if len(iso.ReachedNodes) == 0 {
		return nil
	}
	res := NetworkResolution(g, iso.ReachedNodes)

	out := make(map[string]*GeoJSONPolygon, maxQuintile)
	for q := 1; q <= maxQuintile; q++ {
		budget := ceilingMinutes * float64(quintileMultiplier(Quintile(q), w))
		if budget <= ceilingMinutes {
			continue // no stretch, so already covered by the committed reach
		}
		limitSecs := float32(budget * 60)

		// A node belongs to quintile q's ring when it is within q's stretched budget. The
		// node's OWN quintile is deliberately not consulted: this ring is "where a member of
		// quintile q may be admitted from", so it has to be a contiguous area a member can
		// actually be standing in, not the scattered subset of roads that happen to sit in a
		// q-classified LSOA. (fairness.go's /v1/fairness endpoint does the latter, which is
		// why its per-quintile polygons come back as thousands of disconnected islands.)
		filtered := make(map[NodeID]float32, len(iso.ReachedNodes))
		for nid, t := range iso.ReachedNodes {
			if t <= limitSecs {
				filtered[nid] = t
			}
		}
		if len(filtered) == 0 {
			continue
		}
		poly := IsochronePolygon(g, filtered, res)
		if len(poly.Geometry.Coordinates) == 0 || len(poly.Geometry.Coordinates[0]) < 4 {
			continue
		}
		p := poly
		out[quintileKey(q)] = &p
	}

	if len(out) == 0 {
		return nil
	}
	return &fairnessOverflowResult{Rings: out, BudgetMinutes: widest}
}

// quintileKey is the JSON key for a quintile's ring: "1" is most deprived.
func quintileKey(q int) string {
	return string(rune('0' + q))
}
