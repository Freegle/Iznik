package main

import (
	"encoding/json"
	"io"
	"log"
	"math"
	"net/http"
	"sort"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
)

// ---------------------------------------------------------------------------
// Simulator evaluation endpoint
// ---------------------------------------------------------------------------
//
// rippleEvalRequest carries a single post location plus a list of replier
// points.  The simulator calls this once per (historical) post.
type rippleEvalRequest struct {
	Lat        float64      `json:"lat"`
	Lng        float64      `json:"lng"`
	Mode       string       `json:"mode"`
	MaxMinutes float64      `json:"max_minutes"`
	Points     [][2]float64 `json:"points"` // [[lng, lat], ...] (GeoJSON order)
}

type rippleEvalPoint struct {
	DriveMin *float64 `json:"drive_min"` // null if unreachable within max_minutes
	Rank     *int     `json:"rank"`      // 1-based: count of reachable freeglers <= this drive_min
}

type rippleEvalResponse struct {
	TotalReachable int               `json:"total_reachable"`
	Results        []rippleEvalPoint `json:"results"`
}

// handleRippleEval returns, for a post at (lat, lng), each input point's
// drive-time-from-the-post and rank among all reachable freeglers.
// Used by the simulator to evaluate "at what tick of an N-tick schedule
// would each historical replier have been notified".
//
// POST /v1/ripple-eval
// Body: rippleEvalRequest
func handleRippleEval(g *Graph, spatialURL string) fiber.Handler {
	return func(c *fiber.Ctx) error {
		var req rippleEvalRequest
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "invalid body")
		}
		if req.Lat == 0 && req.Lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		if req.MaxMinutes <= 0 || req.MaxMinutes > 120 {
			req.MaxMinutes = 30
		}
		var mode Mode
		switch req.Mode {
		case "walk":
			mode = Walk
		case "cycle":
			mode = Cycle
		default:
			mode = Drive
		}

		empty := rippleEvalResponse{Results: make([]rippleEvalPoint, len(req.Points))}

		maxSecs := float32(req.MaxMinutes * 60)
		iso := Isochrone(g, req.Lat, req.Lng, maxSecs, mode)
		if len(iso.ReachedNodes) == 0 {
			return c.JSON(empty)
		}

		// --- collect all reachable freegler drive-times ---
		// (Same approach as handleNearbyFreeglers / handleRippleSchedule.)
		res := AutoResolution(maxSecs, mode)
		fullPoly := IsochronePolygon(g, iso.ReachedNodes, res)
		ring := fullPoly.Geometry.Coordinates
		if len(ring) == 0 || len(ring[0]) < 4 {
			return c.JSON(empty)
		}
		wkt := ringToWKT(ring[0])

		reqURL := spatialURL + "/v1/userapproxlocs/within_coords"
		resp, err := http.Post(reqURL, "text/plain", strings.NewReader(wkt)) //nolint:gosec
		if err != nil || resp.StatusCode != 200 {
			log.Printf("ripple-eval: within_coords failed (status=%v err=%v)", func() int {
				if resp != nil {
					return resp.StatusCode
				}
				return 0
			}(), err)
			return c.JSON(empty)
		}
		defer resp.Body.Close()
		body, err := io.ReadAll(resp.Body)
		if err != nil {
			return c.JSON(empty)
		}
		var within struct {
			Results []struct {
				Extra map[string]any `json:"extra"`
			} `json:"results"`
		}
		if err := json.Unmarshal(body, &within); err != nil {
			return c.JSON(empty)
		}

		// All freegler drive-times in seconds, sorted ascending.
		freeglerSecs := make([]float32, 0, len(within.Results))
		for _, r := range within.Results {
			if r.Extra == nil {
				continue
			}
			lat, ok1 := r.Extra["lat"].(float64)
			lng, ok2 := r.Extra["lng"].(float64)
			if !ok1 || !ok2 {
				continue
			}
			nid := nearestNodeForMode(g, lat, lng, mode)
			if nid == noNode {
				continue
			}
			t, ok := iso.ReachedNodes[nid]
			if !ok {
				continue
			}
			freeglerSecs = append(freeglerSecs, t)
		}
		sort.Slice(freeglerSecs, func(i, j int) bool { return freeglerSecs[i] < freeglerSecs[j] })
		total := len(freeglerSecs)

		// --- per-input-point drive_min + rank ---
		results := make([]rippleEvalPoint, len(req.Points))
		for i, p := range req.Points {
			lng, lat := p[0], p[1]
			nid := nearestNodeForMode(g, lat, lng, mode)
			if nid == noNode {
				continue
			}
			t, ok := iso.ReachedNodes[nid]
			if !ok {
				continue
			}
			dMin := float64(t) / 60.0
			// rank = count of freeglers with drive_min <= this point's drive_min
			rank := sort.Search(total, func(j int) bool { return freeglerSecs[j] > t })
			results[i] = rippleEvalPoint{DriveMin: &dMin, Rank: &rank}
		}

		return c.JSON(rippleEvalResponse{
			TotalReachable: total,
			Results:        results,
		})
	}
}

