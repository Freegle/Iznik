package main

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net/http"
	"strconv"
	"strings"

	"github.com/gofiber/fiber/v2"
	"github.com/gofiber/fiber/v2/middleware/cors"
)

type isochroneResponse struct {
	Walk  GeoJSONPolygon `json:"walk"`
	Cycle GeoJSONPolygon `json:"cycle"`
	Drive GeoJSONPolygon `json:"drive"`
}

// handleIsochrone handles GET /v1/isochrone?lat=&lng=&minutes=
func handleIsochrone(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat, err := strconv.ParseFloat(c.Query("lat"), 64)
		if err != nil || lat == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat required")
		}
		lng, err := strconv.ParseFloat(c.Query("lng"), 64)
		if err != nil || lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lng required")
		}
		minutes, _ := strconv.ParseFloat(c.Query("minutes", "15"), 64)
		if minutes <= 0 || minutes > 120 {
			minutes = 15
		}
		secs := float32(minutes * 60)

		type result struct {
			mode Mode
			poly GeoJSONPolygon
		}
		ch := make(chan result, 3)

		for _, m := range []Mode{Walk, Cycle, Drive} {
			go func(m Mode) {
				iso := Isochrone(g, lat, lng, secs, m)
				res := NetworkResolution(g, iso.ReachedNodes, m)
				ch <- result{m, IsochronePolygon(g, iso.ReachedNodes, res)}
			}(m)
		}

		resp := isochroneResponse{}
		for i := 0; i < 3; i++ {
			r := <-ch
			switch r.mode {
			case Walk:
				resp.Walk = r.poly
			case Cycle:
				resp.Cycle = r.poly
			case Drive:
				resp.Drive = r.poly
			}
		}
		return c.JSON(resp)
	}
}

// handleQuintile handles GET /v1/quintile?lat=&lng=
//
// Returns the IMD deprivation quintile of the nearest LSOA centroid: 1 = most deprived,
// 5 = least, 0 = no data. This server already holds the index (deprivation.go) purely for the
// fairness isochrone, so a member's quintile can be answered here without anything else in the
// estate needing to load or understand IMD data.
//
// No Dijkstra, no graph traversal: this is a grid lookup and costs microseconds. The single
// form is for one-off questions ("what fifth is this postcode in?"); consumers deciding a
// whole set of people at once want handleQuintiles below instead.
func handleQuintile(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat, err1 := strconv.ParseFloat(c.Query("lat"), 64)
		lng, err2 := strconv.ParseFloat(c.Query("lng"), 64)
		if err1 != nil || err2 != nil {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		if g.Deprivation == nil {
			// No index loaded. Answer honestly rather than implying "not deprived":
			// 0 already means "unknown" to every caller of Lookup.
			return c.JSON(fiber.Map{"quintile": 0, "available": false})
		}
		return c.JSON(fiber.Map{
			"quintile":  int(g.Deprivation.Lookup(lat, lng)),
			"available": true,
		})
	}
}

// quintilesRequest is a batch of points to classify.
type quintilesRequest struct {
	Points [][2]float64 `json:"points"` // [lat, lng] pairs
}

// maxQuintilesBatch bounds one request. The lookup itself is microseconds, so this is about
// request size rather than compute: a caller wanting more should page, and a caller sending
// more by accident should be told rather than quietly served a truncated answer.
const maxQuintilesBatch = 5000

// handleQuintiles handles POST /v1/quintiles with {"points": [[lat,lng], ...]}
//
// Answers the deprivation fifth for many points in one call, in the order given: 1 = most
// deprived, 5 = least, 0 = no data.
//
// This exists because of how the fairness lane is READ. The stretched ring decides who is
// geographically eligible, and that is a containment test the database does; the fifth then
// decides which of those people the stretch was actually FOR. Only the people the ring adds
// need classifying - not the membership - so the batch is small and bounded by the extra
// audience rather than by group size.
//
// Answering here rather than storing a fifth against each member is deliberate: this server
// already holds the index for the isochrone itself, so nothing else in the estate has to load,
// understand, or retain IMD data, and no inferred deprivation attribute is written against an
// individual anywhere.
func handleQuintiles(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		var req quintilesRequest
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "invalid JSON body")
		}
		if len(req.Points) > maxQuintilesBatch {
			return fiber.NewError(fiber.StatusBadRequest, "too many points")
		}
		if g.Deprivation == nil {
			// Honest "unknown" for every point, same as the single form: 0 already means
			// unknown to every caller, and a shorter array would misalign with the input.
			return c.JSON(fiber.Map{
				"quintiles": make([]int, len(req.Points)),
				"available": false,
			})
		}

		out := make([]int, len(req.Points))
		for i, p := range req.Points {
			out[i] = int(g.Deprivation.Lookup(p[0], p[1]))
		}
		return c.JSON(fiber.Map{"quintiles": out, "available": true})
	}
}

