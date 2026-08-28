package main

// Membership evaluation from STORED reach labels - the read side of the
// labels-truth cutover. Callers (the browse feed, the reply gate, the digest)
// send a member point and candidate post ids; for every candidate whose
// stored FRL2 label exists and matches this partition build, the answer is
// the label's EXACT road-network verdict at the post's CURRENT tick budget.
// Candidates without labels come back "nolabels" and the caller keeps its
// cell-grid verdict - so the flip activates per post as the backfill
// progresses, with no flag day and no behaviour cliff.

import (
	"database/sql"
	"encoding/json"
	"strings"
	"sync"
	"time"

	"github.com/gofiber/fiber/v2"
)

// Decoded-label cache: labels are written once (and only rewritten by a
// --all re-backfill), so a short TTL is purely a rewrite-visibility bound.
type evalLabelEntry struct {
	lbl     *ReachLabels // nil = row has no usable labels
	expires time.Time
}

// Current-budget cache: the tick advances on a schedule measured in hours,
// so a short TTL keeps the JSON parsing off the hot path.
type evalBudgetEntry struct {
	secs    float32
	expires time.Time
}

var (
	evalMu      sync.Mutex
	evalLabels  = map[uint64]evalLabelEntry{}
	evalBudgets = map[uint64]evalBudgetEntry{}
)

const (
	evalLabelTTL  = 10 * time.Minute
	evalBudgetTTL = time.Minute
	evalCacheCap  = 20000
	evalMaxItems  = 1000
)

// evalRow is one candidate's stored state, however loaded.
type evalRow struct {
	msgid    uint64
	blob     []byte
	tick     int
	maxMin   float64
	schedule string
}

// evalRowLoader fetches candidate rows; a var so tests can inject rows
// without a database. The default reads the same MySQL the spatial loaders
// use; nil db means "unavailable".
var evalRowLoader = func(ids []uint64) ([]evalRow, error) {
	db := groupsDB
	if db == nil {
		return nil, errNoEvalDB
	}
	ph := make([]string, len(ids))
	args := make([]interface{}, len(ids))
	for i, id := range ids {
		ph[i] = "?"
		args[i] = id
	}
	rows, err := db.Query(
		"SELECT msgid, reach_labels, tick, max_drive_min, schedule FROM rippling_reach WHERE msgid IN ("+
			strings.Join(ph, ",")+")", args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []evalRow
	for rows.Next() {
		var r evalRow
		var blob []byte
		var schedule sql.NullString
		if err := rows.Scan(&r.msgid, &blob, &r.tick, &r.maxMin, &schedule); err != nil {
			continue
		}
		r.blob = blob
		r.schedule = schedule.String
		out = append(out, r)
	}
	return out, nil
}

var errNoEvalDB = fiber.NewError(fiber.StatusServiceUnavailable, "no database for stored labels")

type reachEvalReq struct {
	Lat    float64  `json:"lat"`
	Lng    float64  `json:"lng"`
	Msgids []uint64 `json:"msgids"`
}

type reachEvalResult struct {
	Msgid uint64 `json:"msgid"`
	// "in" / "out" from the stored label; "nolabels" when the post has no
	// usable stored label (not backfilled yet, or from another partition
	// build) and the caller must keep its cell-grid verdict.
	Verdict string   `json:"verdict"`
	Arrival *float32 `json:"arrival,omitempty"`
}

// handleReachEval handles POST /v1/reach-eval.
func handleReachEval() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachLive
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		var req reachEvalReq
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "invalid body")
		}
		if len(req.Msgids) == 0 || len(req.Msgids) > evalMaxItems {
			return fiber.NewError(fiber.StatusBadRequest, "1-1000 msgids required")
		}
		if req.Lat == 0 && req.Lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}

		// One snap for the member, shared across every candidate.
		v := nearestNodeForMode(e.G, req.Lat, req.Lng, Drive)
		if v == noNode {
			return fiber.NewError(fiber.StatusBadRequest, "point is not on the road network")
		}

		if err := evalLoad(e, req.Msgids); err != nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "labels unavailable: "+err.Error())
		}

		results := make([]reachEvalResult, 0, len(req.Msgids))
		evalMu.Lock()
		defer evalMu.Unlock()
		for _, id := range req.Msgids {
			le, ok := evalLabels[id]
			be := evalBudgets[id]
			if !ok || le.lbl == nil {
				results = append(results, reachEvalResult{Msgid: id, Verdict: "nolabels"})
				continue
			}
			arr := e.ArrivalAtBaseNode(le.lbl, v)
			if arr <= be.secs {
				a := arr
				results = append(results, reachEvalResult{Msgid: id, Verdict: "in", Arrival: &a})
			} else {
				results = append(results, reachEvalResult{Msgid: id, Verdict: "out"})
			}
		}
		return c.JSON(fiber.Map{"results": results})
	}
}

