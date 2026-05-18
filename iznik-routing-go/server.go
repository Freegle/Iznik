package main

import (
	_ "embed"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"

	"github.com/gofiber/fiber/v2"
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

		result := FairnessIsochrone(g, lat, lng, float32(minutes*60), mode, float32(fairness))
		return c.JSON(result)
	}
}

// handleNearbyFreeglers proxies to the spatial server's userapproxlocs KNN endpoint
// and returns {"freeglers":[{"lat":X,"lng":Y},...]}. Returns empty list on any error.
func handleNearbyFreeglers(spatialURL string) fiber.Handler {
	return func(c *fiber.Ctx) error {
		lat := c.Query("lat")
		lng := c.Query("lng")
		limit := c.Query("limit", "500")
		if lat == "" || lng == "" {
			return fiber.NewError(fiber.StatusBadRequest, "lat and lng required")
		}
		empty := fiber.Map{"freeglers": []interface{}{}}
		url := spatialURL + "/v1/userapproxlocs?lat=" + lat + "&lng=" + lng + "&limit=" + limit
		resp, err := http.Get(url) //nolint:gosec
		if err != nil || resp.StatusCode != 200 {
			return c.JSON(empty)
		}
		defer resp.Body.Close()
		body, err := io.ReadAll(resp.Body)
		if err != nil {
			return c.JSON(empty)
		}
		var knn struct {
			Results []struct {
				Extra map[string]any `json:"extra"`
			} `json:"results"`
		}
		if err := json.Unmarshal(body, &knn); err != nil {
			return c.JSON(empty)
		}
		type pt struct {
			Lat float64 `json:"lat"`
			Lng float64 `json:"lng"`
		}
		pts := make([]pt, 0, len(knn.Results))
		for _, r := range knn.Results {
			if r.Extra == nil {
				continue
			}
			lat, ok1 := r.Extra["lat"].(float64)
			lng, ok2 := r.Extra["lng"].(float64)
			if ok1 && ok2 {
				pts = append(pts, pt{lat, lng})
			}
		}
		return c.JSON(fiber.Map{"freeglers": pts})
	}
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

func startServer(g *Graph, addr string) {
	spatialURL := os.Getenv("SPATIAL_SERVER_URL")
	if spatialURL == "" {
		spatialURL = "http://localhost:8194"
	}
	app := fiber.New(fiber.Config{
		JSONEncoder: func(v interface{}) ([]byte, error) {
			return json.Marshal(v)
		},
	})
	initGroupsDB()
	app.Get("/health", handleHealth(g))
	app.Get("/demo", func(c *fiber.Ctx) error {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.Send(demoHTML)
	})

	// All /v1/* routes require a valid JWT.
	v1 := app.Group("/v1", jwtAuthMiddleware())
	v1.Get("/isochrone", handleIsochrone(g))
	v1.Get("/fairness", handleFairness(g))
	v1.Get("/nearby-freeglers", handleNearbyFreeglers(spatialURL))
	v1.Get("/groups/nearby", handleNearbyGroups())

	log.Printf("spatial-server: listening on %s (%d nodes, deprivation=%v)",
		addr, g.NodeCount(), g.Deprivation != nil)
	log.Fatal(app.Listen(addr))
}
