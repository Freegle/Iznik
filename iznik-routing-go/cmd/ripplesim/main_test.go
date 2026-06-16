package main

import "testing"

// effectiveReach is the core of the population-cap model: given the total
// freeglers reachable within the max-minutes isochrone and an optional cap,
// how many are actually in the notification pool?
func TestEffectiveReach(t *testing.T) {
	cases := []struct {
		name  string
		total int
		cap   int
		want  int
	}{
		{"uncapped returns total", 19318, 0, 19318},
		{"cap below total truncates (dense urban)", 19318, 1000, 1000},
		{"cap above total is a no-op (sparse rural)", 242, 1000, 242},
		{"cap equal to total is a no-op", 1000, 1000, 1000},
		{"negative cap treated as uncapped", 500, -5, 500},
		{"zero total stays zero", 0, 1000, 0},
		{"cap of one", 500, 1, 1},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := effectiveReach(c.total, c.cap); got != c.want {
				t.Errorf("effectiveReach(%d, %d) = %d, want %d", c.total, c.cap, got, c.want)
			}
		})
	}
}

// A replier is "capped out" (never notified) exactly when their drive-time
// rank exceeds the effective reach.  This pins the boundary condition the
// sweep depends on: rank == effectiveTotal is the furthest still-notified
// replier; rank == effectiveTotal+1 is the first one dropped.
func TestCapOutBoundary(t *testing.T) {
	const total = 5000
	cap := 1000
	eff := effectiveReach(total, cap)
	if eff != 1000 {
		t.Fatalf("setup: effectiveReach = %d, want 1000", eff)
	}
	cappedOut := func(rank int) bool { return rank > eff }

	if cappedOut(eff) {
		t.Errorf("rank == effectiveTotal (%d) must still be notified", eff)
	}
	if !cappedOut(eff + 1) {
		t.Errorf("rank == effectiveTotal+1 (%d) must be capped out", eff+1)
	}
	if cappedOut(1) {
		t.Errorf("the closest replier (rank 1) must always be notified")
	}
}
