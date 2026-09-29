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

// lokiQuerier issues a LogQL query_range and returns log entries. Abstracted so
// tests can substitute a fake without an HTTP round trip.
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

// Sources whose lines carry user_id only inside the JSON payload, not as an
// indexed stream label. Confirmed against production: api, chat_reply and
// client label it; these do not. Keeping the expensive `| json` / regex passes
// pinned to this set is what makes them affordable - it excludes the api and
// client firehose, which pass A has already covered by label. api_headers is
// deliberately NOT here: it is ~67GB per 7 days on prod - the dominant cost of
// the old 7-source pass - so it gets its own bounded, lowest-priority pass.
const unlabelledSources = "batch|batch_event|email|incoming_mail|similar_posts|vector_search"

// maxLokiRange is how far back a single query_range may reach. Production Loki
// enforces 30d1h and rejects anything longer with a 400, so asking for more is
// not "get less back", it is "get nothing back".
const maxLokiRange = 30 * 24 * time.Hour

// shortRetention is how long prod Loki keeps the api_headers and client
// sources. Querying them further back returns nothing at real cost.
const shortRetention = 7 * 24 * time.Hour

// halfSpan splits the parse/regex passes over the unlabelled sources into
// sub-windows: a 30d single shot measured 9.8-26s cold against prod, which
// leaves no headroom under the 30s HTTP client timeout; 15d halves measured
// 9.3-10.1s each.
const halfSpan = 15 * 24 * time.Hour

// api_headers is searched newest-first in 1.5d slices (~16s each cold, so one
// slice always fits the client timeout), capped by count and by the section
// budget below.
const apiHeadersSlice = 36 * time.Hour

const apiHeadersMaxSlices = 5

