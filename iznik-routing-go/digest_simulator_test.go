package main

import (
	"math"
	"testing"
)

// Reference environment for the scoring tests: 30-minute max drive, 24-hour
// digest window, 25 (minute-equivalent) eyeballs-budget decay.  These match
// the simulator's documented defaults.
var refEnv = digestSimEnv{MaxMinutes: 30, WindowH: 24, BudgetDecay: 25}

// Equal weights on all four signals, so each component contributes 1× its
// raw value to the total.  Lets us check component math by reading Total.
var equalW = digestSimWeights{Closeness: 1, Freshness: 1, Budget: 1, Anchor: 1}

func approx(t *testing.T, name string, got, want, tol float64) {
	t.Helper()
	if math.Abs(got-want) > tol {
		t.Errorf("%s: got %f, want ≈%f (±%f)", name, got, want, tol)
	}
}

func TestScoreDigestPost_ClosenessAtOrigin(t *testing.T) {
	// 0 minutes away → closeness should be 1.0 (maximum).
	s := scoreDigestPost(0, 0, 0, 0, false, equalW, refEnv)
	approx(t, "close@0", s.Close, 1.0, 1e-9)
}

func TestScoreDigestPost_ClosenessAtMaxDrive(t *testing.T) {
	// Exactly at the boundary → closeness drops to 0.
	s := scoreDigestPost(refEnv.MaxMinutes, 0, 0, 0, false, equalW, refEnv)
	approx(t, "close@max", s.Close, 0.0, 1e-9)
}

func TestScoreDigestPost_ClosenessClampedAtZero(t *testing.T) {
	// Beyond the boundary the raw formula would go negative; we clamp.
	s := scoreDigestPost(refEnv.MaxMinutes*2, 0, 0, 0, false, equalW, refEnv)
	approx(t, "close@2×max", s.Close, 0.0, 1e-9)
}

func TestScoreDigestPost_FreshnessBoundary(t *testing.T) {
	// At the window edge (24h) freshness is 0; halfway through is 0.5.
	s := scoreDigestPost(0, refEnv.WindowH, 0, 0, false, equalW, refEnv)
	approx(t, "fresh@window", s.Fresh, 0.0, 1e-9)
	s = scoreDigestPost(0, refEnv.WindowH/2, 0, 0, false, equalW, refEnv)
	approx(t, "fresh@half", s.Fresh, 0.5, 1e-9)
}

func TestScoreDigestPost_FreshnessClampedAtZero(t *testing.T) {
	// A post older than the window keeps a non-negative score (we don't
	// drop it, but its freshness contribution is zero).
	s := scoreDigestPost(0, refEnv.WindowH*2, 0, 0, false, equalW, refEnv)
	approx(t, "fresh@2×window", s.Fresh, 0.0, 1e-9)
}

func TestScoreDigestPost_BudgetFavoursLowEngagement(t *testing.T) {
	// Two posts, same age, different view counts.  The rarer one (fewer
	// views) should have the higher budget score — that's the whole
	// point of the eyeballs-budget signal.
	rare := scoreDigestPost(0, 5, 0, 0, false, equalW, refEnv)
	popular := scoreDigestPost(0, 5, 100, 0, false, equalW, refEnv)
	if rare.Budget <= popular.Budget {
		t.Errorf("rare post (budget=%f) should rank higher than popular (budget=%f)", rare.Budget, popular.Budget)
	}
	// And the rare post with zero engagement should be near 1.0 (exp(0) = 1).
	approx(t, "budget@rare", rare.Budget, 1.0, 1e-9)
}

func TestScoreDigestPost_BudgetTreatsRepliesAs3xViews(t *testing.T) {
	// 1 reply should count as 3 views for the engagement rate — replies
	// are a stronger signal than passive views.
	threeViews := scoreDigestPost(0, 5, 3, 0, false, equalW, refEnv)
	oneReply := scoreDigestPost(0, 5, 0, 1, false, equalW, refEnv)
	approx(t, "3 views ≈ 1 reply for budget", threeViews.Budget, oneReply.Budget, 1e-9)
}

func TestScoreDigestPost_BudgetIsTimeAware(t *testing.T) {
	// An older post with the same raw engagement should rank HIGHER on
	// budget (its per-hour rate is lower).  This is the rate-not-total
	// behaviour documented in the function.
	young := scoreDigestPost(0, 2, 10, 0, false, equalW, refEnv)
	old := scoreDigestPost(0, 18, 10, 0, false, equalW, refEnv)
	if old.Budget <= young.Budget {
		t.Errorf("older post (budget=%f) should rank higher than younger post (budget=%f) at equal raw engagement", old.Budget, young.Budget)
	}
}

func TestScoreDigestPost_BudgetAgeClampedAt1Hour(t *testing.T) {
	// Brand-new posts (age < 1h) should not all collapse to the same
	// runaway-low budget score.  Clamp at 1h means a 0.1h-old post and a
	// 1.0h-old post with the same engagement get the same rate.
	infant := scoreDigestPost(0, 0.1, 10, 0, false, equalW, refEnv)
	oneH := scoreDigestPost(0, 1.0, 10, 0, false, equalW, refEnv)
	approx(t, "budget@0.1h == budget@1h (clamped)", infant.Budget, oneH.Budget, 1e-9)
}

func TestScoreDigestPost_AnchorOnlyForHomeGroup(t *testing.T) {
	cross := scoreDigestPost(0, 0, 0, 0, false, equalW, refEnv)
	home := scoreDigestPost(0, 0, 0, 0, true, equalW, refEnv)
	approx(t, "anchor@cross", cross.Anchor, 0.0, 1e-9)
	approx(t, "anchor@home", home.Anchor, 1.0, 1e-9)
}

func TestScoreDigestPost_TotalIsWeightedSum(t *testing.T) {
	// Custom weights so we can verify the linear combination.
	w := digestSimWeights{Closeness: 2, Freshness: 0.5, Budget: 1.5, Anchor: 0.25}
	s := scoreDigestPost(15, 12, 0, 0, true, w, refEnv)
	want := w.Closeness*s.Close + w.Freshness*s.Fresh + w.Budget*s.Budget + w.Anchor*s.Anchor
	approx(t, "total = weighted sum", s.Total, want, 1e-9)
}

func TestScoreDigestPost_ZeroWeightDisablesSignal(t *testing.T) {
	// A signal with weight 0 should not contribute to the total even
	// when its component is non-zero.
	closeOnly := digestSimWeights{Closeness: 1, Freshness: 0, Budget: 0, Anchor: 0}
	s := scoreDigestPost(0, 0, 0, 0, true, closeOnly, refEnv) // every component > 0
	approx(t, "total = close-only", s.Total, s.Close, 1e-9)
}

func TestScoreDigestPost_AllZeroWeightsGivesZeroTotal(t *testing.T) {
	z := digestSimWeights{}
	s := scoreDigestPost(0, 0, 0, 0, true, z, refEnv)
	approx(t, "total@zero-weights", s.Total, 0.0, 1e-9)
}
