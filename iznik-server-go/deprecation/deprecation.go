// Package deprecation flags apiv2 routes that are on the way out and records one
// Loki hit per call, so monitor:deprecated-endpoints (Laravel) can decide when a
// route is safe to retire and, if not, who is still calling it. See
// plans/active/api-deprecation-observe.md.
package deprecation

import (
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// Marker returns middleware for a deprecated route. It sets a Deprecation
// response header (so well-behaved external clients can self-detect) and logs
// one hit. It NEVER alters the response — logging is side-effect only.
//
// The hit is logged synchronously (not in a goroutine): deprecated routes are
// low-traffic by definition, and a synchronous write keeps the behaviour
// deterministic and testable. Loki writes are non-blocking file appends.
func Marker() fiber.Handler {
	return func(c *fiber.Ctx) error {
		c.Set("Deprecation", "true")
		err := c.Next()
		logHit(c)
		return err
	}
}

func logHit(c *fiber.Ctx) {
	loki := misc.GetLoki()
	if !loki.IsEnabled() {
		return
	}
	endpoint, data := buildHitFields(c)
	// endpoint is a label (low cardinality: the bounded set of deprecated
	// routes). Caller identity stays in the JSON body to keep label cardinality
	// low, matching misc/loki.go's convention.
	loki.LogCustom("deprecated_endpoint", map[string]string{"endpoint": endpoint}, data)
}

// buildHitFields derives the route-pattern endpoint label and the best-effort
// caller identity already present on the request. No new lookups: whatever the
// request carries is what we log.
func buildHitFields(c *fiber.Ctx) (string, map[string]interface{}) {
	endpoint := c.Method() + " " + c.Route().Path

	data := map[string]interface{}{
		"endpoint":   endpoint,
		"user_agent": c.Get("User-Agent"),
		"webversion": c.Query("webversion"), // client BUILD_DATE, when sent
		"ip":         c.IP(),
	}
	// user_id if the request is authenticated (same source main.go uses for the
	// request logger).
	if uid, _, _ := user.GetJWTFromRequest(c); uid > 0 {
		data["user_id"] = uid
	}
	return endpoint, data
}
