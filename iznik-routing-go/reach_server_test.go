package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"math"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
)

func TestReachEndpoints(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	setReachLive(eng)
	defer func() { setReachLive(prev) }()

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
	prev := reachEngine()
	setReachLive(nil)
	defer func() { setReachLive(prev) }()
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
	prev := reachEngine()
	setReachLive(eng)
	defer func() { setReachLive(prev) }()
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
		v := nearestDriveNode(g, tg["lat"].(float64), tg["lng"].(float64))
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
	prev := reachEngine()
	defer func() { setReachLive(prev) }()
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

	setReachLive(nil)
	okSweep, minSweep, _ := fetch()
	setReachLive(eng)
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
	origin := nearestDriveNode(g, 51.4545, -2.5879)
	_, baseM := baseDriveDijkstraM(g, origin, 0, 600)
	blurred := nearestDriveNode(g, la1, ln1)
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

func TestBlurNaNAndFloors(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)
	app := newApp(g, "", false)

	// metres=NaN must fall back to the 400m default, not poison the search.
	req := httptest.NewRequest("GET", "/v1/blur?lat=51.4545&lng=-2.5879&metres=NaN", nil)
	resp, err := app.Test(req, 30000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("blur NaN: err=%v status=%v", err, resp.StatusCode)
	}
	var r struct {
		Lat   float64 `json:"lat"`
		Lng   float64 `json:"lng"`
		Roadm float64 `json:"roadm"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&r); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if r.Roadm < 200 || r.Roadm > 600 {
		t.Fatalf("NaN metres: roadm %f outside default ring", r.Roadm)
	}

	// lat=NaN is a 400.
	req = httptest.NewRequest("GET", "/v1/blur?lat=NaN&lng=-2.5879", nil)
	resp, _ = app.Test(req, 30000)
	if resp.StatusCode != 400 {
		t.Fatalf("NaN lat: status %v, want 400", resp.StatusCode)
	}

	// Floors over a spread of origins: converged road metres within the ring
	// AND crow displacement over the floor, verified independently.
	for i, org := range [][2]float64{{51.4545, -2.5879}, {51.4700, -2.6000}, {51.4400, -2.5600}, {51.4650, -2.5500}} {
		url := fmt.Sprintf("/v1/blur?lat=%f&lng=%f&metres=400", org[0], org[1])
		req := httptest.NewRequest("GET", url, nil)
		resp, err := app.Test(req, 30000)
		if err != nil || resp.StatusCode != 200 {
			t.Fatalf("blur %d: err=%v status=%v", i, err, resp.StatusCode)
		}
		if err := json.NewDecoder(resp.Body).Decode(&r); err != nil {
			t.Fatalf("decode: %v", err)
		}
		if r.Roadm == 0 {
			continue // no-road / degenerate fallback: returns input
		}
		if r.Roadm < 200 || r.Roadm > 600 {
			t.Fatalf("blur %d: roadm %f outside [200,600]", i, r.Roadm)
		}
		crow := haversineM(org[0], org[1], r.Lat, r.Lng)
		if crow < 100 {
			t.Fatalf("blur %d: crow displacement %fm under the 100m floor", i, crow)
		}
		// Independent check: converged road distance from the origin snap.
		origin := nearestDriveNode(g, org[0], org[1])
		_, baseM := baseDriveDijkstraM(g, origin, 0, 900)
		blurred := nearestDriveNode(g, r.Lat, r.Lng)
		m, ok := baseM[blurred]
		if !ok || math.Abs(float64(m)-r.Roadm) > 50 {
			t.Fatalf("blur %d: independent road distance %v (ok=%v) vs reported %f", i, m, ok, r.Roadm)
		}
	}
}

func TestBlurBatchMatchesSingle(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g := loadBristol(t)
	app := newApp(g, "", false)

	pts := [][2]float64{{51.4545, -2.5879}, {51.4700, -2.6000}, {0, 0}}
	body := map[string]any{"metres": 400, "points": []map[string]any{}}
	for i, p := range pts {
		body["points"] = append(body["points"].([]map[string]any), map[string]any{"id": i, "lat": p[0], "lng": p[1]})
	}
	raw, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/v1/blur-batch", bytes.NewReader(raw))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 30000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("blur-batch: err=%v status=%v", err, resp.StatusCode)
	}
	var br struct {
		Results []struct {
			ID    int64   `json:"id"`
			Lat   float64 `json:"lat"`
			Lng   float64 `json:"lng"`
			Roadm float64 `json:"roadm"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&br); err != nil {
		t.Fatalf("decode: %v", err)
	}
	for i, p := range pts[:2] {
		la, ln, rm := roadBlurPoint(g, p[0], p[1], 400)
		got := br.Results[i]
		if got.Lat != la || got.Lng != ln || got.Roadm != rm {
			t.Fatalf("point %d: batch (%f,%f,%f) vs single (%f,%f,%f)", i, got.Lat, got.Lng, got.Roadm, la, ln, rm)
		}
	}
	// (0,0) sentinel passes through untouched.
	if br.Results[2].Lat != 0 || br.Results[2].Lng != 0 || br.Results[2].Roadm != 0 {
		t.Fatalf("null island must pass through: %+v", br.Results[2])
	}
}

