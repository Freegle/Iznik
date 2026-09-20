package main

import (
	"encoding/json"
	"os"
	"regexp"
	"sort"
	"strings"
	"testing"
)

// normaliseRoutePath converts a Fiber path param (:name) to OpenAPI path param
// form ({name}) so the two sets can be compared directly.
func normaliseRoutePath(p string) string {
	re := regexp.MustCompile(`:([^/]+)`)
	return re.ReplaceAllString(p, "{$1}")
}

// routeFromSource extracts the method+path pairs registered in newApp() from the
// server.go source file.  It looks for lines of the form:
//
//	v1.Get("/foo", ...)   → GET /v1/foo
//	v1.Post("/bar", ...)  → POST /v1/bar
//	app.Get("/baz", ...)  → GET /baz
//
// This is a textual parse, not AST-based, but it is intentionally simple: the
// format of these registration calls is stable and any drift will show up in the
// test immediately.
func routesFromSource(t *testing.T) map[string]bool {
	t.Helper()
	src, err := os.ReadFile("server.go")
	if err != nil {
		t.Fatalf("swagger_sync: cannot read server.go: %v", err)
	}

	// Match lines like:  v1.Get("/catchment", ...) or app.Get("/health", ...)
	// Capture (receiver, method, path).
	re := regexp.MustCompile(`\b(v1|app)\.(Get|Post|Put|Patch|Delete)\("(/[^"]*)"`)
	routes := make(map[string]bool)
	for _, m := range re.FindAllSubmatch(src, -1) {
		receiver := string(m[1])
		method := strings.ToUpper(string(m[2]))
		path := string(m[3])
		if receiver == "v1" {
			path = "/v1" + path
		}
		// Normalise Fiber :param to {param} for comparison with OpenAPI.
		path = normaliseRoutePath(path)
		routes[method+" "+path] = true
	}
	return routes
}

// routesFromSwagger extracts every method+path pair from swagger/swagger.json.
func routesFromSwagger(t *testing.T) map[string]bool {
	t.Helper()
	raw, err := os.ReadFile("swagger/swagger.json")
	if err != nil {
		t.Fatalf("swagger_sync: cannot read swagger/swagger.json: %v", err)
	}
	var spec struct {
		Paths map[string]map[string]json.RawMessage `json:"paths"`
	}
	if err := json.Unmarshal(raw, &spec); err != nil {
		t.Fatalf("swagger_sync: swagger.json is not valid JSON: %v", err)
	}
	routes := make(map[string]bool)
	for path, methods := range spec.Paths {
		for method := range methods {
			routes[strings.ToUpper(method)+" "+path] = true
		}
	}
	return routes
}

// swaggerAllowlist contains paths that are registered in server.go but are
// intentionally absent from swagger.json (non-API infrastructure endpoints).
var swaggerAllowlist = map[string]bool{
	"GET /swagger":           true,
	"GET /swagger/{filepath}": true,
	// Fiber's Static handler generates a wildcard route that go-swagger does not model.
}

// TestSwaggerSyncWithServer fails if swagger/swagger.json is missing a route
// registered in newApp() or documents a path not registered there.
// /swagger* routes are allowlisted as non-API infrastructure.
func TestSwaggerSyncWithServer(t *testing.T) {
	code := routesFromSource(t)
	spec := routesFromSwagger(t)

	// Routes in source but not swagger (except allowlisted).
	var missing []string
	for r := range code {
		if swaggerAllowlist[r] {
			continue
		}
		if !spec[r] {
			missing = append(missing, r)
		}
	}
	sort.Strings(missing)

	// Routes in swagger but not source — ghosts.
	var ghost []string
	for r := range spec {
		if !code[r] {
			ghost = append(ghost, r)
		}
	}
	sort.Strings(ghost)

	if len(missing) > 0 || len(ghost) > 0 {
		var sb strings.Builder
		sb.WriteString("swagger/swagger.json is out of sync with server.go newApp():\n")
		for _, r := range missing {
			sb.WriteString("  MISSING from swagger: " + r + "\n")
		}
		for _, r := range ghost {
			sb.WriteString("  GHOST in swagger (not in server.go): " + r + "\n")
		}
		sb.WriteString("\nRun generate-swagger.sh or hand-sync swagger/swagger.json to fix.")
		t.Error(sb.String())
	}
}
