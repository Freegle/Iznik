package main

// Reach engine server integration: artifact boot path and the two reach endpoints.
//
// Boot: set REACH_DIR to a directory holding graph.snap + partition.snap +
// matrices.snap and the server loads the engine (and its base graph) from
// artifacts in seconds instead of rebuilding from the OSM extract. If the
// partition or matrices files are absent they are DERIVED at boot (~3 minutes
// for the whole UK) and saved back — so storing them is a convenience, not a
// requirement. If REACH_DIR is unset nothing changes: no engine, endpoints
// answer 503, the server builds its graph from the PBF exactly as before.
//
// Endpoints (both /v1, same auth as the rest):
//   GET  /v1/reach-labels?lat=&lng=&minutes=   -> compute a post's labels
//        (gated: runs graph computation). Returns the stored-form bytes,
//        base64, plus summary counts.
//   POST /v1/reach-arrival {labels, points[]}  -> exact arrival seconds and
//        in-reach flag per point, evaluated from the stored-form bytes
//        (ungated: table lookups, no graph sweeps).

import (
	"container/heap"
	"encoding/base64"
	"fmt"
	"log"
	"math"
	"path/filepath"
	"sort"
	"time"

	"github.com/gofiber/fiber/v2"
)

// reachLive is the engine serving the reach endpoints; nil = not configured.
// Set once at boot before the server starts (or by tests).
var reachLive *ReachEngine

// reachPrev is the PREVIOUS partition build (REACH_DIR_PREV), held so a map
// refresh stops being a cliff: stored labels embed their build's fingerprint,
// and every evaluator routes each blob to the build that can read it. The
// re-backfill after a rebuild then becomes a rolling migration - old labels
// keep answering until each post's new one lands - instead of a site-wide
// "nolabels" window. nil = single-build operation, exactly as before.
var reachPrev *ReachEngine

// decodeLabelsAnyBuild decodes a stored blob against whichever loaded build
// matches its embedded fingerprint, returning the engine that must evaluate
// it (arrivals are only meaningful on the build that decoded the blob).
func decodeLabelsAnyBuild(b []byte) (*ReachLabels, *ReachEngine, error) {
	if reachLive == nil {
		return nil, nil, fmt.Errorf("reach engine not configured")
	}
	lbl, err := reachLive.DecodeLabels(b)
	if err == nil {
		return lbl, reachLive, nil
	}
	if reachPrev != nil {
		if lbl, perr := reachPrev.DecodeLabels(b); perr == nil {
			return lbl, reachPrev, nil
		}
	}
	return nil, nil, err
}

// loadReachEngineFromDir loads (or derives) the full artifact set, including
// the leaf-tables attach/self-heal. loadReachEngineCore is the same minus the
// leaf-tables step, for the CLI's explicit synchronous build.
func loadReachEngineFromDir(dir string) (*ReachEngine, error) {
	eng, err := loadReachEngineCore(dir)
	if err != nil {
		return nil, err
	}
	// Precomputed leaf tables: mmap when present and matching this partition;
	// otherwise the lazy path serves while the artifact self-heals in the
	// background (same derive-at-boot convention as partition/matrices, made
	// asynchronous because the full build takes ~90s for the UK).
	maybeLoadOrBuildLeafTables(dir, eng)
	return eng, nil
}

func loadReachEngineCore(dir string) (*ReachEngine, error) {
	g, ov, err := LoadReachSnapshot(filepath.Join(dir, "graph.snap"))
	if err != nil {
		return nil, fmt.Errorf("graph snapshot: %w", err)
	}
	// Both artifacts are stamped with the overlay they were built on (and the
	// matrices with the partition too), so a stale one is refused and rebuilt
	// rather than read through against a numbering it never matched.
	ovFP := overlayFingerprint(ov)
	part, err := loadPartition(filepath.Join(dir, "partition.snap"), ovFP)
	if err != nil {
		log.Printf("reach: partition artifact unusable (%v): deriving at boot", err)
		part = PartitionOverlay(g, ov, 10000, 0.25)
		if err := savePartition(filepath.Join(dir, "partition.snap"), part, ovFP); err != nil {
			log.Printf("reach: WARNING: could not save derived partition: %v", err)
		}
	}
	partFP := partitionFingerprint(part)
	rm, err := loadMatrices(filepath.Join(dir, "matrices.snap"), ovFP, partFP)
	if err != nil {
		log.Printf("reach: matrices artifact unusable (%v): deriving at boot", err)
		rm = BuildRegionMatrices(ov, part)
		if err := saveMatrices(filepath.Join(dir, "matrices.snap"), rm, ovFP, partFP); err != nil {
			log.Printf("reach: WARNING: could not save derived matrices: %v", err)
		}
	}
	return NewReachEngine(g, ov, part, rm), nil
}

