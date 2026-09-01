package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

// newInternalApp builds the unauthenticated (internal) app for testing.
func newInternalApp(t *testing.T) *fiber.App {
	t.Helper()
	g := getTestGraph(t)
	return newApp(g, "", false)
}

// newExternalApp builds the JWT-authenticated (external) app for testing.
func newExternalApp(t *testing.T) *fiber.App {
	t.Helper()
	g := getTestGraph(t)
	return newApp(g, "", true)
}

// makeJWT creates a signed test JWT using the current JWT_SECRET env var.
func makeJWT(t *testing.T, userID, sessionID string) string {
	t.Helper()
	secret := os.Getenv("JWT_SECRET")
	if secret == "" {
		secret = "secret"
	}
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":        userID,
		"sessionid": sessionID,
		"exp":       time.Now().Add(time.Hour).Unix(),
	})
	signed, err := token.SignedString([]byte(secret))
	if err != nil {
		t.Fatalf("makeJWT: %v", err)
	}
	return signed
}

func TestHealth(t *testing.T) {
	app := newInternalApp(t)
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
	if nodes, ok := m["nodes"].(float64); !ok || nodes < 100 {
		t.Errorf("nodes=%v, expected ≥100", m["nodes"])
	}
}

// TestInternalPort_NoAuthRequired verifies that /v1/* is accessible without
// a JWT on the internal (unauthenticated) app.
func TestInternalPort_NoAuthRequired(t *testing.T) {
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/isochrone?lat=51.4545&lng=-2.5879&minutes=5", nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		body, _ := io.ReadAll(resp.Body)
		t.Fatalf("internal port: expected 200 without auth, got %d: %s", resp.StatusCode, body)
	}
}

// TestExternalPort_RequiresAuth verifies that /v1/* returns 401 without a JWT
// on the external (authenticated) app.
func TestExternalPort_RequiresAuth(t *testing.T) {
	app := newExternalApp(t)
	for _, path := range []string{
		"/v1/isochrone?lat=51.4545&lng=-2.5879&minutes=5",
		"/v1/fairness?lat=51.4545&lng=-2.5879&minutes=5&mode=drive&fairness=0.5",
		"/v1/nearby-freeglers?lat=51.4545&lng=-2.5879",
		"/v1/groups/nearby?lat=51.4545&lng=-2.5879",
	} {
		req := httptest.NewRequest(http.MethodGet, path, nil)
		resp, err := app.Test(req, 5000)
		if err != nil {
			t.Fatalf("%s: %v", path, err)
		}
		if resp.StatusCode != 401 {
			t.Errorf("external port %s: expected 401 without auth, got %d", path, resp.StatusCode)
		}
	}
}

// TestExternalPort_HealthNoAuth verifies /health is accessible without JWT on
// the external port (health checks must not require auth).
func TestExternalPort_HealthNoAuth(t *testing.T) {
	app := newExternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("external port /health: expected 200, got %d", resp.StatusCode)
	}
}

// TestExternalPort_ValidJWT_IsochroneAccessible verifies that a valid JWT
// grants access to /v1/isochrone on the external port.
// When groupsDB is nil (no MySQL in test), JWT signature check still runs
// but session validation is skipped.
func TestExternalPort_ValidJWT_IsochroneAccessible(t *testing.T) {
	app := newExternalApp(t)
	tok := makeJWT(t, "12345", "67890")
	req := httptest.NewRequest(http.MethodGet,
		"/v1/isochrone?lat=51.4545&lng=-2.5879&minutes=5&jwt="+tok, nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		body, _ := io.ReadAll(resp.Body)
		t.Fatalf("external port with valid JWT: expected 200, got %d: %s", resp.StatusCode, body)
	}
}

