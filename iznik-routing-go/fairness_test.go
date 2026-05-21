package main

import (
	"testing"
)

func TestFairnessIsochrone_NoDeprivation(t *testing.T) {
	g := makeTestGrid(nil)
	// Without deprivation data, should still return a standard polygon.
	res := FairnessIsochrone(g, 51.4545, -2.5879, 15*60, Walk, 0.5)
	if len(res.Standard.Geometry.Coordinates[0]) < 4 {
		t.Error("expected non-empty standard polygon even without deprivation data")
	}
	if res.FairnessScore != -1 {
		t.Errorf("expected FairnessScore=-1 without deprivation data, got %f", res.FairnessScore)
	}
}

func TestFairnessIsochrone_ZeroWeight(t *testing.T) {
	idx := LoadDeprivation(sampleLSOACSV)
	g := makeTestGrid(idx)
	// W=0 should match standard isochrone exactly.
	standard := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	fair := FairnessIsochrone(g, 51.4545, -2.5879, 15*60, Walk, 0.0)

	if len(fair.Standard.Geometry.Coordinates[0]) < 4 {
		t.Error("expected non-empty standard polygon at W=0")
	}
	// NodesTouched should equal the standard reach at W=0.
	if fair.NodesTouched != len(standard.ReachedNodes) {
		t.Errorf("at W=0, NodesTouched=%d but standard reach=%d",
			fair.NodesTouched, len(standard.ReachedNodes))
	}
}

func TestFairnessIsochrone_FullWeight(t *testing.T) {
	idx := LoadDeprivation(sampleLSOACSV)
	g := makeTestGrid(idx)
	// W=1 should explore 2× the base time — more nodes than W=0.
	r0 := FairnessIsochrone(g, 51.4545, -2.5879, 15*60, Walk, 0.0)
	r1 := FairnessIsochrone(g, 51.4545, -2.5879, 15*60, Walk, 1.0)

	if r1.NodesTouched <= r0.NodesTouched {
		t.Errorf("W=1 should explore more nodes than W=0: %d vs %d",
			r1.NodesTouched, r0.NodesTouched)
	}
	t.Logf("W=0 touched=%d  W=1 touched=%d  fairness_score=%.2f",
		r0.NodesTouched, r1.NodesTouched, r1.FairnessScore)
}

func TestFairnessIsochrone_Q1GetsMoreTime(t *testing.T) {
	idx := LoadDeprivation(sampleLSOACSV)
	g := makeTestGrid(idx)
	const base = 15 * 60
	r := FairnessIsochrone(g, 51.4545, -2.5879, base, Walk, 1.0)

	// Q1 time budget should be 2× base; Q5 should equal base.
	const eps = 0.01
	if q1 := r.Quintiles[1]; q1.Count > 0 {
		want := float32(base*2) / 60
		if abs32(q1.TimeBudgetMin-want) > eps {
			t.Errorf("Q1 budget: want %.2f min, got %.2f", want, q1.TimeBudgetMin)
		}
	}
	if q5 := r.Quintiles[5]; q5.Count > 0 {
		want := float32(base) / 60
		if abs32(q5.TimeBudgetMin-want) > eps {
			t.Errorf("Q5 budget: want %.2f min, got %.2f", want, q5.TimeBudgetMin)
		}
	}
}

func abs32(x float32) float32 {
	if x < 0 {
		return -x
	}
	return x
}

func TestQuintileMultiplier(t *testing.T) {
	cases := []struct {
		q    Quintile
		W    float32
		want float32
	}{
		{1, 0.0, 1.00}, // W=0: no adjustment
		{5, 0.0, 1.00},
		{1, 1.0, 2.00}, // W=1, Q1: 2× budget
		{5, 1.0, 1.00}, // W=1, Q5: unchanged
		{3, 1.0, 1.50}, // W=1, Q3: 1.5×
		{2, 0.5, 1.375},
	}
	for _, tc := range cases {
		got := quintileMultiplier(tc.q, tc.W)
		if abs32(got-tc.want) > 0.001 {
			t.Errorf("quintileMultiplier(Q%d, W=%.1f) = %.3f, want %.3f",
				tc.q, tc.W, got, tc.want)
		}
	}
}