// reachBootFromEnv loads the engine when REACH_DIR is set. Returns the
// engine's graph so main can skip the PBF build, or nil to fall back.
func reachBootFromEnv() *Graph {
	dir := getenv("REACH_DIR", "")
	if dir == "" {
		return nil
	}
	start := time.Now()
	eng, err := loadReachEngineFromDir(dir)
	if err != nil {
		log.Printf("reach: boot from %s failed (%v); falling back to PBF build", dir, err)
		return nil
	}
	reachLive = eng
	log.Printf("reach: engine ready in %v (%d regions, %d boundary nodes)",
		time.Since(start).Round(time.Millisecond), len(eng.Part.LeafNodes), len(eng.BI.leafOf))
	if prevDir := getenv("REACH_DIR_PREV", ""); prevDir != "" {
		pstart := time.Now()
		if prev, err := loadReachEngineFromDir(prevDir); err != nil {
			log.Printf("reach: WARNING: previous build from %s failed (%v); single-build operation", prevDir, err)
		} else if prev.partFP == eng.partFP {
			log.Printf("reach: previous build %s is the same partition; ignoring", prevDir)
		} else {
			reachPrev = prev
			log.Printf("reach: previous build ready in %v (%d regions) - rolling label migration active",
				time.Since(pstart).Round(time.Millisecond), len(prev.Part.LeafNodes))
		}
	}
	return eng.G
}

func handleReachLabels() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachLive
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		minutes := c.QueryFloat("minutes")
		if lat == 0 || lng == 0 || minutes <= 0 || minutes > 240 {
			return fiber.NewError(fiber.StatusBadRequest, "lat, lng and minutes (0-240) required")
		}
		start := time.Now()
		lbl := e.QueryLabels(lat, lng, float32(minutes*60))
		blob := e.EncodeLabels(lbl)
		full, partial := 0, 0
		for _, rl := range lbl.Reached {
			if rl.Full {
				full++
			} else {
				partial++
			}
		}
		leaves := make([]int32, 0, len(lbl.Reached))
		for leaf := range lbl.Reached {
			leaves = append(leaves, leaf)
		}
		sort.Slice(leaves, func(i, j int) bool { return leaves[i] < leaves[j] })

		resp := fiber.Map{
			"labels":  base64.StdEncoding.EncodeToString(blob),
			"t":       lbl.T,
			"regions": len(lbl.Reached),
			"leaves":  leaves,
			"full":    full,
			"partial": partial,
			"bytes":   len(blob),
			"fp":      fmt.Sprintf("%d", e.partFP),
			"ms":      float64(time.Since(start).Microseconds()) / 1000,
		}
		// With the post's msgid we can also answer the origin-group union
		// road-natively (reach_union.go): the smallest budget at which this
		// label covers 90% of the origin group's road nodes, and the
		// partition regions the group's area occupies (merged into the
		// stored leaves so union-admitted members discover the post).
		if msgid := uint64(c.QueryInt("msgid")); msgid != 0 {
			secs, unionLeaves := unionForMsgid(e, lbl, msgid)
			resp["origin_union_secs"] = secs
			resp["union_leaves"] = unionLeaves
		}
		return c.JSON(resp)
	}
}

// unionForMsgid resolves the post's origin group and computes the union
// threshold + area regions for one label. unionNever when the group has no
// area, no road nodes, or the label never covers it.
func unionForMsgid(e *ReachEngine, lbl *ReachLabels, msgid uint64) (float32, []int32) {
	gid := originGroupForMsgidFn(msgid)
	if gid == 0 {
		return unionNever, []int32{}
	}
	rings := groupAreaRings(gid)
	if len(rings) == 0 {
		return unionNever, []int32{}
	}
	secs, leaves := unionSecsForLabel(e, lbl, rings)
	if leaves == nil {
		leaves = []int32{}
	}
	return secs, leaves
}

