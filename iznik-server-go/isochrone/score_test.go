package isochrone

import (
	"math"
	"testing"
)

// refEnv mirrors the reference environment used by iznik-routing-go's
// digest_simulator_test.go / iznik-batch's DigestPostScorerTest: a 24h
// freshness window and a budget-decay of 25 (minute-equivalent units). The
// browse scorer uses a reach RADIUS IN METRES as its closeness denominator
// (rather than a drive-time-in-minutes ceiling), so refReach stands in for
// the reference's refEnv.MaxMinutes.
var refEnv = ScoreEnv{WindowHours: 24, BudgetDecay: 25, DefaultReachM: 30000}

const refReach = 30000.0 // 30km, an arbitrary-but-representative reach radius

// equalW puts a 1x weight on every signal so Total can be read straight off
// the individual components.
var equalW = ScoreWeights{Close: 1, Fresh: 1, Budget: 1, Anchor: 1}

func approxScore(t *testing.T, name string, got, want, tol float64) {
	t.Helper()
	if math.Abs(got-want) > tol {
		t.Errorf("%s: got %f, want ≈%f (±%f)", name, got, want, tol)
	}
}

func TestScore_ClosenessAtOrigin(t *testing.T) {
	// 0 metres away -> closeness is 1.0 (maximum).
	s := Score(0, refReach, 0, 0, 0, false, equalW, refEnv)
	approxScore(t, "close@0", s.Close, 1.0, 1e-9)
}

func TestScore_ClosenessAtReachEdge(t *testing.T) {
	// Exactly at the reach boundary -> closeness drops to 0.
	s := Score(refReach, refReach, 0, 0, 0, false, equalW, refEnv)
	approxScore(t, "close@reach", s.Close, 0.0, 1e-9)
}

func TestScore_ClosenessClampedAtZero(t *testing.T) {
	// Beyond the reach boundary the raw formula would go negative; clamp.
	s := Score(refReach*2, refReach, 0, 0, 0, false, equalW, refEnv)
	approxScore(t, "close@2xreach", s.Close, 0.0, 1e-9)
}

func TestScore_ClosenessZeroReachRadiusIsNoSignal(t *testing.T) {
	// reachRadius <= 0 -> no closeness signal at all (avoid divide-by-zero /
	// nonsensical negative-radius maths), regardless of distance.
	s := Score(100, 0, 0, 0, 0, false, equalW, refEnv)
	approxScore(t, "close@zero-reach", s.Close, 0.0, 1e-9)

	s2 := Score(0, -5, 0, 0, 0, false, equalW, refEnv)
	approxScore(t, "close@negative-reach", s2.Close, 0.0, 1e-9)
}

func TestScore_FreshnessBoundary(t *testing.T) {
	// At the window edge (24h) freshness is 0; halfway through is 0.5.
	s := Score(0, refReach, refEnv.WindowHours, 0, 0, false, equalW, refEnv)
	approxScore(t, "fresh@window", s.Fresh, 0.0, 1e-9)
	s = Score(0, refReach, refEnv.WindowHours/2, 0, 0, false, equalW, refEnv)
	approxScore(t, "fresh@half", s.Fresh, 0.5, 1e-9)
}

func TestScore_FreshnessClampedAtZero(t *testing.T) {
	s := Score(0, refReach, refEnv.WindowHours*2, 0, 0, false, equalW, refEnv)
	approxScore(t, "fresh@2xwindow", s.Fresh, 0.0, 1e-9)
}

func TestScore_BudgetFavoursLowEngagement(t *testing.T) {
	// Same age, different view counts: the rarer post should score higher.
	rare := Score(0, refReach, 5, 0, 0, false, equalW, refEnv)
	popular := Score(0, refReach, 5, 100, 0, false, equalW, refEnv)
	if rare.Budget <= popular.Budget {
		t.Errorf("rare post (budget=%f) should rank higher than popular (budget=%f)", rare.Budget, popular.Budget)
	}
	approxScore(t, "budget@rare", rare.Budget, 1.0, 1e-9)
}

