package rippling

// Stored-label membership verdicts from the routing server - the read side
// of the labels-truth cutover. One batched call answers "is this member
// inside each of these posts' CURRENT reach" exactly from the road network,
// for every post the backfill has labelled; the rest come back "nolabels"
// and keep their cell-grid verdict.
//
// Any routing problem returns nil with ok=false, which reads as "no verdict"
// - and no verdict is NOT a refusal. Gate callers fail open on it, so an
// outage here is invisible from the outside; reportEvalUnavailable is what
// makes it visible. The badge count is the exception: since the cell grids
// retired, discovery is its only source of posts, so for it "unanswered" must
// not be served as "none" - it refuses (503) and the client keeps the number
// it has. The ok flag is what lets it tell the two apart.

import (
	"bytes"
	"encoding/json"
	"log"
	"net/http"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/getsentry/sentry-go"
)

var labelEvalClient = &http.Client{Timeout: 3 * time.Second}

// A 503 from reach-eval is the routing server saying its label store could
// not be read for THIS request (a dropped MySQL connection mid-read, not an
// outage: the server is up and answered). One immediate retry is what turns
// that from an empty feed and a refused badge into an answer, and it costs
// nothing when the store is healthy. Never more than one: an outage must
// still show up as one, promptly, rather than as a slow feed.
const labelEvalAttempts = 2

// labelEvalRetryDelay is the pause before that retry; a var so tests can zero it.
var labelEvalRetryDelay = 100 * time.Millisecond

const (
	LabelVerdictIn  = "in"
	LabelVerdictOut = "out"
)

// How often one process may report that reach evaluation is unavailable. An
// outage produces one of these per call otherwise, and the calls are on the
// feed's hot path.
const reachAlertInterval = time.Minute

var (
	reachAlertMu   sync.Mutex
	reachAlertLast time.Time
)

// reportEvalUnavailable raises a Sentry alert when the routing server cannot
// answer a reach question, at most once a minute per process.
//
// This is the ONLY thing that shows an outage while it is happening. Every gate
// in front of these verdicts now fails open, deliberately: a reply goes
// through, no "hasn't reached you yet" notice is shown, and the site therefore
// looks entirely well to the members using it. On 2026-09-02 the engine was
// down for 16 hours behind a gate that failed closed instead, and the way we
// found out was a member asking why a post three miles away had not reached
// her.
func reportEvalUnavailable(reason string) {
	reachAlertMu.Lock()
	if !reachAlertLast.IsZero() && time.Since(reachAlertLast) < reachAlertInterval {
		reachAlertMu.Unlock()
		return
	}
	reachAlertLast = time.Now()
	reachAlertMu.Unlock()

	msg := "Rippling reach evaluation unavailable: " + reason
	log.Println(msg)
	sentry.CaptureMessage(msg)
}

// RescueUndecided returns the subset of ids whose stored label verdicts the
// member IN. This is the degraded-path rescue: when the spatial index is down
// and a row's grid cannot answer (a RETIRED grid has none), one batched label
// evaluation decides instead - shared by the feed's probe filter and search's
// degraded arm so the two cannot drift.
func RescueUndecided(lat, lng float64, ids []uint64) []uint64 {
	if len(ids) == 0 {
		return nil
	}
	verdicts := LabelVerdicts(lat, lng, ids)
	var kept []uint64
	for _, id := range ids {
		if verdicts[id] == LabelVerdictIn {
			kept = append(kept, id)
		}
	}
	return kept
}

// DropLabelOut narrows an id list by label verdicts: ids the labels decided
// OUT are removed; everything else (in, or no labels) is kept. nil verdicts
// return the list untouched.
func DropLabelOut[T int64 | uint64](ids []T, verdicts map[uint64]string) []T {
	if len(verdicts) == 0 {
		return ids
	}
	kept := ids[:0]
	for _, id := range ids {
		if verdicts[uint64(id)] != LabelVerdictOut {
			kept = append(kept, id)
		}
	}
	return kept
}

// LabelVerdicts returns msgid -> "in"/"out" for candidates whose stored
// labels decided them; candidates with no labels (or on any failure) are
// simply absent from the map.
func LabelVerdicts[T int64 | uint64](lat, lng float64, msgids []T) map[uint64]string {
	ids := make([]uint64, len(msgids))
	for i, id := range msgids {
		ids[i] = uint64(id)
	}
	return LabelVerdictsAtBudget(lat, lng, ids, "")
}

// LabelVerdictsAtBudget is LabelVerdicts at an explicit budget: "" (or
// "current") = the post's current tick, "max" = the label's full budget (the
// maximum reach, which is what first-reply targeting asks about).
func LabelVerdictsAtBudget(lat, lng float64, msgids []uint64, budget string) map[uint64]string {
	verdicts, _, _ := labelEval(lat, lng, msgids, budget, false)
	return verdicts
}

// LabelVerdictsWithDiscover additionally returns posts the candidate list
// MISSED whose stored labels admit this member - covering the band where the
// grid prefilter under-covers the true road reach. ok=false means the
// question was not answered at all (routing unreachable, breaker open, a
// 503 that survived the retry): the caller must not read the empty result as
// "nothing admits this member".
func LabelVerdictsWithDiscover(lat, lng float64, msgids []uint64) (map[uint64]string, []uint64, bool) {
	return labelEval(lat, lng, msgids, "", true)
}

