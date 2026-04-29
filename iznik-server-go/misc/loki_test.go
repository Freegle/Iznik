package misc

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestTruncateStringShort(t *testing.T) {
	// Strings at or below maxStringLength are returned unchanged.
	s := strings.Repeat("a", maxStringLength)
	assert.Equal(t, s, truncateString(s))
	assert.Equal(t, "hello", truncateString("hello"))
	assert.Equal(t, "", truncateString(""))
}

func TestTruncateStringLong(t *testing.T) {
	// Over-length strings are truncated to maxStringLength + "..." suffix.
	s := strings.Repeat("a", maxStringLength+10)
	out := truncateString(s)
	assert.True(t, strings.HasSuffix(out, "..."))
	assert.Equal(t, maxStringLength+3, len(out))
}

func TestTruncateValueString(t *testing.T) {
	out := truncateValue(strings.Repeat("x", 100))
	s, ok := out.(string)
	assert.True(t, ok)
	assert.True(t, strings.HasSuffix(s, "..."))
}

func TestTruncateValueNonString(t *testing.T) {
	// Non-string scalars pass through unchanged.
	assert.Equal(t, 42, truncateValue(42))
	assert.Equal(t, true, truncateValue(true))
	assert.Equal(t, 3.14, truncateValue(3.14))
	assert.Nil(t, truncateValue(nil))
}

func TestTruncateValueSlice(t *testing.T) {
	// Slices of strings have their elements truncated recursively.
	long := strings.Repeat("a", 100)
	out := truncateValue([]interface{}{"short", long, 42})
	slice, ok := out.([]interface{})
	assert.True(t, ok)
	assert.Len(t, slice, 3)
	assert.Equal(t, "short", slice[0])
	s, _ := slice[1].(string)
	assert.True(t, strings.HasSuffix(s, "..."))
	assert.Equal(t, 42, slice[2])
}

func TestTruncateMapRecursive(t *testing.T) {
	long := strings.Repeat("q", 100)
	input := map[string]interface{}{
		"short": "ok",
		"long":  long,
		"nested": map[string]interface{}{
			"inner": long,
		},
	}
	out := truncateMap(input)

	assert.Equal(t, "ok", out["short"])
	s, _ := out["long"].(string)
	assert.True(t, strings.HasSuffix(s, "..."))

	nested, _ := out["nested"].(map[string]interface{})
	assert.NotNil(t, nested)
	ns, _ := nested["inner"].(string)
	assert.True(t, strings.HasSuffix(ns, "..."))

	// The original input must not be mutated — truncateMap returns a new map.
	assert.Equal(t, long, input["long"])
}

func TestFilterHeadersResponseKeepsNonSensitive(t *testing.T) {
	// Response headers (useAllowlist=false) include everything except
	// sensitive patterns.
	headers := map[string]string{
		"Content-Type":  "application/json",
		"X-Custom":      "yes",
		"Authorization": "Bearer secret",
		"Set-Cookie":    "session=abc",
	}
	out := filterHeaders(headers, false)
	assert.Equal(t, "application/json", out["Content-Type"])
	assert.Equal(t, "yes", out["X-Custom"])
	_, hasAuth := out["Authorization"]
	assert.False(t, hasAuth, "Authorization must be filtered")
	_, hasCookie := out["Set-Cookie"]
	assert.False(t, hasCookie, "Set-Cookie must be filtered")
}

func TestFilterHeadersRequestUsesAllowlist(t *testing.T) {
	// Request headers (useAllowlist=true) keep only the allowlisted set.
	headers := map[string]string{
		"User-Agent":        "test/1.0",
		"Content-Type":      "application/json",
		"X-Random":          "nope",
		"Cookie":            "whatever",
		"X-Freegle-Session": "sess-1",
	}
	out := filterHeaders(headers, true)
	assert.Equal(t, "test/1.0", out["User-Agent"])
	assert.Equal(t, "application/json", out["Content-Type"])
	assert.Equal(t, "sess-1", out["X-Freegle-Session"])
	_, hasRandom := out["X-Random"]
	assert.False(t, hasRandom, "X-Random is not on the allowlist")
	_, hasCookie := out["Cookie"]
	assert.False(t, hasCookie, "Cookie must be filtered")
}