// handleFairness handles GET /v1/fairness?lat=&lng=&minutes=&mode=&fairness=
func handleFairness(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat, err := strconv.ParseFloat(c.Query("lat"), 64)
		if err != nil || lat == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat required")
		}
		lng, err := strconv.ParseFloat(c.Query("lng"), 64)
		if err != nil || lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lng required")
		}
		minutes, _ := strconv.ParseFloat(c.Query("minutes", "15"), 64)
		if minutes <= 0 || minutes > 120 {
			minutes = 15
		}
		fairness, _ := strconv.ParseFloat(c.Query("fairness", "0"), 64)
		if fairness < 0 {
			fairness = 0
		}
		if fairness > 1 {
			fairness = 1
		}

		mode := parseMode(c.Query("mode", "walk"))

		// Reach-engine fast path (drive): the label query + table expansion
		// replaces the bounded full-graph sweep; the quintile weighting and
		// polygons run unchanged on the same reached set.
		if e := reachLive; e != nil && mode == Drive {
			limitSecs := float32(minutes * 60)
			maxLimit := limitSecs * (1 + float32(clampFairnessWeight(fairness)))
			origin := nearestNodeForMode(g, lat, lng, Drive)
			if origin != noNode {
				lbl := e.QueryLabelsFromNode(origin, maxLimit)
				reached := e.ReachedNodes(lbl, maxLimit)
				return c.JSON(fairnessFromReached(g, origin, reached, limitSecs, mode, float32(clampFairnessWeight(fairness))))
			}
		}
		result := FairnessIsochrone(g, lat, lng, float32(minutes*60), mode, float32(fairness))
		return c.JSON(result)
	}
}

// parseMode maps a mode query value to a Mode, defaulting to Walk.
func parseMode(s string) Mode {
	switch s {
	case "cycle":
		return Cycle
	case "drive":
		return Drive
	default:
		return Walk
	}
}

// handleCatchment handles GET /v1/catchment?lat=&lng=&minutes=&mode=&friction=1
// Returns the inbound catchment polygon for a group: the area from which posts would ripple
// far enough to reach it — seeded from the group's whole boundary (via groupid) so corridor
// reach into the group's edges is captured (a centroid-only seed misses e.g. an M62 offer
// clipping HullFreegle's western strip).
func handleCatchment(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		minutes, _ := strconv.ParseFloat(c.Query("minutes", "30"), 64)
		if minutes <= 0 || minutes > 120 {
			minutes = 30
		}
		secs := float32(minutes * 60)
		mode := parseMode(c.Query("mode", "drive"))

		if gidStr := c.Query("groupid"); gidStr != "" {
			gid, err := strconv.ParseInt(gidStr, 10, 64)
			if err != nil {
				return fiber.NewError(fiber.StatusBadRequest, "invalid groupid")
			}
			seeds, ok := groupSeedNodes(g, gid, mode)
			if !ok {
				return fiber.NewError(fiber.StatusNotFound, "group not found or has no polygon")
			}
			iso := engineOrFlatMultiSource(g, seeds, secs, mode)
			poly := IsochronePolygon(g, iso.ReachedNodes, NetworkResolution(g, iso.ReachedNodes, mode))
			// Drive-time bands (heatmap): how rapidly a post from each area would ripple in.
			bands := catchmentBands(g, iso, secs, mode, 6)
			return c.JSON(fiber.Map{"catchment": poly, "bands": bands, "seeds": len(seeds)})
		}

		// Point form (ad-hoc): catchment of a single location.
		lat, err := strconv.ParseFloat(c.Query("lat"), 64)
		if err != nil || lat == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat or groupid required")
		}
		lng, err := strconv.ParseFloat(c.Query("lng"), 64)
		if err != nil || lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lng required")
		}
		iso := engineOrFlatIsochrone(g, lat, lng, secs, mode)
		res := NetworkResolution(g, iso.ReachedNodes, mode)
		poly := IsochronePolygon(g, iso.ReachedNodes, res)
		// Sandwich bounds for the reach containment queries (see bounds.go): derived on
		// the same grid as the exact polygon, so the superset/subset guarantees hold by
		// construction. Shipped only on the point form — it is what materialises
		// rippling_reach tick polygons; the groupid form is a display view.
		bounds := IsochroneBounds(g, iso.ReachedNodes, res)
		resp := fiber.Map{"catchment": poly}
		if bounds.Outer != nil {
			resp["catchment_outer"] = bounds.Outer
		}
		if bounds.Inner != nil {
			resp["catchment_inner"] = bounds.Inner
		}
		return c.JSON(resp)
	}
}

