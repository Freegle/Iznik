package main

import (
	"math"
	"testing"
)

// TestFrontierMilesMedianMax covers the median/max summary of the per-sector road-distance
// frontiers. Nodes are placed in known compass directions from the origin so each falls in a
// distinct 22.5-degree sector; distM sets each one's frontier distance directly, so only the
// bearing matters for bucketing.
func TestFrontierMilesMedianMax(t *testing.T) {
	const lat0, lng0 = 51.5, -0.1
	g := &Graph{Nodes: []Node{
		{},                     // 0 sentinel
		{Lat: 51.6, Lng: -0.1}, // 1 due N  -> sector 0
		{Lat: 51.5, Lng: 0.0},  // 2 due E  -> sector 4
		{Lat: 51.4, Lng: -0.1}, // 3 due S  -> sector 8
		{Lat: 51.5, Lng: -0.2}, // 4 due W  -> sector 12
		{Lat: 51.7, Lng: -0.1}, // 5 also N -> sector 0
	}}

	approx := func(gotMiles, wantMetres float64) bool {
		return math.Abs(gotMiles-wantMetres/metresPerMile) < 1e-6
	}

	t.Run("even count: median of the four sector frontiers", func(t *testing.T) {
		distM := map[NodeID]float32{1: 1000, 2: 2000, 3: 3000, 4: 4000}
		med, mx := frontierMilesMedianMax(g, lat0, lng0, distM)
		if !approx(med, 2500) { // (2000+3000)/2
			t.Errorf("median = %v mi, want %v mi", med, 2500.0/metresPerMile)
		}
		if !approx(mx, 4000) {
			t.Errorf("max = %v mi, want %v mi", mx, 4000.0/metresPerMile)
		}
	})

	t.Run("odd count: the middle sector frontier", func(t *testing.T) {
		distM := map[NodeID]float32{1: 1000, 2: 3000, 3: 2000}
		med, mx := frontierMilesMedianMax(g, lat0, lng0, distM)
		if !approx(med, 2000) {
			t.Errorf("median = %v mi, want %v mi", med, 2000.0/metresPerMile)
		}
		if !approx(mx, 3000) {
			t.Errorf("max = %v mi, want %v mi", mx, 3000.0/metresPerMile)
		}
	})

	t.Run("furthest node per sector wins", func(t *testing.T) {
		// Nodes 1 and 5 are both due north; the sector keeps the furthest (1500, not 500).
		distM := map[NodeID]float32{1: 500, 5: 1500, 2: 2000}
		med, mx := frontierMilesMedianMax(g, lat0, lng0, distM)
		if !approx(med, 1750) { // (1500+2000)/2
			t.Errorf("median = %v mi, want %v mi", med, 1750.0/metresPerMile)
		}
		if !approx(mx, 2000) {
			t.Errorf("max = %v mi, want %v mi", mx, 2000.0/metresPerMile)
		}
	})

	t.Run("no reached nodes: zero range", func(t *testing.T) {
		med, mx := frontierMilesMedianMax(g, lat0, lng0, map[NodeID]float32{})
		if med != 0 || mx != 0 {
			t.Errorf("empty distM = (%v, %v), want (0, 0)", med, mx)
		}
	})
}
