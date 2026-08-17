package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// /v1/drive-time exists to answer "when will this post's reach cover me" and
// its contract is to MATCH the reach: the rippling tick polygons come from
// Isochrone, which seeds the drive-startup overhead at the origin.  If
// costToTargets doesn't seed the same overhead, /v1/drive-time reports a
// member reachable up to driveStartupSecs earlier than the reach actually
// covers them, skewing reply-hold release timing.
func TestDriveTimeIncludesStartupAndMatchesIsochrone(t *testing.T) {
	// A straight residential chain long enough for a meaningful time.
	var nodes []RawNodeSpec
	ids := make([]int64, 30)
	for i := 0; i < 30; i++ {
		ids[i] = int64(i + 1)
		nodes = append(nodes, RawNodeSpec{OSMID: ids[i], Lat: 51.5 + float64(i)*0.001, Lng: -1.0})
	}
	ways := []RawWaySpec{{NodeIDs: ids, Highway: "residential"}}
	g := BuildGraphFromRaw(nodes, ways, nil)
	app := newApp(g, "", false)

	oLat, oLng := 51.5, -1.0
	dLat, dLng := 51.529, -1.0

	// Ground truth: the Isochrone cost to the destination's node (which is how
	// the reach tick polygons see it).
	iso := Isochrone(g, oLat, oLng, 3600, Drive)
	dest := nearestNodeForMode(g, dLat, dLng, Drive)
	want, ok := iso.ReachedNodes[dest]
	if !ok {
		t.Fatalf("destination not reached by isochrone")
	}
	if want < driveStartupSecs {
		t.Fatalf("isochrone cost %f should include the %f startup", want, driveStartupSecs)
	}

	req := httptest.NewRequest(http.MethodGet,
		fmt.Sprintf("/v1/drive-time?lat=%f&lng=%f&tolat=%f&tolng=%f&max_minutes=60", oLat, oLng, dLat, dLng), nil)
	resp, err := app.Test(req, 15000)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d: %s", resp.StatusCode, raw)
	}
	var out struct {
		Reachable bool     `json:"reachable"`
		DriveMin  *float64 `json:"drive_min"`
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatal(err)
	}
	if !out.Reachable || out.DriveMin == nil {
		t.Fatalf("expected reachable with drive_min, got %s", raw)
	}
	got := *out.DriveMin * 60
	if diff := got - float64(want); diff < -1 || diff > 1 {
		t.Errorf("/v1/drive-time = %.1fs but the isochrone (reach) says %.1fs - the startup cost is not seeded consistently", got, want)
	}
}