// curveFraction maps "elapsed fraction" (k/ticks ∈ [0,1]) to "notified fraction"
// (∈ [0,1]) under a named curve shape.
//
// Simulator evaluation against 4,264 historical posts (see
// plans/reference/ripple-curve-evaluation.md) ranks the front-loaded shapes:
//   step-70%+linear ≈ front-x^0.15 > front-x^0.2 > front-heavy x^0.3 > …
//
// "step-70%+linear" is the recommended production default: 70 % of users
// notified immediately at tick 1, the remaining 30 % linearly across the
// rest of the lifetime.  Hits 92 % first-replier in-time in urban areas
// with only 8 % wasted notifications.  Mimics legacy "notify-everyone-
// within-2-miles" bombardment for the bulk of users while still rippling
// the long tail outward over the lifetime.
//
// The numStepsForStep parameter is needed for the step curve since the
// "what does tick 1 cover" depends on the tick granularity.  Caller
// passes the total tick count.
func curveFraction(shape string, x float64, numTicks int) float64 {
	switch shape {
	case "step-70":
		return stepCurve(0.70, numTicks, x)
	case "step-50":
		return stepCurve(0.50, numTicks, x)
	case "front-x015", "x^0.15":
		// Continuous near-equivalent of step-70.  Tick 1 covers 60 %.
		return math.Pow(x, 0.15)
	case "front-x02", "x^0.2":
		return math.Pow(x, 0.2)
	case "front-heavy":
		// x^0.3 — kept for comparison; previous default.
		return math.Pow(x, 0.3)
	case "front-sqrt":
		return math.Sqrt(x)
	case "front-cubic":
		return 1.0 - math.Pow(1.0-x, 3)
	case "front", "front-quad":
		return 1.0 - math.Pow(1.0-x, 2)
	case "back":
		return x * x
	case "linear":
		return x
	default:
		// Unknown shape -> recommended default.
		return stepCurve(0.70, numTicks, x)
	}
}

// stepCurve: fraction s of users notified at tick 1, remaining (1-s) linear
// across ticks 2..N.  Within the first tick (x ≤ 1/N) we ramp smoothly to s
// so the curve is monotone — production cron snaps to tick boundaries so
// the ramp is invisible there, but the simulator can evaluate fractional x.
func stepCurve(s float64, numTicks int, x float64) float64 {
	if x <= 0 {
		return 0
	}
	if numTicks < 1 {
		return s
	}
	oneTick := 1.0 / float64(numTicks)
	if x <= oneTick {
		return s * (x / oneTick)
	}
	return s + (1-s)*(x-oneTick)/(1-oneTick)
}

// rippleScheduleEntry is a single tick of the density-driven ripple schedule.
type rippleScheduleEntry struct {
	Tick            int            `json:"tick"`
	DriveMin        float64        `json:"drive_min"`
	CumulativeUsers int            `json:"cumulative_users"`
	Polygon         GeoJSONPolygon `json:"polygon"`
}

type rippleScheduleResponse struct {
	TotalFreeglers int                   `json:"total_freeglers"`
	MaxDriveMin    float64               `json:"max_drive_min"`
	Schedule       []rippleScheduleEntry `json:"schedule"`
}

