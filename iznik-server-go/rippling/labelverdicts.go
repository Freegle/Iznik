package rippling

// Stored-label membership verdicts from the routing server - the read side
// of the labels-truth cutover. One batched call answers "is this member
// inside each of these posts' CURRENT reach" exactly from the road network,
// for every post the backfill has labelled; the rest come back "nolabels"
// and keep their cell-grid verdict. Fail-soft: any routing problem returns
// nil and callers change nothing.

import (
	"bytes"
	"encoding/json"
	"net/http"
	"time"

	"github.com/freegle/iznik-server-go/roadblur"
)

var labelEvalClient = &http.Client{Timeout: 3 * time.Second}

const (
	LabelVerdictIn  = "in"
	LabelVerdictOut = "out"
)

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
	verdicts, _ := labelEval(lat, lng, msgids, budget, false)
	return verdicts
}

// LabelVerdictsWithDiscover additionally returns posts the candidate list
// MISSED whose stored labels admit this member - covering the band where the
// grid prefilter under-covers the true road reach.
func LabelVerdictsWithDiscover(lat, lng float64, msgids []uint64) (map[uint64]string, []uint64) {
	return labelEval(lat, lng, msgids, "", true)
}

func labelEval(lat, lng float64, msgids []uint64, budget string, discover bool) (map[uint64]string, []uint64) {
	// An empty candidate list still discovers: a member covered by NO grid
	// can still be admitted by a stored label (the under-coverage band).
	if (len(msgids) == 0 && !discover) || (lat == 0 && lng == 0) || !roadblur.RoutingHealthy() {
		return nil, nil
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
			return nil, nil
		}
		resp, err := labelEvalClient.Post(roadblur.RoutingURL()+"/v1/reach-eval", "application/json", bytes.NewReader(body))
		if err != nil {
			roadblur.MarkRoutingFailure()
			return nil, nil
		}
		func() {
			defer resp.Body.Close()
			if resp.StatusCode != http.StatusOK {
				// 503 = engine or labels store not configured: expected until
				// the artifacts are deployed, not a routing failure.
				if resp.StatusCode != http.StatusServiceUnavailable {
					roadblur.MarkRoutingFailure()
				}
				out = nil
				return
			}
			var parsed struct {
				Results []struct {
					Msgid   uint64 `json:"msgid"`
					Verdict string `json:"verdict"`
				} `json:"results"`
				Discovered []struct {
					Msgid uint64 `json:"msgid"`
				} `json:"discovered"`
			}
			if err := json.NewDecoder(resp.Body).Decode(&parsed); err != nil {
				out = nil
				return
			}
			for _, r := range parsed.Results {
				if r.Verdict == LabelVerdictIn || r.Verdict == LabelVerdictOut {
					out[r.Msgid] = r.Verdict
				}
			}
			for _, d := range parsed.Discovered {
				discovered = append(discovered, d.Msgid)
			}
		}()
		if out == nil {
			return nil, nil
		}
	}
	return out, discovered
}
