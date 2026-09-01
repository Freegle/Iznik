package main

import (
	"math"
	"testing"
)

// The engine-materialised isochrone must equal the flat full-graph search:
// same reached set (up to float noise at the exact time boundary) and the
// same arrival at every node. This is what lets catchment polygons, bands,
// bounds and the fairness weighting move onto the engine without behaviour
// change.
func TestEngineReachedNodesMatchesFlatIsochrone(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	const lat, lng = 51.4545, -2.5879
	const secs = float32(12 * 60)

	flat := Isochrone(g, lat, lng, secs)
	lbl := eng.QueryLabels(lat, lng, secs)
	engineReached := eng.ReachedNodes(lbl, secs)

	compareReached(t, flat.ReachedNodes, engineReached, secs, "point")
}

func TestEngineMultiSourceMatchesFlat(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	// A handful of junction seeds spread around Bristol, as a group boundary
	// would produce.
	coords := [][2]float64{
		{51.4545, -2.5879}, {51.47, -2.60}, {51.44, -2.55}, {51.46, -2.62},
	}
	seeds := make([]NodeID, 0, len(coords))
	for _, c := range coords {
		if v := nearestDriveNode(g, c[0], c[1]); v != noNode {
			seeds = append(seeds, v)
		}
	}
	if len(seeds) < 3 {
		t.Fatalf("only %d seeds snapped", len(seeds))
	}
	const secs = float32(10 * 60)

	flat := multiSourceIsochrone(g, seeds, secs)

	prev := reachLive
	reachLive = eng
	defer func() { reachLive = prev }()
	engineIso := engineOrFlatMultiSource(g, seeds, secs)

	compareReached(t, flat.ReachedNodes, engineIso.ReachedNodes, secs, "multi")
}

func TestEngineFairnessMatchesFlat(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)

	const lat, lng = 51.4545, -2.5879
	const limitSecs = float32(10 * 60)
	const weight = float32(0.5)

	flat := FairnessIsochrone(g, lat, lng, limitSecs, weight)

	maxLimit := limitSecs * (1 + weight)
	origin := nearestDriveNode(g, lat, lng)
	lbl := eng.QueryLabelsFromNode(origin, maxLimit)
	reached := eng.ReachedNodes(lbl, maxLimit)
	engineRes := fairnessFromReached(g, origin, reached, limitSecs, weight)

	// The reached-set sizes must agree to within boundary float noise.
	if flat.NodesTouched == 0 {
		t.Fatal("flat fairness reached nothing")
	}
	diff := math.Abs(float64(engineRes.NodesTouched - flat.NodesTouched))
	if diff/float64(flat.NodesTouched) > 0.001 {
		t.Fatalf("nodes touched: engine %d vs flat %d", engineRes.NodesTouched, flat.NodesTouched)
	}
	if flat.FairnessScore >= 0 || engineRes.FairnessScore >= 0 {
		if math.Abs(float64(engineRes.FairnessScore-flat.FairnessScore)) > 0.01 {
			t.Fatalf("fairness score: engine %f vs flat %f", engineRes.FairnessScore, flat.FairnessScore)
		}
	}
}

// compareReached asserts set equality away from the exact time boundary and
// arrival equality (relative float tolerance) on the intersection.
func compareReached(t *testing.T, flat, engine map[NodeID]float32, limit float32, tag string) {
	t.Helper()
	const bnd = float32(1.0) // seconds from the limit inside which inclusion may flip on float noise
	tol := func(want float32) float32 { return 0.01 + 1e-5*want }

	missing, extra, worst := 0, 0, float32(0)
	for id, want := range flat {
		got, ok := engine[id]
		if !ok {
			if limit-want > bnd {
				missing++
			}
			continue
		}
		if d := float32(math.Abs(float64(got - want))); d > tol(want) {
			if d > worst {
				worst = d
			}
			missing++ // arrival mismatch counts as a failure too
		}
	}
	for id, got := range engine {
		if _, ok := flat[id]; !ok && limit-got > bnd {
			extra++
		}
	}
	if missing > 0 || extra > 0 {
		t.Fatalf("%s: %d missing/mismatched, %d extra (flat %d, engine %d, worst arrival diff %.3fs)",
			tag, missing, extra, len(flat), len(engine), worst)
	}
	if len(engine) == 0 {
		t.Fatalf("%s: engine reached nothing", tag)
	}
}
