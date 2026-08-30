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
// the reached-node set recovered from a stored blob has to be the set the full search
// finds, because every other answer in the response is derived from it.
func TestReachTickReachedNodesMatchTheLiveSearch(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	// Store the labels the way a post does at creation, then read them back - so the test
	// exercises the encode/decode round trip and not just an in-memory labeling.
	const stored = float32(30 * 60)
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, stored))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	// Every tick budget the schedule could ask for, including the stored ceiling.
	for _, secs := range []float32{5 * 60, 12 * 60, 20 * 60, stored} {
		fromLabels := eng.ReachedNodes(lbl, secs)
		fromSearch := Isochrone(g, tickOriginLat, tickOriginLng, secs, Drive).ReachedNodes

		compareReached(t, fromSearch, fromLabels, secs, "tick")
	}
}

// TestReachTickGeometryMatchesTheLiveSearch checks the step expansion actually consumes:
// the same coarse outline and bounds whichever way the reached set was obtained. Compared
// as geometry rather than byte-for-byte, since both come off the same rasterisation.
func TestReachTickGeometryMatchesTheLiveSearch(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	const secs = float32(15 * 60)
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, 30*60))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	labelPoly, labelBounds, labelRes := CoarseCatchment(g, eng.ReachedNodes(lbl, secs))
	searchPoly, _, searchRes := CoarseCatchment(g, Isochrone(g, tickOriginLat, tickOriginLng, secs, Drive).ReachedNodes)

	if labelRes != searchRes {
		t.Fatalf("coarse resolution differs: labels %g, search %g", labelRes, searchRes)
	}

	labelRing, searchRing := ringOf(labelPoly), ringOf(searchPoly)
	if len(labelRing) < 4 || len(searchRing) < 4 {
		t.Fatalf("rings too small: labels %d, search %d", len(labelRing), len(searchRing))
	}

	// Each outline's vertices sit inside the other, to within a cell - i.e. the same
	// shape, not merely a similar area.
	for _, pt := range searchRing {
		if !pointInRingWithin(labelRing, pt[0], pt[1], labelRes) {
			t.Fatalf("the live search reaches %v, which the label-derived outline does not cover", pt)
		}
	}
	for _, pt := range labelRing {
		if !pointInRingWithin(searchRing, pt[0], pt[1], searchRes) {
			t.Fatalf("the label-derived outline claims %v, which the live search does not reach", pt)
		}
	}

	if labelBounds.Outer == nil {
		t.Fatal("no outer bound from the label path; the containment queries need one")
	}
	outer := ringOf(*labelBounds.Outer)
	for _, pt := range searchRing {
		if !pointInRingWithin(outer, pt[0], pt[1], labelRes) {
			t.Fatalf("outer bound from labels fails to contain the live reach at %v", pt)
		}
	}
}

// TestReachTickGroupsMatchTheLiveSearch is the parity bar the brief sets for cutover: the
// group set decided from stored labels has to be the set the live search decides, by the
// same member-snapping rule. Run against synthetic members rather than the groups
// database, so it tests the decision and not the fixture's data.
func TestReachTickGroupsMatchTheLiveSearch(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	const secs = float32(12 * 60)
	blob := eng.EncodeLabels(eng.QueryLabels(tickOriginLat, tickOriginLng, 30*60))
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode stored labels: %v", err)
	}

	fromSearch := Isochrone(g, tickOriginLat, tickOriginLng, secs, Drive).ReachedNodes
	fromLabels := eng.ReachedNodes(lbl, secs)

	// One synthetic member per reached node, in its own group, spread across the reach -
	// so the group decision is exercised at every drive-time in the set rather than at a
	// handful of hand-picked points.
	var members []memberLoc
	var gid int64
	for id := range fromSearch {
		gid++
		if gid%25 != 0 {
			continue // thin it out; the full set is tens of thousands
		}
		n := g.Nodes[id]
		members = append(members, memberLoc{groupID: gid, lat: float64(n.Lat), lng: float64(n.Lng)})
	}
	if len(members) < 10 {
		t.Fatalf("fixture produced only %d members to test with", len(members))
	}

	searchIDs := groupIDsWithinSeconds(snapMembers(g, fromSearch, members, Drive), secs)
	labelIDs := groupIDsWithinSeconds(snapMembers(g, fromLabels, members, Drive), secs)

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
}

// TestReachTickClampsToTheStoredBudget guards the one way a caller could ask for reach
// that was never computed: a tick budget beyond the ceiling the labels were stored at.
// The answer is the stored ceiling, not an error and not a silent extrapolation.
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

	atCeiling := eng.ReachedNodes(lbl, stored)
	beyond := eng.ReachedNodes(lbl, stored*3)

	if len(beyond) != len(atCeiling) {
		t.Fatalf("asking beyond the stored budget changed the reach: %d nodes vs %d at the ceiling",
			len(beyond), len(atCeiling))
	}
}