// handleDriveTime handles GET /v1/drive-time?lat=&lng=&tolat=&tolng=&max_minutes=&mode=
//
// The road time between two points, as a single number. It exists so the site can answer
// "when will this post's reach expand to cover me": that needs the member's drive time from
// the post's origin, which is then compared against the drive-time budget stored on each
// tick of the post's reach schedule.
//
// The alternatives were both far more expensive for a number we immediately throw away.
// Materialising a tick's isochrone via /v1/catchment costs ~0.5s and ~1.2MB of polygon;
// bisecting on /v1/isochrone costs several of those. The Dijkstra underneath is the same
// one, bounded the same way. Measured on the UK graph, this budget-bounded search is ~40ms
// at 30 minutes and ~10ms at 15, so the answer is cheap enough to show unprompted.
//
// Direction matters and matches the reach. rippling_reach tick polygons come from the point
// form of /v1/catchment, which is a plain outward Isochrone from the post's origin, so this
// measures origin -> member too. Measuring the other way would disagree with the stored
// polygon wherever one-way roads do.
//
// reachable:false means "not within max_minutes", which is a real answer rather than an
// error: it is how the caller learns the reach will never grow to cover this member, and
// so that their held reply waits for the reach to finish instead.
func handleDriveTime(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat, err := strconv.ParseFloat(c.Query("lat"), 64)
		if err != nil || lat == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat required")
		}
		lng, err := strconv.ParseFloat(c.Query("lng"), 64)
		if err != nil || lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lng required")
		}
		toLat, err := strconv.ParseFloat(c.Query("tolat"), 64)
		if err != nil || toLat == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "tolat required")
		}
		toLng, err := strconv.ParseFloat(c.Query("tolng"), 64)
		if err != nil || toLng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "tolng required")
		}

		// Bounded like every other search here. The ceiling is 120 to match
		// handleGroupProximity; callers pass the post's own final tick budget, which is
		// well under that, and the cost scales with it.
		minutes, _ := strconv.ParseFloat(c.Query("max_minutes", "60"), 64)
		if minutes <= 0 || minutes > 120 {
			minutes = 60
		}
		mode := parseMode(c.Query("mode", "drive"))

		// Reach-engine fast path: exact answer in milliseconds, with road
		// miles included, when the engine is live (drive mode only).
		if resp, handled := engineDriveTime(lat, lng, toLat, toLng, minutes, mode); handled {
			return c.JSON(resp)
		}

		dest := nearestNodeForMode(g, toLat, toLng, mode)
		if dest == noNode {
			// Off the road graph entirely (mid-sea coordinates, or a mode with no
			// network here). Not reachable, and not an error.
			return c.JSON(fiber.Map{"reachable": false})
		}

		targets := []NodeID{dest}
		bbox := boundingBox(g, targets, lat, lng, 0.15)
		costs, _ := costToTargets(g, lat, lng, targets, float32(minutes*60), mode, bbox)

		cost, reached := costs[dest]
		if !reached {
			return c.JSON(fiber.Map{"reachable": false})
		}

		return c.JSON(fiber.Map{
			"reachable": true,
			"drive_min": float64(cost) / 60,
		})
	}
}

