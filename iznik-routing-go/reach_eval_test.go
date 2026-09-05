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
	leafRowLoader = func(leaf int32) ([]uint64, error) { return []uint64{1, 6, 7, 8}, nil }
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

// A region with more live posts than discover will evaluate must lose its OLDEST
// posts to the cap, never its newest: msgids are allotted in posting order, and
// the leaf loader hands them back in index (ascending) order, which is exactly
// how the nearby feed came to show a city member nothing from the last week.
// A post offered twice (a point straddling two regions) is evaluated once.
func TestReachEvalDiscoverNewestFirstBeyondCap(t *testing.T) {
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

	// Every candidate's label admits the member at its current budget.
	prevLoader := evalRowLoader
	evalRowLoader = func(ids []uint64) ([]evalRow, error) {
		out := make([]evalRow, 0, len(ids))
		for _, id := range ids {
			out = append(out, evalRow{msgid: id, blob: blob, tick: 2, maxMin: 30, schedule: schedule})
		}
		return out, nil
	}
	prevLeaf := leafRowLoader
	leafRowLoader = func(leaf int32) ([]uint64, error) { return []uint64{1, 2, 3, 4, 5, 6, 7, 8, 8, 7}, nil }
	prevCap := discoverMaxItems
	discoverMaxItems = 5
	defer func() {
		evalRowLoader = prevLoader
		leafRowLoader = prevLeaf
		discoverMaxItems = prevCap
		resetReachEvalForTest()
	}()

	const memberLat, memberLng = 51.47, -2.60
	app := newApp(g, "", false)
	b, _ := json.Marshal(map[string]any{"lat": memberLat, "lng": memberLng, "msgids": []uint64{}, "discover": true})
	req := httptest.NewRequest("POST", "/v1/reach-eval", bytes.NewReader(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 60000)
	if err != nil || resp.StatusCode != 200 {
		t.Fatalf("reach-eval: err=%v status=%v", err, resp.StatusCode)
	}
	var parsed struct {
		Discovered []reachEvalResult `json:"discovered"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		t.Fatalf("decode: %v", err)
	}
	got := map[uint64]bool{}
	for _, r := range parsed.Discovered {
		if got[r.Msgid] {
			t.Fatalf("msgid %d discovered twice", r.Msgid)
		}
		got[r.Msgid] = true
	}
	if len(got) != 5 {
		t.Fatalf("discovered %d posts, want the cap of 5: %v", len(got), got)
	}
	for _, want := range []uint64{8, 7, 6, 5, 4} {
		if !got[want] {
			t.Fatalf("newest post %d not discovered; got %v - the cap cut the wrong end", want, got)
		}
	}
	for _, old := range []uint64{1, 2, 3} {
		if got[old] {
			t.Fatalf("oldest post %d discovered ahead of a newer one: %v", old, got)
		}
	}
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

// A discover request whose label store cannot be read must fail CLOSED with a
// 503 the caller can see - never a 200 with nothing discovered. Since the cell
// grids retired, discovery is the only way a post reaches the nearby feed and
// badge; a 200-with-nothing was read as "nothing in reach", cached by the badge
// for 30 seconds, and painted "You're up to date" over a feed that had simply
// not been loaded. Both loaders are covered: the region candidates and the
// labels for them.
func TestReachEvalDiscoverFailsClosedWhenTheStoreIsUnreadable(t *testing.T) {
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
	prevLeaf := leafRowLoader
	defer func() {
		evalRowLoader = prevLoader
		leafRowLoader = prevLeaf
		resetReachEvalForTest()
	}()

	const memberLat, memberLng = 51.47, -2.60
	app := newApp(g, "", false)
	discover := func() (int, map[string]any) {
		b, _ := json.Marshal(map[string]any{"lat": memberLat, "lng": memberLng, "msgids": []uint64{}, "discover": true})
		req := httptest.NewRequest("POST", "/v1/reach-eval", bytes.NewReader(b))
		req.Header.Set("Content-Type", "application/json")
		resp, err := app.Test(req, 60000)
		if err != nil {
			t.Fatalf("reach-eval: %v", err)
		}
		var body map[string]any
		_ = json.NewDecoder(resp.Body).Decode(&body)
		return resp.StatusCode, body
	}

	// The region read fails: 503, and nothing is cached for the region.
	leafRowLoader = func(leaf int32) ([]uint64, error) { return nil, fmt.Errorf("driver: bad connection") }
	evalRowLoader = func(ids []uint64) ([]evalRow, error) {
		t.Fatalf("labels must not be asked for when the region read failed")
		return nil, nil
	}
	if status, _ := discover(); status != 503 {
		t.Fatalf("region read failure: status %d, want 503", status)
	}

	// The region reads but its labels do not: 503 again, never an empty 200.
	leafRowLoader = func(leaf int32) ([]uint64, error) { return []uint64{1, 2, 3}, nil }
	evalRowLoader = func(ids []uint64) ([]evalRow, error) { return nil, fmt.Errorf("driver: bad connection") }
	if status, _ := discover(); status != 503 {
		t.Fatalf("label read failure: status %d, want 503", status)
	}

	// The same member, once the store answers: a normal 200 with the posts -
	// the failures above cached nothing that would hide them now.
	evalRowLoader = func(ids []uint64) ([]evalRow, error) {
		out := make([]evalRow, 0, len(ids))
		for _, id := range ids {
			out = append(out, evalRow{msgid: id, blob: blob, tick: 2, maxMin: 30, schedule: schedule})
		}
		return out, nil
	}
	status, body := discover()
	if status != 200 {
		t.Fatalf("recovered read: status %d, want 200", status)
	}
	disc, _ := body["discovered"].([]any)
	if len(disc) != 3 {
		t.Fatalf("recovered read: discovered %v, want the 3 region posts", body["discovered"])
	}

	// Nothing discovered is an empty list, not null: null is what a swallowed
	// failure used to look like, and a captured response must tell them apart.
	// The region read above is cached for a minute, so drop it first - the
	// empty region must come from the loader, not from the cache.
	resetReachEvalForTest()
	leafRowLoader = func(leaf int32) ([]uint64, error) { return nil, nil }
	status, body = discover()
	if status != 200 {
		t.Fatalf("empty region: status %d, want 200", status)
	}
	if v, ok := body["discovered"].([]any); !ok || v == nil || len(v) != 0 {
		t.Fatalf("empty region must serialise discovered as [], got %v", body["discovered"])
	}
}

// Crossing the cache cap inside one request must not cost that request its
// own answers. The bound used to reset both maps right after evalLoad had
// filled them, so the crossing request found nothing and answered "nolabels"
// for every post - discovery then serialised as nothing found, and a member's
// badge read zero for one poll. Aged entries are what the bound evicts, and
// only once the cap is crossed.
func TestReachEvalCacheCapDoesNotEvictWhatThisRequestLoaded(t *testing.T) {
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
	prevLeaf := leafRowLoader
	prevCap := evalCacheCap
	evalRowLoader = func(ids []uint64) ([]evalRow, error) {
		out := make([]evalRow, 0, len(ids))
		for _, id := range ids {
			out = append(out, evalRow{msgid: id, blob: blob, tick: 2, maxMin: 30, schedule: schedule})
		}
		return out, nil
	}
	leafRowLoader = func(leaf int32) ([]uint64, error) { return []uint64{11, 12, 13, 14, 15, 16}, nil }
	evalCacheCap = 4
	defer func() {
		evalRowLoader = prevLoader
		leafRowLoader = prevLeaf
		evalCacheCap = prevCap
		resetReachEvalForTest()
	}()

	const memberLat, memberLng = 51.47, -2.60
	app := newApp(g, "", false)
	call := func(msgids []uint64) (map[uint64]string, []uint64) {
		b, _ := json.Marshal(map[string]any{"lat": memberLat, "lng": memberLng, "msgids": msgids, "discover": true})
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

	// Two asked posts plus six discovered: eight entries against a cap of
	// four, crossed twice within this one request. Every answer must stand.
	got, disc := call([]uint64{1, 2})
	if got[1] != "in" || got[2] != "in" {
		t.Fatalf("asked posts after crossing the cap: got %v want in/in", got)
	}
	if len(disc) != 6 {
		t.Fatalf("discovered after crossing the cap: got %v want all 6 region posts", disc)
	}

	// Nothing loaded in the last few seconds is evicted, however far over the
	// cap that leaves the cache: those entries may be mid-flight elsewhere.
	evalMu.Lock()
	if n := len(evalLabels); n != 8 {
		t.Fatalf("fresh entries must survive the bound, have %d want 8", n)
	}
	// Age everything past the grace, then one more load crosses the cap again
	// and this time the bound has something it may evict: the oldest, down to
	// half the cap, never the entry just loaded.
	for id, be := range evalBudgets {
		be.expires = be.expires.Add(-evalRecentGrace)
		evalBudgets[id] = be
	}
	evalMu.Unlock()
	// Only the region cache is dropped here (not the label cache being aged):
	// otherwise the region re-offers its six posts, they reload as FRESH
	// entries, and the bound rightly keeps them - which is not what this
	// step measures.
	leafCandMu.Lock()
	leafCandCache = map[int32]leafCandEntry{}
	leafCandMu.Unlock()
	leafRowLoader = func(leaf int32) ([]uint64, error) { return nil, nil }
	got, _ = call([]uint64{99})
	if got[99] != "in" {
		t.Fatalf("the post loaded by the evicting request must keep its verdict, got %v", got)
	}
	evalMu.Lock()
	defer evalMu.Unlock()
	if _, kept := evalLabels[99]; !kept {
		t.Fatalf("the entry loaded by the evicting request must not be evicted")
	}
	if n := len(evalLabels); n > evalCacheCap {
		t.Fatalf("aged entries must be evicted back under the cap, have %d", n)
	}
}
