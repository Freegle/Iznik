package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
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
