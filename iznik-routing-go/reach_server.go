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
	"context"
	"database/sql"
	"encoding/base64"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"sync/atomic"
	"time"

	"github.com/gofiber/fiber/v2"
)

// reachLivePtr holds the engine serving the reach endpoints; nil = not configured.
//
// Atomic because it is no longer written only at boot: when the artifacts are
// not loadable the server keeps retrying in the background (reachRetryLoop),
// so a publish can land while handlers are reading. Before that, a failed boot
// meant every /v1/reach-* answered 503 for the LIFETIME OF THE PROCESS and the
// only cure was a restart - which is how the 2026-09-02 deploy lost reach for
// ~16 hours. "Unavailable" must never be a permanent answer.
var reachLivePtr atomic.Pointer[ReachEngine]

func reachEngine() *ReachEngine { return reachLivePtr.Load() }

func setReachLive(e *ReachEngine) { reachLivePtr.Store(e) }

// liveReachPartFP is the live engine's partition fingerprint, or 0 when no
// engine is loaded. 0 is never a real fingerprint, so a SQL comparison
// against a staged label's stamp simply fails to match.
func liveReachPartFP() uint64 {
	if e := reachEngine(); e != nil {
		return e.partFP
	}
	return 0
}

// reachPrevPtr holds the PREVIOUS partition build (REACH_DIR_PREV), held so a map
// refresh stops being a cliff: stored labels embed their build's fingerprint,
// and every evaluator routes each blob to the build that can read it. The
// re-backfill after a rebuild then becomes a rolling migration - old labels
// keep answering until each post's new one lands - instead of a site-wide
// "nolabels" window. nil = single-build operation, exactly as before.
var reachPrevPtr atomic.Pointer[ReachEngine]

func reachPrevEngine() *ReachEngine { return reachPrevPtr.Load() }

func setReachPrev(e *ReachEngine) { reachPrevPtr.Store(e) }