// TestGroupProximityEngineMatchesSweep: the engine path must agree with the
// flat two-sweep implementation for the same offer + seed set. The flat path
// prunes to a bounding box, so where they differ the engine must be finding a
// strictly better (shorter) road — never a worse one.
func TestGroupProximityEngineMatchesSweep(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	setReachLive(eng)
	defer func() { setReachLive(prev) }()

	// Synthetic "group": a spread of drive-snappable junctions east of centre.
	var seeds []NodeID
	for v := NodeID(1); v <= NodeID(g.NodeCount()) && len(seeds) < 120; v += 211 {
		if eng.Ov.IdxOf(v) != 0 && (g.DriveSnappable == nil || g.DriveSnappable.Get(int(v))) {
			nd := g.Nodes[v]
			if nd.Lng > -2.58 && nd.Lat > 51.43 && nd.Lat < 51.49 {
				seeds = append(seeds, v)
			}
		}
	}
	if len(seeds) < 25 {
		t.Fatalf("degenerate seed set: %d", len(seeds))
	}

	for _, offer := range [][2]float64{{51.4545, -2.5879}, {51.4700, -2.6100}} {
		maxSecs := float32(1800)
		ec, ef, eok, handled := engineGroupProximity(offer[0], offer[1], seeds, maxSecs)
		if !handled {
			t.Fatal("engine path should handle drive mode")
		}
		fc, ff, fok := groupProximity(g, offer[0], offer[1], seeds, maxSecs)
		if eok != fok {
			t.Fatalf("reachable disagreement: engine %v sweep %v", eok, fok)
		}
		if !eok {
			continue
		}
		if math.Abs(ec.DriveMin-fc.DriveMin) > 0.02 {
			if ec.DriveMin > fc.DriveMin {
				t.Fatalf("engine P %.3fmin worse than sweep %.3fmin", ec.DriveMin, fc.DriveMin)
			}
			t.Logf("engine found better P: %.3f vs %.3f (sweep bbox clipped)", ec.DriveMin, fc.DriveMin)
		}
		// Q is defined relative to P; only compare when P agreed.
		if ec.Lat == fc.Lat && ec.Lng == fc.Lng {
			if math.Abs(ef.DriveMin-ff.DriveMin) > 0.02 && ef.DriveMin < ff.DriveMin {
				t.Fatalf("engine Q %.3fmin shorter than sweep %.3fmin: engine missed a farther point", ef.DriveMin, ff.DriveMin)
			}
		}
	}
}

