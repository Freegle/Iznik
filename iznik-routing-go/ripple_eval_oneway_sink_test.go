package main

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// A destination can snap to a node on a one-way street that can be LEFT but
// never ARRIVED AT (a pure source in the directed drive graph).  Weak-
// component filtering can't catch that, and a single-nearest-node lookup then
// reports the trip as unreachable even though the road 100m further on is
// reached in minutes (real example: Plympton -> Plymstock, forward direction
// only).  The eval endpoint must fall back to the next-nearest reachable
// candidate instead of returning null.
func TestRippleEvalPointFallsBackPastOnewaySourceSnap(t *testing.T) {
	nodes := []RawNodeSpec{
		{OSMID: 1, Lat: 51.500, Lng: -1.000},   // origin
		{OSMID: 2, Lat: 51.501, Lng: -1.000},   // B: on the two-way chain
		{OSMID: 3, Lat: 51.502, Lng: -1.000},   // C
		{OSMID: 4, Lat: 51.5013, Lng: -1.0015}, // A: one-way source, A->B only
	}
	ways := []RawWaySpec{
		{NodeIDs: []int64{1, 2, 3}, Highway: "residential"},
		{NodeIDs: []int64{4, 2}, Highway: "residential", Oneway: true},
	}
	g := BuildGraphFromRaw(nodes, ways, nil)
	app := newApp(g, "", false)

	// The evaluation point sits ~12m from A (nearest) and ~120m from B.
	body := map[string]any{
		"lat": 51.500, "lng": -1.000, "mode": "drive", "max_minutes": 30,
		"points":      [][2]float64{{-1.0016, 51.5014}},
		"points_only": true,
	}
	buf, _ := json.Marshal(body)
	req := httptest.NewRequest(http.MethodPost, "/v1/ripple-eval", bytes.NewReader(buf))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 15000)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d: %s", resp.StatusCode, raw)
	}
	var out struct {
		Results []struct {
			DriveMin *float64 `json:"drive_min"`
		} `json:"results"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatal(err)
	}
	if len(out.Results) != 1 {
		t.Fatalf("expected 1 result, got %d", len(out.Results))
	}
	if out.Results[0].DriveMin == nil {
		t.Fatalf("drive_min is null: the eval snapped to the one-way source and gave up instead of falling back to the reachable node beside it")
	}
	if *out.Results[0].DriveMin <= 0 || *out.Results[0].DriveMin > 10 {
		t.Errorf("drive_min = %f, expected a small positive time via node B", *out.Results[0].DriveMin)
	}
}
