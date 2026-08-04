package town

import "testing"

// reachRadiusMiles turns the reachable towns' straight-line distances into a crow-flies mile radius
// to store as settings.browseMaxDistance: the larger of the furthest reachable town and the road
// frontier, never below a small floor. The frontier is a lower bound even when towns ARE reachable:
// with a sparse towns table the only reachable named town can be the member's own town a mile away,
// and taking the crow-distance to it collapsed a 25-minute reach to a 1-mile cap that starved the
// member's feed (ChitChat 616307). This is the value the slider's chosen travel time maps to - via
// real routing, with no hardcoded miles<->minutes constant.
func TestReachRadiusMiles(t *testing.T) {
	cases := []struct {
		name     string
		reach    []float64
		fallback float64
		want     float64
	}{
		{"furthest reachable town wins when beyond the frontier", []float64{2.0, 8.5, 5.1}, 3.0, 8.5},
		{"no town reachable -> road frontier fallback", []float64{}, 6.2, 6.2},
		{"tiny reach floored", []float64{0.2}, 0.0, reachRadiusFloorMiles},
		{"empty and no fallback floored", []float64{}, 0.0, reachRadiusFloorMiles},
		// ChitChat 616307: member ~1 mile from her own town centre, no other
		// named town in the table nearby - but the 25-minute isochrone reaches
		// ~15 road miles. The frontier must govern, not the own-town distance.
		{"own town next door must not collapse the radius", []float64{1.0}, 14.7, 14.7},
		{"frontier lower-bounds even with several near towns", []float64{1.0, 2.2}, 9.0, 9.0},
	}
	for _, c := range cases {
		if got := reachRadiusMiles(c.reach, c.fallback); got != c.want {
			t.Errorf("%s: reachRadiusMiles(%v, %v) = %v, want %v", c.name, c.reach, c.fallback, got, c.want)
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
