package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
)

func newTestApp(t *testing.T) *fiber.App {
	t.Helper()
	g := getBristolGraph(t)
	app := fiber.New()
	app.Get("/health", handleHealth(g))
	app.Get("/v1/isochrone", handleIsochrone(g))
	return app
}

func TestHealth(t *testing.T) {
	app := newTestApp(t)
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	var m map[string]interface{}
	if err := json.Unmarshal(body, &m); err != nil {
		t.Fatalf("invalid JSON: %v", err)
	}
	if m["status"] != "ok" {
		t.Errorf("status=%v", m["status"])
	}
	if nodes, ok := m["nodes"].(float64); !ok || nodes < 10000 {
		t.Errorf("nodes=%v, expected ≥10000", m["nodes"])
	}
}

func TestIsochroneEndpoint_AllThreeModes(t *testing.T) {
	app := newTestApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/isochrone?lat=51.4545&lng=-2.5879&minutes=15", nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		body, _ := io.ReadAll(resp.Body)
		t.Fatalf("expected 200, got %d: %s", resp.StatusCode, body)
	}
	body, _ := io.ReadAll(resp.Body)
	var r isochroneResponse
	if err := json.Unmarshal(body, &r); err != nil {
		t.Fatalf("invalid JSON: %v\nbody: %s", err, body)
	}
	for _, named := range []struct {
		name string
		poly GeoJSONPolygon
	}{
		{"walk", r.Walk},
		{"cycle", r.Cycle},
		{"drive", r.Drive},
	} {
		if named.poly.Geometry.Type != "Polygon" {
			t.Errorf("%s: expected Polygon geometry, got %q", named.name, named.poly.Geometry.Type)
		}
		ring := named.poly.Geometry.Coordinates[0]
		if len(ring) < 4 {
			t.Errorf("%s: polygon has only %d points", named.name, len(ring))
		}
	}
	t.Logf("walk ring=%d cycle ring=%d drive ring=%d",
		len(r.Walk.Geometry.Coordinates[0]),
		len(r.Cycle.Geometry.Coordinates[0]),
		len(r.Drive.Geometry.Coordinates[0]))
}

func TestIsochroneEndpoint_MissingLat(t *testing.T) {
	app := newTestApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/isochrone?lng=-2.5879", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 400 {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestIsochroneEndpoint_DefaultMinutes(t *testing.T) {
	app := newTestApp(t)
	// No minutes param — should default to 15.
	req := httptest.NewRequest(http.MethodGet, "/v1/isochrone?lat=51.4545&lng=-2.5879", nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
}
