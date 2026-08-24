package main

import (
	"testing"
)

// The fairness lane stretches the travel-time budget for deprived RECIPIENTS, on the reaches
// where the measured Q1 deficit actually lives (the ones the audience cap never bound).

func TestFairnessOverflow_OffAtZeroWeight(t *testing.T) {
	g := getTestGraph(t)
	for _, w := range []float64{0, -1} {
		if got := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 45, w, 1); got != nil {
			t.Errorf("weight %g produced %d rings, expected none", w, len(got.Rings))
		}
	}
}

// The default is Q1 only. That is both what the data supports (a Q1 knee, with Q2-Q5 within
// about 7% of each other) and what keeps the lane affordable, since it needs one polygon rather
// than four.
func TestFairnessOverflow_DefaultsToQ1Only(t *testing.T) {
	g := getTestGraph(t)
	res := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 10, 1, 1)
	if res == nil {
		t.Fatal("no rings at weight 1")
	}
	if len(res.Rings) != 1 {
		t.Errorf("expected exactly one ring for maxQuintile=1, got %d: %v", len(res.Rings), keysOfRings(res.Rings))
	}
	if _, ok := res.Rings["1"]; !ok {
		t.Errorf("expected a ring for quintile 1, got %v", keysOfRings(res.Rings))
	}
}

// Asking for the full gradient gives one ring per stretched quintile, and they must nest: a Q1
// member is admitted anywhere a Q4 member would be.
func TestFairnessOverflow_GradientNestsByQuintile(t *testing.T) {
	g := getTestGraph(t)
	res := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 5, 1, 4)
	if res == nil {
		t.Fatal("no rings")
	}
	if len(res.Rings) != 4 {
		t.Fatalf("expected 4 rings for maxQuintile=4, got %d: %v", len(res.Rings), keysOfRings(res.Rings))
	}

	prev := 0.0
	for q := 4; q >= 1; q-- {
		ring := res.Rings[quintileKey(q)]
		if ring == nil {
			t.Fatalf("missing ring for quintile %d", q)
		}
		a := math_absShoelace(ring.Geometry.Coordinates[0])
		if a < prev {
			t.Errorf("quintile %d ring (%g) is smaller than the less-deprived one before it (%g)", q, a, prev)
		}
		prev = a
	}
}

// Q5 earns no stretch at any weight, so it must never get a ring: its budget is the committed
// reach, which already covers it.
func TestFairnessOverflow_Q5NeverEarnsARing(t *testing.T) {
	g := getTestGraph(t)
	res := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 5, 1, 5)
	if res == nil {
		t.Fatal("no rings")
	}
	if _, present := res.Rings["5"]; present {
		t.Error("quintile 5 got a ring despite a multiplier of 1.0")
	}
}

// The stretch has to actually stretch: a higher weight must route further, or the lane is doing
// nothing while costing a Dijkstra.
func TestFairnessOverflow_HigherWeightReachesFurther(t *testing.T) {
	g := getTestGraph(t)
	var last float64
	for _, w := range []float64{0.25, 0.5, 1.0} {
		res := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 5, w, 1)
		if res == nil {
			t.Fatalf("no rings at weight %g", w)
		}
		if res.BudgetMinutes <= last {
			t.Errorf("weight %g routed to %g minutes, not more than the %g at the previous weight",
				w, res.BudgetMinutes, last)
		}
		last = res.BudgetMinutes
	}
	// W=1 gives Q1 a 2.0x multiplier, so a 5-minute ceiling must route to 10.
	if last != 10 {
		t.Errorf("expected a 10 minute budget at weight 1 from a 5 minute ceiling, got %g", last)
	}
}

// Out-of-range weights are clamped rather than trusted, through the same helper the /v1/fairness
// endpoint uses, so the two cannot drift apart.
func TestFairnessOverflow_ClampsWeightAboveOne(t *testing.T) {
	g := getTestGraph(t)
	atOne := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 5, 1.0, 1)
	way := fairnessOverflowRings(g, 51.4545, -2.5879, Drive, 5, 99.0, 1)
	if atOne == nil || way == nil {
		t.Fatal("expected rings at both weights")
	}
	if atOne.BudgetMinutes != way.BudgetMinutes {
		t.Errorf("weight 99 was not clamped to 1: %g vs %g", way.BudgetMinutes, atOne.BudgetMinutes)
	}
}

func TestClampFairnessWeight(t *testing.T) {
	cases := map[float64]float64{-5: 0, 0: 0, 0.5: 0.5, 1: 1, 7: 1}
	for in, want := range cases {
		if got := clampFairnessWeight(in); got != want {
			t.Errorf("clampFairnessWeight(%g) = %g, want %g", in, got, want)
		}
	}
}

func keysOfRings(m map[string]*GeoJSONPolygon) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
