package main

import (
	"testing"
)

// The point of serving a tick from stored labels is that it is the SAME answer, arrived at
// without the graph sweep that has to be rationed. These pin that: if the label path and
// the live search ever disagree, expansion would quietly start reaching a different set of
// people, which is the one thing this change must not do.

// tickOrigin is a Bristol point with plenty of network around it in every direction.
const tickOriginLat, tickOriginLng = 51.4545, -2.5879

// TestReachTickReachedNodesMatchTheLiveSearch is the parity bar in its most direct form:
// the set of places recovered from a stored blob is the set the full search finds, because
// every answer this endpoint gives is derived from it.
//
// The SET is compared, not the arrival times. The tick path never reads an arrival: the
// outline is traced from which places are in the set, and the group threshold is applied at
// the same budget on both sides, so every place in either set is within the budget by
// construction. The engine does overestimate some arrivals against the flat search at
// larger budgets - about 12% of them at 900s, worst case ~115s, and identically so whether
// the labels were stored at 900s or 1800s - which is a property of the engine rather than
// of storing labels, and one nothing here depends on. compareReached, which does check
// arrivals, is therefore the wrong bar for this path.
func TestReachTickReachedNodesMatchTheLiveSearch(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	// Store the labels the way a post does at creation, then read them back - so the test
	// exercises the store-and-read-back round trip and not just an in-memory labeling.
	const stored = float32(30 * 60)
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, stored))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	// Every budget this endpoint will serve, up to the stored ceiling. Shorter ones are
	// refused rather than answered approximately - see reachTickMinBudgetSeconds.
	for _, secs := range []float32{reachTickMinBudgetSeconds, 20 * 60, stored} {
		fromLabels := eng.ReachedNodes(lbl, secs)
		fromSearch := Isochrone(g, tickOriginLat, tickOriginLng, secs, Drive).ReachedNodes

		var absent, extra int
		for id := range fromSearch {
			if _, ok := fromLabels[id]; !ok {
				absent++
			}
		}
		for id := range fromLabels {
			if _, ok := fromSearch[id]; !ok {
				extra++
			}
		}
		if absent > 0 || extra > 0 {
			t.Fatalf("at %.0fs: %d places the search reaches are missing from the labels, %d are claimed that it does not (search %d, labels %d)",
				secs, absent, extra, len(fromSearch), len(fromLabels))
		}
		if len(fromLabels) == 0 {
			t.Fatalf("at %.0fs the labels reached nothing", secs)
		}
	}
}

// TestReachTickGeometryCoversTheLiveSearch checks the property expansion actually depends
// on: the outline derived from stored labels contains everywhere the live search reaches.
// That is what makes it safe as the group prefilter - a shape that covered less could drop
// a group the exact path would have found.
//
// Asserted over reached NODES rather than outline vertices. The two reached sets differ by
// float noise at the very edge (compareReached allows a second of slack there for exactly
// this reason), and at coarse resolution a single boundary node moves the traced outline by
// a whole cell - so comparing vertices tests the noise, while comparing coverage tests the
// guarantee.
func TestReachTickGeometryCoversTheLiveSearch(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	const secs = reachTickMinBudgetSeconds
	const boundary = float32(1.0) // matches compareReached's own slack
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, 30*60))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	poly, bounds, res := CoarseCatchment(g, eng.ReachedNodes(lbl, secs))
	ring := ringOf(poly)
	if len(ring) < 4 {
		t.Fatalf("label-derived outline has no usable ring (%d points)", len(ring))
	}
	if bounds.Outer == nil {
		t.Fatal("no outer bound from the label path; the containment queries need one")
	}
	outer := ringOf(*bounds.Outer)

	var missedByOutline, missedByOuter, checked int
	for id, arr := range Isochrone(g, tickOriginLat, tickOriginLng, secs, Drive).ReachedNodes {
		if secs-arr < boundary {
			continue // within float noise of the budget: legitimately either side
		}
		checked++
		n := g.Nodes[id]
		if !pointInRingWithin(ring, float64(n.Lng), float64(n.Lat), res) {
			missedByOutline++
		}
		if !pointInRingWithin(outer, float64(n.Lng), float64(n.Lat), res) {
			missedByOuter++
		}
	}

	if checked == 0 {
		t.Fatal("no nodes to check; the test would pass vacuously")
	}
	if missedByOutline > 0 {
		t.Fatalf("%d of %d live-search nodes fall outside the label-derived outline", missedByOutline, checked)
	}
	if missedByOuter > 0 {
		t.Fatalf("%d of %d live-search nodes fall outside the outer bound", missedByOuter, checked)
	}
}

