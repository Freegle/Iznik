// Package deprecation flags apiv2 routes that are on the way out and records one
// Loki hit per call, so monitor:deprecated-endpoints (Laravel) can decide when a
// route is safe to retire and, if not, who is still calling it.
//
// The Marker() calls ARE the single source of truth: the registry is exactly the
// set of Marker()'d routes, so the observed set and the hit-logging can never
// drift out of sync. (An endpoint listed for observation but not actually logged
// would falsely read as "safe to retire" — deleting a live endpoint — so keeping
// the two inseparable is a safety property, not just tidiness.) See
// plans/active/api-deprecation-observe.md.
package deprecation

import (
	"sort"
	"sync"

	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// Entry is a registered deprecated endpoint.
type Entry struct {
	Endpoint string `json:"endpoint"` // e.g. "GET /activity" (Fiber route-pattern form)
	Sunset   string `json:"sunset"`   // e.g. "2026-08-01" (ISO date; calls after this are unexpected)
}

var (
	mu       sync.RWMutex
	registry = map[string]Entry{}
)

// Marker registers a deprecated route and returns middleware for it. The
// endpoint ("GET /activity") and sunset date become the source of truth for the
// nightly report (exposed via List / GET /deprecated). The middleware sets the
// RFC 8594 Deprecation + Sunset response headers and logs one hit per call. It
// NEVER alters the response — logging is side-effect only, synchronous (these
// routes are low-traffic by definition, and Loki writes are non-blocking file
// appends).
func Marker(endpoint, sunset string) fiber.Handler {
	mu.Lock()
	registry[endpoint] = Entry{Endpoint: endpoint, Sunset: sunset}
	mu.Unlock()

	return func(c *fiber.Ctx) error {
		c.Set("Deprecation", "true")
		c.Set("Sunset", sunset)
		err := c.Next()
		logHit(c, endpoint)
		return err
	}
}

// List returns every registered deprecated endpoint, sorted by endpoint.
func List() []Entry {
	mu.RLock()
	defer mu.RUnlock()
	out := make([]Entry, 0, len(registry))
	for _, e := range registry {
		out = append(out, e)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Endpoint < out[j].Endpoint })
	return out
}

// GetDeprecated serves the registry as JSON so the batch monitor can read the
// deprecated set + sunset dates without parsing the (generated) OpenAPI spec.
// Deliberately NOT annotated for the OpenAPI spec: go-swagger's swagger:route
// form is fragile, and this is internal batch-facing metadata. Returns a JSON
// array of {endpoint, sunset}. Low-sensitivity (endpoint names + dates only).
func GetDeprecated(c *fiber.Ctx) error {
	return c.JSON(List())
}

// logHit records one deprecated_endpoint Loki line: the endpoint plus best-effort
// caller identity already on the request (no new lookups) so the report's
// "still in use" line is an actual chase-down worklist.
func logHit(c *fiber.Ctx, endpoint string) {
	loki := misc.GetLoki()
	if !loki.IsEnabled() {
		return
	}
	data := map[string]interface{}{
		"endpoint":   endpoint,
		"user_agent": c.Get("User-Agent"),
		"webversion": c.Query("webversion"), // client BUILD_DATE, when sent
		"ip":         c.IP(),
	}
	if uid, _, _ := user.GetJWTFromRequest(c); uid > 0 {
		data["user_id"] = uid
	}
	loki.LogCustom("deprecated_endpoint", map[string]string{"endpoint": endpoint}, data)
}
