package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"math"
	"net/http/httptest"
	"sync"
	"testing"
)

func TestReachEndpoints(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := stage2Live
	stage2Live = eng
	defer func() { stage2Live = prev }()

	app := newApp(g, "", false)

	// Labels for a 12-minute reach from the centre.
	req := httptest.NewRequest("GET", "/v1/reach-labels?lat=51.4545&lng=-2.5879&minutes=12", nil)
	resp, err := app.Test(req, 30000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("reach-labels: err=%v status=%v", err, resp.StatusCode)
	}
	var lr struct {
		Labels  string  `json:"labels"`
		T       float32 `json:"t"`
		Regions int     `json:"regions"`
		Bytes   int     `json:"bytes"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&lr); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if lr.Regions == 0 || lr.Bytes == 0 || lr.T != 720 {
		t.Fatalf("labels response degenerate: %+v", lr)
	}

	// Arrival evaluation must equal the engine's own answers.
	blob, _ := base64.StdEncoding.DecodeString(lr.Labels)
	lbl, err := eng.DecodeLabels(blob)
	if err != nil {
		t.Fatalf("decode labels: %v", err)
	}
	points := []struct{ lat, lng float64 }{
		{51.4545, -2.5879}, // origin
		{51.4700, -2.6000}, // in reach
		{51.3000, -2.3000}, // far out
	}
	body := map[string]any{"labels": lr.Labels, "points": []map[string]float64{}}
	for _, p := range points {
		body["points"] = append(body["points"].([]map[string]float64), map[string]float64{"lat": p.lat, "lng": p.lng})
	}
	raw, _ := json.Marshal(body)
	req2 := httptest.NewRequest("POST", "/v1/reach-arrival", bytes.NewReader(raw))
	req2.Header.Set("Content-Type", "application/json")
	resp2, err := app.Test(req2, 30000)
	if err != nil || resp2.StatusCode != 200 {
		t.Fatalf("reach-arrival: err=%v status=%v", err, resp2.StatusCode)
	}
	var ar struct {
		Results []struct {
			Arrival *float32 `json:"arrival"`
			In      bool     `json:"in"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp2.Body).Decode(&ar); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(ar.Results) != len(points) {
		t.Fatalf("got %d results", len(ar.Results))
	}
	for i, p := range points {
		want := eng.ArrivalFromStored(lbl, p.lat, p.lng)
		got := ar.Results[i]
		if want == f32Inf {
			if got.Arrival != nil || got.In {
				t.Fatalf("point %d: endpoint %v/%v, engine says unreachable", i, got.Arrival, got.In)
			}
			continue
		}
		if got.Arrival == nil || math.Abs(float64(*got.Arrival-want)) > 0.01 {
			t.Fatalf("point %d: endpoint arrival %v, engine %v", i, got.Arrival, want)
		}
		if got.In != (want <= lbl.T) {
			t.Fatalf("point %d: endpoint in=%v, engine %v", i, got.In, want <= lbl.T)
		}
	}

	// Concurrency smoke: parallel arrival requests share the table caches.
	var wg sync.WaitGroup
	errs := make(chan error, 8)
	for w := 0; w < 8; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			r := httptest.NewRequest("POST", "/v1/reach-arrival", bytes.NewReader(raw))
			r.Header.Set("Content-Type", "application/json")
			rp, err := app.Test(r, 30000)
			if err != nil || rp.StatusCode != 200 {
				errs <- fmt.Errorf("concurrent arrival: err=%v status=%v", err, rp.StatusCode)
			}
		}()
	}
	wg.Wait()
	close(errs)
	for e := range errs {
		t.Fatal(e)
	}
}

func TestReachEndpointsUnconfigured(t *testing.T) {
	g := makeTestGrid(nil)
	prev := stage2Live
	stage2Live = nil
	defer func() { stage2Live = prev }()
	app := newApp(g, "", false)
	req := httptest.NewRequest("GET", "/v1/reach-labels?lat=51&lng=-2&minutes=10", nil)
	resp, err := app.Test(req, 10000)
	if err != nil || resp.StatusCode != 503 {
		t.Fatalf("expected 503 when unconfigured, got err=%v status=%v", err, resp.StatusCode)
	}
}