// evalLoad fills the label/budget caches for any of the ids not already
// cached. One indexed query; decode failures (including a partition
// fingerprint mismatch) cache as "no usable labels".
func evalLoad(e *ReachEngine, ids []uint64) error {
	now := time.Now()
	evalMu.Lock()
	var missing []uint64
	for _, id := range ids {
		le, okL := evalLabels[id]
		be, okB := evalBudgets[id]
		if !okL || now.After(le.expires) || !okB || now.After(be.expires) {
			missing = append(missing, id)
		}
	}
	evalMu.Unlock()
	if len(missing) == 0 {
		return nil
	}

	loaded, err := evalRowLoader(missing)
	if err != nil {
		return err
	}

	evalMu.Lock()
	defer evalMu.Unlock()
	seen := make(map[uint64]bool, len(missing))
	for _, r := range loaded {
		seen[r.msgid] = true

		var lbl *ReachLabels
		if len(r.blob) > 0 {
			if decoded, err := e.DecodeLabels(r.blob); err == nil {
				lbl = decoded
			}
		}
		evalLabels[r.msgid] = evalLabelEntry{lbl: lbl, expires: now.Add(evalLabelTTL)}
		evalBudgets[r.msgid] = evalBudgetEntry{secs: currentBudgetSecs(r.tick, r.maxMin, r.schedule), expires: now.Add(evalBudgetTTL)}
	}
	// Ids with no reach row at all: cache as no-labels so repeats stay cheap.
	for _, id := range missing {
		if !seen[id] {
			evalLabels[id] = evalLabelEntry{lbl: nil, expires: now.Add(evalBudgetTTL)}
			evalBudgets[id] = evalBudgetEntry{expires: now.Add(evalBudgetTTL)}
		}
	}
	// Crude bound: reset rather than LRU - refilled in one query per feed.
	if len(evalLabels) > evalCacheCap {
		evalLabels = map[uint64]evalLabelEntry{}
		evalBudgets = map[uint64]evalBudgetEntry{}
	}
	return nil
}

// currentBudgetSecs is the post's CURRENT drive-time budget: the schedule
// entry for its current tick, falling back to the row's maximum when the
// schedule is missing or unparseable (a too-wide budget only re-admits what
// the maximum-budget label already contains).
func currentBudgetSecs(tick int, maxMin float64, schedule string) float32 {
	if schedule != "" {
		var entries []struct {
			Tick     int     `json:"tick"`
			DriveMin float64 `json:"drive_min"`
		}
		if err := json.Unmarshal([]byte(schedule), &entries); err == nil {
			for _, en := range entries {
				if en.Tick == tick && en.DriveMin > 0 {
					return float32(en.DriveMin * 60)
				}
			}
		}
	}
	return float32(maxMin * 60)
}

// resetReachEvalForTest clears the caches between tests.
func resetReachEvalForTest() {
	evalMu.Lock()
	defer evalMu.Unlock()
	evalLabels = map[uint64]evalLabelEntry{}
	evalBudgets = map[uint64]evalBudgetEntry{}
}
