package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"
)

// The stored-label membership endpoint must agree with the engine's own live
// answer: a point inside a post's current budget is "in", outside is "out",
// a post with no stored label is "nolabels" (caller keeps its cell verdict),
// and a label from a different partition build is treated as no label.
func TestReachEvalVerdicts(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	setReachLive(eng)
	defer func() { setReachLive(prev) }()
	resetReachEvalForTest()

	// Post at Bristol centre with a 30-minute maximum label.
	const postLat, postLng = 51.4545, -2.5879
	blob := eng.EncodeLabels(eng.QueryLabels(postLat, postLng, 30*60))

	// A corrupted-fingerprint copy: decodes on some other build only.
	badBlob := append([]byte(nil), blob...)
	badBlob[4] ^= 0xff

	schedule := `[{"tick":1,"drive_min":5},{"tick":2,"drive_min":30}]`
	prevLoader := evalRowLoader
	evalRowLoader = func(ids []uint64) ([]evalRow, error) {
		var out []evalRow
		for _, id := range ids {
			switch id {
			case 1: // tick 1: 5-minute current budget
				out = append(out, evalRow{msgid: 1, blob: blob, tick: 1, maxMin: 30, schedule: schedule})
			case 2: // tick 2: full 30 minutes
				out = append(out, evalRow{msgid: 2, blob: blob, tick: 2, maxMin: 30, schedule: schedule})
			case 3: // not backfilled yet
				out = append(out, evalRow{msgid: 3, tick: 1, maxMin: 30, schedule: schedule})
			case 4: // stale partition build
				out = append(out, evalRow{msgid: 4, blob: badBlob, tick: 2, maxMin: 30, schedule: schedule})
			}
		}
		return out, nil
	}
	defer func() { evalRowLoader = prevLoader; resetReachEvalForTest() }()

	app := newApp(g, "", false)

	// A member ~10 road-minutes away: inside the 30-minute budget, outside 5.
	const memberLat, memberLng = 51.47, -2.60
	body, _ := json.Marshal(map[string]any{
		"lat": memberLat, "lng": memberLng,
		"msgids": []uint64{1, 2, 3, 4, 5},
	})
	req := httptest.NewRequest("POST", "/v1/reach-eval", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 60000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("reach-eval: err=%v status=%v", err, resp.StatusCode)
	}
	var parsed struct {
		Results []reachEvalResult `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		t.Fatalf("decode: %v", err)
	}
	got := map[uint64]string{}
	for _, r := range parsed.Results {
		got[r.Msgid] = r.Verdict
	}
	want := map[uint64]string{
		1: "out",      // current tick budget (5 min) does not reach the member
		2: "in",       // full budget does
		3: "nolabels", // not backfilled
		4: "nolabels", // different partition build
		5: "nolabels", // no reach row at all
	}
	for id, w := range want {
		if got[id] != w {
			t.Fatalf("msgid %d: got %q want %q (all: %v)", id, got[id], w, got)
		}
	}

	// The "in" verdict must agree with the engine's own live evaluation.
	lbl := eng.QueryLabels(postLat, postLng, 30*60)
	if arr := eng.Arrival(lbl, memberLat, memberLng); arr > 30*60 {
		t.Fatalf("test premise broken: member not within 30min (arr %f)", arr)
	}
	fmt.Println("verdicts", got)
}

// The extended arms of the endpoint: budget "max" evaluates at the label's own
// full budget (first-reply targeting), a rejected group's area forces "out"
// whatever the label says (the durable record of a per-group retraction), and
// discover surfaces label-admitted posts the caller's candidate list missed -
// but only the admitted ones, and never ids the caller already asked about.
func TestReachEvalMaxRejectedDiscover(t *testing.T) {
	if testing.Short() {
		t.Skip("short mode")
	}
	g, eng := buildBristolEngine(t)
	prev := reachEngine()
	setReachLive(eng)
	defer func() { setReachLive(prev) }()
	resetReachEvalForTest()

	const postLat, postLng = 51.4545, -2.5879
	blob := eng.EncodeLabels(eng.QueryLabels(postLat, postLng, 30*60))
	schedule := `[{"tick":1,"drive_min":5},{"tick":2,"drive_min":30}]`

	prevLoader := evalRowLoader
	evalRowLoader = func(ids []uint64) ([]evalRow, error) {
		var out []evalRow
		for _, id := range ids {
			switch id {
			case 1: // tick 1: 5-minute current budget - "out" today, "in" at max
				out = append(out, evalRow{msgid: 1, blob: blob, tick: 1, maxMin: 30, schedule: schedule})
			case 2: // full budget, but the member sits in a rejected group's area
				out = append(out, evalRow{msgid: 2, blob: blob, tick: 2, maxMin: 30, schedule: schedule, rejected: "[99]"})
			case 6: // discover: label admits the member
				out = append(out, evalRow{msgid: 6, blob: blob, tick: 2, maxMin: 30, schedule: schedule})
			case 7: // discover: label does NOT admit at the current budget
				out = append(out, evalRow{msgid: 7, blob: blob, tick: 1, maxMin: 30, schedule: schedule})
			case 8: // discover: admitted by its label but FROZEN (held)
				out = append(out, evalRow{msgid: 8, blob: blob, tick: 2, maxMin: 30, schedule: schedule, held: true})
			case 9: // beyond the current budget, but the member stands in the
				// post's ORIGIN group's area, which the stored reach unions in
				out = append(out, evalRow{msgid: 9, blob: blob, tick: 1, maxMin: 30, schedule: schedule, originGid: 55})
			}
		}
		return out, nil
	}
	defer func() { evalRowLoader = prevLoader; resetReachEvalForTest() }()

	const memberLat, memberLng = 51.47, -2.60

	call := func(body map[string]any) (map[uint64]string, []uint64) {
		app := newApp(g, "", false)
		b, _ := json.Marshal(body)
		req := httptest.NewRequest("POST", "/v1/reach-eval", bytes.NewReader(b))
		req.Header.Set("Content-Type", "application/json")
		resp, err := app.Test(req, 60000)
		if err != nil || resp.StatusCode != 200 {
			t.Fatalf("reach-eval: err=%v status=%v", err, resp.StatusCode)
		}
		var parsed struct {
			Results    []reachEvalResult `json:"results"`
			Discovered []reachEvalResult `json:"discovered"`
		}
		if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
			t.Fatalf("decode: %v", err)
		}
		got := map[uint64]string{}
		for _, r := range parsed.Results {
			got[r.Msgid] = r.Verdict
		}
		var disc []uint64
		for _, r := range parsed.Discovered {
			disc = append(disc, r.Msgid)
		}
		return got, disc
	}

	// Budget "max": the 5-minute tick no longer constrains msgid 1.
	got, _ := call(map[string]any{
		"lat": memberLat, "lng": memberLng, "msgids": []uint64{1}, "budget": "max",
	})
	if got[1] != "in" {
		t.Fatalf("budget=max msgid 1: got %q want in", got[1])
	}
	resetReachEvalForTest()

	// Rejected group: seed the area cache with a box around the member, so
	// msgid 2 is "out" despite its label admitting the point.
	groupAreaMu.Lock()
	groupAreaCache[99] = groupAreaEntry{
		rings: [][][2]float64{{
			{memberLng - 0.01, memberLat - 0.01}, {memberLng + 0.01, memberLat - 0.01},
			{memberLng + 0.01, memberLat + 0.01}, {memberLng - 0.01, memberLat + 0.01},
			{memberLng - 0.01, memberLat - 0.01},
		}},
		expires: time.Now().Add(time.Hour),
	}
	groupAreaMu.Unlock()
	got, _ = call(map[string]any{
		"lat": memberLat, "lng": memberLng, "msgids": []uint64{2},
	})
	if got[2] != "out" {
		t.Fatalf("rejected-area msgid 2: got %q want out", got[2])
	}
	resetReachEvalForTest()

	// Discover: the leaf loader offers 1 (already asked), 6 (admitted),
	// 7 (label says out at its current budget) and 8 (admitted but held -
	// frozen posts are hidden on every surface and must not be resurrected).
	// Only 6 comes back.
	prevLeaf := leafRowLoader
	leafRowLoader = func(leaf int32) []uint64 { return []uint64{1, 6, 7, 8} }
	defer func() { leafRowLoader = prevLeaf }()
	got, disc := call(map[string]any{
		"lat": memberLat, "lng": memberLng, "msgids": []uint64{1}, "discover": true,
	})
	if got[1] != "out" {
		t.Fatalf("discover run msgid 1: got %q want out", got[1])
	}
	if len(disc) != 1 || disc[0] != 6 {
		t.Fatalf("discovered: got %v want [6]", disc)
	}
	resetReachEvalForTest()

	// An EMPTY candidate list with discover still answers (a member covered
	// by no grid can still be admitted by a stored label) - through the real
	// handler, not a stub.
	got, disc = call(map[string]any{
		"lat": memberLat, "lng": memberLng, "msgids": []uint64{}, "discover": true,
	})
	if len(got) != 0 {
		t.Fatalf("empty-candidates run: unexpected verdicts %v", got)
	}
	if len(disc) != 1 || disc[0] != 6 {
		t.Fatalf("empty-candidates discovered: got %v want [6]", disc)
	}
	resetReachEvalForTest()

	// Origin-group union: out at the current budget, but flagged so callers
	// let the cell grid (which holds the union) decide.
	groupAreaMu.Lock()
	groupAreaCache[55] = groupAreaEntry{
		rings: [][][2]float64{{
			{memberLng - 0.01, memberLat - 0.01}, {memberLng + 0.01, memberLat - 0.01},
			{memberLng + 0.01, memberLat + 0.01}, {memberLng - 0.01, memberLat + 0.01},
			{memberLng - 0.01, memberLat - 0.01},
		}},
		expires: time.Now().Add(time.Hour),
	}
	groupAreaMu.Unlock()
	app := newApp(g, "", false)
	b, _ := json.Marshal(map[string]any{"lat": memberLat, "lng": memberLng, "msgids": []uint64{9}})
	req := httptest.NewRequest("POST", "/v1/reach-eval", bytes.NewReader(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 60000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("origin-area call: err=%v status=%v", err, resp.StatusCode)
	}
	var parsedOrigin struct {
		Results []reachEvalResult `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsedOrigin); err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(parsedOrigin.Results) != 1 || parsedOrigin.Results[0].Verdict != "out" || !parsedOrigin.Results[0].OriginArea {
		t.Fatalf("origin-area msgid 9: got %+v want out+origin_area", parsedOrigin.Results)
	}
	resetReachEvalForTest()

	// A member point that does not snap to the road network degrades to
	// all-nolabels (200), never a 4xx - a 4xx would trip the callers' shared
	// routing breaker on one member's ordinary location.
	got, disc = call(map[string]any{
		"lat": 51.0, "lng": -8.0, "msgids": []uint64{1}, "discover": true,
	})
	if got[1] != "nolabels" || len(disc) != 0 {
		t.Fatalf("ocean point: got %v disc %v want nolabels/none", got, disc)
	}
	fmt.Println("max/rejected/discover/held/empty/origin/ocean ok")
}