// lokiSectionBudget bounds the whole section's wall time. The passes run in
// value order (labelled, six-source, emails, sessions, api_headers last), so
// when the budget bites it is the least valuable coverage that is dropped -
// and every truncation is recorded in _sections rather than silently lost.
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
//	A1: user_id STREAM LABEL across api/chat_reply/client - an index lookup.
//	A2: the six slim unlabelled sources, `|=` prefiltered then `| json`
//	    post-filtered, in 15d halves.
//	B:  every email address at once, `|= "a" or "b"` prefiltered then a
//	    case-insensitive regex, over the same slim sources in 15d halves.
//	C:  client session logs, per session id: the indexed user_id label over
//	    the full window, plus the anonymous (pre-login) streams capped to the
//	    client source's 7d retention.
//	D:  api_headers (the ~67GB/7d firehose that used to dominate the whole
//	    section), newest-first in 1.5d slices, slice- and budget-capped.
//
// A1 runs first and alone. The rest then run concurrently, at most
// lokiParallel queries at a time: run one after another they used up the whole
// 100s budget for a member with many addresses. C needs the session ids from A,
// so it starts when A is done; D is a chain in its own goroutine because it
// walks newest-first and stops at the first failure.
//
// Every line filter comes BEFORE any `| json`: the parser is the expensive
// stage, and the substring filter skips the lines that can't match. The exact
// `| json | field="…"` post-filter stays because a bare substring has false
// positives. Anything the budget or the caps drop is recorded in _sections.
//
// Pass A1 failing is fatal for the section (the caller records a warning);
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
	run := func(logql string, s, e int64) ([]lokiEntry, error) {
		slots <- struct{}{}
		defer func() { <-slots }()
		if time.Now().After(deadline) {
			mu.Lock()
			skipped++
			mu.Unlock()
			return nil, errLokiBudget
		}
		entries, err := q.query(logql, s, e, perQuery)
		if len(entries) >= perQuery {
			mu.Lock()
			capped++
			mu.Unlock()
		}
		return entries, err
	}
	var wgA, wgRest sync.WaitGroup
	goA := func(f func()) {
		wgA.Add(1)
		go func() { defer wgA.Done(); f() }()
	}
	goRest := func(f func()) {
		wgRest.Add(1)
		go func() { defer wgRest.Done(); f() }()
	}

	// A1: user_id covers api, chat_reply and client, which is the bulk of the
	// volume. It is addressed in TWO forms and we must ask for both, because a
	// 30-day window straddles the change:
	//
	//   - entries written before 2026-08-23 carry user_id as a stream label;
	//   - entries written after carry a coarse user_bucket label plus the exact
	//     user_id as structured metadata (see misc.UserBucket for why).
	//
	// Both are index-narrowed, so both are cheap, and add() dedupes the overlap.
	// Once nothing older than the change is still inside retention, the first
	// query can go.
	//
	// These two run on their own, before anything else is sent, and are tried
	// twice. They are the only fatal queries, and a heavy member's return 5000
	// long lines: queued behind the other passes, one hit the 30s client timeout
	// and the whole section came back empty.
	uidStr := strconv.FormatUint(userID, 10)
	var a1Err, a1bErr error
	var a1, a1b, a2 []lokiEntry
	runTwice := func(logql string) ([]lokiEntry, error) {
		e, err := run(logql, startNs, endNs)
		if err != nil && err != errLokiBudget {
			e, err = run(logql, startNs, endNs)
		}
		return e, err
	}
	goA(func() { a1, a1Err = runTwice(fmt.Sprintf(`{app="freegle", user_id="%s"}`, uidStr)) })
	goA(func() {
		a1b, a1bErr = runTwice(fmt.Sprintf(`{app="freegle", user_bucket="%s"} | user_id="%s"`, misc.UserBucket(userID), uidStr))
	})
	wgA.Wait()
	if a1Err != nil {
		return 0, a1Err
	}
	if a1bErr != nil {
		return 0, a1bErr
	}

	// A2: the slim unlabelled sources still need the parse, but the `|=`
	// prefilter means only lines containing the id get parsed, and the 15d
	// halves keep each request well inside the 30s client timeout (measured
	// ~10s per half cold against prod, vs 68s for the old single-shot -
	// which always timed out and contributed nothing).
	var a2mu sync.Mutex
	for _, r := range splitRange(startNs, endNs, int64(halfSpan)) {
		r := r
		goA(func() {
			if e, err := run(
				fmt.Sprintf(`{app="freegle", source=~"%s"} |= "%s" | json | user_id="%s"`, unlabelledSources, uidStr, uidStr),
				r.start, r.end); err == nil {
				a2mu.Lock()
				a2 = append(a2, e...)
				a2mu.Unlock()
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
			goRest(func() {
				if e, err := run(logql, r.start, r.end); err == nil {
					add(e)
				}
			})
		}
	}

	// Pass D: api_headers, the costliest per line of value (~16s per 1.5d slice
	// cold). Newest-first so whatever the caps keep is the most recent; its
	// retention is 7d so older slices cannot exist.
	goRest(func() {
		hdrOldest := endNs - int64(shortRetention)
		if startNs > hdrOldest {
			hdrOldest = startNs
		}
		slices := 0
		e := endNs
		for e > hdrOldest && slices < apiHeadersMaxSlices {
			if time.Now().After(deadline) {
				bound(fmt.Sprintf("api_headers: section budget exhausted after %d slices (newest-first)", slices))
				return
			}
			s := e - int64(apiHeadersSlice)
			if s < hdrOldest {
				s = hdrOldest
			}
			hs, err := run(
				fmt.Sprintf(`{app="freegle", source="api_headers"} |= "%s" | json | user_id="%s"`, uidStr, uidStr),
				s, e)
			if err != nil {
				// Slices get SLOWER going deeper (measured 21s, 18s, then 40s+
				// timeouts walking back through a heavy user's headers), so after
				// one failure the rest can only burn the budget for nothing.
				bound(fmt.Sprintf("api_headers: stopped after %d slices (query failed: %v)", slices, err))
				return
			}
			add(hs)
			e = s
			slices++
		}
		if e > hdrOldest && slices >= apiHeadersMaxSlices {
			bound(fmt.Sprintf("api_headers: capped at %d newest slices", apiHeadersMaxSlices))
		}
	})

	wgA.Wait()
	add(a1)
	add(a1b)
	add(a2)

	// Harvest session ids from the api lines pass A found.
	sessions := map[string]bool{}
	for _, entries := range [][]lokiEntry{a1, a1b, a2} {
		for _, e := range entries {
			if e.source != "api" && e.source != "api_headers" {
				continue
			}
			var m map[string]interface{}
			if json.Unmarshal([]byte(e.line), &m) == nil {
				if sid, ok := m["session_id"].(string); ok && sid != "" {
					sessions[sid] = true
				}
			}
		}
	}

	// Pass C (cap the number of sessions queried). The user_id label (in both
	// eras) makes the logged-in legs index lookups over the full window; the
	// anonymous leg (pre-login lines have user_id="") has to touch the client
	// firehose's tiny chunks, so it is capped to that source's 7d retention -
	// beyond which there is nothing to find anyway.
	//
	// Sessions are asked about in groups, not one at a time. Loki's queriers
	// are the bottleneck, not the requests: one query per session per leg was
	// 60 queries for a member with 20 sessions, each ~6s once they queued, and
	// the whole section took 175s. The same three legs over all 20 at once
	// returned the same lines in 58s. Groups of sessionsPerQuery keep each
	// query's share of the line cap from getting thin.
	sids := make([]string, 0, len(sessions))
	for sid := range sessions {
		sids = append(sids, sid)
	}
	sort.Strings(sids)
	if len(sids) > 25 {
		bound(fmt.Sprintf("sessions: only 25 of %d session ids searched", len(sids)))
		sids = sids[:25]
	}
	anonStart := endNs - int64(shortRetention)
	if startNs > anonStart {
		anonStart = startNs
	}
	var wgC sync.WaitGroup
	for g := 0; g < len(sids); g += sessionsPerQuery {
		group := sids[g:min(g+sessionsPerQuery, len(sids))]
		var pre, alt []string
		for _, sid := range group {
			pre = append(pre, `"`+escapeLokiString(sid)+`"`)
			alt = append(alt, escapeLokiRegex(sid))
		}
		sidFilter := strings.Join(pre, " or ")
		sidRegex := strings.Join(alt, "|")
		legs := []struct {
			logql string
			start int64
		}{
			// Pre-bucket form, for entries still in retention from before the change.
			{fmt.Sprintf(`{app="freegle", source="client", user_id="%s"} |= %s | json | session_id=~"%s"`, uidStr, sidFilter, sidRegex), startNs},
			// Bucketed form. The user_id filter runs BEFORE | json deliberately: json
			// would extract a user_id from the line body too, and the parsed one gets
			// renamed rather than replacing the structured-metadata value.
			{fmt.Sprintf(`{app="freegle", source="client", user_bucket="%s"} |= %s | user_id="%s" | json | session_id=~"%s"`, misc.UserBucket(userID), sidFilter, uidStr, sidRegex), startNs},
			// Anonymous sessions carry no user at all, so neither label is set and
			// this one form covers both eras.
			{fmt.Sprintf(`{app="freegle", source="client"} | user_id="" |= %s | json | session_id=~"%s"`, sidFilter, sidRegex), anonStart},
		}
		for _, leg := range legs {
			leg := leg
			wgC.Add(1)
			go func() {
				defer wgC.Done()
				if e, err := run(leg.logql, leg.start, endNs); err == nil {
					add(e)
				}
			}()
		}
	}
	wgC.Wait()
	wgRest.Wait()
	if skipped > 0 {
		bound(fmt.Sprintf("section budget exhausted: %d queries skipped", skipped))
	}
	if capped > 0 {
		bound(fmt.Sprintf("%d queries hit the %d-line cap, so may be missing older lines", capped, perQuery))
	}

	for _, note := range bounds {
		b.AddSection("loki_bounds", "warning", 0, note, 0)
	}

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

// sessionsPerQuery is how many session ids pass C puts in one query.
const sessionsPerQuery = 10

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
