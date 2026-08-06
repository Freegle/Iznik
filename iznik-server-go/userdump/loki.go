package userdump

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"
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

func escapeLokiRegex(s string) string {
	for _, c := range []string{`\`, `.`, `+`, `*`, `?`, `^`, `$`, `(`, `)`, `[`, `]`, `{`, `}`, `|`, `"`} {
		s = strings.ReplaceAll(s, c, `\`+c)
	}
	return s
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

// collectLoki gathers a user's Loki logs into the loki_logs table, in value
// order so the section budget drops the least valuable coverage first:
//
//	A1: user_id STREAM LABEL across api/chat_reply/client - an index lookup.
//	A2: the six slim unlabelled sources, `|=` prefiltered then `| json`
//	    post-filtered, in 15d halves.
//	B:  each email address, `|=` prefiltered then case-insensitive regex,
//	    over the same slim sources in 15d halves.
//	C:  client session logs, two legs per session id: the indexed user_id
//	    label over the full window, plus the anonymous (pre-login) streams
//	    capped to the client source's 7d retention.
//	D:  api_headers (the ~67GB/7d firehose that used to dominate the whole
//	    section), newest-first in 1.5d slices, slice- and budget-capped.
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
	var bounds []string

	seen := map[string]bool{}
	var all []lokiEntry
	add := func(entries []lokiEntry) {
		for _, e := range entries {
			key := strconv.FormatInt(e.tsNs, 10) + "|" + e.line
			if seen[key] {
				continue
			}
			seen[key] = true
			all = append(all, e)
		}
	}

	// A1: user_id is an indexed STREAM LABEL on api, chat_reply and client,
	// which is the bulk of the volume. As a label selector this is an index
	// lookup: about a second for a production day.
	uidStr := strconv.FormatUint(userID, 10)
	entries, err := q.query(fmt.Sprintf(`{app="freegle", user_id="%s"}`, uidStr), startNs, endNs, perQuery)
	if err != nil {
		return 0, err
	}
	add(entries)

	// A2: the slim unlabelled sources still need the parse, but the `|=`
	// prefilter means only lines containing the id get parsed, and the 15d
	// halves keep each request well inside the 30s client timeout (measured
	// ~10s per half cold against prod, vs 68s for the old single-shot -
	// which always timed out and contributed nothing).
	for _, r := range splitRange(startNs, endNs, int64(halfSpan)) {
		if e1b, err := q.query(
			fmt.Sprintf(`{app="freegle", source=~"%s"} |= "%s" | json | user_id="%s"`, unlabelledSources, uidStr, uidStr),
			r.start, r.end, perQuery); err == nil {
			add(e1b)
		}
	}

	// Harvest session ids from api lines.
	sessions := map[string]bool{}
	for _, e := range all {
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

	// Pass B: catch lines that name the member by email rather than by id -
	// mail delivery, incoming mail, batch jobs. The case-sensitive `|=` on the
	// lowercased address prefilters for the case-insensitive regex (measured
	// ~9-13s per half cold, vs 69s unprefiltered single-shot). Still pinned to
	// the slim sources: emails verifiably never appear in api_headers lines.
	// The deadline is checked per HALF, not just per email: a member can have
	// many addresses, and a per-email gate lets the last one overshoot by two
	// full queries (observed pushing the section to 149s against its 100s
	// budget on prod).
	for _, em := range emails {
		em = strings.TrimSpace(em)
		if em == "" {
			continue
		}
		for _, r := range splitRange(startNs, endNs, int64(halfSpan)) {
			if time.Now().After(deadline) {
				bounds = append(bounds, fmt.Sprintf("emails: section budget exhausted at %q", em))
				break
			}
			if e2, err := q.query(
				fmt.Sprintf(`{app="freegle", source=~"%s"} |= "%s" |~ "(?i)%s"`,
					unlabelledSources, escapeLokiString(strings.ToLower(em)), escapeLokiRegex(em)),
				r.start, r.end, perQuery); err == nil {
				add(e2)
			}
		}
		if time.Now().After(deadline) {
			break
		}
	}

	// Pass C (cap the number of sessions queried). Two legs per session id:
	// the user_id label makes the logged-in leg an index lookup over the full
	// window; the anonymous leg (pre-login lines have user_id="") has to
	// touch the client firehose's tiny chunks, so it is capped to that
	// source's 7d retention - beyond which there is nothing to find anyway.
	// Measured ~5s per session cold, vs 77s for the old unlabelled scan.
	sids := make([]string, 0, len(sessions))
	for sid := range sessions {
		sids = append(sids, sid)
	}
	sort.Strings(sids)
	if len(sids) > 25 {
		bounds = append(bounds, fmt.Sprintf("sessions: only 25 of %d session ids searched", len(sids)))
		sids = sids[:25]
	}
	anonStart := endNs - int64(shortRetention)
	if startNs > anonStart {
		anonStart = startNs
	}
	for i, sid := range sids {
		if time.Now().After(deadline) {
			bounds = append(bounds, fmt.Sprintf("sessions: section budget exhausted after %d of %d", i, len(sids)))
			break
		}
		sidEsc := escapeLokiString(sid)
		if e3, err := q.query(
			fmt.Sprintf(`{app="freegle", source="client", user_id="%s"} |= "%s" | json | session_id="%s"`, uidStr, sidEsc, sidEsc),
			startNs, endNs, perQuery); err == nil {
			add(e3)
		}
		if e3, err := q.query(
			fmt.Sprintf(`{app="freegle", source="client", user_id=""} |= "%s" | json | session_id="%s"`, sidEsc, sidEsc),
			anonStart, endNs, perQuery); err == nil {
			add(e3)
		}
	}

	// Pass D: api_headers, last because it costs the most per line of value
	// (~16s per 1.5d slice cold). Newest-first so whatever the caps keep is
	// the most recent; its retention is 7d so older slices cannot exist.
	hdrOldest := endNs - int64(shortRetention)
	if startNs > hdrOldest {
		hdrOldest = startNs
	}
	slices := 0
	e := endNs
	for e > hdrOldest && slices < apiHeadersMaxSlices {
		if time.Now().After(deadline) {
			bounds = append(bounds, fmt.Sprintf("api_headers: section budget exhausted after %d slices (newest-first)", slices))
			break
		}
		s := e - int64(apiHeadersSlice)
		if s < hdrOldest {
			s = hdrOldest
		}
		hs, err := q.query(
			fmt.Sprintf(`{app="freegle", source="api_headers"} |= "%s" | json | user_id="%s"`, uidStr, uidStr),
			s, e, perQuery)
		if err != nil {
			// Slices get SLOWER going deeper (measured 21s, 18s, then 40s+
			// timeouts walking back through a heavy user's headers), so after
			// one failure the rest can only burn the budget for nothing.
			bounds = append(bounds, fmt.Sprintf("api_headers: stopped after %d slices (query failed: %v)", slices, err))
			break
		}
		add(hs)
		e = s
		slices++
	}
	if e > hdrOldest && slices >= apiHeadersMaxSlices {
		bounds = append(bounds, fmt.Sprintf("api_headers: capped at %d newest slices", apiHeadersMaxSlices))
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
