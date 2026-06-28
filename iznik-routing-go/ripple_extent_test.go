package main

import "testing"

// Stage-A audience-budget extent cap (Discourse #9808). Verifies the pure cap
// logic used by handleRippleSchedule: the curve is spread over at most
// target_users nearest freeglers when the cap is set and binds below the pool,
// and is otherwise the full reachable pool (so target_users=0/absent leaves the
// schedule byte-identical to the pre-feature behaviour).
func TestEffectiveScheduleTotal(t *testing.T) {
	cases := []struct {
		name        string
		total       int
		targetUsers int
		want        int
	}{
		{"uncapped (target 0) keeps full pool", 17000, 0, 17000},
		{"negative target treated as off", 17000, -5, 17000},
		{"dense pool capped to target (London)", 17000, 4000, 4000},
		{"target above pool never binds (rural)", 500, 4000, 500},
		{"target equal to pool does not bind", 4000, 4000, 4000},
		{"target one below pool binds", 4000, 3999, 3999},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := effectiveScheduleTotal(tc.total, tc.targetUsers); got != tc.want {
				t.Fatalf("effectiveScheduleTotal(%d, %d) = %d, want %d",
					tc.total, tc.targetUsers, got, tc.want)
			}
		})
	}
}