func TestScore_BudgetTreatsRepliesAs3xViews(t *testing.T) {
	threeViews := Score(0, refReach, 5, 3, 0, false, equalW, refEnv)
	oneReply := Score(0, refReach, 5, 0, 1, false, equalW, refEnv)
	approxScore(t, "3 views ≈ 1 reply for budget", threeViews.Budget, oneReply.Budget, 1e-9)
}

func TestScore_BudgetIsTimeAware(t *testing.T) {
	young := Score(0, refReach, 2, 10, 0, false, equalW, refEnv)
	old := Score(0, refReach, 18, 10, 0, false, equalW, refEnv)
	if old.Budget <= young.Budget {
		t.Errorf("older post (budget=%f) should rank higher than younger post (budget=%f) at equal raw engagement", old.Budget, young.Budget)
	}
}

func TestScore_BudgetAgeClampedAt1Hour(t *testing.T) {
	infant := Score(0, refReach, 0.1, 10, 0, false, equalW, refEnv)
	oneH := Score(0, refReach, 1.0, 10, 0, false, equalW, refEnv)
	approxScore(t, "budget@0.1h == budget@1h (clamped)", infant.Budget, oneH.Budget, 1e-9)
}

func TestScore_AnchorOnlyForHomeGroup(t *testing.T) {
	cross := Score(0, refReach, 0, 0, 0, false, equalW, refEnv)
	home := Score(0, refReach, 0, 0, 0, true, equalW, refEnv)
	approxScore(t, "anchor@cross", cross.Anchor, 0.0, 1e-9)
	approxScore(t, "anchor@home", home.Anchor, 1.0, 1e-9)
}

func TestScore_TotalIsWeightedSum(t *testing.T) {
	w := ScoreWeights{Close: 2, Fresh: 0.5, Budget: 1.5, Anchor: 0.25}
	s := Score(7500, refReach, 12, 0, 0, true, w, refEnv)
	want := w.Close*s.Close + w.Fresh*s.Fresh + w.Budget*s.Budget + w.Anchor*s.Anchor
	approxScore(t, "total = weighted sum", s.Total, want, 1e-9)
}

func TestScore_ZeroWeightDisablesSignal(t *testing.T) {
	closeOnly := ScoreWeights{Close: 1, Fresh: 0, Budget: 0, Anchor: 0}
	s := Score(0, refReach, 0, 0, 0, true, closeOnly, refEnv) // every component > 0
	approxScore(t, "total = close-only", s.Total, s.Close, 1e-9)
}

func TestScore_AllZeroWeightsGivesZeroTotal(t *testing.T) {
	z := ScoreWeights{}
	s := Score(0, refReach, 0, 0, 0, true, z, refEnv)
	approxScore(t, "total@zero-weights", s.Total, 0.0, 1e-9)
}

// --- Default weight/env loading (mirrors the digest's browse-independent
// env-overridable knobs, per RIPPLE_BROWSE_* rather than RIPPLE_DIGEST_*) ---

func TestLoadScoreWeights_Defaults(t *testing.T) {
	w := LoadScoreWeights()
	approxScore(t, "default w_close", w.Close, 1.0, 1e-9)
	approxScore(t, "default w_fresh", w.Fresh, 0.0, 1e-9)
	approxScore(t, "default w_budget", w.Budget, 1.0, 1e-9)
	approxScore(t, "default w_anchor", w.Anchor, 0.0, 1e-9)
}

func TestLoadScoreWeights_EnvOverride(t *testing.T) {
	t.Setenv("RIPPLE_BROWSE_W_CLOSE", "2.5")
	t.Setenv("RIPPLE_BROWSE_W_FRESH", "0.75")
	t.Setenv("RIPPLE_BROWSE_W_BUDGET", "0.1")
	t.Setenv("RIPPLE_BROWSE_W_ANCHOR", "3")

	w := LoadScoreWeights()
	approxScore(t, "override w_close", w.Close, 2.5, 1e-9)
	approxScore(t, "override w_fresh", w.Fresh, 0.75, 1e-9)
	approxScore(t, "override w_budget", w.Budget, 0.1, 1e-9)
	approxScore(t, "override w_anchor", w.Anchor, 3.0, 1e-9)
}