// handleRippleSchedule produces the density-driven ripple schedule for a given
// origin. The schedule contains `ticks` entries; tick k's drive-time is chosen
// so that approximately (k/ticks) of all freeglers within the max isochrone
// are encapsulated. The drive-time delta between consecutive ticks is small in
// dense regions and large (one big jump) across empty voids.
//
// GET /v1/ripple-schedule?lat=...&lng=...&mode=drive&ticks=30&max_minutes=30
func handleRippleSchedule(g *Graph, spatialURL string) fiber.Handler {
	return func(c *fiber.Ctx) error {
		latS := c.Query("lat")
		lngS := c.Query("lng")
		if latS == "" || lngS == "" {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		latF, err1 := strconv.ParseFloat(latS, 64)
		lngF, err2 := strconv.ParseFloat(lngS, 64)
		if err1 != nil || err2 != nil {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng must be numeric")
		}

		ticks, _ := strconv.Atoi(c.Query("ticks", "30"))
		if ticks < 1 || ticks > 200 {
			ticks = 30
		}

		maxMinutes, _ := strconv.ParseFloat(c.Query("max_minutes", "30"), 64)
		if maxMinutes <= 0 || maxMinutes > 120 {
			maxMinutes = 30
		}

		// Curve shape determines how cumulative-user targets are spaced
		// across the tick range.  See curveFraction() for the supported
		// shapes; "step-70" is the data-driven default.
		curve := c.Query("curve", "step-70")
		validCurves := map[string]bool{
			"linear": true, "front-heavy": true, "front-sqrt": true,
			"front-cubic": true, "front": true, "front-quad": true, "back": true,
			"step-70": true, "step-50": true, "x^0.15": true, "x^0.2": true,
			"front-x015": true, "front-x02": true,
		}
		if !validCurves[curve] {
			curve = "step-70"
		}

		modeStr := c.Query("mode", "drive")
		var mode Mode
		switch modeStr {
		case "walk":
			mode = Walk
		case "cycle":
			mode = Cycle
		default:
			mode = Drive
		}

		empty := rippleScheduleResponse{Schedule: []rippleScheduleEntry{}}

		// --- Step 1: one Dijkstra to the maximum drive-time ---
		maxSecs := float32(maxMinutes * 60)
		iso := Isochrone(g, latF, lngF, maxSecs, mode)
		if len(iso.ReachedNodes) == 0 {
			return c.JSON(empty)
		}

		// --- Step 2: get the freeglers within the max polygon via spatial ---
		res := AutoResolution(maxSecs, mode)
		fullPoly := IsochronePolygon(g, iso.ReachedNodes, res)
		ring := fullPoly.Geometry.Coordinates
		if len(ring) == 0 || len(ring[0]) < 4 {
			return c.JSON(empty)
		}
		wkt := ringToWKT(ring[0])

		reqURL := spatialURL + "/v1/userapproxlocs/within_coords"
		resp, err := http.Post(reqURL, "text/plain", strings.NewReader(wkt)) //nolint:gosec
		if err != nil || resp.StatusCode != 200 {
			log.Printf("ripple-schedule: within_coords failed (status=%v err=%v)", func() int {
				if resp != nil {
					return resp.StatusCode
				}
				return 0
			}(), err)
			return c.JSON(empty)
		}
		defer resp.Body.Close()
		body, err := io.ReadAll(resp.Body)
		if err != nil {
			return c.JSON(empty)
		}
		var within struct {
			Results []struct {
				Extra map[string]any `json:"extra"`
			} `json:"results"`
		}
		if err := json.Unmarshal(body, &within); err != nil {
			return c.JSON(empty)
		}

		// --- Step 3: for each freegler, look up drive-time-to-them ---
		// (using nearest reached node — freeglers beyond the max are skipped)
		type freeglerWithTime struct {
			seconds float32
		}
		fwt := make([]freeglerWithTime, 0, len(within.Results))
		for _, r := range within.Results {
			if r.Extra == nil {
				continue
			}
			lat, ok1 := r.Extra["lat"].(float64)
			lng, ok2 := r.Extra["lng"].(float64)
			if !ok1 || !ok2 {
				continue
			}
			nid := nearestNodeForMode(g, lat, lng, mode)
			if nid == noNode {
				continue
			}
			t, ok := iso.ReachedNodes[nid]
			if !ok {
				continue
			}
			fwt = append(fwt, freeglerWithTime{t})
		}

		if len(fwt) == 0 {
			return c.JSON(empty)
		}

		sort.Slice(fwt, func(i, j int) bool { return fwt[i].seconds < fwt[j].seconds })
		total := len(fwt)

		// --- Step 4: build the schedule ---
		// For tick k in 1..ticks, target cumulative = ceil(k * total / ticks).
		// Drive-time for tick k = fwt[target-1].seconds.
		schedule := make([]rippleScheduleEntry, 0, ticks)
		for k := 1; k <= ticks; k++ {
			// Curve maps "elapsed fraction" (k/ticks) → "notified fraction"
			// (∈ [0,1]); multiply by total to get the target cumulative count.
			x := float64(k) / float64(ticks)
			frac := curveFraction(curve, x, ticks)
			target := int(math.Round(frac * float64(total)))
			if target < 1 {
				target = 1
			}
			if target > total {
				target = total
			}
			driveSecs := fwt[target-1].seconds

			// Polygon at this drive-time: filter reached nodes by time.
			filtered := make(map[NodeID]float32, target*4)
			for nid, t := range iso.ReachedNodes {
				if t <= driveSecs {
					filtered[nid] = t
				}
			}
			tickPoly := IsochronePolygon(g, filtered, res)

			schedule = append(schedule, rippleScheduleEntry{
				Tick:            k,
				DriveMin:        float64(driveSecs) / 60.0,
				CumulativeUsers: target,
				Polygon:         tickPoly,
			})
		}

		return c.JSON(rippleScheduleResponse{
			TotalFreeglers: total,
			MaxDriveMin:    maxMinutes,
			Schedule:       schedule,
		})
	}
}