// labelEvalResponse is the reach-eval wire shape apiv2 reads.
type labelEvalResponse struct {
	Results []struct {
		Msgid      uint64 `json:"msgid"`
		Verdict    string `json:"verdict"`
		OriginArea bool   `json:"origin_area"`
	} `json:"results"`
	Discovered []struct {
		Msgid uint64 `json:"msgid"`
	} `json:"discovered"`
}

// postLabelEval asks the routing server once. reason is empty on success.
// retry says whether asking again could plausibly succeed: only a 503, the
// server's own "could not read the store for this request". A transport error
// opens the shared breaker, and retrying would contradict it; a 4xx is this
// request's fault and repeats identically.
func postLabelEval(body []byte) (parsed labelEvalResponse, retry bool, reason string) {
	resp, err := labelEvalClient.Post(roadblur.RoutingURL()+"/v1/reach-eval", "application/json", bytes.NewReader(body))
	if err != nil {
		roadblur.MarkRoutingFailure()
		return parsed, false, "routing server unreachable: " + err.Error()
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusNotImplemented {
		// The routing server has no reach engine at all (no REACH_DIR: dev,
		// CI, or a node before the artifacts deploy). That is an ANSWER - no
		// labels exist here, every caller keeps its grid verdict - not a
		// failure to retry or refuse on: a badge in an engine-less environment
		// must not sit at 503 forever. Reported all the same, because in
		// production an engine-less routing server is an outage.
		reportEvalUnavailable("routing server has no reach engine (HTTP 501)")
		return parsed, false, ""
	}
	if resp.StatusCode != http.StatusOK {
		// The breaker is SHARED with blur and drive-time display, so only a
		// server-side fault may trip it. 503 = a configured engine could not
		// read its label store for this request; any 4xx = this request (a
		// 404 routing server that predates the endpoint, a rejected body) -
		// neither says the routing server is unhealthy.
		if resp.StatusCode >= 500 && resp.StatusCode != http.StatusServiceUnavailable {
			roadblur.MarkRoutingFailure()
		}
		return parsed, resp.StatusCode == http.StatusServiceUnavailable,
			"routing server returned HTTP " + strconv.Itoa(resp.StatusCode)
	}
	if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
		return parsed, false, "unreadable response from routing server: " + err.Error()
	}
	return parsed, false, ""
}

func labelEval(lat, lng float64, msgids []uint64, budget string, discover bool) (map[uint64]string, []uint64, bool) {
	// An empty candidate list still discovers: a member covered by NO grid
	// can still be admitted by a stored label (the under-coverage band).
	// Nothing to ask is an answered question, not a failed one.
	if (len(msgids) == 0 && !discover) || (lat == 0 && lng == 0) {
		return nil, nil, true
	}
	if !roadblur.RoutingHealthy() {
		// The breaker is open, so nothing is asked and everything downstream
		// fails open. Keep reporting it: an outage lasts hours, and this is
		// the only alert during all of them.
		reportEvalUnavailable("routing breaker open")
		return nil, nil, false
	}
	out := make(map[uint64]string, len(msgids))
	var discovered []uint64
	const chunk = 1000
	for start := 0; start == 0 || start < len(msgids); start += chunk {
		end := start + chunk
		if end > len(msgids) {
			end = len(msgids)
		}
		body, err := json.Marshal(map[string]any{
			"lat": lat, "lng": lng, "msgids": msgids[start:end], "budget": budget,
			// Only the first chunk discovers: the discovery set is a property
			// of the member, not of which candidate chunk it rode in on.
			"discover": discover && start == 0,
		})
		if err != nil {
			reportEvalUnavailable("cannot build request: " + err.Error())
			return nil, nil, false
		}
		var parsed labelEvalResponse
		var reason string
		for attempt := 1; attempt <= labelEvalAttempts; attempt++ {
			var retry bool
			parsed, retry, reason = postLabelEval(body)
			if reason == "" || !retry || attempt == labelEvalAttempts {
				break
			}
			time.Sleep(labelEvalRetryDelay)
		}
		if reason != "" {
			reportEvalUnavailable(reason)
			return nil, nil, false
		}
		for _, r := range parsed.Results {
			// out+origin_area = the member stands in the post's origin
			// group's area, which the stored reach deliberately unions
			// in: treat as NO verdict, so the cell grid (which holds
			// that union) decides - on every surface.
			if r.Verdict == LabelVerdictOut && r.OriginArea {
				continue
			}
			if r.Verdict == LabelVerdictIn || r.Verdict == LabelVerdictOut {
				out[r.Msgid] = r.Verdict
			}
		}
		for _, d := range parsed.Discovered {
			discovered = append(discovered, d.Msgid)
		}
	}
	// A discovered id can also ride in a LATER chunk of the candidate list,
	// where its own verdict may be "out" (discover only sees the first
	// chunk's asked set). The verdict wins: never re-admit what the labels
	// narrowed away.
	if len(discovered) > 0 {
		kept := discovered[:0]
		for _, id := range discovered {
			if out[id] != LabelVerdictOut {
				kept = append(kept, id)
			}
		}
		discovered = kept
	}
	return out, discovered, true
}
