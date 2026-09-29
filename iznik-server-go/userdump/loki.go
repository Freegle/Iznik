package userdump

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/misc"
)

type lokiEntry struct {
	tsNs   int64
	source string
	line   string
}

// lokiQuerier issues a LogQL query_range and returns log entries, newest
// first. Abstracted so tests can substitute a fake without an HTTP round trip.
type lokiQuerier interface {
	query(logql string, startNs, endNs int64, limit int) ([]lokiEntry, error)
}

type httpLoki struct {
	baseURL string
	hc      *http.Client
}

func newHTTPLoki(baseURL string) *httpLoki {
	return &httpLoki{
		baseURL: strings.TrimRight(baseURL, "/"),
		hc:      &http.Client{Timeout: 30 * time.Second},
	}
}

func (l *httpLoki) query(logql string, startNs, endNs int64, limit int) ([]lokiEntry, error) {
	params := url.Values{}
	params.Set("query", logql)
	params.Set("start", strconv.FormatInt(startNs, 10))
	params.Set("end", strconv.FormatInt(endNs, 10))
	params.Set("limit", strconv.Itoa(limit))
	params.Set("direction", "backward")

	resp, err := l.hc.Get(l.baseURL + "/loki/api/v1/query_range?" + params.Encode())
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return nil, fmt.Errorf("loki status %d: %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	var parsed struct {
		Data struct {
			Result []struct {
				Stream map[string]string `json:"stream"`
				Values [][]string        `json:"values"`
			} `json:"result"`
		} `json:"data"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		return nil, err
	}

	var out []lokiEntry
	for _, s := range parsed.Data.Result {
		src := s.Stream["source"]
		for _, v := range s.Values {
			if len(v) < 2 {
				continue
			}
			ts, _ := strconv.ParseInt(v[0], 10, 64)
			out = append(out, lokiEntry{tsNs: ts, source: src, line: v[1]})
		}
	}
	return out, nil
}

// activityWindows turns the minutes a member was active (the start of each
// minute, in ns) into the time ranges to search, newest first: minutes less than activityGap apart are merged, and
// each range is padded by activityPad either side.
func activityWindows(minutes []int64, startNs, endNs int64) []nsRange {
	sorted := append([]int64(nil), minutes...)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i] < sorted[j] })

	var out []nsRange
	for _, m := range sorted {
		s, e := m-int64(activityPad), m+int64(time.Minute)+int64(activityPad)
		if s < startNs {
			s = startNs
		}
		if e > endNs {
			e = endNs
		}
		if s >= e {
			continue
		}
		if n := len(out); n > 0 && s <= out[n-1].end+int64(activityGap) {
			if e > out[n-1].end {
				out[n-1].end = e
			}
			continue
		}
		out = append(out, nsRange{start: s, end: e})
	}
	for i, j := 0, len(out)-1; i < j; i, j = i+1, j-1 {
		out[i], out[j] = out[j], out[i]
	}
	return out
}

const (
	activityGap = 5 * time.Minute
	activityPad = time.Minute

	// apiHeadersRetention is how long prod Loki keeps api_headers; there is
	// nothing to find further back.
	apiHeadersRetention = 7 * 24 * time.Hour
	// apiHeadersSlice splits a long active window so the newest part arrives
	// first and each query fits the client timeout.
	apiHeadersSlice = 12 * time.Hour
	// apiHeadersLineCap bounds the pass for a member who is active all week:
	// fetching and storing 40,000 of an admin's header lines was most of a
	// 55s snapshot.
	apiHeadersLineCap = 5000
)

// Sources whose lines carry user_id only inside the JSON payload, not as an
// indexed stream label. Confirmed against production: api, chat_reply and
// client label it; these do not. Keeping the expensive `| json` / regex passes
// pinned to this set is what makes them affordable - it excludes the api and
// client firehose, which pass A has already covered by label. api_headers is
// deliberately NOT here: it is ~67GB per 7 days on prod - the dominant cost of
// the old 7-source pass - so it gets its own pass, pass D, confined to the
// minutes the member was active.
const unlabelledSources = "batch|batch_event|email|incoming_mail|similar_posts|vector_search"

// maxLokiRange is how far back a single query_range may reach. Production Loki
// enforces 30d1h and rejects anything longer with a 400, so asking for more is
// not "get less back", it is "get nothing back".
const maxLokiRange = 30 * 24 * time.Hour

// halfSpan splits the parse/regex passes over the unlabelled sources into
// sub-windows: a 30d single shot measured 9.8-26s cold against prod, which
// leaves no headroom under the 30s HTTP client timeout; 15d halves measured
// 9.3-10.1s each.
const halfSpan = 15 * 24 * time.Hour

// lokiSectionBudget bounds the whole section's wall time: a query that has not
// started by then is skipped, and every skip is recorded in _sections rather
// than silently lost.
const lokiSectionBudget = 100 * time.Second

// clampLokiStart pulls start forward if the requested window is longer than
// Loki will serve.
func clampLokiStart(startNs, endNs int64) int64 {
	if oldest := endNs - int64(maxLokiRange); startNs < oldest {
		return oldest
	}
	return startNs
}

// escapeLokiRegex makes a literal safe inside a LogQL regex (`|~ "…"`). That is
// two layers: a regex escape, then a string escape, because the regex sits in a
// double-quoted string whose own escapes are Go's. A bare `\.` is not a valid
// string escape, so Loki rejected every email query with a 400 - every address
// has a dot - and pass B never returned anything.
func escapeLokiRegex(s string) string {
	return escapeLokiString(regexp.QuoteMeta(s))
}

// escapeLokiString makes a value safe inside a LogQL double-quoted string
// (the `|= "…"` line filter), which is not a regex.
func escapeLokiString(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	return strings.ReplaceAll(s, `"`, `\"`)
}

type nsRange struct{ start, end int64 }

// splitRange cuts [startNs, endNs) into consecutive sub-ranges no longer than
// span, oldest first.
func splitRange(startNs, endNs, span int64) []nsRange {
	var out []nsRange
	for s := startNs; s < endNs; s += span {
		e := s + span
		if e > endNs {
			e = endNs
		}
		out = append(out, nsRange{start: s, end: e})
	}
	return out
}

// collectLoki gathers a user's Loki logs into the loki_logs table:
//
//	A:  the member's lines in the labelled sources (api, client, chat_reply,
//	    logs_table), by the coarse user_bucket label plus the exact user_id -
//	    an index lookup. One query per source group, so the busiest source
//	    cannot use up the line cap and crowd out the rest: one query across all
//	    of them returned an admin's 5,000 newest api lines and no client lines.
//	A2: the six slim unlabelled sources, `|=` prefiltered then `| json`
//	    post-filtered, in 15d halves.
//	B:  every email address at once, `|= "a" or "b"` prefiltered then a
//	    case-insensitive regex, over the same slim sources in 15d halves.
//	D:  api_headers, searched only in the minutes the member's own (indexed)
//	    api lines show they were active.
//
// All of it runs at once, at most lokiParallel queries at a time. Nothing waits
// on anything else: client lines used to be found through the session ids pass
// A turned up, one query per group of sessions after A finished, which cost an
// admin 24s for 514 lines. They are the member's own labelled lines, so pass A
// asks for them directly - 0.7s for 3,031.
//
// Two kinds of line are deliberately NOT asked for:
//
//   - Lines labelled with user_id itself rather than user_bucket. That form
//     was written until 2026-08-23 (see misc.UserBucket) and Loki is only asked
//     for the last 30 days, so nothing in range has it; the queries for it
//     returned nothing and cost 3-5s each.
//   - A member's logged-out client lines. They carry no user at all, so
//     finding them is a full-text scan of seven days of client logs (30-40s
//     against prod). lokiNotCollected says so in the dump, and loki_search
//     can still run it.
//
// Every line filter comes BEFORE any `| json`: the parser is the expensive
// stage, and the substring filter skips the lines that can't match. The exact
// `| json | field="…"` post-filter stays because a bare substring has false
// positives. Anything the budget or the caps drop is recorded in _sections.
//
// Pass A failing is fatal for the section (the caller records a warning);
// everything else is best effort.
func collectLoki(b *Builder, q lokiQuerier, userID uint64, emails []string, startNs, endNs int64) (int, error) {
	const perQuery = 5000

	// Production Loki refuses any query_range longer than 30d1h outright. The
	// dump's own default window is 90 days, so pass A came straight back with
	// "the query time range exceeds the limit", the whole section was recorded
	// as a warning, and EVERY dump has been arriving with no logs at all.
	// Narrow the window to what Loki will serve and say so, rather than asking
	// for something that can only fail.
	startNs = clampLokiStart(startNs, endNs)
	deadline := time.Now().Add(lokiSectionBudget)

	var mu sync.Mutex
	var bounds []string
	bound := func(s string) {
		mu.Lock()
		bounds = append(bounds, s)
		mu.Unlock()
	}

	seen := map[string]bool{}
	var all []lokiEntry
	add := func(entries []lokiEntry) {
		mu.Lock()
		defer mu.Unlock()
		for _, e := range entries {
			key := strconv.FormatInt(e.tsNs, 10) + "|" + e.line
			if seen[key] {
				continue
			}
			seen[key] = true
			all = append(all, e)
		}
	}

	// The deadline is checked once a query has its slot, not before it queues:
	// a query that waited out the budget in the queue must not start then.
	slots := make(chan struct{}, lokiParallel)
	skipped, capped := 0, 0
	runLimit := func(logql string, s, e int64, limit int) ([]lokiEntry, error) {
		slots <- struct{}{}
		defer func() { <-slots }()
		if time.Now().After(deadline) {
			mu.Lock()
			skipped++
			mu.Unlock()
			return nil, errLokiBudget
		}
		entries, err := q.query(logql, s, e, limit)
		if len(entries) >= limit {
			mu.Lock()
			capped++
			mu.Unlock()
		}
		return entries, err
	}
	run := func(logql string, s, e int64) ([]lokiEntry, error) {
		return runLimit(logql, s, e, perQuery)
	}
	var wg sync.WaitGroup
	goRun := func(f func()) {
		wg.Add(1)
		go func() { defer wg.Done(); f() }()
	}

	uidStr := strconv.FormatUint(userID, 10)
	bucket := misc.UserBucket(userID)

	// Pass A goes first so it has slots before the rest queue, and each query is
	// tried twice: they are the only fatal ones, and one lost to a timeout would
	// empty the whole section. The api group's lines are also pass D's index,
	// handed over on apiLines.
	var aErr error
	apiLines := make(chan []lokiEntry, 1)
	for i, sources := range labelledSourceGroups {
		logql := fmt.Sprintf(`{app="freegle", source%s, user_bucket="%s"} | user_id="%s"`, sources, bucket, uidStr)
		goRun(func() {
			e, err := run(logql, startNs, endNs)
			if err != nil && err != errLokiBudget {
				e, err = run(logql, startNs, endNs)
			}
			if i == 0 {
				apiLines <- e
			}
			if err != nil {
				mu.Lock()
				if aErr == nil {
					aErr = err
				}
				mu.Unlock()
				return
			}
			add(e)
		})
	}

	// A2: the slim unlabelled sources still need the parse, but the `|=`
	// prefilter means only lines containing the id get parsed, and the 15d
	// halves keep each request well inside the 30s client timeout (measured
	// ~10s per half cold against prod, vs 68s for the old single-shot -
	// which always timed out and contributed nothing).
	for _, r := range splitRange(startNs, endNs, int64(halfSpan)) {
		r := r
		goRun(func() {
			if e, err := run(
				fmt.Sprintf(`{app="freegle", source=~"%s"} |= "%s" | json | user_id="%s"`, unlabelledSources, uidStr, uidStr),
				r.start, r.end); err == nil {
				add(e)
			}
		})
	}

	// Pass B: catch lines that name the member by email rather than by id -
	// mail delivery, incoming mail, batch jobs. All addresses go in one query
	// per half: the case-sensitive `|= "a" or "b"` on the lowercased addresses
	// prefilters for the case-insensitive regex. One query per address per half
	// was ~2-10s each, so a member with 23 addresses ran out of budget; all 23
	// together measured 5-7s per half against prod. Still pinned to the slim
	// sources: emails verifiably never appear in api_headers lines.
	if logql := lokiEmailQuery(emails); logql != "" {
		for _, r := range splitRange(startNs, endNs, int64(halfSpan)) {
			r := r
			goRun(func() {
				if e, err := run(logql, r.start, r.end); err == nil {
					add(e)
				}
			})
		}
	}

	// Pass D: api_headers. It has no member label, so searching it for 7 days
	// is a full-text scan of the ~67GB firehose (~40-50s against prod). But
	// every request writes exactly one api line and one api_headers line, at the
	// same moment - and pass A has just fetched the member's api lines by index.
	// They say which minutes to search, and how many header lines each window
	// holds, so each query can ask for that many: the queries run in parallel
	// without overshooting the cap. Measured against prod: 27s -> 1s for an
	// ordinary member, with every request the full scan found.
	//
	// A member active all week (an admin with tabs open) has more api lines than
	// pass A's line cap, so the window covers only the period of their newest
	// ones - which is also where the newest headers, the ones apiHeadersLineCap
	// keeps, are.
	goRun(func() {
		api := <-apiLines
		hdrStart := endNs - int64(apiHeadersRetention)
		if hdrStart < startNs {
			hdrStart = startNs
		}
		if len(api) >= perQuery {
			bound(fmt.Sprintf("api_headers: only for the period of the member's newest %d api lines", perQuery))
		}

		var minutes, stamps []int64
		for _, e := range api {
			if e.tsNs >= hdrStart && e.tsNs < endNs {
				stamps = append(stamps, e.tsNs)
				minutes = append(minutes, e.tsNs-e.tsNs%int64(time.Minute))
			}
		}
		type piece struct {
			nsRange
			expect int
		}
		var pieces []piece
		for _, w := range activityWindows(minutes, hdrStart, endNs) {
			sl := splitRange(w.start, w.end, int64(apiHeadersSlice))
			for i := len(sl) - 1; i >= 0; i-- {
				n := 0
				for _, t := range stamps {
					if t >= sl[i].start && t < sl[i].end {
						n++
					}
				}
				if n > 0 {
					pieces = append(pieces, piece{nsRange: sl[i], expect: n})
				}
			}
		}

		// A query only starts if the lines it expects still fit under the cap
		// alongside the queries already running. Headers and api lines are one
		// each per request, so a little headroom covers the edges of a window.
		hq := fmt.Sprintf(`{app="freegle", source="api_headers"} |= "%s" | json | user_id="%s"`, uidStr, uidStr)
		var hmu sync.Mutex
		cond := sync.NewCond(&hmu)
		next, got, reserved, full := 0, 0, 0, false
		var wgD sync.WaitGroup
		for w := 0; w < lokiParallel; w++ {
			wgD.Add(1)
			go func() {
				defer wgD.Done()
				for {
					hmu.Lock()
					for next < len(pieces) && got < apiHeadersLineCap && got+reserved >= apiHeadersLineCap {
						cond.Wait()
					}
					if next >= len(pieces) || got >= apiHeadersLineCap {
						full = full || next < len(pieces)
						hmu.Unlock()
						return
					}
					p := pieces[next]
					next++
					limit := min(p.expect+p.expect/10+10, apiHeadersLineCap-got-reserved)
					reserved += limit
					hmu.Unlock()

					e, err := runLimit(hq, p.start, p.end, limit)
					if err == nil {
						add(e)
					}
					hmu.Lock()
					reserved -= limit
					if err == nil {
						got += len(e)
					}
					cond.Broadcast()
					hmu.Unlock()
				}
			}()
		}
		wgD.Wait()
		if full {
			bound(fmt.Sprintf("api_headers: stopped at %d lines, newest first", apiHeadersLineCap))
		}
	})

	wg.Wait()
	if aErr != nil {
		return 0, aErr
	}
	if skipped > 0 {
		bound(fmt.Sprintf("section budget exhausted: %d queries skipped", skipped))
	}
	if capped > 0 {
		bound(fmt.Sprintf("%d queries hit the %d-line cap, so may be missing older lines", capped, perQuery))
	}

	for _, note := range bounds {
		b.AddSection("loki_bounds", "warning", 0, note, 0)
	}
	b.AddSection("loki_not_collected", "skipped", 0, lokiNotCollected, 0)

	if err := b.EnsureTable("loki_logs", `"ts" TEXT, "ts_ns" INTEGER, "source" TEXT, "line" TEXT`); err != nil {
		return 0, err
	}
	sort.Slice(all, func(i, j int) bool { return all[i].tsNs > all[j].tsNs })
	for _, e := range all {
		ts := time.Unix(0, e.tsNs).UTC().Format(time.RFC3339Nano)
		if err := b.InsertRow("loki_logs", []string{"ts", "ts_ns", "source", "line"}, ts, e.tsNs, e.source, e.line); err != nil {
			return len(all), err
		}
	}
	return len(all), nil
}

// labelledSourceGroups splits pass A by source, as LogQL matchers. api and
// client are the two big ones; everything else labelled by member (chat_reply,
// logs_table) is small and shares a query. api must stay first: pass D reads
// the first group's lines as its index.
var labelledSourceGroups = []string{`="api"`, `="client"`, `!~"api|client"`}

// lokiNotCollected is recorded in _sections on every dump, so whoever reads it
// knows these logs were never asked for rather than absent.
const lokiNotCollected = "logged-out client lines are not in this dump: they carry no user, so " +
	"finding them is a full-text scan of 7 days of logs. Use loki_search if a question needs them."

// errLokiBudget is returned for a query the section budget stopped from starting.
var errLokiBudget = errors.New("section budget exhausted")

// lokiParallel caps how many Loki queries one dump has in flight, so a single
// snapshot cannot swamp the Loki queriers.
const lokiParallel = 6

// lokiEmailQuery is pass B's single query for all of a member's addresses, or
// "" when they have none.
func lokiEmailQuery(emails []string) string {
	var pre, alt []string
	for _, em := range emails {
		em = strings.TrimSpace(em)
		if em == "" {
			continue
		}
		pre = append(pre, `"`+escapeLokiString(strings.ToLower(em))+`"`)
		alt = append(alt, escapeLokiRegex(em))
	}
	if len(pre) == 0 {
		return ""
	}
	return fmt.Sprintf(`{app="freegle", source=~"%s"} |= %s |~ "(?i)(%s)"`,
		unlabelledSources, strings.Join(pre, " or "), strings.Join(alt, "|"))
}