func TestIsochroneEndpoint_AllThreeModes(t *testing.T) {
	app := newInternalApp(t)
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
	// Drive is the only mode the endpoint serves. Walk and cycle were never
	// consumed (apiv2 decodes only "drive") and cost two extra full-graph
	// Dijkstras per call.
	if r.Drive.Geometry.Type != "Polygon" {
		t.Errorf("drive: expected Polygon geometry, got %q", r.Drive.Geometry.Type)
	}
	if ring := r.Drive.Geometry.Coordinates[0]; len(ring) < 4 {
		t.Errorf("drive: polygon has only %d points", len(ring))
	}
	// The response must not carry walk/cycle members any more.
	var raw map[string]json.RawMessage
	if err := json.Unmarshal(body, &raw); err != nil {
		t.Fatalf("invalid JSON: %v", err)
	}
	for _, gone := range []string{"walk", "cycle"} {
		if _, ok := raw[gone]; ok {
			t.Errorf("response still carries a %q member", gone)
		}
	}
	t.Logf("drive ring=%d", len(r.Drive.Geometry.Coordinates[0]))
}

func TestIsochroneEndpoint_MissingLat(t *testing.T) {
	app := newInternalApp(t)
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
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/isochrone?lat=51.4545&lng=-2.5879", nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
}

// TestNearbyGroups_NoDBReturnsEmptyCollection verifies that /v1/groups/nearby
// returns a valid GeoJSON FeatureCollection (empty) when no MySQL is configured.
func TestNearbyGroups_NoDBReturnsEmptyCollection(t *testing.T) {
	// Clear MYSQL_HOST so ensureGroupsDB cannot reconnect even if groupsDB is nil.
	t.Setenv("MYSQL_HOST", "")
	groupsDB = nil // ensure no DB for this test
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/groups/nearby?lat=51.75&lng=-1.25", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	var fc groupsCollection
	if err := json.Unmarshal(body, &fc); err != nil {
		t.Fatalf("invalid JSON: %v\nbody: %s", err, body)
	}
	if fc.Type != "FeatureCollection" {
		t.Errorf("expected FeatureCollection, got %q", fc.Type)
	}
	if fc.Features == nil {
		t.Error("features must be [] not null")
	}
}

// TestGroupExtentEndpoint_UnknownGroup404 verifies /v1/group-extent 404s when the group can't be
// seeded (here: no MySQL configured, so groupSeedNodes fails) — this is a DB-free HTTP-level
// assertion, matching the existing pattern for this handler family, since seeding the group
// normally needs the `groups` table which isn't available in this package's standalone go test.
func TestGroupExtentEndpoint_UnknownGroup404(t *testing.T) {
	t.Setenv("MYSQL_HOST", "")
	groupsDB = nil
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/group-extent?groupid=1", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 404 {
		t.Errorf("expected 404, got %d", resp.StatusCode)
	}
}

// TestGroupActives_NoDBReturns503 verifies /v1/group-actives degrades to 503 (not a
// misleadingly-confident zero/floor response) when no MySQL is configured.
func TestGroupActives_NoDBReturns503(t *testing.T) {
	t.Setenv("MYSQL_HOST", "")
	groupsDB = nil
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/group-actives?groupid=123", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 503 {
		t.Errorf("expected 503, got %d", resp.StatusCode)
	}
}

func TestGroupActives_MissingGroupidReturns400(t *testing.T) {
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/group-actives", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 400 {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

// TestWktPolygonToCoords_DegreeCoords verifies that WKT coordinates in degree
// range (as stored in the production polyindex) are returned as-is without
// Mercator conversion.
func TestWktPolygonToCoords_DegreeCoords(t *testing.T) {
	// A simple polygon in WGS84 degrees stored as SRID 3857 data.
	wkt := "POLYGON((-1.3 51.6, -1.1 51.6, -1.1 51.9, -1.3 51.9, -1.3 51.6))"
	coords, err := wktPolygonToCoords(wkt)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(coords) == 0 || len(coords[0]) < 4 {
		t.Fatalf("expected ring with ≥4 points, got %v", coords)
	}
	// First point should be (-1.3, 51.6) — degree range, not Mercator meters.
	got := coords[0][0]
	if got[0] < -180 || got[0] > 180 || got[1] < -90 || got[1] > 90 {
		t.Errorf("point %v looks like Mercator meters, expected WGS84 degrees", got)
	}
	if got[0] != -1.3 || got[1] != 51.6 {
		t.Errorf("expected [-1.3 51.6], got %v", got)
	}
}
