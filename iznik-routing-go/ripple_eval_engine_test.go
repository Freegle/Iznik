package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
)

// evalPointsOnly runs one points-only /v1/ripple-eval over the given app and returns the
// decoded body.
func evalPointsOnly(t *testing.T, app *fiber.App, body map[string]any) struct {
	Results []struct {
		DriveMin *float64 `json:"drive_min"`
	} `json:"results"`
	FrontierMedianMiles *float64 `json:"frontier_median_miles"`
} {
	t.Helper()
	var out struct {
		Results []struct {
			DriveMin *float64 `json:"drive_min"`
		} `json:"results"`
		FrontierMedianMiles *float64 `json:"frontier_median_miles"`
	}
	enc, err := json.Marshal(body)
	if err != nil {
		t.Fatal(err)
	}
	req := httptest.NewRequest(http.MethodPost, "/v1/ripple-eval", bytes.NewReader(enc))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 60000)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d: %.300s", resp.StatusCode, raw)
	}
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatalf("decode: %v (body %.300s)", err, raw)
	}
	return out
}

// A points-only eval (the sysadmin drive-time sampler's shape: it reads drive_min and nothing
// else) is answered from the reach engine when one is live. It must give the sweep's answer
// for every point: the same reachable set under the budget, the same minutes, and the same
// snap fallback past a one-way source. The points are every Nth node of the fixture evaluated
// at its own coordinates - so each snaps to itself first - plus every pure source node (out-
// edges but no in-edges: leavable, never arrivable), the shape that made the sweep path try
// several snap candidates in the first place.
func TestRippleEvalPointsOnlyEngineMatchesSweep(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	defer func() { setReachLive(prev) }()
	app := newApp(g, "", false)

	indeg := make([]int, g.NodeCount()+1)
	for v := NodeID(1); v <= NodeID(g.NodeCount()); v++ {
		for _, e := range g.EdgesFrom(v) {
			indeg[e.To]++
		}
	}
	var points [][2]float64
	sources := 0
	for v := NodeID(1); v <= NodeID(g.NodeCount()); v++ {
		n := g.Nodes[v]
		isSource := indeg[v] == 0 && len(g.EdgesFrom(v)) > 0
		if isSource {
			sources++
		}
		if isSource || v%997 == 0 {
			points = append(points, [2]float64{float64(n.Lng), float64(n.Lat)})
		}
	}
	if len(points) < 50 {
		t.Fatalf("fixture too small: only %d sample points", len(points))
	}
	t.Logf("%d points (%d one-way sources)", len(points), sources)

	// A 10-minute budget from the centre leaves a real share of the fixture unreachable, so the
	// "null in both" side of the contract is exercised, not just the minutes.
	const budgetMin = 10.0
	body := map[string]any{
		"lat": 51.4545, "lng": -2.5879, "mode": "drive", "max_minutes": budgetMin,
		"points": points, "points_only": true,
	}

	setReachLive(nil)
	sweep := evalPointsOnly(t, app, body)
	setReachLive(eng)
	engine := evalPointsOnly(t, app, body)

	if len(sweep.Results) != len(points) || len(engine.Results) != len(points) {
		t.Fatalf("result counts: sweep=%d engine=%d want %d", len(sweep.Results), len(engine.Results), len(points))
	}
	reached, mismatches := 0, 0
	for i := range points {
		s, e := sweep.Results[i].DriveMin, engine.Results[i].DriveMin
		switch {
		case s == nil && e == nil:
			continue
		case s == nil || e == nil:
			// The two searches agree up to float noise at the exact budget boundary (see
			// TestEngineReachedNodesMatchesFlatIsochrone), so a point one side can see and
			// the other cannot is only a disagreement when it is clearly inside the budget.
			got := s
			if got == nil {
				got = e
			}
			if budgetMin-*got <= 0.02 {
				continue
			}
			mismatches++
			if mismatches <= 10 {
				t.Errorf("point %d %v: reachable disagrees (sweep=%v engine=%v)", i, points[i], fmtMin(s), fmtMin(e))
			}
		case math.Abs(*s-*e) > 0.02:
			mismatches++
			if mismatches <= 10 {
				t.Errorf("point %d %v: sweep %.3f min vs engine %.3f min", i, points[i], *s, *e)
			}
		default:
			reached++
		}
	}
	if mismatches > 0 {
		t.Fatalf("%d of %d points disagree between the sweep and the engine", mismatches, len(points))
	}
	if reached == 0 || reached == len(points) {
		t.Fatalf("test premise broken: %d of %d points reached - want a mix of reached and unreachable", reached, len(points))
	}
}

func fmtMin(m *float64) string {
	if m == nil {
		return "null"
	}
	return fmt.Sprintf("%.3f min", *m)
}

// The fast path is only for the pure per-point shape. Asking for the frontier (or a polygon)
// alongside points_only still needs the sweep's road-distance map, so it must keep taking the
// sweep even with an engine live.
func TestRippleEvalPointsOnlyWithFrontierStillSweeps(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	defer func() { setReachLive(prev) }()
	setReachLive(eng)
	app := newApp(g, "", false)

	out := evalPointsOnly(t, app, map[string]any{
		"lat": 51.4545, "lng": -2.5879, "mode": "drive", "max_minutes": 10,
		"points": [][2]float64{{-2.5879, 51.4545}}, "points_only": true, "frontier": true,
	})
	if out.FrontierMedianMiles == nil || *out.FrontierMedianMiles <= 0 {
		t.Fatalf("frontier requested with points_only but not returned: %v", out.FrontierMedianMiles)
	}
	if len(out.Results) != 1 || out.Results[0].DriveMin == nil {
		t.Fatalf("the origin itself should be reachable: %+v", out.Results)
	}
}

// With no engine live the fast path declines and the sweep answers, byte-for-byte as before.
func TestRippleEvalPointsFromEngineDeclinesWithoutEngine(t *testing.T) {
	prev := reachEngine()
	defer func() { setReachLive(prev) }()
	setReachLive(nil)
	if _, ok := rippleEvalPointsFromEngine(nil, rippleEvalRequest{}, 600); ok {
		t.Fatal("fast path claimed to answer with no engine live")
	}
}
