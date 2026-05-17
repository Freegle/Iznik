package main

import (
	_ "embed"
	"encoding/json"
	"log"
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
	app := fiber.New(fiber.Config{
		JSONEncoder: func(v interface{}) ([]byte, error) {
			return json.Marshal(v)
		},
	})
	app.Get("/health", handleHealth(g))
	app.Get("/v1/isochrone", handleIsochrone(g))
	app.Get("/v1/fairness", handleFairness(g))
	app.Get("/demo", func(c *fiber.Ctx) error {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.Send(demoHTML)
	})

	log.Printf("routing-server: listening on %s (%d nodes, deprivation=%v)",
		addr, g.NodeCount(), g.Deprivation != nil)
	log.Fatal(app.Listen(addr))
}