// TestReachTickGroupsMatchTheLiveSearch is the parity bar the brief sets for cutover: the
// group set decided from stored labels has to be the set the live search decides, by the
// same member-snapping rule.
//
// Members within a second of the budget are excluded, which is not a fudge - it is the
// same rule the engine's own parity check applies (compareReached: "seconds from the limit
// inside which inclusion may flip on float noise"). A node whose arrival is within float
// noise of the limit legitimately falls either side, so pinning group membership on one
// would test the noise rather than the decision. Everything comfortably inside must be in
// both sets and everything comfortably outside in neither, which is what targeting depends
// on.
func TestReachTickGroupsMatchTheLiveSearch(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	const secs = reachTickMinBudgetSeconds
	const boundary = float32(1.0) // matches compareReached's own slack
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, 30*60))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	// The full reach, so members can be placed both inside and outside the tick budget.
	full := Isochrone(g, tickOriginLat, tickOriginLng, 30*60, Drive).ReachedNodes

	// One member per group, spread across the reach, skipping the boundary band.
	var members []memberLoc
	var seq, gid int64
	inside := map[int64]bool{}
	for id, arr := range full {
		seq++
		if seq%25 != 0 {
			continue // thin it out; the full set is tens of thousands
		}
		if d := arr - secs; d > -boundary && d < boundary {
			continue // within float noise of the budget: legitimately either side
		}
		gid++
		n := g.Nodes[id]
		members = append(members, memberLoc{groupID: gid, lat: float64(n.Lat), lng: float64(n.Lng)})
		if arr < secs {
			inside[gid] = true
		}
	}
	if len(inside) < 10 {
		t.Fatalf("fixture produced only %d members inside the budget", len(inside))
	}

	searchIDs := groupIDsWithinSeconds(snapMembers(g, Isochrone(g, tickOriginLat, tickOriginLng, secs, Drive).ReachedNodes, members, Drive), secs)
	labelIDs := groupIDsWithinSeconds(snapMembers(g, eng.ReachedNodes(lbl, secs), members, Drive), secs)

	if len(searchIDs) != len(labelIDs) {
		t.Fatalf("group sets differ in size: search %d, labels %d", len(searchIDs), len(labelIDs))
	}
	for i := range searchIDs {
		if searchIDs[i] != labelIDs[i] {
			t.Fatalf("group sets differ at %d: search %d, labels %d", i, searchIDs[i], labelIDs[i])
		}
	}
	if len(searchIDs) == 0 {
		t.Fatal("neither path reached any group; the test would pass vacuously")
	}

	// And the set is the right one, not merely the same one twice: every group whose
	// member sits comfortably inside the budget is targeted.
	got := map[int64]bool{}
	for _, id := range labelIDs {
		got[id] = true
	}
	for id := range inside {
		if !got[id] {
			t.Fatalf("group %d has a member well inside the budget but was not targeted", id)
		}
	}
}

// TestReachTickRefusesBudgetsItCannotAnswerExactly pins the floor and, more importantly,
// the fact that it is needed: below it the label expansion under-reaches, and a reach that
// is too small silently drops groups. The floor is measured, not guessed - the numbers are
// in the comment on reachTickMinBudgetSeconds.
func TestReachTickRefusesBudgetsItCannotAnswerExactly(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, 45*60))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	// Below the floor the label path really does lose ground, which is why it is refused.
	const short = float32(5 * 60)
	flat := Isochrone(g, tickOriginLat, tickOriginLng, short, Drive).ReachedNodes
	lab := eng.ReachedNodes(lbl, short)
	if len(lab) >= len(flat) {
		t.Fatalf("the floor assumes the label path under-reaches at %.0fs, but it reached %d against the search's %d - re-measure it",
			short, len(lab), len(flat))
	}

	// At the floor it does not.
	atFloor := Isochrone(g, tickOriginLat, tickOriginLng, reachTickMinBudgetSeconds, Drive).ReachedNodes
	labFloor := eng.ReachedNodes(lbl, reachTickMinBudgetSeconds)
	for id := range atFloor {
		if _, ok := labFloor[id]; !ok {
			t.Fatalf("the label path misses a node the search reaches at the floor (%.0fs); the floor is too low",
				reachTickMinBudgetSeconds)
		}
	}
}

// TestReachTickModeDefaultsToDrive guards a silent wrong answer. parseMode's own default
// is WALK, so a request that omits the mode would snap members to walking nodes and decide
// a different, much smaller group set - with nothing in the response to say so. Rippling is
// a drive-time model throughout.
func TestReachTickModeDefaultsToDrive(t *testing.T) {
	if got := tickMode(""); got != Drive {
		t.Fatalf("an omitted mode resolved to %v, want Drive", got)
	}
	if got := tickMode("walk"); got != Walk {
		t.Fatalf("an explicit walk resolved to %v", got)
	}
	if got := tickMode("cycle"); got != Cycle {
		t.Fatalf("an explicit cycle resolved to %v", got)
	}
	if got := tickMode("drive"); got != Drive {
		t.Fatalf("an explicit drive resolved to %v", got)
	}
}

// TestReachTickClampsToTheStoredBudget pins the cap, and the reason it is needed.
//
// ReachedNodes does not clamp itself: asked for more than the labels were computed for it
// keeps yielding arrivals the original query never relaxed. So a tick that asked beyond the
// stored budget would get an unvalidated reach rather than an error. tickBudget is what
// stops that, so it is tested here alongside the raw behaviour that makes it necessary.
func TestReachTickClampsToTheStoredBudget(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	_, eng := buildBristolEngine(t)

	const stored = float32(10 * 60)
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, stored))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	// The clamp itself.
	beyond := float64(stored * 3)
	within := float64(stored / 2)
	if got := tickBudget(lbl, &beyond); got != lbl.T {
		t.Fatalf("a budget beyond the stored one resolved to %v, want the stored %v", got, lbl.T)
	}
	if got := tickBudget(lbl, &within); got != float32(within) {
		t.Fatalf("a budget inside the stored one resolved to %v, want %v", got, within)
	}
	if got := tickBudget(lbl, nil); got != lbl.T {
		t.Fatalf("no budget resolved to %v, want the stored %v", got, lbl.T)
	}

	// And why it is not optional: unclamped, the reach grows past what was stored.
	atCeiling := eng.ReachedNodes(lbl, lbl.T)
	unclamped := eng.ReachedNodes(lbl, float32(beyond))
	if len(unclamped) <= len(atCeiling) {
		t.Skip("this build clamps internally; the cap in tickBudget is then belt-and-braces")
	}
	clamped := eng.ReachedNodes(lbl, tickBudget(lbl, &beyond))
	if len(clamped) != len(atCeiling) {
		t.Fatalf("clamped reach is %d nodes, want the stored ceiling's %d", len(clamped), len(atCeiling))
	}
}
