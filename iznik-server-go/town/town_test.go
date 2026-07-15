package town

import "testing"

// reachRadiusMiles turns the reachable towns' straight-line distances into a crow-flies mile radius
// to store as settings.browseMaxDistance: the furthest reachable town, falling back to the road
// frontier when nothing named is reachable, and never below a small floor. This is the value the
// slider's chosen travel time maps to - via real routing, with no hardcoded miles<->minutes constant.
func TestReachRadiusMiles(t *testing.T) {
	cases := []struct {
		name     string
		reach    []float64
		fallback float64
		want     float64
	}{
		{"furthest reachable town wins", []float64{2.0, 8.5, 5.1}, 3.0, 8.5},
		{"no town reachable -> road frontier fallback", []float64{}, 6.2, 6.2},
		{"tiny reach floored", []float64{0.2}, 0.0, reachRadiusFloorMiles},
		{"empty and no fallback floored", []float64{}, 0.0, reachRadiusFloorMiles},
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
