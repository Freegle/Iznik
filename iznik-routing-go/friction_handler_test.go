package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
)

// ringArea returns the absolute shoelace area of a GeoJSON ring ([[lng,lat],...]).
func ringArea(ring [][]float64) float64 {
	n := len(ring)
	if n < 3 {
		return 0
	}
	var a float64
	for i := 0; i < n; i++ {
		j := (i + 1) % n
		a += ring[i][0]*ring[j][1] - ring[j][0]*ring[i][1]
	}
	if a < 0 {
		a = -a
	}
	return a / 2
}

func isoWalkArea(t *testing.T, app *fiber.App, url string) float64 {
	t.Helper()
	req := httptest.NewRequest(http.MethodGet, url, nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("GET %s: status %d", url, resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	var r struct {
		Walk struct {
			Geometry struct {
				Coordinates [][][]float64 `json:"coordinates"`
			} `json:"geometry"`
		} `json:"walk"`
	}
	if err := json.Unmarshal(body, &r); err != nil {
		t.Fatalf("decode %s: %v", url, err)
	}
	if len(r.Walk.Geometry.Coordinates) == 0 {
		return 0
	}
	return ringArea(r.Walk.Geometry.Coordinates[0])
}

// The friction param must route through FrictionIsochrone: on a uniformly HIGH-
// connectivity grid (friction > 1), the reachable area shrinks vs the plain isochrone.
func TestHandleIsochrone_FrictionShrinksHighConnectivityReach(t *testing.T) {
	g := makeTestGrid(nil)
	for i := NodeID(1); i < NodeID(len(g.Nodes)); i++ {
		g.Nodes[i].Conn = 100 // uniform high connectivity → traversal friction > 1
	}
	app := newApp(g, "", false)

	plain := isoWalkArea(t, app, "/v1/isochrone?lat=51.4545&lng=-2.5879&minutes=15")
	fric := isoWalkArea(t, app, "/v1/isochrone?lat=51.4545&lng=-2.5879&minutes=15&friction=1")

	if plain <= 0 {
		t.Fatalf("plain area should be positive, got %f", plain)
	}
	if !(fric < plain) {
		t.Errorf("friction should shrink reach on high-connectivity ground: plain=%f friction=%f", plain, fric)
	}
	t.Logf("plain walk area=%.3e friction walk area=%.3e (%.0f%% of plain)", plain, fric, 100*fric/plain)
}
