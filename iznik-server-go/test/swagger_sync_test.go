package test

// swagger_sync_test.go: pure-Go drift guard.
//
// Parses router/routes.go and swagger/swagger.json, then asserts that:
//   - every registered route has a corresponding swagger.json entry, and
//   - every swagger.json path has a registered route.
//
// Run as a normal Go test: go test ./test/ -run TestSwaggerSync
// No database or app server required.

import (
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strings"
	"testing"
)

// swaggerAllowlist contains paths that are intentionally absent from one side.
// Entries are path prefixes. Keep this list minimal: only true infrastructure
// routes that are never going to be in swagger.json belong here.
var swaggerAllowlist = map[string]bool{
	// Swagger UI and spec served by the framework itself.
	"/swagger": true,
	// Internal batch-facing metadata (deprecation.GetDeprecated); deliberately not
	// annotated for the OpenAPI spec since go-swagger's swagger:route form can't
	// carry the endpoint/sunset registry cleanly - see deprecation/deprecation.go.
	"/deprecated": true,
}

// routeGroupPrefixes maps known sub-group variable names to their path prefixes.
// These are sub-groups created inside the main route registration function that
// use a different variable than "rg", so the simple rg.Method() regex does not
// capture their routes. Update this map whenever a new rg.Group() sub-group is
// added to router/routes.go.
var routeGroupPrefixes = map[string]string{
	// rg.Group("/rippling") inside the main loop
	"ripplingAdmin": "/rippling",
	// rg.Group("/config/admin") inside the main loop
	"adminConfig": "/config/admin",
	// rg.Group("/modtools/systemlogs") inside the main loop
	"systemLogsGroup": "/modtools/systemlogs",
	// app.Group("/e/d") - email delivery pixel/click/image
	"delivery": "/e/d",
	// app.Group("/amp") - AMP email reply routes
	"ampGroup": "/amp",
}

// normaliseParam converts Fiber :param and :param? notation to OpenAPI {param} notation.
// Fiber uses :param for required and :param? for optional path segments; OpenAPI uses {param} for both.
func normaliseParam(path string) string {
	re := regexp.MustCompile(`:([A-Za-z0-9_]+)\??`)
	return re.ReplaceAllString(path, `{$1}`)
}

// extractRegisteredRoutes reads routes.go and returns a set of normalised paths.
// It handles the main rg.Method() registrations and known sub-group variables
// defined in routeGroupPrefixes.
func extractRegisteredRoutes(routesFile string) (map[string]bool, error) {
	data, err := os.ReadFile(routesFile)
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", routesFile, err)
	}

	// Match <varname>.Get|Post|Put|Patch|Delete|Head("<path>", ...)
	// The path may be "" (empty string) for sub-group root routes.
	re := regexp.MustCompile(`(\w+)\.(Get|Post|Put|Patch|Delete|Head)\s*\(\s*"([^"]*)"`)
	matches := re.FindAllSubmatch(data, -1)

	paths := make(map[string]bool)
	for _, m := range matches {
		varName := string(m[1])
		rawPath := string(m[3])

		// Determine prefix for this variable.
		prefix := ""
		if varName == "rg" {
			// Main route group — no prefix (swagger.json paths are relative to basePath).
			prefix = ""
		} else if p, ok := routeGroupPrefixes[varName]; ok {
			prefix = p
		} else {
			// Unknown variable (e.g. app.Get for top-level app routes like /shortlink).
			// These don't correspond to API swagger.json entries; skip them unless
			// the path appears directly in swagger.json (it won't typically).
			if !strings.HasPrefix(rawPath, "/") {
				continue
			}
			prefix = ""
		}

		fullPath := prefix + rawPath
		if fullPath == "" {
			// rg.Group root when path is ""; this becomes the prefix itself, already in swagger.
			fullPath = prefix
		}
		if fullPath == "" {
			continue
		}
		normalised := normaliseParam(fullPath)
		paths[normalised] = true
	}
	return paths, nil
}

// extractSwaggerPaths reads swagger.json and returns a set of path strings.
func extractSwaggerPaths(swaggerFile string) (map[string]bool, error) {
	data, err := os.ReadFile(swaggerFile)
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", swaggerFile, err)
	}

	var spec struct {
		Paths map[string]json.RawMessage `json:"paths"`
	}
	if err := json.Unmarshal(data, &spec); err != nil {
		return nil, fmt.Errorf("parsing swagger.json: %w", err)
	}

	paths := make(map[string]bool, len(spec.Paths))
	for p := range spec.Paths {
		paths[p] = true
	}
	return paths, nil
}

// isAllowlisted returns true when path starts with any allowlisted prefix.
func isAllowlisted(path string) bool {
	for prefix := range swaggerAllowlist {
		if strings.HasPrefix(path, prefix) {
			return true
		}
	}
	return false
}

// TestSwaggerSync fails if routes.go and swagger.json have mismatched paths.
//
// It is a pure-Go test: no database, no app server, no fixtures.
func TestSwaggerSync(t *testing.T) {
	routesFile := "../router/routes.go"
	swaggerFile := "../swagger/swagger.json"

	registered, err := extractRegisteredRoutes(routesFile)
	if err != nil {
		t.Fatalf("extracting routes: %v", err)
	}

	documented, err := extractSwaggerPaths(swaggerFile)
	if err != nil {
		t.Fatalf("extracting swagger paths: %v", err)
	}

	// Registered routes missing from swagger.json.
	var missing []string
	for path := range registered {
		if isAllowlisted(path) {
			continue
		}
		if !documented[path] {
			missing = append(missing, path)
		}
	}

	// Swagger paths with no registered route.
	var orphan []string
	for path := range documented {
		if isAllowlisted(path) {
			continue
		}
		if !registered[path] {
			orphan = append(orphan, path)
		}
	}

	if len(missing) > 0 || len(orphan) > 0 {
		var sb strings.Builder
		if len(missing) > 0 {
			sb.WriteString(fmt.Sprintf("\n%d registered route(s) missing from swagger.json:\n", len(missing)))
			for _, p := range missing {
				sb.WriteString("  " + p + "\n")
			}
		}
		if len(orphan) > 0 {
			sb.WriteString(fmt.Sprintf("\n%d swagger.json path(s) with no registered route:\n", len(orphan)))
			for _, p := range orphan {
				sb.WriteString("  " + p + "\n")
			}
		}
		t.Errorf("swagger.json / routes.go drift detected:%s", sb.String())
	}
}