// handleReachUnion handles POST /v1/reach-union: the backfill face of the
// union computation, for posts whose labels are ALREADY stored - decode the
// blob, compute origin_union_secs + union leaves, no label refetch.
func handleReachUnion() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachLive
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		var req struct {
			Labels string `json:"labels"`
			Msgid  uint64 `json:"msgid"`
		}
		if err := c.BodyParser(&req); err != nil || req.Labels == "" || req.Msgid == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "labels (base64) and msgid required")
		}
		raw, err := base64.StdEncoding.DecodeString(req.Labels)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "labels not base64")
		}
		lbl, eng, err := decodeLabelsAnyBuild(raw)
		if err != nil {
			return fiber.NewError(fiber.StatusUnprocessableEntity, err.Error())
		}
		secs, leaves := unionForMsgid(eng, lbl, req.Msgid)
		if leaves == nil {
			leaves = []int32{}
		}
		return c.JSON(fiber.Map{
			"origin_union_secs": secs,
			"union_leaves":      leaves,
			"fp":                fmt.Sprintf("%d", eng.partFP),
		})
	}
}

type reachArrivalReq struct {
	Labels string `json:"labels"`
	// T optionally overrides the budget for the in-reach flag (seconds,
	// clamped to the blob's own T): labels are computed once at the maximum
	// budget, and each expansion tick just raises the effective T. A pointer
	// so an explicit t:0 (nothing is in reach yet) is distinct from omitting
	// it (use the blob's full budget).
	T      *float64 `json:"t"`
	Points []struct {
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	} `json:"points"`
}

func handleReachArrival() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachLive
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		var req reachArrivalReq
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "bad body")
		}
		if len(req.Points) == 0 || len(req.Points) > 1000 {
			return fiber.NewError(fiber.StatusBadRequest, "1-1000 points required")
		}
		blob, err := base64.StdEncoding.DecodeString(req.Labels)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "labels not base64")
		}
		// Any loaded build may own this blob (rolling label migration after
		// a partition rebuild); arrivals are computed on the build that
		// decoded it.
		lbl, e, err := decodeLabelsAnyBuild(blob)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("labels: %v", err))
		}
		effT := lbl.T
		if req.T != nil {
			if *req.T < 0 {
				return fiber.NewError(fiber.StatusBadRequest, "t must be >= 0")
			}
			if float32(*req.T) < effT {
				effT = float32(*req.T)
			}
		}
		type res struct {
			Arrival *float32 `json:"arrival"`
			In      bool     `json:"in"`
		}
		out := make([]res, len(req.Points))
		for i, p := range req.Points {
			arr := e.ArrivalFromStored(lbl, p.Lat, p.Lng)
			if arr == f32Inf {
				out[i] = res{nil, false}
			} else {
				a := arr
				out[i] = res{&a, arr <= effT}
			}
		}
		return c.JSON(fiber.Map{"results": out})
	}
}

type driveMetricsReq struct {
	Lat        float64 `json:"lat"`
	Lng        float64 `json:"lng"`
	MaxMinutes float64 `json:"max_minutes"`
	Targets    []struct {
		ID  int64   `json:"id"`
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	} `json:"targets"`
}

// handleDriveMetrics answers road drive time AND road distance from one
// origin to up to 1000 targets in a single query: one labeling query from the
// origin, then a table lookup per target. This is what lets the site show
// "N miles by road" instead of crow-flies on post lists, chat and profiles.
func handleDriveMetrics() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachLive
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		var req driveMetricsReq
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "bad body")
		}
		if req.Lat == 0 || req.Lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		if len(req.Targets) == 0 || len(req.Targets) > 1000 {
			return fiber.NewError(fiber.StatusBadRequest, "1-1000 targets required")
		}
		minutes := req.MaxMinutes
		if minutes <= 0 || minutes > 120 {
			minutes = 120
		}
		start := time.Now()
		lbl := e.QueryLabelsCached(req.Lat, req.Lng, float32(minutes*60))
		type res struct {
			ID    int64    `json:"id"`
			Mins  *float64 `json:"mins"`
			Miles *float64 `json:"miles"`
		}
		out := make([]res, len(req.Targets))
		for i, tg := range req.Targets {
			out[i] = res{ID: tg.ID}
			v := nearestDriveNode(e.G, tg.Lat, tg.Lng)
			if v == noNode {
				continue
			}
			secs, mets := e.ArrivalAtBaseNodeM(lbl, v)
			if secs == f32Inf || secs > lbl.T {
				continue
			}
			mins := float64(secs) / 60
			out[i].Mins = &mins
			if mets != f32Inf {
				miles := float64(mets) / 1609.344
				out[i].Miles = &miles
			}
		}
		return c.JSON(fiber.Map{
			"results": out,
			"ms":      float64(time.Since(start).Microseconds()) / 1000,
		})
	}
}

