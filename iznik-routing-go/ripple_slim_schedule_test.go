package main

import (
	"encoding/json"
	"strings"
	"testing"
)

// The batch requests polygons=0: each tick then carries only drive_min and
// cumulative_users - the polygon key is omitted entirely, keeping the response
// (and what the batch stores) to bytes per tick instead of a ~20k-vertex
// polygon per tick.
func TestRippleScheduleEntry_PolygonOmittedWhenSlim(t *testing.T) {
	b, err := json.Marshal(rippleScheduleEntry{
		Tick:            1,
		DriveMin:        12.5,
		CumulativeUsers: 100,
	})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(b), `"polygon"`) {
		t.Errorf("slim tick entry must omit the polygon key: %s", b)
	}
}

// With polygons requested (the explorer default) the tick polygon is present.
func TestRippleScheduleEntry_PolygonPresentByDefault(t *testing.T) {
	p := GeoJSONPolygon{
		Type: "Feature",
		Geometry: geoGeometry{
			Type:        "Polygon",
			Coordinates: [][][2]float64{{{0, 0}, {1, 0}, {1, 1}, {0, 0}}},
		},
	}
	b, err := json.Marshal(rippleScheduleEntry{Tick: 1, Polygon: &p})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(b), `"polygon"`) {
		t.Errorf("tick entry with polygon must include it: %s", b)
	}
}

// wantTickPolygons: only an explicit polygons=0 disables them (back-compatible
// default for the explorer and older callers).
func TestWantTickPolygons(t *testing.T) {
	for q, want := range map[string]bool{"": true, "1": true, "0": false, "yes": true} {
		if got := wantTickPolygons(q); got != want {
			t.Errorf("wantTickPolygons(%q) = %v, want %v", q, got, want)
		}
	}
}
