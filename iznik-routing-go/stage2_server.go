package main

// Stage 2 server integration: artifact boot path and the two reach endpoints.
//
// Boot: set STAGE2_DIR to a directory holding graph.snap + partition.snap +
// matrices.snap and the server loads the engine (and its base graph) from
// artifacts in seconds instead of rebuilding from the OSM extract. If the
// partition or matrices files are absent they are DERIVED at boot (~3 minutes
// for the whole UK) and saved back — so storing them is a convenience, not a
// requirement. If STAGE2_DIR is unset nothing changes: no engine, endpoints
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

// stage2Live is the engine serving the reach endpoints; nil = not configured.
// Set once at boot before the server starts (or by tests).
var stage2Live *Stage2Engine

// loadStage2EngineFromDir loads (or derives) the full artifact set.
func loadStage2EngineFromDir(dir string) (*Stage2Engine, error) {
	g, ov, err := LoadStage2Snapshot(filepath.Join(dir, "graph.snap"))
	if err != nil {
		return nil, fmt.Errorf("graph snapshot: %w", err)
	}
	part, err := loadPartition(filepath.Join(dir, "partition.snap"))
	if err != nil {
		log.Printf("stage2: partition artifact missing (%v): deriving at boot", err)
		part = PartitionOverlay(g, ov, 10000, 0.25)
		if err := savePartition(filepath.Join(dir, "partition.snap"), part); err != nil {
			log.Printf("stage2: WARNING: could not save derived partition: %v", err)
		}
	}
	rm, err := loadMatrices(filepath.Join(dir, "matrices.snap"))
	if err != nil {
		log.Printf("stage2: matrices artifact missing (%v): deriving at boot", err)
		rm = BuildRegionMatrices(ov, part)
		if err := saveMatrices(filepath.Join(dir, "matrices.snap"), rm); err != nil {
			log.Printf("stage2: WARNING: could not save derived matrices: %v", err)
		}
	}
	return NewStage2Engine(g, ov, part, rm), nil
}

// stage2BootFromEnv loads the engine when STAGE2_DIR is set. Returns the
// engine's graph so main can skip the PBF build, or nil to fall back.
func stage2BootFromEnv() *Graph {
	dir := getenv("STAGE2_DIR", "")
	if dir == "" {
		return nil
	}
	start := time.Now()
	eng, err := loadStage2EngineFromDir(dir)
	if err != nil {
		log.Printf("stage2: boot from %s failed (%v); falling back to PBF build", dir, err)
		return nil
	}
	stage2Live = eng
	log.Printf("stage2: engine ready in %v (%d regions, %d boundary nodes)",
		time.Since(start).Round(time.Millisecond), len(eng.Part.LeafNodes), len(eng.BI.leafOf))
	return eng.G
}

func handleReachLabels() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := stage2Live
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "stage2 engine not configured (STAGE2_DIR)")
		}
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		minutes := c.QueryFloat("minutes")
		if lat == 0 || lng == 0 || minutes <= 0 || minutes > 240 {
			return fiber.NewError(fiber.StatusBadRequest, "lat, lng and minutes (0-240) required")
		}
		start := time.Now()
		lbl := e.QueryLabels(lat, lng, float32(minutes*60))
		blob := EncodeLabels(lbl)
		full, partial := 0, 0
		for _, rl := range lbl.Reached {
			if rl.Full {
				full++
			} else {
				partial++
			}
		}
		return c.JSON(fiber.Map{
			"labels":  base64.StdEncoding.EncodeToString(blob),
			"t":       lbl.T,
			"regions": len(lbl.Reached),
			"full":    full,
			"partial": partial,
			"bytes":   len(blob),
			"ms":      float64(time.Since(start).Microseconds()) / 1000,
		})
	}
}

type reachArrivalReq struct {
	Labels string `json:"labels"`
	Points []struct {
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	} `json:"points"`
}

func handleReachArrival() fiber.Handler {
	return func(c *fiber.Ctx) error {
		e := stage2Live
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "stage2 engine not configured (STAGE2_DIR)")
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
		lbl, err := e.DecodeLabels(blob)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("labels: %v", err))
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
				out[i] = res{&a, arr <= lbl.T}
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
		e := stage2Live
		if e == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (STAGE2_DIR)")
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
			v := nearestNodeForMode(e.G, tg.Lat, tg.Lng, Drive)
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
// is live and the mode is drive: exact, and milliseconds instead of a bounded
// sweep. Returns handled=false to fall through to the sweep.
func engineDriveTime(lat, lng, toLat, toLng, minutes float64, mode Mode) (fiber.Map, bool) {
	e := stage2Live
	if e == nil || mode != Drive {
		return nil, false
	}
	dest := nearestNodeForMode(e.G, toLat, toLng, Drive)
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
func handleBlur(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat := c.QueryFloat("lat")
		lng := c.QueryFloat("lng")
		metres := c.QueryFloat("metres")
		if lat == 0 || lng == 0 || math.IsNaN(lat) || math.IsNaN(lng) || math.IsInf(lat, 0) || math.IsInf(lng, 0) {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		// NaN-proof clamp: any value NOT provably in range gets the default
		// (a NaN fails every comparison, so `metres <= 0 || metres > 2000`
		// would have let it straight through).
		if !(metres > 0 && metres <= 2000) {
			metres = 400
		}
		origin := nearestNodeForMode(g, lat, lng, Drive)
		if origin == noNode {
			// No road nearby: nothing safer to offer than the input.
			return c.JSON(fiber.Map{"lat": lat, "lng": lng, "roadm": 0})
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
				if e.Seconds[Drive] < 0 {
					continue
				}
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
			return c.JSON(fiber.Map{"lat": lat, "lng": lng, "roadm": 0})
		}
		nd := g.Nodes[pick]
		return c.JSON(fiber.Map{
			"lat":   nd.Lat,
			"lng":   nd.Lng,
			"roadm": pickM,
		})
	}
}
