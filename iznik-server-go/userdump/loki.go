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
// client firehose, which pass A has already covered by label.
const unlabelledSources = "api_headers|batch|batch_event|email|incoming_mail|similar_posts|vector_search"

// maxLokiRange is how far back a single query_range may reach. Production Loki
// enforces 30d1h and rejects anything longer with a 400, so asking for more is
// not "get less back", it is "get nothing back".
const maxLokiRange = 30 * 24 * time.Hour

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

// collectLoki gathers a user's Loki logs into the loki_logs table. user_id is a
// JSON field (not a Loki label) so it is filtered after `| json`. Three passes:
//
//	A: user_id field across all sources (api, email, chat_reply, ...).
//	B: each email address by full-text match (catches email/incoming_mail).
//	C: client-side logs, which carry only a session_id, via session ids
//	   harvested from the api lines found in pass A.
//
// Pass A failing is treated as fatal for this section (returns the error so the
// caller records a warning); B and C are best effort.
func collectLoki(b *Builder, q lokiQuerier, userID uint64, emails []string, startNs, endNs int64) (int, error) {
	const perQuery = 5000

	// Production Loki refuses any query_range longer than 30d1h outright. The
	// dump's own default window is 90 days, so pass A came straight back with
	// "the query time range exceeds the limit", the whole section was recorded
	// as a warning, and EVERY dump has been arriving with no logs at all.
	// Narrow the window to what Loki will serve and say so, rather than asking
	// for something that can only fail.
	startNs = clampLokiStart(startNs, endNs)

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

	// Pass A, in two parts, because `{app="freegle"} | json | user_id="..."`
	// alone has to decompress and JSON-parse every Freegle log line in the
	// window - over a minute for a single day against production.
	//
	// A1: user_id is an indexed STREAM LABEL on api, chat_reply and client,
	// which is the bulk of the volume. As a label selector this is an index
	// lookup: about a second for the same day.
	uidStr := strconv.FormatUint(userID, 10)
	entries, err := q.query(fmt.Sprintf(`{app="freegle", user_id="%s"}`, uidStr), startNs, endNs, perQuery)
	if err != nil {
		return 0, err
	}
	add(entries)

	// A2: the remaining sources carry user_id only as a JSON field, so they
	// still need the parse - but restricted to those streams, which excludes
	// the api/client firehose. Best effort: these are the sources that most
	// often have nothing for a given member, and losing them is much better
	// than losing the section.
	if e1b, err := q.query(
		fmt.Sprintf(`{app="freegle", source=~"%s"} | json | user_id="%s"`, unlabelledSources, uidStr),
		startNs, endNs, perQuery); err == nil {
		add(e1b)
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
	// mail delivery, incoming mail, batch jobs. Restricted to the sources that
	// do NOT label user_id, because pass A has already covered the ones that do
	// and a full-text regex across the api/client firehose is exactly the scan
	// that made this section unusable.
	for _, em := range emails {
		em = strings.TrimSpace(em)
		if em == "" {
			continue
		}
		if e2, err := q.query(
			fmt.Sprintf(`{app="freegle", source=~"%s"} |~ "(?i)%s"`, unlabelledSources, escapeLokiRegex(em)),
			startNs, endNs, perQuery); err == nil {
			add(e2)
		}
	}

	// Pass C (cap the number of sessions queried).
	sids := make([]string, 0, len(sessions))
	for sid := range sessions {
		sids = append(sids, sid)
	}
	sort.Strings(sids)
	if len(sids) > 25 {
		sids = sids[:25]
	}
	for _, sid := range sids {
		if e3, err := q.query(fmt.Sprintf(`{app="freegle", source="client"} | json | session_id="%s"`, sid), startNs, endNs, perQuery); err == nil {
			add(e3)
		}
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
