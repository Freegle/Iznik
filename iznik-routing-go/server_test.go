package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/golang-jwt/jwt/v4"
	"github.com/gofiber/fiber/v2"
)

// newInternalApp builds the unauthenticated (internal) app for testing.
func newInternalApp(t *testing.T) *fiber.App {
	t.Helper()
	g := getBristolGraph(t)
	return newApp(g, "", false)
}

// newExternalApp builds the JWT-authenticated (external) app for testing.
func newExternalApp(t *testing.T) *fiber.App {
	t.Helper()
	g := getBristolGraph(t)
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
	if nodes, ok := m["nodes"].(float64); !ok || nodes < 10000 {
		t.Errorf("nodes=%v, expected ≥10000", m["nodes"])
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
