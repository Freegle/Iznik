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
	"log"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/gofiber/fiber/v2"
)

// Decoded-label cache: labels are written once (and only rewritten by a
// --all re-backfill), so a short TTL is purely a rewrite-visibility bound.
type evalLabelEntry struct {
	lbl     *ReachLabels // nil = row has no usable labels
	eng     *ReachEngine // the build that decoded lbl (arrivals only mean anything there)
	expires time.Time
}

// Current-budget cache: the tick advances on a schedule measured in hours,
// so a short TTL keeps the JSON parsing off the hot path.
// Everything row-mutable rides here on the SHORT TTL: a moderator's
// retraction (rejected), a freeze (held) and the advancing tick must all
// bite within a minute, where the immutable label blob can cache for ten.
type evalBudgetEntry struct {
	secs      float32 // current tick budget
	maxSecs   float32 // the row's maximum budget
	rejected  []int64 // group ids whose areas are subtracted from this reach
	held      bool    // frozen (back in moderation): never discoverable
	originGid int64   // the post's origin group (its area is union-admitted)
	// Road-native origin-group union threshold (reach_union.go):
	// unionKnown=false (column NULL) = not computed yet, keep the
	// transitional origin_area no-verdict behaviour; unionSecs=unionNever =
	// computed, the union never activates; >=0 = the budget at which the
	// origin group's whole area becomes admitted.
	unionKnown bool
	unionSecs  float32
	expires    time.Time
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

// discoverMaxItems bounds how many leaf candidates one discover evaluates. It
// is a var so a test can shrink it. It used to share evalMaxItems (1,000), which
// is sized for a caller's candidate chunk, not for a region: once the cell grids
// retired (2026-08-28) discovery became the ONLY way a post reaches the nearby
// feed, and a region inside the 45-minute maximum reach of a city holds far more
// live posts than that - 682 of the UK's ~23,700 regions exceed 1,000, the
// densest ~5,100. The candidates were then trimmed in id order, so the thousand
// kept were the OLDEST and members in those regions saw no post from the last
// week at all (Bath, ChitChat 2026-08-31). Candidates are now evaluated newest
// first and the valve sits at twice the densest region measured, so reaching it
// drops the oldest posts, never this week's - and it logs, so it is never silent.
var discoverMaxItems = 10000

// evalRow is one candidate's stored state, however loaded.
type evalRow struct {
	msgid      uint64
	blob       []byte
	tick       int
	maxMin     float64
	schedule   string
	rejected   string // rejected_groups JSON: group areas subtracted from this reach
	held       bool   // status='held': frozen, hidden on every surface
	originGid  int64  // the post's origin group id (0 = unknown)
	unionKnown bool   // origin_union_secs column non-NULL
	unionSecs  float32
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
	args := make([]interface{}, 0, len(ids)+1)
	// A label staged for the NEXT partition (reach_labels_next, stamped with
	// reach_labels_next_fp) is used only when that stamp is THIS engine's
	// partition; otherwise the live column decides, exactly as before. That
	// is what makes a partition cutover atomic: the moment a node boots the
	// new artifacts, every staged post switches with it, and nothing was
	// mutated to get there. An engine with no fingerprint (nil) never matches.
	args = append(args, liveReachPartFP())
	for i, id := range ids {
		ph[i] = "?"
		args = append(args, id)
	}
	rows, err := db.Query(
		"SELECT rr.msgid, COALESCE(IF(rr.reach_labels_next_fp = ?, rr.reach_labels_next, NULL), rr.reach_labels), "+
			"rr.tick, rr.max_drive_min, rr.schedule, rr.rejected_groups, rr.status, rr.origin_union_secs, "+
			"(SELECT mg.groupid FROM messages_groups mg WHERE mg.msgid = rr.msgid AND mg.deleted = 0 ORDER BY mg.arrival ASC LIMIT 1) "+
			"FROM rippling_reach rr WHERE rr.msgid IN ("+
			strings.Join(ph, ",")+")", args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []evalRow
	for rows.Next() {
		var r evalRow
		var blob []byte
		var maxMin, unionSecs sql.NullFloat64
		var origin sql.NullInt64
		var schedule, rejected, status sql.NullString
		if err := rows.Scan(&r.msgid, &blob, &r.tick, &maxMin, &schedule, &rejected, &status, &unionSecs, &origin); err != nil {
			// Every column is NOT NULL or scanned through a Null type, so a scan
			// error is the connection failing under us, not a row's data. Dropping
			// the row would let the caller cache "no reach row" for it.
			return nil, err
		}
		r.blob = blob
		r.maxMin = maxMin.Float64
		r.schedule = schedule.String
		r.rejected = rejected.String
		r.held = status.String == "held"
		r.originGid = origin.Int64
		r.unionKnown = unionSecs.Valid
		r.unionSecs = float32(unionSecs.Float64)
		out = append(out, r)
	}
	// A result set cut short by a dropped connection ends the loop exactly like
	// a complete one: only rows.Err tells them apart. Without this check the
	// posts that never arrived were cached as having no reach row, and vanished
	// from every member's feed and badge for a minute.
	if err := rows.Err(); err != nil {
		return nil, err
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
	// OriginArea on an "out": the member stands inside the post's ORIGIN
	// group's area. The stored reach deliberately unions that area in once
	// the isochrone covers most of it (ExpandService::unionWithOriginGroupArea),
	// so road time alone must not retract it - callers treat out+origin_area
	// as NO verdict and let their cell grid decide, which is exactly the
	// union the grid materialised.
	OriginArea bool `json:"origin_area,omitempty"`
}

// handleReachEval handles POST /v1/reach-eval.
func handleReachEval() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachEngine()
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

		// One snap for the member per BUILD, shared across every candidate:
		// a blob only evaluates on the build that decoded it, so a member
		// node is needed on each loaded build. A point that does not snap
		// (off-network address, geocoding slop) degrades to "no verdicts" -
		// the callers keep their cell-grid answers - the same graceful shape
		// blur, leaf and drive-metrics use. A 4xx here would trip the
		// callers' shared routing breaker on one member's ordinary location.
		v := nearestDriveNode(e.G, req.Lat, req.Lng)
		vPrev := noNode
		if prev := reachPrevEngine(); prev != nil {
			vPrev = nearestDriveNode(prev.G, req.Lat, req.Lng)
		}
		if v == noNode {
			results := make([]reachEvalResult, 0, len(req.Msgids))
			for _, id := range req.Msgids {
				results = append(results, reachEvalResult{Msgid: id, Verdict: "nolabels"})
			}
			return c.JSON(fiber.Map{"results": results, "discovered": []reachEvalResult{}})
		}

		if err := evalLoad(e, req.Msgids); err != nil {
			log.Printf("reach-eval: labels unavailable for %d candidates: %v", len(req.Msgids), err)
			return fiber.NewError(fiber.StatusServiceUnavailable, "labels unavailable: "+err.Error())
		}

		useMax := req.Budget == "max"

		// A member inside a REJECTED group's area is out of that post's reach
		// whatever the label says - the durable record of a per-group mod
		// retraction is rejected_groups; the cells clip was only its
		// materialisation. The area tests can hit MySQL, so they are resolved
		// OUT HERE and the verdict loop below only reads the result map -
		// evalMu is process-wide and must never be held across a round trip.
		areaHit := map[int64]bool{}
		resolveAreas := func(ids []uint64) {
			gids := map[int64]bool{}
			evalMu.Lock()
			for _, id := range ids {
				if be, ok := evalBudgets[id]; ok {
					for _, gid := range be.rejected {
						if _, done := areaHit[gid]; !done {
							gids[gid] = true
						}
					}
					if be.originGid != 0 {
						if _, done := areaHit[be.originGid]; !done {
							gids[be.originGid] = true
						}
					}
				}
			}
			evalMu.Unlock()
			for gid := range gids {
				areaHit[gid] = groupAreaContains(gid, req.Lat, req.Lng)
			}
		}
		resolveAreas(req.Msgids)

		verdictFor := func(id uint64, discovering bool) reachEvalResult {
			le, ok := evalLabels[id]
			be := evalBudgets[id]
			if !ok || le.lbl == nil {
				return reachEvalResult{Msgid: id, Verdict: "nolabels"}
			}
			// The blob evaluates only on the build that decoded it.
			eng, node := le.eng, v
			if eng == nil {
				eng = e
			}
			if eng != e {
				node = vPrev
			}
			if node == noNode {
				return reachEvalResult{Msgid: id, Verdict: "nolabels"}
			}
			if discovering && be.held {
				// A frozen reach is hidden on every surface; discover must
				// not resurrect it. (Explicitly-asked candidates keep their
				// label verdict - the caller's own status filters apply.)
				return reachEvalResult{Msgid: id, Verdict: "out"}
			}
			for _, gid := range be.rejected {
				if areaHit[gid] {
					return reachEvalResult{Msgid: id, Verdict: "out"}
				}
			}
			budget := be.secs
			if useMax {
				budget = be.maxSecs
			}
			arr := eng.ArrivalAtBaseNode(le.lbl, node)
			if arr <= budget {
				a := arr
				return reachEvalResult{Msgid: id, Verdict: "in", Arrival: &a}
			}
			inOriginArea := be.originGid != 0 && areaHit[be.originGid]
			if inOriginArea && be.unionKnown {
				// Road-native union (reach_union.go): once the budget passes
				// the stored threshold, the origin group's whole area is
				// admitted - the definitive answer, no cells needed.
				if be.unionSecs >= 0 && budget >= be.unionSecs {
					return reachEvalResult{Msgid: id, Verdict: "in"}
				}
				return reachEvalResult{Msgid: id, Verdict: "out"}
			}
			return reachEvalResult{
				Msgid: id, Verdict: "out",
				// Transitional (threshold not computed yet): flag it so the
				// callers let the cell grid - which holds the union - decide.
				OriginArea: inOriginArea,
			}
		}

		results := make([]reachEvalResult, 0, len(req.Msgids))
		asked := make(map[uint64]bool, len(req.Msgids))
		evalMu.Lock()
		for _, id := range req.Msgids {
			asked[id] = true
			results = append(results, verdictFor(id, false))
		}
		evalMu.Unlock()

		// Discover: posts a grid prefilter missed. The stored leaves say
		// whose MAXIMUM reach covers the member's region (a superset), and
		// the label evaluation above then answers exactly at the requested
		// budget. Bounded like the candidate list itself - a region with more
		// live posts than the cap is one no feed page would exhaust anyway.
		// Serialised as [] rather than null when nothing is discovered: the
		// callers read null and [] the same way, but a null answer is what a
		// swallowed failure used to look like, and the distinction has to be
		// visible in a captured response.
		discovered := []reachEvalResult{}
		if req.Discover {
			// Discovery fails CLOSED, with a 503 the caller can see. Since the
			// cell grids retired it is the only way a post reaches the nearby
			// feed and badge, so "the label store could not be read" must never
			// be served as "nothing is in reach": that answer was cached by
			// the badge for 30s and painted "You're up to date" over a feed
			// that was simply not loaded.
			cands, err := leafCandidates(v, vPrev, e)
			if err != nil {
				log.Printf("reach-eval discover: region candidates unavailable: %v", err)
				return fiber.NewError(fiber.StatusServiceUnavailable, "labels unavailable: "+err.Error())
			}
			// Newest first: msgids are allotted in posting order, so if the
			// valve below trims anything it is the oldest posts that go, never
			// the ones that arrived this week (see discoverMaxItems). A point
			// straddling two regions can offer the same post twice; evaluate it
			// once.
			sort.Slice(cands, func(i, j int) bool { return cands[i] > cands[j] })
			fresh := make([]uint64, 0, len(cands))
			seen := make(map[uint64]bool, len(cands))
			for _, id := range cands {
				if asked[id] || seen[id] {
					continue
				}
				seen[id] = true
				fresh = append(fresh, id)
				if len(fresh) == discoverMaxItems {
					log.Printf("reach-eval discover: region with %d candidates trimmed to the newest %d", len(cands), discoverMaxItems)
					break
				}
			}
			if len(fresh) > 0 {
				if err := evalLoad(e, fresh); err != nil {
					log.Printf("reach-eval discover: labels unavailable for %d region candidates: %v", len(fresh), err)
					return fiber.NewError(fiber.StatusServiceUnavailable, "labels unavailable: "+err.Error())
				}
				resolveAreas(fresh)
				evalMu.Lock()
				for _, id := range fresh {
					if r := verdictFor(id, true); r.Verdict == "in" {
						discovered = append(discovered, r)
					}
				}
				evalMu.Unlock()
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
		var lblEng *ReachEngine
		if len(r.blob) > 0 {
			if decoded, eng, err := decodeLabelsAnyBuild(r.blob); err == nil {
				lbl, lblEng = decoded, eng
			}
		}
		var rejected []int64
		if r.rejected != "" {
			_ = json.Unmarshal([]byte(r.rejected), &rejected)
		}
		ttl := evalLabelTTL
		if lbl == nil {
			// A row mid-backfill grows a label soon: re-check on the short
			// TTL rather than pinning "no labels" for the full label TTL.
			ttl = evalBudgetTTL
		}
		evalLabels[r.msgid] = evalLabelEntry{lbl: lbl, eng: lblEng, expires: now.Add(ttl)}
		evalBudgets[r.msgid] = evalBudgetEntry{
			secs:       currentBudgetSecs(r.tick, r.maxMin, r.schedule),
			maxSecs:    float32(r.maxMin * 60),
			rejected:   rejected,
			held:       r.held,
			originGid:  r.originGid,
			unionKnown: r.unionKnown,
			unionSecs:  r.unionSecs,
			expires:    now.Add(evalBudgetTTL),
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

// groupAreaRings loads (cached) the group's area as a flat ring list; nil
// when the group has no polygonal area or the database is unavailable.
func groupAreaRings(gid int64) [][][2]float64 {
	now := time.Now()
	groupAreaMu.Lock()
	entry, ok := groupAreaCache[gid]
	groupAreaMu.Unlock()
	if !ok || now.After(entry.expires) {
		var rings [][][2]float64
		if db := groupsDB; db != nil {
			var wkt sql.NullString
			if err := db.QueryRow("SELECT ST_AsText(polyindex) FROM `groups` WHERE id = ? AND polyindex IS NOT NULL AND ST_GeometryType(polyindex) <> 'POINT'", gid).Scan(&wkt); err == nil && wkt.Valid {
				if r, err := wktAreaRings(wkt.String); err == nil {
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
	return entry.rings
}

func groupAreaContains(gid int64, lat, lng float64) bool {
	rings := groupAreaRings(gid)
	if len(rings) == 0 {
		return false
	}
	return pointInRings(lng, lat, rings)
}

// pointInRings is pure even-odd over every ring: correct for holes AND for
// the disjoint parts of a MULTIPOLYGON (whose rings are flattened into one
// list). The one containment loop the eval and the union sampler share.
func pointInRings(lng, lat float64, rings [][][2]float64) bool {
	crossings := 0
	for _, ring := range rings {
		if pointInRing(lng, lat, ring) {
			crossings++
		}
	}
	return crossings%2 == 1
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
// var so tests can inject candidates without a database. Leaf ids are
// build-local, so once the fp column exists rows are filtered to the loaded
// builds - NULL fp (rows from before the column, or whose blob predates the
// stamp) matches loosely: a false candidate only costs a lookup, because the
// verdict still comes from the blob itself.
var leafRowLoader = func(leaf int32) ([]uint64, error) {
	db := groupsDB
	if db == nil {
		return nil, errNoEvalDB
	}
	q := "SELECT msgid FROM rippling_reach_leaves WHERE leaf = ?"
	args := []interface{}{leaf}
	if live := reachEngine(); live != nil {
		if prev := reachPrevEngine(); prev != nil {
			q += " AND (fp IS NULL OR fp IN (?, ?))"
			args = append(args, live.partFP, prev.partFP)
		} else {
			q += " AND (fp IS NULL OR fp = ?)"
			args = append(args, live.partFP)
		}
	}
	rows, err := db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var ids []uint64
	for rows.Next() {
		var id uint64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	// The loop ends the same way whether the region was read in full or the
	// connection dropped part-way; rows.Err is the only thing that says which.
	// This loader used to swallow both, and the caller then served the
	// truncated - or empty - region as the truth for a minute.
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return ids, nil
}

func leafCandidates(v, vPrev NodeID, e *ReachEngine) ([]uint64, error) {
	// The member's region(s) on EVERY loaded build: one per junction, up to
	// two for a mid-chain point straddling a cut (same rule as /v1/leaf).
	// Leaf numbers are build-local; querying the union of both builds' leaf
	// numbers with the loose fp filter gives a superset of candidates, and
	// the label evaluation decides each one exactly.
	var leaves []int32
	addOn := func(eng *ReachEngine, j NodeID) {
		if j == 0 {
			return
		}
		if oi := eng.Ov.IdxOf(j); oi != 0 {
			if l := eng.Part.LeafAt(oi); l >= 0 {
				for _, x := range leaves {
					if x == l {
						return
					}
				}
				leaves = append(leaves, l)
			}
		}
	}
	forEngine := func(eng *ReachEngine, node NodeID) {
		if eng == nil || node == noNode {
			return
		}
		if eng.Ov.IdxOf(node) != 0 {
			addOn(eng, node)
		} else {
			addOn(eng, eng.Ov.ChainA(node))
			addOn(eng, eng.Ov.ChainEndB[node])
		}
	}
	forEngine(e, v)
	forEngine(reachPrevEngine(), vPrev)

	now := time.Now()
	var out []uint64
	for _, leaf := range leaves {
		leafCandMu.Lock()
		entry, ok := leafCandCache[leaf]
		leafCandMu.Unlock()
		if !ok || now.After(entry.expires) {
			ids, err := leafRowLoader(leaf)
			if err != nil {
				// Not cached: the next request asks again. Caching a failed read
				// as an empty region is how one dropped connection emptied a
				// member's feed and zeroed their badge for the next minute.
				return nil, err
			}
			entry = leafCandEntry{ids: ids, expires: now.Add(time.Minute)}
			leafCandMu.Lock()
			leafCandCache[leaf] = entry
			if len(leafCandCache) > 5000 {
				leafCandCache = map[int32]leafCandEntry{leaf: entry}
			}
			leafCandMu.Unlock()
		}
		out = append(out, entry.ids...)
	}
	return out, nil
}
