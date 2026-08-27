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
	"encoding/base64"
	"fmt"
	"log"
	"path/filepath"
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
