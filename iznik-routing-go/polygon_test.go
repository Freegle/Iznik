package main

import (
	"testing"
)

func TestIsochronePolygon_Walk15min(t *testing.T) {
	g := getTestGraph(t)
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	res := AutoResolution(15*60, Walk)
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
	result := Isochrone(g, 51.4545, -2.5879, 15*60, Walk)
	res := AutoResolution(15*60, Walk)
	poly := IsochronePolygon(g, result.ReachedNodes, res)

	ring := poly.Geometry.Coordinates[0]
	// Origin should be inside the polygon.
	// Use a simple ray-casting test.
	if !pointInPolygon(-2.5879, 51.4545, ring) {
		t.Error("origin point is not inside the 15-min walk isochrone polygon")
	}
}

func TestAutoResolution(t *testing.T) {
	walkRes := AutoResolution(15*60, Walk)
	driveRes := AutoResolution(15*60, Drive)
	if driveRes <= walkRes {
		t.Errorf("drive resolution should be coarser than walk: drive=%f walk=%f", driveRes, walkRes)
	}
	t.Logf("walk15 res=%.5f drive15 res=%.5f", walkRes, driveRes)
}

// pointInPolygon now lives in reachable_groups.go (production code), reused here.