// handleGroupExtent returns the group's own road "diameter": the widest road drive-time between
// two points inside the group. It sets a yardstick on the catchment view — a post rippling in
// from no further away than the group already spans internally is unremarkable.
func handleGroupExtent(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		gid, err := strconv.ParseInt(c.Query("groupid"), 10, 64)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "groupid required")
		}
		minutes, _ := strconv.ParseFloat(c.Query("max_minutes", "240"), 64)
		if minutes <= 0 || minutes > 480 {
			minutes = 240
		}
		mode := parseMode(c.Query("mode", "drive"))

		seeds, okS := groupSeedNodes(g, gid, mode)
		if !okS {
			return fiber.NewError(fiber.StatusNotFound, "group not found or has no polygon")
		}
		from, to, milesBetween, ok := groupDiameter(g, seeds, mode, float32(minutes*60))
		if !ok {
			return c.JSON(fiber.Map{"reachable": false})
		}

		// Reverse-geocode both endpoints. Best-effort: on any failure the postcode/place fields
		// are simply absent (omitempty) — the core reachable/minutes/miles response is unaffected.
		db := ensureGroupsDB()
		from.Postcode, from.Place = resolvePlace(db, from.Lat, from.Lng)
		to.Postcode, to.Place = resolvePlace(db, to.Lat, to.Lng)

		return c.JSON(fiber.Map{
			"reachable": true,
			"from":      from,
			"to":        to,
			"minutes":   to.DriveMin,
			"miles":     milesBetween,
		})
	}
}

// handleGroupProximity handles GET /v1/group-proximity?groupid=&lat=&lng=&mode=&max_minutes=
// For an offer at (lat,lng) rippling into groupid, returns the nearest in-group point P and the
// in-group point furthest FROM P (Q), each with road drive-time, plus quicker = (offer→P < P→Q).
// Backs the moderator "this post is quicker to get to for Freeglers in {P} than {P} is to {Q}"
// line, which is shown only when quicker is true.
func handleGroupProximity(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat, err := strconv.ParseFloat(c.Query("lat"), 64)
		if err != nil || lat == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lat required")
		}
		lng, err := strconv.ParseFloat(c.Query("lng"), 64)
		if err != nil || lng == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "lng required")
		}
		gid, err := strconv.ParseInt(c.Query("groupid"), 10, 64)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "groupid required")
		}
		minutes, _ := strconv.ParseFloat(c.Query("max_minutes", "120"), 64)
		if minutes <= 0 || minutes > 240 {
			minutes = 120
		}
		mode := parseMode(c.Query("mode", "drive"))

		seeds, okS := groupSeedNodes(g, gid, mode)
		if !okS {
			return fiber.NewError(fiber.StatusNotFound, "group not found or has no polygon")
		}
		// Reach-engine fast path (drive): two label queries instead of two
		// bounded full-graph sweeps — this call backs the proximity-notes
		// cron, whose sweeps were a measured ~12 CPU-hours/day standing tax.
		closest, furthest, ok, handled := engineGroupProximity(lat, lng, seeds, mode, float32(minutes*60))
		if !handled {
			closest, furthest, ok = groupProximity(g, lat, lng, seeds, mode, float32(minutes*60))
		}
		if !ok {
			return c.JSON(fiber.Map{"reachable": false})
		}
		return c.JSON(fiber.Map{
			"reachable": true,
			"closest":   closest,
			"furthest":  furthest,
			"quicker":   closest.DriveMin < furthest.DriveMin,
		})
	}
}

// maxFreeglersReturned caps the number of freegler points returned to avoid
// overwhelming the map. Points are uniformly sampled when over this limit.
const maxFreeglersReturned = 2000

