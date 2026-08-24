package town

import "testing"

// reachRadiusMiles maps the slider's chosen travel time to a mile radius for
// settings.browseMaxDistance from PURE travel time: the isochrone's road frontier, floored.
// Named towns play no part - they are display material for NearbyTowns / community news, and
// deriving the radius from them collapsed a 25-minute reach to 1 mile for a member whose only
// nearby named town was her own (ChitChat 616307). Callers omit the field entirely when the
// isochrone has no frontier, so a failed derivation is visible to the client rather than a
// made-up cap.
func TestReachRadiusMiles(t *testing.T) {
	cases := []struct {
		name     string
		frontier float64
		want     float64
	}{
		// ChitChat 616307: 25 minutes reaches ~14.7 road miles; the radius is
		// that frontier, regardless of any nearby named town.
		{"frontier passes through", 14.7, 14.7},
		{"short-trip frontier passes through", 3.2, 3.2},
		{"degenerate frontier floored", 0.2, reachRadiusFloorMiles},
		{"zero frontier floored", 0.0, reachRadiusFloorMiles},
	}
	for _, c := range cases {
		if got := reachRadiusMiles(c.frontier); got != c.want {
			t.Errorf("%s: reachRadiusMiles(%v) = %v, want %v", c.name, c.frontier, got, c.want)
		}
	}
}

// routingEvalURL prefers ROUTING_EVAL_URL, then SPATIAL_KNN_URL, then the in-cluster default.
func TestRoutingEvalURL(t *testing.T) {
	t.Setenv("ROUTING_EVAL_URL", "")
	t.Setenv("SPATIAL_KNN_URL", "")
	if got := routingEvalURL(); got != "http://spatial:8194" {
		t.Errorf("default routingEvalURL = %q, want http://spatial:8194", got)
	}

	t.Setenv("SPATIAL_KNN_URL", "http://knn:1")
	if got := routingEvalURL(); got != "http://knn:1" {
		t.Errorf("routingEvalURL fell through to SPATIAL_KNN_URL = %q, want http://knn:1", got)
	}

	t.Setenv("ROUTING_EVAL_URL", "http://routing:2")
	if got := routingEvalURL(); got != "http://routing:2" {
		t.Errorf("routingEvalURL = %q, want ROUTING_EVAL_URL http://routing:2", got)
	}
}
