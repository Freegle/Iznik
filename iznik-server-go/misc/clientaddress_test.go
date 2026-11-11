package misc

import (
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// clientAddressFrom runs ClientAddress inside a Fiber app configured as main.go configures it
// (ProxyHeader is X-Forwarded-For, which Fiber trusts without validating) and returns what it
// saw for a request carrying the given header lines.
func clientAddressFrom(t *testing.T, headers [][2]string) string {
	t.Helper()

	app := fiber.New(fiber.Config{ProxyHeader: "X-Forwarded-For"})
	var got string
	app.Get("/", func(c *fiber.Ctx) error {
		got = ClientAddress(c)
		return nil
	})

	req := httptest.NewRequest("GET", "/", nil)
	for _, h := range headers {
		req.Header.Add(h[0], h[1])
	}
	if _, err := app.Test(req); err != nil {
		t.Fatalf("request failed: %v", err)
	}

	return got
}

func TestClientAddress_IsTheAddressHaproxyPutInXRealIP(t *testing.T) {
	got := clientAddressFrom(t, [][2]string{{"X-Real-IP", "203.0.113.9"}})
	assert.Equal(t, "203.0.113.9", got)

	got = clientAddressFrom(t, [][2]string{{"X-Real-IP", "  2001:db8::9 "}})
	assert.Equal(t, "2001:db8::9", got, "spaces around the address are dropped")
}

func TestClientAddress_NeverTakesTheLeadingForwardedFor(t *testing.T) {
	// A client can write X-Forwarded-For before it reaches haproxy, and haproxy adds its own line
	// after the client's. haproxy replaces X-Real-IP outright, so only that one is the peer.
	got := clientAddressFrom(t, [][2]string{
		{"X-Forwarded-For", "6.6.6.6, 10.0.0.2"},
		{"X-Real-IP", "203.0.113.9"},
	})
	assert.Equal(t, "203.0.113.9", got)

	got = clientAddressFrom(t, [][2]string{
		{"X-Forwarded-For", "6.6.6.6"},
		{"X-Forwarded-For", "203.0.113.9"},
		{"X-Real-IP", "203.0.113.9"},
	})
	assert.Equal(t, "203.0.113.9", got, "two forwarded lines, the first written by the client")
}

func TestClientAddress_WithoutXRealIPIsTheSocketPeerNotTheForwardedFor(t *testing.T) {
	for _, headers := range [][][2]string{
		{{"X-Forwarded-For", "6.6.6.6"}},
		{{"X-Forwarded-For", "6.6.6.6, 10.0.0.2"}},
		{{"X-Real-IP", ""}, {"X-Forwarded-For", "6.6.6.6"}},
		{{"X-Real-IP", "   "}, {"X-Forwarded-For", "6.6.6.6"}},
		{},
	} {
		got := clientAddressFrom(t, headers)
		assert.NotEmpty(t, got, "the socket peer is always there")
		assert.NotContains(t, got, "6.6.6.6", "X-Forwarded-For is written by the client and is never used: %v", headers)
	}
}