// handleNearbyFreeglers computes the isochrone polygon for the given location
// and returns all freeglers within it. This avoids the centre-distance bias of
// a KNN query: every part of the reachable area is equally represented.
func handleNearbyFreeglers(g *Graph, spatialURL string) fiber.Handler {
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

		minutes, _ := strconv.ParseFloat(c.Query("minutes", "15"), 64)
		if minutes <= 0 || minutes > 120 {
			minutes = 15
		}
		modeStr := c.Query("mode", "walk")
		var mode Mode
		switch modeStr {
		case "cycle":
			mode = Cycle
		case "drive":
			mode = Drive
		default:
			mode = Walk
		}

		empty := fiber.Map{"freeglers": []interface{}{}}

		// Compute the reachable polygon for the given location.
		secs := float32(minutes * 60)
		iso := Isochrone(g, latF, lngF, secs, mode)
		if len(iso.ReachedNodes) == 0 {
			return c.JSON(empty)
		}
		res := NetworkResolution(g, iso.ReachedNodes, mode)
		poly := IsochronePolygon(g, iso.ReachedNodes, res)
		ring := poly.Geometry.Coordinates
		if len(ring) == 0 || len(ring[0]) < 4 {
			return c.JSON(empty)
		}
		outer := ring[0]

		// Candidates come from the reach's BOUNDING BOX, not its boundary; the exact
		// boundary test then runs here, on `outer`, so the answer is the same set as
		// asking the index to do the containment.
		//
		// Posting the boundary itself stopped working once display smoothing went from
		// the tracer: a 45-minute drive reach out of central London traces ~13k vertices,
		// over the spatial index's 10,000-vertex WKT limit, so the query 400d and this
		// handler soft-failed to an empty list. 45 minutes is the DEFAULT reach, so the
		// explorer's Freegler dots and its "~N would be notified" panel both read as
		// "nobody" in exactly the case they exist for, with only a server-side log line
		// to say why. ripple.go already takes the bbox route for this reason.
		minLat, maxLat, minLng, maxLng := reachedBBox(g, iso.ReachedNodes)
		wkt := bboxWKT(minLat, maxLat, minLng, maxLng)

		reqURL := spatialURL + "/v1/userapproxlocs/within_coords"
		resp, err := http.Post(reqURL, "text/plain", strings.NewReader(wkt)) //nolint:gosec
		if err != nil || resp.StatusCode != 200 {
			statusCode := 0
			if resp != nil {
				statusCode = resp.StatusCode
			}
			log.Printf("nearby-freeglers: within_coords request failed (status=%d err=%v)", statusCode, err)
			if resp != nil {
				resp.Body.Close()
			}
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

		type pt struct {
			Lat float64 `json:"lat"`
			Lng float64 `json:"lng"`
		}
		pts := make([]pt, 0, len(within.Results))
		for _, r := range within.Results {
			if r.Extra == nil {
				continue
			}
			lat, ok1 := r.Extra["lat"].(float64)
			lng, ok2 := r.Extra["lng"].(float64)
			// The bbox is wider than the reach, so drop the corners the boundary
			// excludes. This is the containment the index used to do for us.
			if ok1 && ok2 && pointInRing(lng, lat, outer) {
				pts = append(pts, pt{lat, lng})
			}
		}

		// Record the total located count before any sampling cap.
		totalLocated := len(pts)

		// Uniform random sample if over the display cap.
		if len(pts) > maxFreeglersReturned {
			rand.Shuffle(len(pts), func(i, j int) { pts[i], pts[j] = pts[j], pts[i] })
			pts = pts[:maxFreeglersReturned]
		}

		return c.JSON(fiber.Map{"freeglers": pts, "total_located": totalLocated})
	}
}

// bboxWKT renders a lat/lng bounding box as a WKT POLYGON, for spatial-index queries
// that want a candidate set rather than an exact answer. Four corners, so it can never
// hit the index's vertex limit however large the reach is - which is the whole reason
// callers ask by box and filter the boundary themselves.
func bboxWKT(minLat, maxLat, minLng, maxLng float64) string {
	return fmt.Sprintf("POLYGON((%[1]f %[3]f, %[2]f %[3]f, %[2]f %[4]f, %[1]f %[4]f, %[1]f %[3]f))",
		minLng, maxLng, minLat, maxLat)
}

// ringToWKT converts a GeoJSON polygon ring ([lng,lat] pairs) to WKT POLYGON.
func ringToWKT(ring [][2]float64) string {
	pts := make([]string, len(ring))
	for i, p := range ring {
		pts[i] = fmt.Sprintf("%.8f %.8f", p[0], p[1])
	}
	return "POLYGON((" + strings.Join(pts, ",") + "))"
}