func TestLeafEndpointAndBudgetOverride(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	setReachLive(eng)
	defer func() { setReachLive(prev) }()
	app := newApp(g, "", false)

	// Leaf lookup for a junction point.
	req := httptest.NewRequest("GET", "/v1/leaf?lat=51.4545&lng=-2.5879", nil)
	resp, err := app.Test(req, 30000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("leaf: err=%v status=%v", err, resp.StatusCode)
	}
	var lr struct {
		Leaves []int32 `json:"leaves"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&lr); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(lr.Leaves) == 0 {
		t.Fatal("no leaves for a road point")
	}

	// Labels carry the reached leaf list, and the member's leaf must be in it
	// when the member is in reach (the prefilter-superset property).
	lreq := httptest.NewRequest("GET", "/v1/reach-labels?lat=51.4545&lng=-2.5879&minutes=12", nil)
	lresp, _ := app.Test(lreq, 30000)
	var lab struct {
		Labels string  `json:"labels"`
		Leaves []int32 `json:"leaves"`
		T      float32 `json:"t"`
	}
	if err := json.NewDecoder(lresp.Body).Decode(&lab); err != nil {
		t.Fatalf("decode labels: %v", err)
	}
	if len(lab.Leaves) == 0 {
		t.Fatal("labels response has no leaves")
	}
	found := false
	for _, l := range lab.Leaves {
		for _, m := range lr.Leaves {
			if l == m {
				found = true
			}
		}
	}
	if !found {
		t.Fatal("origin's own leaf missing from reached leaves")
	}

	// Budget override: a nearby point in reach at full T, out at t=60s.
	body := fmt.Sprintf(`{"labels":%q,"t":60,"points":[{"lat":51.4700,"lng":-2.6000}]}`, lab.Labels)
	areq := httptest.NewRequest("POST", "/v1/reach-arrival", strings.NewReader(body))
	areq.Header.Set("Content-Type", "application/json")
	aresp, _ := app.Test(areq, 30000)
	var ar struct {
		Results []struct {
			Arrival *float32 `json:"arrival"`
			In      bool     `json:"in"`
		} `json:"results"`
	}
	if err := json.NewDecoder(aresp.Body).Decode(&ar); err != nil {
		t.Fatalf("decode arrival: %v", err)
	}
	if len(ar.Results) != 1 || ar.Results[0].Arrival == nil {
		t.Fatalf("bad arrival response: %+v", ar)
	}
	if ar.Results[0].In {
		t.Fatalf("t=60s override should exclude a %.0fs arrival", *ar.Results[0].Arrival)
	}

	// t:0 is a real budget (nothing in reach), NOT the same as omitting t.
	for _, c := range []struct {
		body   string
		wantIn bool
	}{
		{fmt.Sprintf(`{"labels":%q,"t":0,"points":[{"lat":51.4700,"lng":-2.6000}]}`, lab.Labels), false},
		{fmt.Sprintf(`{"labels":%q,"points":[{"lat":51.4700,"lng":-2.6000}]}`, lab.Labels), true},
	} {
		zreq := httptest.NewRequest("POST", "/v1/reach-arrival", strings.NewReader(c.body))
		zreq.Header.Set("Content-Type", "application/json")
		zresp, _ := app.Test(zreq, 30000)
		var zr struct {
			Results []struct {
				In bool `json:"in"`
			} `json:"results"`
		}
		if err := json.NewDecoder(zresp.Body).Decode(&zr); err != nil {
			t.Fatalf("decode: %v", err)
		}
		if len(zr.Results) != 1 || zr.Results[0].In != c.wantIn {
			t.Fatalf("body %s: in=%v want %v", c.body[len(c.body)-60:], zr.Results[0].In, c.wantIn)
		}
	}

	// A negative t is rejected.
	nreq := httptest.NewRequest("POST", "/v1/reach-arrival",
		strings.NewReader(fmt.Sprintf(`{"labels":%q,"t":-1,"points":[{"lat":51.47,"lng":-2.6}]}`, lab.Labels)))
	nreq.Header.Set("Content-Type", "application/json")
	nresp, _ := app.Test(nreq, 30000)
	if nresp.StatusCode != 400 {
		t.Fatalf("negative t: expected 400, got %d", nresp.StatusCode)
	}
}

// Stored labels are only meaningful against the partition they were computed
// on: leaf ids are bisection-order artifacts. A blob whose embedded partition
// fingerprint differs from the live engine's must be rejected, not evaluated.
func TestLabelsRejectDifferentPartitionBuild(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	_, eng := buildBristolEngine(t)
	lbl := eng.QueryLabels(51.4545, -2.5879, 720)
	blob := eng.EncodeLabels(lbl)
	if _, err := eng.DecodeLabels(blob); err != nil {
		t.Fatalf("same-partition round trip failed: %v", err)
	}
	// Flip one fingerprint byte: decode must fail with the partition message.
	bad := append([]byte(nil), blob...)
	bad[4] ^= 0xff
	if _, err := eng.DecodeLabels(bad); err == nil {
		t.Fatal("decode accepted a blob from a different partition build")
	}
}
