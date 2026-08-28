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
func DropLabelOut(ids []uint64, verdicts map[uint64]string) []uint64 {
	if len(verdicts) == 0 {
		return ids
	}
	kept := ids[:0]
	for _, id := range ids {
		if verdicts[id] != LabelVerdictOut {
			kept = append(kept, id)
		}
	}
	return kept
}

// LabelVerdicts returns msgid -> "in"/"out" for candidates whose stored
// labels decided them; candidates with no labels (or on any failure) are
// simply absent from the map.
func LabelVerdicts(lat, lng float64, msgids []uint64) map[uint64]string {
	if len(msgids) == 0 || (lat == 0 && lng == 0) || !roadblur.RoutingHealthy() {
		return nil
	}
	out := make(map[uint64]string, len(msgids))
	const chunk = 1000
	for start := 0; start < len(msgids); start += chunk {
		end := start + chunk
		if end > len(msgids) {
			end = len(msgids)
		}
		body, err := json.Marshal(map[string]any{
			"lat": lat, "lng": lng, "msgids": msgids[start:end],
		})
		if err != nil {
			return nil
		}
		resp, err := labelEvalClient.Post(roadblur.RoutingURL()+"/v1/reach-eval", "application/json", bytes.NewReader(body))
		if err != nil {
			roadblur.MarkRoutingFailure()
			return nil
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
		}()
		if out == nil {
			return nil
		}
	}
	return out
}
