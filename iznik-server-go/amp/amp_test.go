package amp

import (
	"net/http/httptest"
	"os"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestComputeHMACKnownVector(t *testing.T) {
	// HMAC-SHA256("hello", "key") — standard reference vector.
	got := computeHMAC("hello", "key")
	assert.Equal(t, "9307b3b915efb5171ff14d8cb55fbcc798c6c0ef1456d66ded1a6aa723a58b7b", got)
}

func TestComputeHMACDeterministic(t *testing.T) {
	// Same inputs must produce the same output every time.
	a := computeHMAC("message", "secret")
	b := computeHMAC("message", "secret")
	assert.Equal(t, a, b)
}

func TestComputeHMACDifferentSecretsDiffer(t *testing.T) {
	// Different secrets must produce different signatures for the same message.
	assert.NotEqual(t,
		computeHMAC("same-message", "secret-A"),
		computeHMAC("same-message", "secret-B"),
	)
}

func TestIsAllowedSenderAllowedDomains(t *testing.T) {
	allowed := []string{
		"alice@ilovefreegle.org",
		"bot@users.ilovefreegle.org",
		"noreply@mail.ilovefreegle.org",
		"tester@gmail.dev",
	}
	for _, addr := range allowed {
		assert.True(t, isAllowedSender(addr), "expected %s to be allowed", addr)
	}
}

func TestIsAllowedSenderRejectsOtherDomains(t *testing.T) {
	rejected := []string{
		"alice@example.com",
		"attacker@ilovefreegle.com",     // wrong TLD
		"fake@notilovefreegle.org",      // suffix trick
		"someone@mail.ilovefreegle.com", // subdomain + wrong TLD
		"",                              // empty string
	}
	for _, addr := range rejected {
		assert.False(t, isAllowedSender(addr), "expected %s to be rejected", addr)
	}
}

func TestIsAllowedSenderIsCaseInsensitive(t *testing.T) {
	// Matcher lowercases the input before comparing.
	assert.True(t, isAllowedSender("MixedCase@ILoveFreegle.ORG"))
	assert.True(t, isAllowedSender("USER@USERS.ILOVEFREEGLE.ORG"))
}

func TestGetAMPSecretPrefersAMPSecret(t *testing.T) {
	// If both are set, AMP_SECRET wins.
	os.Setenv("AMP_SECRET", "primary")
	os.Setenv("FREEGLE_AMP_SECRET", "fallback")
	defer os.Unsetenv("AMP_SECRET")
	defer os.Unsetenv("FREEGLE_AMP_SECRET")

	assert.Equal(t, "primary", getAMPSecret())
}

func TestGetAMPSecretFallsBackToFreegleVar(t *testing.T) {
	// With AMP_SECRET unset, FREEGLE_AMP_SECRET is used.
	os.Unsetenv("AMP_SECRET")
	os.Setenv("FREEGLE_AMP_SECRET", "fallback-only")
	defer os.Unsetenv("FREEGLE_AMP_SECRET")

	assert.Equal(t, "fallback-only", getAMPSecret())
}

func TestGetAMPSecretEmptyWhenNeitherSet(t *testing.T) {
	os.Unsetenv("AMP_SECRET")
	os.Unsetenv("FREEGLE_AMP_SECRET")
	assert.Equal(t, "", getAMPSecret())
}

// --- AMPCORSMiddleware tests ---

// newTestApp wires the middleware up to a trivial GET/OPTIONS handler so we can
// exercise its response-header behaviour without hitting any real route logic.
func newTestApp() *fiber.App {
	app := fiber.New()
	app.Use(AMPCORSMiddleware())
	app.Get("/x", func(c *fiber.Ctx) error { return c.SendString("ok") })
	app.Post("/x", func(c *fiber.Ctx) error { return c.SendString("ok") })
	return app
}

func TestAMPCORSMiddlewareV2AllowedSender(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/x", nil)
	req.Header.Set("AMP-Email-Sender", "amp@ilovefreegle.org")

	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "amp@ilovefreegle.org", resp.Header.Get("AMP-Email-Allow-Sender"))
	assert.Contains(t, resp.Header.Get("Access-Control-Expose-Headers"), "AMP-Email-Allow-Sender")
}

func TestAMPCORSMiddlewareV2ForbiddenSender(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/x", nil)
	req.Header.Set("AMP-Email-Sender", "spam@example.com")

	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAMPCORSMiddlewareV1AllowedSender(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/x?__amp_source_origin=sender%40ilovefreegle.org", nil)
	req.Header.Set("Origin", "https://amp.gmail.dev")

	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "https://amp.gmail.dev", resp.Header.Get("Access-Control-Allow-Origin"))
	assert.Equal(t, "sender@ilovefreegle.org", resp.Header.Get("AMP-Access-Control-Allow-Source-Origin"))
}

func TestAMPCORSMiddlewareV1ForbiddenSender(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/x?__amp_source_origin=spam%40example.com", nil)
	req.Header.Set("Origin", "https://attacker.example")

	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestAMPCORSMiddlewareOPTIONSPreflight(t *testing.T) {
	// OPTIONS short-circuits to 204 with preflight headers set.
	app := newTestApp()
	req := httptest.NewRequest("OPTIONS", "/x", nil)
	req.Header.Set("AMP-Email-Sender", "amp@ilovefreegle.org")

	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 204, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Access-Control-Allow-Methods"), "POST")
	assert.Contains(t, resp.Header.Get("Access-Control-Allow-Headers"), "AMP-Email-Sender")
	assert.Equal(t, "86400", resp.Header.Get("Access-Control-Max-Age"))
}

func TestAMPCORSMiddlewareNoAMPHeadersPassesThrough(t *testing.T) {
	// No AMP headers at all — request should still reach the handler.
	app := newTestApp()
	req := httptest.NewRequest("GET", "/x", nil)

	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	// No AMP-specific response headers set in this branch.
	assert.Empty(t, resp.Header.Get("AMP-Email-Allow-Sender"))
	assert.Empty(t, resp.Header.Get("Access-Control-Allow-Origin"))
}
