package adapter

import (
	"context"
	"encoding/base64"
	"testing"

	"github.com/aws/aws-lambda-go/events"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// newTestApp wires a minimal Fiber app with a small set of routes that let us
// exercise Proxy/ProxyWithContext without touching any real handlers.
func newTestApp() *fiber.App {
	app := fiber.New()
	app.Get("/ping", func(c *fiber.Ctx) error {
		return c.SendString("pong")
	})
	app.Post("/echo", func(c *fiber.Ctx) error {
		return c.Status(201).Send(c.Body())
	})
	app.Get("/headered", func(c *fiber.Ctx) error {
		c.Set("X-Custom", "value")
		return c.SendString("ok")
	})
	return app
}

func TestNewReturnsFiberLambda(t *testing.T) {
	app := fiber.New()
	fl := New(app)
	assert.NotNil(t, fl)
	assert.Same(t, app, fl.app, "New must retain the *fiber.App it was given")
}

func TestProxyRoutesGETRequest(t *testing.T) {
	fl := New(newTestApp())

	resp, err := fl.Proxy(events.APIGatewayProxyRequest{
		HTTPMethod: "GET",
		Path:       "/ping",
	})
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "pong", resp.Body)
}

func TestProxyRoutesPOSTWithBody(t *testing.T) {
	// Body must survive the net/http → fasthttp conversion inside adaptor().
	fl := New(newTestApp())

	resp, err := fl.Proxy(events.APIGatewayProxyRequest{
		HTTPMethod: "POST",
		Path:       "/echo",
		Body:       "hello-world",
	})
	assert.NoError(t, err)
	assert.Equal(t, 201, resp.StatusCode)
	assert.Equal(t, "hello-world", resp.Body)
}

func TestProxyCopiesResponseHeaders(t *testing.T) {
	// Headers set by the Fiber handler must round-trip into the APIGW response.
	fl := New(newTestApp())

	resp, err := fl.Proxy(events.APIGatewayProxyRequest{
		HTTPMethod: "GET",
		Path:       "/headered",
	})
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	// MultiValueHeaders is what NewProxyResponseWriter populates.
	found := false
	for k, v := range resp.MultiValueHeaders {
		if k == "X-Custom" {
			for _, s := range v {
				if s == "value" {
					found = true
				}
			}
		}
	}
	// Fall back to single-value map if the library switched representation.
	if !found {
		assert.Equal(t, "value", resp.Headers["X-Custom"])
	} else {
		assert.True(t, found)
	}
}

func TestProxyReturns404ForUnknownRoute(t *testing.T) {
	// Fiber's default 404 behaviour flows through the proxy unchanged.
	fl := New(newTestApp())

	resp, err := fl.Proxy(events.APIGatewayProxyRequest{
		HTTPMethod: "GET",
		Path:       "/does-not-exist",
	})
	assert.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestProxyWithContextRoutesRequest(t *testing.T) {
	// ProxyWithContext is a thin wrapper around EventToRequestWithContext.
	fl := New(newTestApp())

	resp, err := fl.ProxyWithContext(context.Background(), events.APIGatewayProxyRequest{
		HTTPMethod: "GET",
		Path:       "/ping",
	})
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "pong", resp.Body)
}

func TestProxyHandlesBase64EncodedBody(t *testing.T) {
	// APIGW may deliver binary/base64-encoded bodies; the core RequestAccessor
	// decodes them before they reach our adaptor. Verify the round-trip.
	fl := New(newTestApp())

	raw := []byte("binary-bytes")
	encoded := base64.StdEncoding.EncodeToString(raw)

	resp, err := fl.Proxy(events.APIGatewayProxyRequest{
		HTTPMethod:      "POST",
		Path:            "/echo",
		Body:            encoded,
		IsBase64Encoded: true,
	})
	assert.NoError(t, err)
	assert.Equal(t, 201, resp.StatusCode)
	assert.Equal(t, string(raw), resp.Body)
}