// engineDriveTime is the fast path for /v1/drive-time when the reach engine
// is live: exact, and milliseconds instead of a bounded sweep. Returns
// handled=false to fall through to the sweep.
func engineDriveTime(lat, lng, toLat, toLng, minutes float64) (fiber.Map, bool) {
	e := reachLive
	if e == nil {
		return nil, false
	}
	dest := nearestDriveNode(e.G, toLat, toLng)
	if dest == noNode {
		return fiber.Map{"reachable": false}, true
	}
	lbl := e.QueryLabelsCached(lat, lng, float32(minutes*60))
	secs, mets := e.ArrivalAtBaseNodeM(lbl, dest)
	if secs == f32Inf || secs > lbl.T {
		return fiber.Map{"reachable": false}, true
	}
	resp := fiber.Map{
		"reachable": true,
		"drive_min": float64(secs) / 60,
	}
	if mets != f32Inf {
		resp["drive_miles"] = float64(mets) / 1609.344
	}
	return resp, true
}

// handleBlur is road-aware location blurring: instead of displacing a point
// by up to R metres in any direction (which can jump an unbridged river and
// make road distances lie wildly), pick a deterministic pseudo-random road
// node whose ROAD distance from the true location is within [R/2, 3R/2].
// The blurred point is always on the same side of any connectivity seam,
// is never the true location (at least R/2 road metres away), and is stable
// for the same input (no jitter between requests).
//
// This is the enabling primitive for routing-aware member blurring; adopting
// it for user display is a separate (privacy-visible) decision.
// roadBlurPoint is the road-aware blur core: a deterministic pseudo-random
// road node whose CONVERGED road distance from (lat,lng) is within
// [metres/2, metres*1.5] and whose crow-flies displacement is at least
// metres/4. Returns the input unchanged (roadm 0) when there is no road
// nearby or no candidate satisfies the floors.
func roadBlurPoint(g *Graph, lat, lng, metres float64) (float64, float64, float64) {
	origin := nearestDriveNode(g, lat, lng)
	if origin == noNode {
		return lat, lng, 0
	}

	lo, hi := float32(metres/2), float32(metres*1.5)
	crowFloor := metres / 4
	// Metres-bounded Dijkstra over drive edges: a real priority queue with
	// the standard stale-entry guard, so ring membership is judged on
	// CONVERGED distances only (a FIFO sweep could admit a node on a stale
	// long-way-round value whose true distance is under the privacy floor).
	dist := map[NodeID]float32{origin: 0}
	h := &miniHeap{{int32(origin), 0}}
	var farthest NodeID
	var farM float32
	for h.Len() > 0 {
		cur := heap.Pop(h).(miniHeapItem)
		id := NodeID(cur.li)
		if d, ok := dist[id]; !ok || cur.c > d {
			continue // stale entry
		}
		if cur.c > farM {
			farM, farthest = cur.c, id
		}
		if cur.c >= hi {
			continue
		}
		cn := g.Nodes[id]
		for _, e := range g.EdgesFrom(id) {
			tn := g.Nodes[e.To]
			nm := cur.c + float32(haversineM(float64(cn.Lat), float64(cn.Lng), float64(tn.Lat), float64(tn.Lng)))
			if d, seen := dist[e.To]; !seen || nm < d {
				dist[e.To] = nm
				heap.Push(h, miniHeapItem{int32(e.To), nm})
			}
		}
	}

	// Candidates from FINAL distances only, with both floors enforced:
	// road metres in [R/2, 3R/2] AND crow-flies displacement >= R/4 (a
	// hairpin lane can put 200 road metres just 20 crow metres away,
	// which is not a meaningful blur).
	var ring []NodeID
	for id, m := range dist {
		if m < lo || m > hi || id == origin {
			continue
		}
		nd := g.Nodes[id]
		if haversineM(lat, lng, float64(nd.Lat), float64(nd.Lng)) < crowFloor {
			continue
		}
		ring = append(ring, id)
	}
	// Deterministic per input location: stable blur, no jitter. Sort so
	// map iteration order cannot change the pick.
	sort.Slice(ring, func(i, j int) bool { return ring[i] < ring[j] })
	pick := farthest
	pickM := farM
	if len(ring) > 0 {
		seed := uint64(int64(lat*1e6))*6364136223846793005 + uint64(int64(lng*1e6))*1442695040888963407
		pick = ring[seed%uint64(len(ring))]
		pickM = dist[pick]
	}
	if pick == 0 || pick == origin {
		return lat, lng, 0
	}
	nd := g.Nodes[pick]
	return float64(nd.Lat), float64(nd.Lng), float64(pickM)
}

