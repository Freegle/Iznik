package main

import (
	"testing"
)

func TestIsochronePolygon_Walk15min(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60)
	res := AutoResolution(15 * 60)
	poly := IsochronePolygon(g, result.ReachedNodes, res)

	if poly.Geometry.Type != "Polygon" {
		t.Errorf("expected Polygon, got %s", poly.Geometry.Type)
	}
	ring := poly.Geometry.Coordinates[0]
	if len(ring) < 4 {
		t.Errorf("polygon ring has %d points, expected ≥4", len(ring))
	}
	// Ring must be closed (first == last).
	first, last := ring[0], ring[len(ring)-1]
	if first != last {
		t.Errorf("polygon ring not closed: first=%v last=%v", first, last)
	}
	t.Logf("walk 15min polygon: %d ring points", len(ring))
}

func TestIsochronePolygon_CentreInside(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60)
	res := AutoResolution(15 * 60)
	poly := IsochronePolygon(g, result.ReachedNodes, res)

	ring := poly.Geometry.Coordinates[0]
	// Origin should be inside the polygon.
	// Use a simple ray-casting test.
	if !pointInPolygon(-2.5879, 51.4545, ring) {
		t.Error("origin point is not inside the 15-min walk isochrone polygon")
	}
}

func TestAutoResolution(t *testing.T) {
	// There is one mode now, so the property to pin is that a longer budget
	// gives a coarser grid, bounded by the floor and the cap.
	// Both ends of the range are clamped, so pick budgets inside it: at 50km/h
	// the cap bites above ~240s and the floor below ~72s.
	short := AutoResolution(120)
	long := AutoResolution(600)
	if long <= short {
		t.Errorf("a longer budget should give a coarser grid: 5min=%f 60min=%f", short, long)
	}
	if short < 0.0005 {
		t.Errorf("resolution %f is below the 0.0005 floor", short)
	}
	if long > 0.0016 {
		t.Errorf("resolution %f is above the cap that keeps rivers open", long)
	}
	t.Logf("drive 5min res=%.5f 60min res=%.5f", short, long)
}

// pointInPolygon uses ray casting. Coords are [lng, lat].
func pointInPolygon(lng, lat float64, ring [][2]float64) bool {
	inside := false
	n := len(ring)
	j := n - 1
	for i := 0; i < n; i++ {
		xi, yi := ring[i][0], ring[i][1]
		xj, yj := ring[j][0], ring[j][1]
		if ((yi > lat) != (yj > lat)) && (lng < (xj-xi)*(lat-yi)/(yj-yi)+xi) {
			inside = !inside
		}
		j = i
	}
	return inside
}
