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
	lbl      *ReachLabels // nil = row has no usable labels
	rejected []int64      // group ids whose areas are subtracted from this reach
	expires  time.Time
}

// Current-budget cache: the tick advances on a schedule measured in hours,
// so a short TTL keeps the JSON parsing off the hot path.
type evalBudgetEntry struct {
	secs    float32 // current tick budget
	maxSecs float32 // the row's maximum budget
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
	rejected string // rejected_groups JSON: group areas subtracted from this reach
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
		"SELECT msgid, reach_labels, tick, max_drive_min, schedule, rejected_groups FROM rippling_reach WHERE msgid IN ("+
			strings.Join(ph, ",")+")", args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []evalRow
	for rows.Next() {
		var r evalRow
		var blob []byte
		var schedule, rejected sql.NullString
		if err := rows.Scan(&r.msgid, &blob, &r.tick, &r.maxMin, &schedule, &rejected); err != nil {
			continue
		}
		r.blob = blob
		r.schedule = schedule.String
		r.rejected = rejected.String
		out = append(out, r)
	}
	return out, nil
}

var errNoEvalDB = fiber.NewError(fiber.StatusServiceUnavailable, "no database for stored labels")

type reachEvalReq struct {
	Lat    float64  `json:"lat"`
	Lng    float64  `json:"lng"`
	Msgids []uint64 `json:"msgids"`
	// Budget: "" / "current" = the post's current tick budget (the reach as
	// members see it today); "max" = the label's own full budget (the
	// maximum reach - what the first-reply targeting asks about).
	Budget string `json:"budget"`
	// Discover: also return, as additional "in" results, posts NOT in
	// msgids whose stored leaves cover the member's region and whose label
	// admits them - the candidates a grid prefilter under-covers.
	Discover bool `json:"discover"`
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
		if len(req.Msgids) > evalMaxItems || (len(req.Msgids) == 0 && !req.Discover) {
			return fiber.NewError(fiber.StatusBadRequest, "1-1000 msgids required (0 allowed with discover)")
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

		useMax := req.Budget == "max"

		// A member inside a REJECTED group's area is out of that post's reach
		// whatever the label says - the durable record of a per-group mod
		// retraction is rejected_groups; the cells clip was only its
		// materialisation.
		inRejectedArea := func(rejected []int64) bool {
			for _, gid := range rejected {
				if groupAreaContains(gid, req.Lat, req.Lng) {
					return true
				}
			}
			return false
		}

		verdictFor := func(id uint64) reachEvalResult {
			le, ok := evalLabels[id]
			be := evalBudgets[id]
			if !ok || le.lbl == nil {
				return reachEvalResult{Msgid: id, Verdict: "nolabels"}
			}
			if inRejectedArea(le.rejected) {
				return reachEvalResult{Msgid: id, Verdict: "out"}
			}
			budget := be.secs
			if useMax {
				budget = be.maxSecs
			}
			arr := e.ArrivalAtBaseNode(le.lbl, v)
			if arr <= budget {
				a := arr
				return reachEvalResult{Msgid: id, Verdict: "in", Arrival: &a}
			}
			return reachEvalResult{Msgid: id, Verdict: "out"}
		}

		results := make([]reachEvalResult, 0, len(req.Msgids))
		asked := make(map[uint64]bool, len(req.Msgids))
		evalMu.Lock()
		for _, id := range req.Msgids {
			asked[id] = true
			results = append(results, verdictFor(id))
		}
		evalMu.Unlock()

		// Discover: posts a grid prefilter missed. The stored leaves say
		// whose MAXIMUM reach covers the member's region (a superset), and
		// the label evaluation above then answers exactly at the requested
		// budget.
		var discovered []reachEvalResult
		if req.Discover {
			cands := leafCandidates(v, e)
			var fresh []uint64
			for _, id := range cands {
				if !asked[id] {
					fresh = append(fresh, id)
				}
			}
			if len(fresh) > 0 {
				if err := evalLoad(e, fresh); err == nil {
					evalMu.Lock()
					for _, id := range fresh {
						if r := verdictFor(id); r.Verdict == "in" {
							discovered = append(discovered, r)
						}
					}
					evalMu.Unlock()
				}
			}
		}

		return c.JSON(fiber.Map{"results": results, "discovered": discovered})
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
		var rejected []int64
		if r.rejected != "" {
			_ = json.Unmarshal([]byte(r.rejected), &rejected)
		}
		evalLabels[r.msgid] = evalLabelEntry{lbl: lbl, rejected: rejected, expires: now.Add(evalLabelTTL)}
		evalBudgets[r.msgid] = evalBudgetEntry{
			secs:    currentBudgetSecs(r.tick, r.maxMin, r.schedule),
			maxSecs: float32(r.maxMin * 60),
			expires: now.Add(evalBudgetTTL),
		}
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
	evalLabels = map[uint64]evalLabelEntry{}
	evalBudgets = map[uint64]evalBudgetEntry{}
	evalMu.Unlock()
	groupAreaMu.Lock()
	groupAreaCache = map[int64]groupAreaEntry{}
	groupAreaMu.Unlock()
	leafCandMu.Lock()
	leafCandCache = map[int32]leafCandEntry{}
	leafCandMu.Unlock()
}

// groupAreaContains answers "is this point inside group gid's area", from the
// group's stored polygon (cached). False on any failure - failing open on a
// rejected-group subtraction would resurrect a post a moderator retracted,
// so absence of the polygon keeps the member OUT only when the group truly
// has no area (nothing was subtracted then either).
type groupAreaEntry struct {
	rings   [][][2]float64
	expires time.Time
}

var (
	groupAreaMu    sync.Mutex
	groupAreaCache = map[int64]groupAreaEntry{}
)

func groupAreaContains(gid int64, lat, lng float64) bool {
	now := time.Now()
	groupAreaMu.Lock()
	entry, ok := groupAreaCache[gid]
	groupAreaMu.Unlock()
	if !ok || now.After(entry.expires) {
		var rings [][][2]float64
		if db := groupsDB; db != nil {
			var wkt sql.NullString
			if err := db.QueryRow("SELECT ST_AsText(polyindex) FROM `groups` WHERE id = ? AND polyindex IS NOT NULL AND ST_GeometryType(polyindex) <> 'POINT'", gid).Scan(&wkt); err == nil && wkt.Valid {
				if r, err := wktPolygonToCoords(wkt.String); err == nil {
					rings = r
				}
			}
		}
		entry = groupAreaEntry{rings: rings, expires: now.Add(10 * time.Minute)}
		groupAreaMu.Lock()
		groupAreaCache[gid] = entry
		if len(groupAreaCache) > 5000 {
			groupAreaCache = map[int64]groupAreaEntry{gid: entry}
		}
		groupAreaMu.Unlock()
	}
	if len(entry.rings) == 0 {
		return false
	}
	// Outer ring contains, holes exclude - same even-odd rule as pointInRing.
	inside := false
	for i, ring := range entry.rings {
		if pointInRing(lng, lat, ring) {
			if i == 0 {
				inside = true
			} else {
				inside = false
			}
		}
	}
	return inside
}

// leafCandidates: which posts' stored leaves cover the member's region(s) -
// the discover superset. Cached briefly; posts churn slowly at region scale.
type leafCandEntry struct {
	ids     []uint64
	expires time.Time
}

var (
	leafCandMu    sync.Mutex
	leafCandCache = map[int32]leafCandEntry{}
)

// leafRowLoader fetches the posts whose stored leaves include one region; a
// var so tests can inject candidates without a database.
var leafRowLoader = func(leaf int32) []uint64 {
	db := groupsDB
	if db == nil {
		return nil
	}
	var ids []uint64
	rows, err := db.Query("SELECT msgid FROM rippling_reach_leaves WHERE leaf = ?", leaf)
	if err == nil {
		for rows.Next() {
			var id uint64
			if rows.Scan(&id) == nil {
				ids = append(ids, id)
			}
		}
		rows.Close()
	}
	return ids
}

func leafCandidates(v NodeID, e *ReachEngine) []uint64 {
	// The member's region(s): one for a junction, up to two for a mid-chain
	// point straddling a cut (same rule as /v1/leaf).
	var leaves []int32
	add := func(j NodeID) {
		if j == 0 {
			return
		}
		if oi := e.Ov.Idx[j]; oi != 0 {
			if l := e.Part.LeafOf[oi]; l >= 0 {
				for _, x := range leaves {
					if x == l {
						return
					}
				}
				leaves = append(leaves, l)
			}
		}
	}
	if e.Ov.Idx[v] != 0 {
		add(v)
	} else {
		add(e.Ov.ChainEndA[v])
		add(e.Ov.ChainEndB[v])
	}

	now := time.Now()
	var out []uint64
	for _, leaf := range leaves {
		leafCandMu.Lock()
		entry, ok := leafCandCache[leaf]
		leafCandMu.Unlock()
		if !ok || now.After(entry.expires) {
			entry = leafCandEntry{ids: leafRowLoader(leaf), expires: now.Add(time.Minute)}
			leafCandMu.Lock()
			leafCandCache[leaf] = entry
			if len(leafCandCache) > 5000 {
				leafCandCache = map[int32]leafCandEntry{leaf: entry}
			}
			leafCandMu.Unlock()
		}
		out = append(out, entry.ids...)
	}
	return out
}