func TestLoadScoreEnv_Defaults(t *testing.T) {
	env := LoadScoreEnv()
	approxScore(t, "default window_hours", env.WindowHours, 24, 1e-9)
	approxScore(t, "default budget_decay", env.BudgetDecay, 25, 1e-9)
	approxScore(t, "default reach_m", env.DefaultReachM, 30000, 1e-9)
}

func TestLoadScoreEnv_EnvOverride(t *testing.T) {
	t.Setenv("RIPPLE_BROWSE_WINDOW_HOURS", "48")
	t.Setenv("RIPPLE_BROWSE_BUDGET_DECAY", "10")
	t.Setenv("RIPPLE_BROWSE_DEFAULT_REACH_M", "15000")

	env := LoadScoreEnv()
	approxScore(t, "override window_hours", env.WindowHours, 48, 1e-9)
	approxScore(t, "override budget_decay", env.BudgetDecay, 10, 1e-9)
	approxScore(t, "override reach_m", env.DefaultReachM, 15000, 1e-9)
}

// --- Haversine / reach-radius helpers ---

func TestHaversineMetres_KnownDistanceAtEquator(t *testing.T) {
	// At the equator, 1 degree of longitude is ~111,319.5 metres (WGS84
	// equatorial circumference / 360).
	got := HaversineMetres(0, 0, 0, 1)
	approxScore(t, "1 deg lng @ equator", got, 111319.5, 100)
}

func TestReachRadiusMetres_MaxVertexDistanceFromOrigin(t *testing.T) {
	// A simple square ring around an origin at the equator so the metre
	// conversion is easy to reason about. Origin at (0,0); vertices 1 degree
	// away in each direction -> expect ~111,319.5m (matches the haversine
	// check above, since the corner distances from the CENTRE... use a ring
	// where the origin is a MIDPOINT of one edge to keep the maths simple:
	// origin (0,0), ring corners at (1,0) exactly 1 degree of longitude away.
	wkt := "POLYGON((1 0, 1 1, -1 1, -1 0, 1 0))"
	got := ReachRadiusMetres(0, 0, wkt, 30000)
	// The furthest vertex from (0,0) is one of the (±1,1) corners.
	want := HaversineMetres(0, 0, 1, 1)
	approxScore(t, "reach radius = max vertex distance", got, want, 1)
}

func TestReachRadiusMetres_FallsBackToDefaultWhenUnparseable(t *testing.T) {
	got := ReachRadiusMetres(51.5, -0.1, "", 12345)
	approxScore(t, "empty wkt -> default", got, 12345, 1e-9)

	got2 := ReachRadiusMetres(51.5, -0.1, "not-a-polygon", 12345)
	approxScore(t, "malformed wkt -> default", got2, 12345, 1e-9)
}

func TestReachRadiusMetres_FallsBackToDefaultWhenDegenerate(t *testing.T) {
	// A "polygon" whose every vertex sits exactly on the origin has zero
	// extent; fall back to the default rather than returning a zero radius
	// (which would force close=0 for every distance > 0 via the Score
	// reachRadius<=0 short-circuit... actually reachRadius=0 here still
	// triggers close=0, so guard by falling back to default instead).
	// WKT vertices are "lng lat" (x is lng, y is lat), so the origin
	// (originLat=51.5, originLng=-0.1) is written here as "-0.1 51.5".
	wkt := "POLYGON((-0.1 51.5, -0.1 51.5, -0.1 51.5))"
	got := ReachRadiusMetres(51.5, -0.1, wkt, 30000)
	approxScore(t, "degenerate polygon -> default", got, 30000, 1e-9)
}
