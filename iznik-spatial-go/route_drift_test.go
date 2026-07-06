package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"testing"
)

// TestRoutesMatchSwagger is a drift-guard that fails whenever a route is added
// to buildPublicApp or buildAdminApp without a matching entry in swagger.json,
// or when swagger.json documents a path that is no longer registered in the app.
//
// To update: add the route to main.go (buildPublicApp or buildAdminApp), add its
// swagger:route annotation to doc.go, regenerate swagger/swagger.json using
// generate-swagger.sh, then re-run this test.
func TestRoutesMatchSwagger(t *testing.T) {
	// Build both apps with an empty server.  The handlers close over srv but are
	// never invoked — GetRoutes() only reads the routing table, it does not call
	// any handler, so a nil MySQL DB and empty dataset map are safe here.
	srv := newServer(nil, t.TempDir(), nil)
	publicApp := buildPublicApp(srv)
	adminApp := buildAdminApp(srv)

	// Collect all non-middleware routes from both apps.
	// GetRoutes(true) filters out Use() / middleware routes, which includes the
	// static-file server registered by api.Static("/swagger", ...).
	// HEAD routes are excluded: Fiber auto-registers HEAD alongside every GET, but
	// swagger.json documents only GET — the HEAD behaviour is implicit.
	appRoutes := make(map[string]bool) // keyed by "METHOD /fiber/:style/path"
	for _, r := range publicApp.GetRoutes(true) {
		if strings.ToUpper(r.Method) == "HEAD" {
			continue
		}
		appRoutes[fiberRouteKey(r.Method, r.Path)] = true
	}
	for _, r := range adminApp.GetRoutes(true) {
		if strings.ToUpper(r.Method) == "HEAD" {
			continue
		}
		appRoutes[fiberRouteKey(r.Method, r.Path)] = true
	}

	// allowlisted holds routes that are registered in the app but intentionally
	// absent from swagger.json.  The /swagger redirect is the Redoc developer-doc
	// UI — self-justifying (documentation for the API itself) and not an API
	// endpoint, so it requires no swagger entry.
	allowlisted := map[string]bool{
		"GET /swagger": true,
	}

	// Load and parse swagger.json from the well-known relative path.
	data, err := os.ReadFile("swagger/swagger.json")
	if err != nil {
		t.Fatalf("cannot read swagger/swagger.json: %v", err)
	}
	var spec struct {
		Paths map[string]map[string]interface{} `json:"paths"`
	}
	if err := json.Unmarshal(data, &spec); err != nil {
		t.Fatalf("cannot parse swagger/swagger.json: %v", err)
	}

	// Build a swagger route set keyed by "METHOD /swagger/{param}/style path".
	swaggerRoutes := make(map[string]bool)
	for path, methods := range spec.Paths {
		for method := range methods {
			key := fmt.Sprintf("%s %s", strings.ToUpper(method), path)
			swaggerRoutes[key] = true
		}
	}

	var failed bool

	// Check 1: every app route (not allowlisted) must appear in swagger.json.
	for fiberKey := range appRoutes {
		if allowlisted[fiberKey] {
			continue
		}
		swaggerKey := fiberKeyToSwagger(fiberKey)
		if !swaggerRoutes[swaggerKey] {
			t.Errorf("route registered in app but missing from swagger.json:\n  app:     %s\n  swagger: %s (not found)", fiberKey, swaggerKey)
			failed = true
		}
	}

	// Check 2: every swagger.json path must be registered in one of the apps.
	for swaggerKey := range swaggerRoutes {
		fiberKey := swaggerKeyToFiber(swaggerKey)
		if !appRoutes[fiberKey] {
			t.Errorf("route documented in swagger.json but not registered in any app:\n  swagger: %s\n  app:     %s (not found)", swaggerKey, fiberKey)
			failed = true
		}
	}

	if failed {
		t.Log("To fix: update buildPublicApp/buildAdminApp in main.go and doc.go, then run generate-swagger.sh to regenerate swagger/swagger.json.")
	}
}

// fiberRouteKey constructs a canonical "METHOD /path" key from Fiber route fields.
func fiberRouteKey(method, path string) string {
	return strings.ToUpper(method) + " " + path
}

// fiberKeyToSwagger converts a Fiber route key "GET /v1/:dataset/knn" to the
// Swagger/OpenAPI 2.0 path format "GET /v1/{dataset}/knn".
func fiberKeyToSwagger(key string) string {
	method, path, ok := strings.Cut(key, " ")
	if !ok {
		return key
	}
	segs := strings.Split(path, "/")
	for i, seg := range segs {
		if strings.HasPrefix(seg, ":") {
			segs[i] = "{" + seg[1:] + "}"
		}
	}
	return method + " " + strings.Join(segs, "/")
}

// swaggerKeyToFiber converts a Swagger key "GET /v1/{dataset}/knn" to the
// Fiber route format "GET /v1/:dataset/knn".
func swaggerKeyToFiber(key string) string {
	method, path, ok := strings.Cut(key, " ")
	if !ok {
		return key
	}
	segs := strings.Split(path, "/")
	for i, seg := range segs {
		if strings.HasPrefix(seg, "{") && strings.HasSuffix(seg, "}") {
			segs[i] = ":" + seg[1:len(seg)-1]
		}
	}
	return method + " " + strings.Join(segs, "/")
}
