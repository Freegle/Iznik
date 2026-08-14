package main

import (
	"encoding/json"
	"fmt"
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
	// PointsOnly skips the freegler enumeration (the within_coords KNN call + per-freegler
	// nearestNodeForMode loop - the routing bottleneck). Callers that only need per-point
	// drive-times (and don't read `rank`) set this to avoid seconds of unused work. rank comes
	// back null.
	PointsOnly bool `json:"points_only,omitempty"`
	// Frontier also returns the isochrone's road-distance reach range (frontier_{min,max}_miles).
	Frontier bool `json:"frontier,omitempty"`
	// PolygonSimplifyM, when positive, also returns the reach's SHAPE as a display polygon,
	// simplified to that many metres (see displaypolygon.go). It exists because the browse
	// map's coverage overlay wants the shape of the very same reach /town/near is already
	// asking about: the Dijkstra below dominates the cost and has to run either way, so
	// taking the polygon from it costs only the trace, and never a second routing pass.
	// Omit it (or pass <= 0) and the response is byte-for-byte what it was.
	PolygonSimplifyM float64 `json:"polygon_simplify_m,omitempty"`
}

type rippleEvalPoint struct {
	DriveMin *float64 `json:"drive_min"` // null if unreachable within max_minutes
	Rank     *int     `json:"rank"`      // 1-based: count of reachable freeglers <= this drive_min
}

type rippleEvalResponse struct {
	TotalReachable int               `json:"total_reachable"`
	Results        []rippleEvalPoint `json:"results"`
	// FrontierMedianMiles/FrontierMaxMiles: how far the isochrone reaches BY ROAD across directions -
	// the median and max of the per-sector furthest road-distance. Present only when Frontier was
	// requested.
	FrontierMedianMiles *float64 `json:"frontier_median_miles,omitempty"`
	FrontierMaxMiles    *float64 `json:"frontier_max_miles,omitempty"`
	// Polygon is the reach's outline for DISPLAY, present only when polygon_simplify_m was
	// positive and the reach traced something drawable. It is an approximation of the
	// boundary by construction, so it must never be used for containment - the exact
	// polygon from /v1/catchment is what reach membership is decided on.
	Polygon *GeoJSONPolygon `json:"polygon,omitempty"`
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

		// --- collect all reachable freegler drive-times (for the `rank` field only) ---
		// Skipped when points_only: this is the routing bottleneck (a within_coords KNN call +
		// a no-dedup nearestNodeForMode per freegler - seconds in a dense area, dwarfing the
		// isochrone), and callers that only want per-point drive-times never read rank.
		var freeglerSecs []float32
		if !req.PointsOnly {
			// Candidates come from the reach's bbox (not the detailed boundary, which is
			// unsimplified and too large for a WKT query); the nearest-node-in-reached-set
			// test below filters each candidate exactly, so the result is unchanged.
			evMinLat, evMaxLat, evMinLng, evMaxLng := reachedBBox(g, iso.ReachedNodes)
			wkt := fmt.Sprintf("POLYGON((%[1]f %[3]f, %[2]f %[3]f, %[2]f %[4]f, %[1]f %[4]f, %[1]f %[3]f))",
				evMinLng, evMaxLng, evMinLat, evMaxLat)

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
		}
		total := len(freeglerSecs)

		// --- per-input-point drive_min (+ rank, unless points_only) ---
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
			pt := rippleEvalPoint{DriveMin: &dMin}
			if !req.PointsOnly {
				rank := sort.Search(total, func(j int) bool { return freeglerSecs[j] > t })
				pt.Rank = &rank
			}
			results[i] = pt
		}

		out := rippleEvalResponse{TotalReachable: total, Results: results}
		if req.Frontier {
			fmed, fmax := frontierMilesMedianMax(g, req.Lat, req.Lng, iso.DistM)
			out.FrontierMedianMiles = &fmed
			out.FrontierMaxMiles = &fmax
		}
		if req.PolygonSimplifyM > 0 {
			out.Polygon = displayPolygonFor(g, iso.ReachedNodes, mode, req.PolygonSimplifyM)
		}
		return c.JSON(out)
	}
}

