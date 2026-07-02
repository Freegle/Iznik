package main

import "testing"

func TestClip(t *testing.T) {
	cases := []struct {
		name      string
		x, lo, hi float64
		want      float64
	}{
		{"below floor clips to lo", 500, 1000, 4000, 1000},
		{"above ceiling clips to hi", 9000, 1000, 4000, 4000},
		{"within range passes through", 2500, 1000, 4000, 2500},
		{"exactly at floor", 1000, 1000, 4000, 1000},
		{"exactly at ceiling", 4000, 1000, 4000, 4000},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := clip(tc.x, tc.lo, tc.hi); got != tc.want {
				t.Errorf("clip(%v, %v, %v) = %v, want %v", tc.x, tc.lo, tc.hi, got, tc.want)
			}
		})
	}
}

// N* = clip(max(1.0*actives, 1000), 1000, 4000) — the Stage-A audience target for the Rippling
// Explorer's "Proposed audience-based reach" option. F_band floor is fixed at 1000 for now.
func TestComputeNStar(t *testing.T) {
	cases := []struct {
		name    string
		actives int
		want    int
	}{
		{"sparse group floors to 1000", 680, 1000},
		{"mid-size group passes through", 2485, 2485},
		{"another mid-size group passes through", 3576, 3576},
		{"dense group caps at ceiling", 5000, 4000},
		{"zero actives floors to 1000", 0, 1000},
		{"exactly at floor stays at floor", 1000, 1000},
		{"exactly at ceiling stays at ceiling", 4000, 4000},
		{"just above floor passes through", 1001, 1001},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := computeNStar(tc.actives); got != tc.want {
				t.Errorf("computeNStar(%d) = %d, want %d", tc.actives, got, tc.want)
			}
		})
	}
}