// handleHealth is a simple liveness check.
func handleHealth(g *Graph) fiber.Handler {
	return func(c *fiber.Ctx) error {
		status := fiber.Map{
			"status": "ok",
			"nodes":  g.NodeCount(),
		}
		if g.Deprivation != nil {
			status["deprivation"] = "loaded"
		}
		return c.JSON(status)
	}
}

// newApp builds a Fiber app with all spatial endpoints.
// When requireAuth is true, /v1/* routes require a valid moderator JWT.
// When false (internal port), /v1/* routes are accessible without auth.
func newApp(g *Graph, spatialURL string, requireAuth bool) *fiber.App {
	app := fiber.New(fiber.Config{
		JSONEncoder: func(v interface{}) ([]byte, error) {
			return json.Marshal(v)
		},
	})
	app.Use(cors.New(cors.Config{
		AllowOrigins: "*",
		AllowMethods: "GET,OPTIONS",
	}))
	app.Get("/health", handleHealth(g))

	var v1 fiber.Router
	if requireAuth {
		v1 = app.Group("/v1", jwtAuthMiddleware())
	} else {
		v1 = app.Group("/v1")
	}
	// gated() routes run graph computations (Dijkstra working sets sized by
	// the reached area) and share the bounded compute pool — see computegate.go.
	// Ungated routes are lookups that never traverse the graph.
	v1.Get("/isochrone", gated(handleIsochrone(g)))
	v1.Get("/fairness", gated(handleFairness(g)))
	v1.Get("/quintile", handleQuintile(g))
	v1.Post("/quintiles", handleQuintiles(g))
	v1.Get("/catchment", gated(handleCatchment(g)))
	v1.Get("/group-proximity", gated(handleGroupProximity(g)))
	v1.Get("/drive-time", gated(handleDriveTime(g)))
	v1.Get("/group-extent", gated(handleGroupExtent(g)))
	v1.Get("/group-actives", handleGroupActives())
	v1.Get("/nearby-freeglers", gated(handleNearbyFreeglers(g, spatialURL)))
	v1.Get("/ripple-schedule", gated(handleRippleSchedule(g, spatialURL)))
	v1.Get("/reachable-groups", gated(handleReachableGroups(g)))
	v1.Post("/ripple-eval", gated(handleRippleEval(g, spatialURL)))
	v1.Get("/posts-for-member", gated(handlePostsForMember(g, spatialURL)))
	v1.Get("/digest-simulator", gated(handleDigestSimulator(g, spatialURL)))
	// Reach engine reach engine (503 until REACH_DIR is configured): labels are a
	// graph computation (gated); arrival evaluation is table lookups (ungated).
	v1.Get("/reach-labels", gated(handleReachLabels()))
	v1.Post("/reach-arrival", handleReachArrival())
	v1.Post("/drive-metrics", gated(handleDriveMetrics()))
	v1.Get("/blur", handleBlur(g))
	v1.Post("/blur-batch", handleBlurBatch(g))
	v1.Get("/leaf", handleLeaf())
	v1.Post("/reach-eval", handleReachEval())
	v1.Get("/groups/nearby", handleNearbyGroups())
	v1.Get("/groups/list", handleGroupsList())

	// Swagger UI (Redoc) — mirrors the v2 Go API pattern.
	app.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/index.html", 302)
	})
	app.Static("/swagger", "./swagger", fiber.Static{Index: "index.html"})
	return app
}

func startServer(g *Graph) {
	spatialURL := getenv("SPATIAL_KNN_URL", "http://localhost:8194")

	initGroupsDB()

	// Internal port: no authentication — for trusted backend services.
	internalAddr := ":" + getenv("SPATIAL_INTERNAL_PORT", "8194")
	internalApp := newApp(g, spatialURL, false)
	go func() {
		log.Printf("spatial-server: internal listener on %s (no auth)", internalAddr)
		log.Fatal(internalApp.Listen(internalAddr))
	}()

	// External port: JWT authentication required, moderators only.
	externalAddr := ":" + getenv("SPATIAL_PORT", "8196")
	externalApp := newApp(g, spatialURL, true)
	log.Printf("spatial-server: external listener on %s (JWT auth, %d nodes, deprivation=%v)",
		externalAddr, g.NodeCount(), g.Deprivation != nil)
	log.Fatal(externalApp.Listen(externalAddr))
}
