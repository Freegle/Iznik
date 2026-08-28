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
	prev := reachLive
	reachLive = eng
	defer func() { reachLive = prev }()
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
	prev := reachLive
	reachLive = eng
	defer func() { reachLive = prev }()
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

	// Discover: the leaf loader offers 1 (already asked), 6 (admitted) and
	// 7 (label says out at its current budget). Only 6 comes back.
	prevLeaf := leafRowLoader
	leafRowLoader = func(leaf int32) []uint64 { return []uint64{1, 6, 7} }
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
	fmt.Println("max/rejected/discover ok")
}
