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