// decodeLabelsAnyBuild decodes a stored blob against whichever loaded build
// matches its embedded fingerprint, returning the engine that must evaluate
// it (arrivals are only meaningful on the build that decoded the blob).
func decodeLabelsAnyBuild(b []byte) (*ReachLabels, *ReachEngine, error) {
	// Load each pointer ONCE: a background republish must not be able to hand
	// back a label decoded by one engine and an engine that did not decode it.
	live := reachEngine()
	if live == nil {
		return nil, nil, fmt.Errorf("reach engine not configured")
	}
	lbl, err := live.DecodeLabels(b)
	if err == nil {
		return lbl, live, nil
	}
	if prev := reachPrevEngine(); prev != nil {
		if lbl, perr := prev.DecodeLabels(b); perr == nil {
			return lbl, prev, nil
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

// rebuildReachSnapshot regenerates graph.snap in dir from the PBF, for the
// self-heal in reachBootFromEnv. It writes ONLY the graph snapshot; the
// partition, matrices and leaf-table artifacts already self-heal at load time
// once their fingerprints stop matching, so there is nothing else to do here.
func rebuildReachSnapshot(dir string) error {
	pbf := getenv("OSM_PBF_PATH", "data/uk-latest.osm.pbf")
	if _, err := os.Stat(pbf); err != nil {
		return fmt.Errorf("no PBF at %s: %w", pbf, err)
	}
	var dep *DeprivationIndex
	if path := getenv("DEPRIVATION_CSV", ""); path != "" {
		dep = LoadDeprivation(path)
	}
	start := time.Now()
	g, err := BuildGraph(pbf, dep)
	if err != nil {
		return fmt.Errorf("BuildGraph: %w", err)
	}
	ov := BuildOverlay(g)
	// Match reachLoadOrBuild: the three-mode edges only shaped the contraction
	// and are not stored, so release them before the write.
	g.releaseModalEdges()
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("mkdir %s: %w", dir, err)
	}
	if err := SaveReachSnapshot(filepath.Join(dir, "graph.snap"), g, ov); err != nil {
		return fmt.Errorf("save: %w", err)
	}
	log.Printf("reach: rebuilt graph.snap in %v", time.Since(start).Round(time.Second))
	return nil
}

// reachPartFPConfigKey names the config row holding the partition fingerprint
// that the STORED reach_labels blobs were computed against. The blobs and the
// artifacts are one versioned pair: a partition rebuild renumbers the regions
// every stored blob refers to, so loading new artifacts against old labels
// silently empties every member's feed. It lives in `config` rather than on
// rippling_reach because that table is 7.39 GB and DDL on it took a node down
// on 2026-09-02.
const reachPartFPConfigKey = "reach_partition_fp"

// reachExpectedPartFP reads the fingerprint the stored labels were built
// against. Opens its own connection: initGroupsDB runs inside startServer,
// which is AFTER this. Returns ok=false when the row is absent or the database
// cannot be reached - the guard then stays out of the way, so a deployment
// that has never recorded a fingerprint behaves exactly as it does today.
func reachExpectedPartFP() (uint64, bool) {
	dsn := groupsDSN()
	if dsn == "" {
		return 0, false
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Printf("reach: cannot open db to read %s: %v", reachPartFPConfigKey, err)
		return 0, false
	}
	defer db.Close()
	// Bounded: this runs before the listener starts, so an unreachable database
	// must not be able to hold the whole server down. Timing out means "no
	// fingerprint recorded", which leaves the guard out of the way.
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var raw string
	if err := db.QueryRowContext(ctx, "SELECT `value` FROM config WHERE `key` = ?", reachPartFPConfigKey).Scan(&raw); err != nil {
		if err != sql.ErrNoRows {
			log.Printf("reach: cannot read %s: %v", reachPartFPConfigKey, err)
		}
		return 0, false
	}
	fp, perr := strconv.ParseUint(raw, 10, 64)
	if perr != nil {
		log.Printf("reach: %s = %q is not a fingerprint: %v", reachPartFPConfigKey, raw, perr)
		return 0, false
	}
	return fp, true
}

// reachPublish makes eng the live engine, unless its partition disagrees with
// the fingerprint the stored labels were built against.
//
// This is the guard that was missing on 2026-09-02. Rebuilding the artifacts
// produced a new partition (92 MB -> 66 MB, new fingerprint) and every one of
// the 53,680 stored labels became meaningless the moment it loaded - not with
// an error, but by quietly answering "not in reach" for everybody. Refusing to
// publish turns that into a 503, which is loud, which the deploy gate already
// fails on, and which the background retry can still heal from once the
// matching labels are applied.
func reachPublish(eng *ReachEngine, why string) bool {
	if want, ok := reachExpectedPartFP(); ok && want != eng.partFP {
		log.Printf("reach: REFUSING to serve %s: artifacts are partition %d but the stored labels "+
			"were built against %d. Serving this engine would answer \"not in reach\" for every "+
			"member. Apply labels for partition %d and set config[%s], or restore the matching "+
			"artifacts.", why, eng.partFP, want, eng.partFP, reachPartFPConfigKey)
		return false
	}
	setReachLive(eng)
	return true
}

// reachRetryLoop keeps trying to load the engine after a failed boot.
//
// Without it, "reach unavailable" is cached for the lifetime of the process:
// reachLivePtr was written once at boot, so a bad boot meant every /v1/reach-*
// answered 503 until someone restarted the service - which is exactly how the
// 2026-09-02 deploy lost reach for ~16 hours. With it, artifacts that land
// late (an rsync still in flight, a fingerprint recorded after the fact) heal
// on their own.
//
// It deliberately does NOT rebuild anything. The boot path may rebuild once,
// under the reachPublish guard; a background rebuild would renumber the
// partition unattended, which is the failure this whole mechanism exists to
// prevent.
func reachRetryLoop(dir string) {
	const (
		first = 30 * time.Second
		max   = 10 * time.Minute
	)
	delay := first
	for {
		time.Sleep(delay)
		if reachEngine() != nil {
			return
		}
		eng, err := loadReachEngineFromDir(dir)
		if err != nil {
			log.Printf("reach: retry: still cannot load from %s (%v); next attempt in %v", dir, err, delay)
		} else if reachPublish(eng, "on retry") {
			log.Printf("reach: retry SUCCEEDED - engine now live (%d regions, partition %d)",
				len(eng.Part.LeafNodes), eng.partFP)
			return
		}
		if delay < max {
			if delay *= 2; delay > max {
				delay = max
			}
		}
	}
}

// snapshotStaleAgainstPBF reports whether the graph snapshot at snapPath predates
// the OSM extract it was built from. Nothing keys these artifacts to the extract,
// so a refreshed map with an old snapshot serves the OLD road network and says
// nothing about it - which is how a missing region survives its own fix. Returns
// an empty string when the snapshot is current, or when either file is missing.
func snapshotStaleAgainstPBF(snapPath string) string {
	snap, err := os.Stat(snapPath)
	if err != nil {
		return ""
	}
	pbfPath := getenv("OSM_PBF_PATH", "data/uk-latest.osm.pbf")
	pbf, err := os.Stat(pbfPath)
	if err != nil {
		return ""
	}
	if !pbf.ModTime().After(snap.ModTime()) {
		return ""
	}
	return fmt.Sprintf("%s was built %s, before %s changed at %s",
		snapPath, snap.ModTime().Format(time.RFC3339), pbfPath, pbf.ModTime().Format(time.RFC3339))
}

// reachBootFromEnv loads the engine when REACH_DIR is set. Returns the
// engine's graph so main can skip the PBF build, or nil to fall back.
func reachBootFromEnv() *Graph {
	dir := getenv("REACH_DIR", "")
	if dir == "" {
		return nil
	}
	if stale := snapshotStaleAgainstPBF(filepath.Join(dir, "graph.snap")); stale != "" {
		// Loud on purpose: the artifacts win over the extract at boot, so a map
		// refresh that stops here changes nothing and reports nothing.
		log.Printf("reach: WARNING: %s - serving the OLD road network until the artifacts are rebuilt", stale)
	}
	start := time.Now()
	eng, err := loadReachEngineFromDir(dir)
	if err != nil {
		// A stale or unreadable graph.snap used to mean "serve without a reach
		// engine": the PBF fallback below produces a working graph, so /health
		// and every non-reach route answer 200 while /v1/reach-* quietly 503.
		// That is exactly what happened on 2026-09-02, when a binary carrying
		// graphSnapVersion 2 met version-1 artifacts on all three db nodes and
		// the deploy reported clean. Rebuild and save instead, then retry: the
		// cost is one slow boot (~7 min, inside monit's 15-cycle grace) rather
		// than silently losing reach until someone notices.
		//
		// The rebuild is safe to attempt ONLY because reachPublish below now
		// refuses to serve a partition the stored labels do not match: on
		// 2026-09-02 this same rebuild renumbered the regions and emptied every
		// member's feed.
		log.Printf("reach: boot from %s failed (%v); rebuilding artifacts", dir, err)
		if rebuildErr := rebuildReachSnapshot(dir); rebuildErr != nil {
			log.Printf("reach: rebuild failed (%v); falling back to PBF build", rebuildErr)
			go reachRetryLoop(dir)
			return nil
		}
		if eng, err = loadReachEngineFromDir(dir); err != nil {
			log.Printf("reach: boot still failing after rebuild (%v); falling back to PBF build", err)
			go reachRetryLoop(dir)
			return nil
		}
	}
	if !reachPublish(eng, "at boot") {
		// The graph itself is fine - only the region numbering disagrees - so
		// serve every non-reach route from it rather than spending ~7 minutes
		// rebuilding from the PBF. Reach stays 503 until the pairing is fixed,
		// and the deploy gate fails the node on exactly that.
		go reachRetryLoop(dir)
		return eng.G
	}
	log.Printf("reach: engine ready in %v (%d regions, %d boundary nodes, partition %d)",
		time.Since(start).Round(time.Millisecond), len(eng.Part.LeafNodes), len(eng.BI.leafOf), eng.partFP)
	if prevDir := getenv("REACH_DIR_PREV", ""); prevDir != "" {
		pstart := time.Now()
		if prev, err := loadReachEngineFromDir(prevDir); err != nil {
			log.Printf("reach: WARNING: previous build from %s failed (%v); single-build operation", prevDir, err)
		} else if prev.partFP == eng.partFP {
			log.Printf("reach: previous build %s is the same partition; ignoring", prevDir)
		} else {
			setReachPrev(prev)
			log.Printf("reach: previous build ready in %v (%d regions) - rolling label migration active",
				time.Since(pstart).Round(time.Millisecond), len(prev.Part.LeafNodes))
		}
	}
	return eng.G
}

func handleReachLabels() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := reachEngine()
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		minutes := c.QueryFloat("minutes")
		if !validLatLng(lat, lng) || minutes <= 0 || minutes > 240 {
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
		e := reachEngine()
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
		e := reachEngine()
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
		e := reachEngine()
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
	e := reachEngine()
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
		if !validLatLng(lat, lng) {
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
			if !validLatLng(p.Lat, p.Lng) {
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
	e := reachEngine()
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
		e := reachEngine()
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		if !validLatLng(lat, lng) {
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
	if e := reachEngine(); e != nil {
		lbl := e.QueryLabelsCached(lat, lng, secs)
		// Same snap the engine did, read back so callers can tell an unmapped
		// origin from a small reach (see IsochroneResult.OriginFound).
		return IsochroneResult{
			ReachedNodes: e.ReachedNodes(lbl, secs),
			OriginFound:  nearestDriveNode(g, lat, lng) != noNode,
		}
	}
	return Isochrone(g, lat, lng, secs)
}

// engineOrFlatMultiSource is the group-boundary form: one label query per
// seed, min-merged at the LABEL level (a few KB each), then one expansion -
// instead of one full-graph multi-source sweep.
func engineOrFlatMultiSource(g *Graph, seeds []NodeID, secs float32) IsochroneResult {
	e := reachEngine()
	if e == nil || len(seeds) == 0 {
		return multiSourceIsochrone(g, seeds, secs)
	}
	var merged *ReachLabels
	type chainSeed struct {
		node NodeID
		base float32
	}
	var chainSeeds []chainSeed
	seeded := false
	for _, s := range seeds {
		if s != noNode {
			seeded = true
		}
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
	return IsochroneResult{ReachedNodes: reached, OriginFound: seeded}
}