func blurMetresOrDefault(metres float64) float64 {
	// NaN-proof clamp: any value NOT provably in range gets the default
	// (a NaN fails every comparison, so `metres <= 0 || metres > 2000`
	// would have let it straight through).
	if !(metres > 0 && metres <= 2000) {
		return 400
	}
	return metres
}

func handleBlur(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		metres := blurMetresOrDefault(c.QueryFloat("metres"))
		if lat == 0 || lng == 0 || math.IsNaN(lat) || math.IsNaN(lng) || math.IsInf(lat, 0) || math.IsInf(lng, 0) {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		blat, blng, roadm := roadBlurPoint(g, lat, lng, metres)
		return c.JSON(fiber.Map{"lat": blat, "lng": blng, "roadm": roadm})
	}
}

type blurBatchReq struct {
	Metres float64 `json:"metres"`
	Points []struct {
		ID  int64   `json:"id"`
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	} `json:"points"`
}

// handleBlurBatch blurs up to 1000 points in one call — the batch face of
// roadBlurPoint for callers that blur whole result lists (apiv2 member and
// post display locations).
func handleBlurBatch(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		var req blurBatchReq
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "bad body")
		}
		if len(req.Points) == 0 || len(req.Points) > 1000 {
			return fiber.NewError(fiber.StatusBadRequest, "1-1000 points required")
		}
		metres := blurMetresOrDefault(req.Metres)
		type res struct {
			ID    int64   `json:"id"`
			Lat   float64 `json:"lat"`
			Lng   float64 `json:"lng"`
			Roadm float64 `json:"roadm"`
		}
		out := make([]res, len(req.Points))
		for i, p := range req.Points {
			if p.Lat == 0 || p.Lng == 0 || math.IsNaN(p.Lat) || math.IsNaN(p.Lng) {
				out[i] = res{ID: p.ID, Lat: p.Lat, Lng: p.Lng, Roadm: 0}
				continue
			}
			blat, blng, roadm := roadBlurPoint(g, p.Lat, p.Lng, metres)
			out[i] = res{ID: p.ID, Lat: blat, Lng: blng, Roadm: roadm}
		}
		return c.JSON(fiber.Map{"results": out})
	}
}