// frontierMilesMedianMax summarises how far the isochrone reaches BY ROAD across directions: it
// buckets every reached node into one of 16 compass sectors around the origin, takes the furthest
// road distance in each sector (that direction's frontier), then returns the MEDIAN and MAX of those
// per-sector frontiers. Median rather than min because the min is dominated by whichever single
// direction a coast/edge cuts short, making a min..max range far too wide to be useful; the median
// is the typical reach, so median..max reads as "usually reaches this far, up to this far down a
// fast road". Uses iso.DistM (road metres per node, accumulated in the isochrone's own Dijkstra
// pass), so there is no extra routing cost. metresPerMile is defined in proximity.go.
func frontierMilesMedianMax(g *Graph, lat0, lng0 float64, distM map[NodeID]float32) (fmedian, fmax float64) {
	const sectors = 16
	var sectorMax [sectors]float32
	cosLat := math.Cos(lat0 * math.Pi / 180)
	for nid, dM := range distM {
		n := g.Nodes[nid]
		// Bearing from origin; atan2(east, north) so 0 = due north, increasing clockwise.
		ang := math.Atan2((float64(n.Lng)-lng0)*cosLat, float64(n.Lat)-lat0)
		if ang < 0 {
			ang += 2 * math.Pi
		}
		s := int(ang/(2*math.Pi/sectors)) % sectors
		if dM > sectorMax[s] {
			sectorMax[s] = dM
		}
	}
	// Non-empty sector frontiers, in metres, ascending.
	vals := make([]float64, 0, sectors)
	for _, m := range sectorMax {
		if m > 0 { // skip empty sectors - the reach doesn't extend that way at all
			vals = append(vals, float64(m))
		}
	}
	if len(vals) == 0 {
		return 0, 0
	}
	sort.Float64s(vals)
	n := len(vals)
	var medM float64
	if n%2 == 1 {
		medM = vals[n/2]
	} else {
		medM = (vals[n/2-1] + vals[n/2]) / 2
	}
	return medM / metresPerMile, vals[n-1] / metresPerMile
}

// curveFraction maps "elapsed fraction" (k/ticks ∈ [0,1]) to "notified fraction"
// (∈ [0,1]) under a named curve shape.
//
// Simulator evaluation against 4,264 historical posts (see
// plans/reference/ripple-curve-evaluation.md) ranks the front-loaded shapes:
//
//	step-70%+linear ≈ front-x^0.15 > front-x^0.2 > front-heavy x^0.3 > …
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
// Polygon is a pointer so the slim form (polygons=0) omits the key entirely: the
// batch needs only drive_min/cumulative_users/reachable_group_ids per tick, and a
// ~20k-vertex polygon per tick is what made schedule calls slow (~7s of a 10.5s
// London call) and stored schedules huge. The explorer keeps the default (with
// polygons) for the animation.
type rippleScheduleEntry struct {
	Tick            int             `json:"tick"`
	DriveMin        float64         `json:"drive_min"`
	CumulativeUsers int             `json:"cumulative_users"`
	Polygon         *GeoJSONPolygon `json:"polygon,omitempty"`
	// ReachableGroupIDs is the targeting decision AT THIS TICK: groups with >=1
	// active in-polygon member road-reachable within this tick's drive-time.
	// The explorer tints groups from this, so the display is the decision.
	ReachableGroupIDs []int64 `json:"reachable_group_ids"`
}

// wantTickPolygons: only an explicit polygons=0 disables per-tick polygons, so
// older callers and the explorer (which draws them) are unaffected.
func wantTickPolygons(q string) bool {
	return q != "0"
}