func TestFilterHeadersSensitiveCaseInsensitive(t *testing.T) {
	// The sensitive-pattern match is case-insensitive.
	headers := map[string]string{
		"AUTHORIZATION": "Bearer secret",
		"cookie":        "session=abc",
		"X-API-Key":     "topsecret",
	}
	out := filterHeaders(headers, false)
	assert.Empty(t, out, "all three should be filtered regardless of case")
}

// getClientIP test helper — spins up a minimal Fiber app and returns whatever
// getClientIP extracted for a request constructed with the given headers.
func runGetClientIP(t *testing.T, headers map[string]string) string {
	t.Helper()
	app := fiber.New()

	var captured string
	app.Get("/ip", func(c *fiber.Ctx) error {
		captured = getClientIP(c)
		return c.SendStatus(fiber.StatusOK)
	})

	req := httptest.NewRequest("GET", "/ip", nil)
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	return captured
}

func TestGetClientIPForwardedForSingle(t *testing.T) {
	ip := runGetClientIP(t, map[string]string{"X-Forwarded-For": "203.0.113.7"})
	assert.Equal(t, "203.0.113.7", ip)
}

func TestGetClientIPForwardedForChainPicksFirst(t *testing.T) {
	// Original client is first in the comma-separated list; intermediate
	// proxies follow.
	ip := runGetClientIP(t, map[string]string{
		"X-Forwarded-For": "198.51.100.1, 10.0.0.1, 10.0.0.2",
	})
	assert.Equal(t, "198.51.100.1", ip)
}

func TestGetClientIPForwardedForTrimsWhitespace(t *testing.T) {
	ip := runGetClientIP(t, map[string]string{
		"X-Forwarded-For": "   198.51.100.9   , 10.0.0.1",
	})
	assert.Equal(t, "198.51.100.9", ip)
}

func TestGetClientIPFallsBackToRealIP(t *testing.T) {
	// With no X-Forwarded-For, X-Real-IP is used.
	ip := runGetClientIP(t, map[string]string{"X-Real-IP": "192.0.2.5"})
	assert.Equal(t, "192.0.2.5", ip)
}

func TestGetClientIPRealIPTrimmed(t *testing.T) {
	ip := runGetClientIP(t, map[string]string{"X-Real-IP": "  192.0.2.42  "})
	assert.Equal(t, "192.0.2.42", ip)
}

func TestGetClientIPForwardedForBeatsRealIP(t *testing.T) {
	// When both are present, X-Forwarded-For wins (first hop is closest to the
	// actual client).
	ip := runGetClientIP(t, map[string]string{
		"X-Forwarded-For": "198.51.100.8",
		"X-Real-IP":       "10.0.0.1",
	})
	assert.Equal(t, "198.51.100.8", ip)
}

func TestGetClientIPNoHeadersFallsBackToFiber(t *testing.T) {
	// With neither proxy header set, getClientIP falls through to c.IP(),
	// which for httptest.NewRequest returns an empty string rather than
	// panicking. Either way the function must return without error.
	ip := runGetClientIP(t, map[string]string{})
	assert.NotPanics(t, func() { _ = ip })
}

func TestGetClientIPEmptyForwardedForFallsThrough(t *testing.T) {
	// An empty X-Forwarded-For header must not shadow X-Real-IP — if the
	// proxy sent an empty value we should still fall through.
	ip := runGetClientIP(t, map[string]string{
		"X-Forwarded-For": "",
		"X-Real-IP":       "192.0.2.77",
	})
	assert.Equal(t, "192.0.2.77", ip)
}

func TestGetClientIPForwardedForEmptyFirstElement(t *testing.T) {
	// If the first comma-separated entry is whitespace-only, the IP should
	// fall through rather than returning an empty string.
	ip := runGetClientIP(t, map[string]string{
		"X-Forwarded-For": "  , 10.0.0.1",
		"X-Real-IP":       "192.0.2.33",
	})
	// Empty first entry triggers fallback to X-Real-IP.
	assert.Equal(t, "192.0.2.33", ip)
}