func TestDriveMetricsEndpoint(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := stage2Live
	stage2Live = eng
	defer func() { stage2Live = prev }()
	app := newApp(g, "", false)

	targets := []map[string]any{
		{"id": 1, "lat": 51.4700, "lng": -2.6000},
		{"id": 2, "lat": 51.4545, "lng": -2.5879},
		{"id": 3, "lat": 51.3000, "lng": -2.3000}, // far outside bristol extract reach
	}
	raw, _ := json.Marshal(map[string]any{"lat": 51.4545, "lng": -2.5879, "max_minutes": 15, "targets": targets})
	req := httptest.NewRequest("POST", "/v1/drive-metrics", bytes.NewReader(raw))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 30000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("drive-metrics: err=%v status=%v", err, resp.StatusCode)
	}
	var dm struct {
		Results []struct {
			ID    int64    `json:"id"`
			Mins  *float64 `json:"mins"`
			Miles *float64 `json:"miles"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&dm); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(dm.Results) != 3 {
		t.Fatalf("got %d results", len(dm.Results))
	}
	lbl := eng.QueryLabels(51.4545, -2.5879, 15*60)
	for i, tg := range targets {
		v := nearestNodeForMode(g, tg["lat"].(float64), tg["lng"].(float64), Drive)
		want, wantM := eng.ArrivalAtBaseNodeM(lbl, v)
		got := dm.Results[i]
		if want == f32Inf || want > lbl.T {
			if got.Mins != nil {
				t.Fatalf("target %d should be unreachable, got %v mins", i, *got.Mins)
			}
			continue
		}
		if got.Mins == nil || math.Abs(*got.Mins-float64(want)/60) > 0.01 {
			t.Fatalf("target %d mins %v vs engine %.2f", i, got.Mins, float64(want)/60)
		}
		if wantM != f32Inf && (got.Miles == nil || math.Abs(*got.Miles-float64(wantM)/1609.344) > 0.01) {
			t.Fatalf("target %d miles %v vs engine %.2f", i, got.Miles, float64(wantM)/1609.344)
		}
	}
}

// TestDriveTimeEngineFastPath: the engine answer must agree with the sweep
// fallback for the same pair, and add road miles.
func TestDriveTimeEngineFastPath(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := stage2Live
	defer func() { stage2Live = prev }()
	app := newApp(g, "", false)

	url := "/v1/drive-time?lat=51.4545&lng=-2.5879&tolat=51.4700&tolng=-2.6000&max_minutes=30"
	fetch := func() (bool, float64, *float64) {
		req := httptest.NewRequest("GET", url, nil)
		resp, err := app.Test(req, 30000)
		if err != nil || resp.StatusCode != 200 {
			t.Fatalf("drive-time: err=%v status=%v", err, resp.StatusCode)
		}
		var r struct {
			Reachable  bool     `json:"reachable"`
			DriveMin   float64  `json:"drive_min"`
			DriveMiles *float64 `json:"drive_miles"`
		}
		if err := json.NewDecoder(resp.Body).Decode(&r); err != nil {
			t.Fatalf("decode: %v", err)
		}
		return r.Reachable, r.DriveMin, r.DriveMiles
	}

	stage2Live = nil
	okSweep, minSweep, _ := fetch()
	stage2Live = eng
	okEng, minEng, milesEng := fetch()

	if !okSweep || !okEng {
		t.Fatalf("reachable: sweep=%v engine=%v", okSweep, okEng)
	}
	// The engine is exact; the sweep is exact within its pruning box — allow
	// the engine to be equal or slightly better, never worse.
	if minEng > minSweep+0.02 {
		t.Fatalf("engine %.3fmin worse than sweep %.3fmin", minEng, minSweep)
	}
	if math.Abs(minEng-minSweep) > 0.02 {
		t.Logf("engine found a better path: %.3f vs %.3f min", minEng, minSweep)
	}
	if milesEng == nil || *milesEng <= 0 {
		t.Fatalf("engine path should include drive_miles, got %v", milesEng)
	}
}

func TestBlurRoadAware(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)
	app := newApp(g, "", false)

	fetch := func(lat, lng float64) (float64, float64, float64) {
		url := fmt.Sprintf("/v1/blur?lat=%f&lng=%f&metres=400", lat, lng)
		req := httptest.NewRequest("GET", url, nil)
		resp, err := app.Test(req, 30000)
		if err != nil || resp.StatusCode != 200 {
			t.Fatalf("blur: err=%v status=%v", err, resp.StatusCode)
		}
		var r struct {
			Lat   float64 `json:"lat"`
			Lng   float64 `json:"lng"`
			Roadm float64 `json:"roadm"`
		}
		if err := json.NewDecoder(resp.Body).Decode(&r); err != nil {
			t.Fatalf("decode: %v", err)
		}
		return r.Lat, r.Lng, r.Roadm
	}

	// Deterministic: same input, same output.
	la1, ln1, rm1 := fetch(51.4545, -2.5879)
	la2, ln2, rm2 := fetch(51.4545, -2.5879)
	if la1 != la2 || ln1 != ln2 || rm1 != rm2 {
		t.Fatalf("blur not deterministic: (%f,%f,%f) vs (%f,%f,%f)", la1, ln1, rm1, la2, ln2, rm2)
	}
	// Displaced, and within the road-metre ring.
	if rm1 < 200 || rm1 > 600 {
		t.Fatalf("blur road distance %f outside [200,600]", rm1)
	}
	if la1 == 51.4545 && ln1 == -2.5879 {
		t.Fatal("blur returned the input location")
	}

	// The property that matters: the blurred point is ROAD-connected to the
	// original within the ring, verified with an independent search — a naive
	// circular blur cannot guarantee this near water.
	origin := nearestNodeForMode(g, 51.4545, -2.5879, Drive)
	_, baseM := baseDriveDijkstraM(g, origin, 0, 600)
	blurred := nearestNodeForMode(g, la1, ln1, Drive)
	m, reached := baseM[blurred]
	if !reached {
		t.Fatal("blurred point not road-reachable from the original within the search bound")
	}
	if math.Abs(float64(m)-rm1) > 50 {
		t.Fatalf("reported road distance %f disagrees with independent search %f", rm1, m)
	}

	// Different nearby inputs blur to different places (no shared anchor).
	la3, ln3, _ := fetch(51.4600, -2.5900)
	if la3 == la1 && ln3 == ln1 {
		t.Fatal("distinct inputs blurred to the identical point")
	}
}
