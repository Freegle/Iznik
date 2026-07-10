package town

import "testing"

// milesToDriveMinutes turns the slider's miles into a drive-time budget: ~2 min/mile, floored at 5
// and capped at 120 so the extremes stay sane.
func TestMilesToDriveMinutes(t *testing.T) {
	cases := []struct{ miles, want float64 }{
		{0, 5},     // 0 -> floored to 5
		{1, 5},     // 2 -> floored to 5
		{3, 6},     // 3 * 2
		{15, 30},   // 15 * 2
		{60, 120},  // 120, at the cap
		{100, 120}, // 200 -> capped at 120
	}
	for _, c := range cases {
		if got := milesToDriveMinutes(c.miles); got != c.want {
			t.Errorf("milesToDriveMinutes(%v) = %v, want %v", c.miles, got, c.want)
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