type rippleScheduleResponse struct {
	TotalFreeglers int                   `json:"total_freeglers"`
	MaxDriveMin    float64               `json:"max_drive_min"`
	Schedule       []rippleScheduleEntry `json:"schedule"`
	// ReachableGroupIDs is the set of groups containing >=1 road node reachable
	// within the max budget - the water/toll-correct ripple-targeting signal
	// (plan 2026-07-06). Present (non-null) when computed, so batch can tell it
	// apart from an older server that omits the field. No omitempty on purpose.
	ReachableGroupIDs []int64 `json:"reachable_group_ids"`
	// OverflowRural is one ring per density-band ceiling, for members the audience cap shut
	// out of a post they are within their own travel-time budget of. Present only when
	// rural_access was requested AND the cap actually bound - see ruraloverflow.go. omitempty
	// on purpose: with the feature off the response must be byte-identical to before.
	OverflowRural map[string]*GeoJSONPolygon `json:"overflow_rural,omitempty"`
}

// wantRuralAccess parses the rural_access query parameter. Off unless explicitly asked for, so
// the lane ships dark and every existing caller is unaffected.
func wantRuralAccess(v string) bool {
	return v == "1" || v == "true"
}

// handleRippleSchedule produces the density-driven ripple schedule for a given
// origin. The schedule contains `ticks` entries; tick k's drive-time is chosen
// so that approximately (k/ticks) of all freeglers within the max isochrone
// are encapsulated. The drive-time delta between consecutive ticks is small in
// dense regions and large (one big jump) across empty voids.
//
// effectiveScheduleTotal applies the Stage-A audience-budget cap: the schedule
// curve is spread over at most targetUsers nearest freeglers when a positive cap
// is set and binds below the reachable pool; otherwise the full pool. Pure so
// the cap logic is unit-testable without a routing graph.
func effectiveScheduleTotal(total, targetUsers int) int {
	if targetUsers > 0 && targetUsers < total {
		return targetUsers
	}
	return total
}