// POLYGON and MULTIPOLYGON group areas both subtract, including even-odd
// holes - a MULTIPOLYGON that silently parsed to nothing would let a
// moderator's per-group retraction leak.
func TestWktAreaRings(t *testing.T) {
	poly, err := wktAreaRings("POLYGON((0 0, 4 0, 4 4, 0 4, 0 0),(1 1, 3 1, 3 3, 1 3, 1 1))")
	if err != nil || len(poly) != 2 {
		t.Fatalf("polygon: rings=%d err=%v", len(poly), err)
	}
	multi, err := wktAreaRings("MULTIPOLYGON(((0 0, 4 0, 4 4, 0 4, 0 0),(1 1, 3 1, 3 3, 1 3, 1 1)),((10 10, 12 10, 12 12, 10 12, 10 10)))")
	if err != nil || len(multi) != 3 {
		t.Fatalf("multipolygon: rings=%d err=%v", len(multi), err)
	}
	evenOdd := func(rings [][][2]float64, lng, lat float64) bool {
		n := 0
		for _, r := range rings {
			if pointInRing(lng, lat, r) {
				n++
			}
		}
		return n%2 == 1
	}
	cases := []struct {
		lng, lat float64
		want     bool
	}{
		{0.5, 0.5, true}, // first part, outside the hole
		{2, 2, false},    // inside the hole
		{11, 11, true},   // second part
		{7, 7, false},    // between the parts
	}
	for _, c := range cases {
		if got := evenOdd(multi, c.lng, c.lat); got != c.want {
			t.Fatalf("(%v,%v): got %v want %v", c.lng, c.lat, got, c.want)
		}
	}
}
