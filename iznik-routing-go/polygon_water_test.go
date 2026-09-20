package main

import "testing"

// buildTwoBanksGraph places two dense blocks of reached nodes separated by an empty
// gap of gapKm kilometres - a river the reach cannot cross. Only Lat/Lng are read by
// the polygon rasteriser. Nodes are ~55 m apart so cells fill at any test resolution.
func buildTwoBanksGraph(gapKm float64) (*Graph, map[NodeID]float32) {
	g := &Graph{Nodes: []Node{{}}} // Nodes is 1-indexed; index 0 unused
	reached := map[NodeID]float32{}
	add := func(lat, lng float64) {
		g.Nodes = append(g.Nodes, Node{Lat: float32(lat), Lng: float32(lng)})
		reached[NodeID(len(g.Nodes)-1)] = 0
	}
	const step = 0.0005
	gapDeg := gapKm / 111.0
	for lat := 51.000; lat <= 51.010+1e-9; lat += step {
		for lng := -0.004; lng <= 0.0+1e-9; lng += step { // west bank
			add(lat, lng)
		}
		for lng := gapDeg; lng <= gapDeg+0.004+1e-9; lng += step { // east bank
			add(lat, lng)
		}
	}
	return g, reached
}

// A ~0.8km river must not be closed over: the reach must stop at the near bank, not
// step across the water into the far bank. This fails at the old 1.1km resolution
// (the river is sub-cell and gets bridged) and passes at the finer resolution.
func TestIsochronePolygon_DoesNotBridgeRiver(t *testing.T) {
	const gapKm = 0.8
	res := AutoResolution(30 * 60)
	g, reached := buildTwoBanksGraph(gapKm)
	poly := IsochronePolygon(g, reached, res)
	if len(poly.Geometry.Coordinates) == 0 {
		t.Fatal("empty polygon")
	}
	ring := poly.Geometry.Coordinates[0]
	midLng := (gapKm / 111.0) / 2 // centre of the water
	if pointInPolygon(midLng, 51.005, ring) {
		t.Errorf("reach polygon bridges a %.1fkm river at lng %.4f (res=%.4f) - it crosses the water into the far bank", gapKm, midLng, res)
	}
}

// A ~0.8km river must span >=2 grid cells so the one-cell closing cannot bridge it,
// i.e. cell size <= ~0.4km ~ 0.0036 deg for a typical 30-minute drive reach.
func TestAutoResolution_DriveResolvesSubKmRiver(t *testing.T) {
	res := AutoResolution(30 * 60)
	if res > 0.0036 {
		t.Errorf("drive resolution %.4f deg (~%.2f km/cell) too coarse to resolve a 0.8km river; want <= 0.0036", res, res*111)
	}
}