// engineGroupProximity is groupProximity answered from the reach engine when
// it is live: two label queries replace two bounded full-graph sweeps.
// Returns handled=false to fall through to the sweep when the engine is off.
func engineGroupProximity(lat, lng float64, seeds []NodeID, maxSecs float32) (ProxPoint, ProxPoint, bool, bool) {
	e := reachLive
	if e == nil || len(seeds) == 0 {
		return ProxPoint{}, ProxPoint{}, false, false
	}

	// P: the group point with the smallest road time from the offer.
	lbl := e.QueryLabelsCached(lat, lng, maxSecs)
	pNode := noNode
	var pCost float32
	for _, s := range seeds {
		if c := e.ArrivalAtBaseNode(lbl, s); c <= maxSecs {
			if pNode == noNode || c < pCost {
				pNode, pCost = s, c
			}
		}
	}
	if pNode == noNode {
		return ProxPoint{}, ProxPoint{}, false, true // offer can't reach the group
	}
	closest := ProxPoint{
		Lat: float64(e.G.Nodes[pNode].Lat), Lng: float64(e.G.Nodes[pNode].Lng),
		DriveMin: float64(pCost) / 60,
	}

	// Q: the group point with the largest road time FROM P.
	lblP := e.QueryLabelsCached(closest.Lat, closest.Lng, maxSecs)
	qNode := noNode
	var qCost float32 = -1
	for _, s := range seeds {
		if c := e.ArrivalAtBaseNode(lblP, s); c <= maxSecs && c > qCost {
			qNode, qCost = s, c
		}
	}
	if qNode == noNode {
		return ProxPoint{}, ProxPoint{}, false, true
	}
	furthest := ProxPoint{
		Lat: float64(e.G.Nodes[qNode].Lat), Lng: float64(e.G.Nodes[qNode].Lng),
		DriveMin: float64(qCost) / 60,
	}
	return closest, furthest, true, true
}

// handleLeaf answers which partition region(s) a point belongs to: one leaf
// for a junction, one or two for a mid-lane point (its lane's two ends can
// sit in different regions across a cut). -1 entries are dropped. Used to
// tag members/posts so feeds can prefilter road-aware with an IN clause.
func handleLeaf() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachLive
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		if lat == 0 || lng == 0 || math.IsNaN(lat) || math.IsNaN(lng) {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		v := nearestDriveNode(e.G, lat, lng)
		if v == noNode {
			return c.JSON(fiber.Map{"leaves": []int32{}})
		}
		var leaves []int32
		add := func(j NodeID) {
			if j == 0 {
				return
			}
			if oi := e.Ov.IdxOf(j); oi != 0 {
				if l := e.Part.LeafAt(oi); l >= 0 {
					for _, x := range leaves {
						if x == l {
							return
						}
					}
					leaves = append(leaves, l)
				}
			}
		}
		if e.Ov.IdxOf(v) != 0 {
			add(v)
		} else {
			add(e.Ov.ChainA(v))
			add(e.Ov.ChainEndB[v])
		}
		if leaves == nil {
			leaves = []int32{}
		}
		return c.JSON(fiber.Map{"leaves": leaves})
	}
}

// engineOrFlatIsochrone serves a drive isochrone from the reach engine when
// it is live (label query + table expansion, no full-graph sweep), and the
// classic Dijkstra otherwise. Same reached-nodes contract either way, so
// polygons/bands/bounds downstream are unchanged.
func engineOrFlatIsochrone(g *Graph, lat, lng float64, secs float32) IsochroneResult {
	if e := reachLive; e != nil {
		lbl := e.QueryLabelsCached(lat, lng, secs)
		return IsochroneResult{ReachedNodes: e.ReachedNodes(lbl, secs)}
	}
	return Isochrone(g, lat, lng, secs)
}

// engineOrFlatMultiSource is the group-boundary form: one label query per
// seed, min-merged at the LABEL level (a few KB each), then one expansion -
// instead of one full-graph multi-source sweep.
func engineOrFlatMultiSource(g *Graph, seeds []NodeID, secs float32) IsochroneResult {
	e := reachLive
	if e == nil || len(seeds) == 0 {
		return multiSourceIsochrone(g, seeds, secs)
	}
	var merged *ReachLabels
	type chainSeed struct {
		node NodeID
		base float32
	}
	var chainSeeds []chainSeed
	for _, s := range seeds {
		lbl := e.QueryLabelsFromNode(s, secs)
		// The merge collapses per-seed origin-chain info, so remember each
		// mid-chain seed for the along-chain refinement after expansion.
		if lbl.originChain != 0 {
			chainSeeds = append(chainSeeds, chainSeed{lbl.originChain, lbl.seedBase})
		}
		if merged == nil {
			merged = lbl
		} else {
			MergeLabels(merged, lbl)
		}
	}
	merged.originChain = 0 // per-seed refinement below covers them all
	reached := e.ReachedNodes(merged, secs)
	for _, cs := range chainSeeds {
		e.refineOriginChain(reached, cs.node, cs.base, secs)
	}
	return IsochroneResult{ReachedNodes: reached}
}