// GET /v1/ripple-schedule?lat=...&lng=...&mode=drive&ticks=30&max_minutes=30&target_users=0
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

		// Audience-budget cap (Stage-A extent governor, Discourse #9808): when
		// target_users > 0 the curve is spread over at most that many NEAREST
		// freeglers, so the schedule and every tick polygon top out at
		// ~target_users members. In dense areas (London) this binds far below
		// the reachable pool, keeping reach tight; where the pool is already
		// smaller it never binds (rural unchanged). 0 / absent (the default)
		// leaves the schedule identical to before — the feature is off unless
		// the batch sends a non-zero value.
		targetUsers, _ := strconv.Atoi(c.Query("target_users", "0"))
		if targetUsers < 0 {
			targetUsers = 0
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

		// Road-reachable ripple targeting: a group is reached iff at least one
		// active member who lives inside the group's own polygon has a street
		// node in the Dijkstra reached set (see snapMembers /
		// groupIDsWithinSeconds). The snaps carry each member's drive-time, so
		// the same decision is made per tick below - the explorer tints exactly
		// what targeting decides. Empty (non-nil) if no group DB is configured,
		// which the batch treats as "gate not available".
		var memberSnaps []memberSnap
		if db := ensureGroupsDB(); db != nil {
			minLat, maxLat, minLng, maxLng := reachedBBox(g, iso.ReachedNodes)
			if members, err := queryActiveMembersInBox(db, minLat, maxLat, minLng, maxLng); err == nil {
				memberSnaps = snapMembers(g, iso.ReachedNodes, members, mode)
			} else {
				log.Printf("ripple-schedule: active-member query failed: %v", err)
			}
		}
		reachableGroups := groupIDsWithinSeconds(memberSnaps, maxSecs)

		// --- Step 2: get candidate freeglers via spatial, by BOUNDING BOX ---
		// The candidate polygon is deliberately just the reach's bbox, not the detailed
		// boundary: Step 3 filters each candidate exactly (their street node must be in
		// the Dijkstra reached set), so a looser candidate set changes nothing in the
		// result - and the unsimplified boundary ring (10k+ vertices now that display
		// smoothing is gone) is too large to ship as a WKT query.
		// polygons=0 (the batch): skip building a polygon per tick - the dominant cost
		// of this endpoint and the bulk of the stored schedule. The resolution is only
		// needed when polygons are built.
		includePolys := wantTickPolygons(c.Query("polygons", "1"))
		var res float64
		if includePolys {
			res = NetworkResolution(g, iso.ReachedNodes, mode)
		}
		bMinLat, bMaxLat, bMinLng, bMaxLng := reachedBBox(g, iso.ReachedNodes)
		wkt := fmt.Sprintf("POLYGON((%[1]f %[3]f, %[2]f %[3]f, %[2]f %[4]f, %[1]f %[4]f, %[1]f %[3]f))",
			bMinLng, bMaxLng, bMinLat, bMaxLat)

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

		// The curve is spread over effectiveTotal, not the full pool: the
		// audience cap (above) binds the schedule to the target_users nearest
		// freeglers when set and smaller than the pool. effectiveTotal ==
		// total when uncapped, so the schedule is unchanged in that case.
		effectiveTotal := effectiveScheduleTotal(total, targetUsers)

		// --- Step 4: build the schedule ---
		// For tick k in 1..ticks, target cumulative = ceil(k * effectiveTotal / ticks).
		// Drive-time for tick k = fwt[target-1].seconds.
		schedule := make([]rippleScheduleEntry, 0, ticks)
		for k := 1; k <= ticks; k++ {
			// Curve maps "elapsed fraction" (k/ticks) → "notified fraction"
			// (∈ [0,1]); multiply by effectiveTotal to get the target cumulative count.
			x := float64(k) / float64(ticks)
			frac := curveFraction(curve, x, ticks)
			target := int(math.Round(frac * float64(effectiveTotal)))
			if target < 1 {
				target = 1
			}
			if target > effectiveTotal {
				target = effectiveTotal
			}
			driveSecs := fwt[target-1].seconds

			// Polygon at this drive-time (only when requested): filter reached nodes
			// by time and rasterise. Skipped entirely for polygons=0.
			var tickPoly *GeoJSONPolygon
			if includePolys {
				filtered := make(map[NodeID]float32, target*4)
				for nid, t := range iso.ReachedNodes {
					if t <= driveSecs {
						filtered[nid] = t
					}
				}
				p := IsochronePolygon(g, filtered, res)
				tickPoly = &p
			}

			schedule = append(schedule, rippleScheduleEntry{
				Tick:              k,
				DriveMin:          float64(driveSecs) / 60.0,
				CumulativeUsers:   target,
				Polygon:           tickPoly,
				ReachableGroupIDs: groupIDsWithinSeconds(memberSnaps, driveSecs),
			})
		}

		// MaxDriveMin is the actual outer reach. When the audience cap binds it
		// is the drive-time to the target_users-th nearest freegler (the schedule
		// never grows past it); uncapped it is the requested ceiling, as before.
		// TotalFreeglers stays the real reachable pool so callers can see how far
		// below the pool the cap bound (for dark-compute measurement).
		maxDriveMin := maxMinutes
		capBound := effectiveTotal < total
		if capBound {
			maxDriveMin = float64(fwt[effectiveTotal-1].seconds) / 60.0
		}

		// Rural-access overflow (see ruraloverflow.go). Only for reaches the CAP cut short:
		// where it did not bind, the reach already went to the ceiling and there is nothing
		// beyond it to admit anyone to, so the rings would be cost with no beneficiary.
		var overflowRural map[string]*GeoJSONPolygon
		if capBound && wantRuralAccess(c.Query("rural_access", "0")) {
			// polygons=0 (what the batch sends) skips the resolution, so derive it here.
			// Same derivation the tick polygons use, so the rings are on the same grid.
			ringRes := res
			if ringRes <= 0 {
				ringRes = NetworkResolution(g, iso.ReachedNodes, mode)
			}
			overflowRural = ruralOverflowRings(g, iso.ReachedNodes, ringRes, maxMinutes)
		}

		return c.JSON(rippleScheduleResponse{
			TotalFreeglers:    total,
			MaxDriveMin:       maxDriveMin,
			Schedule:          schedule,
			ReachableGroupIDs: reachableGroups,
			OverflowRural:     overflowRural,
		})
	}
}
