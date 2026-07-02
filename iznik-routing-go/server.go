package main

import (
	_ "embed"
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

//go:embed demo.html
var demoHTML []byte

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
				res := AutoResolution(secs, m)
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
			iso := multiSourceIsochrone(g, seeds, secs, mode)
			poly := IsochronePolygon(g, iso.ReachedNodes, AutoResolution(secs, mode))
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
		iso := Isochrone(g, lat, lng, secs, mode)
		poly := IsochronePolygon(g, iso.ReachedNodes, AutoResolution(secs, mode))
		return c.JSON(fiber.Map{"catchment": poly})
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
		closest, furthest, ok := groupProximity(g, lat, lng, seeds, mode, float32(minutes*60))
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
		res := AutoResolution(secs, mode)
		poly := IsochronePolygon(g, iso.ReachedNodes, res)
		ring := poly.Geometry.Coordinates
		if len(ring) == 0 || len(ring[0]) < 4 {
			return c.JSON(empty)
		}

		// Convert the outer ring to a WKT POLYGON for the within_coords query.
		wkt := ringToWKT(ring[0])

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
			if ok1 && ok2 {
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
	app.Get("/demo", func(c *fiber.Ctx) error {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.Send(demoHTML)
	})

	var v1 fiber.Router
	if requireAuth {
		v1 = app.Group("/v1", jwtAuthMiddleware())
	} else {
		v1 = app.Group("/v1")
	}
	v1.Get("/isochrone", handleIsochrone(g))
	v1.Get("/fairness", handleFairness(g))
	v1.Get("/catchment", handleCatchment(g))
	v1.Get("/group-proximity", handleGroupProximity(g))
	v1.Get("/nearby-freeglers", handleNearbyFreeglers(g, spatialURL))
	v1.Get("/ripple-schedule", handleRippleSchedule(g, spatialURL))
	v1.Post("/ripple-eval", handleRippleEval(g, spatialURL))
	v1.Get("/posts-for-member", handlePostsForMember(g, spatialURL))
	v1.Get("/digest-simulator", handleDigestSimulator(g, spatialURL))
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
