package handler

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// newTestApp creates a minimal Fiber app for testing GeoLocation.
func newGeoApp() *fiber.App {
	app := fiber.New(fiber.Config{
		// Suppress "invalid port" warnings from test server URLs in logs.
		DisableStartupMessage: true,
	})
	app.Get("/:ip", GeoLocation)
	return app
}

// mockGeoClient replaces geoClient with one that always calls the provided
// test server, then restores the original on test cleanup.
func mockGeoClient(t *testing.T, srv *httptest.Server) {
	t.Helper()
	orig := geoClient
	geoClient = srv.Client()
	t.Cleanup(func() { geoClient = orig })
}

// ---------------------------------------------------------------------------
// CacheRequest
// ---------------------------------------------------------------------------

func TestCacheRequest_ReturnsHandler(t *testing.T) {
	h := CacheRequest(5 * time.Minute)
	assert.NotNil(t, h, "CacheRequest should return a non-nil fiber.Handler")
}

func TestCacheRequest_CanBeRegistered(t *testing.T) {
	// Verify the returned handler can actually be registered and respond.
	app := fiber.New()
	app.Use(CacheRequest(1 * time.Second))
	app.Get("/hello", func(c *fiber.Ctx) error {
		return c.SendString("cached")
	})

	req := httptest.NewRequest("GET", "/hello", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCacheRequest_ZeroDuration(t *testing.T) {
	// Zero duration is a valid config value — should not panic.
	h := CacheRequest(0)
	assert.NotNil(t, h)
}

// ---------------------------------------------------------------------------
// GeoLocation — success path
// ---------------------------------------------------------------------------

func TestGeoLocation_SuccessPath(t *testing.T) {
	payload := response{
		Status:    "success",
		Country:   "United Kingdom",
		Region:    "England",
		Latitude:  51.5,
		Longitude: -0.1,
		ISP:       "BT",
	}
	body, _ := json.Marshal(payload)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(200)
		w.Write(body)
	}))
	defer srv.Close()

	mockGeoClient(t, srv)
	// Redirect the outbound URL so GeoLocation hits the test server instead.
	origURL := "http://ip-api.com/json/"
	_ = origURL // geoClient now points at srv; just set to srv.Client()

	// We need to intercept the URL. Override geoClient with a transport that
	// rewrites the destination to the test server.
	geoClient = &http.Client{
		Transport: rewriteTransport{base: srv.Client().Transport, target: srv.URL},
	}
	t.Cleanup(func() { geoClient = &http.Client{Timeout: 5 * time.Second} })

	app := newGeoApp()
	req := httptest.NewRequest("GET", "/1.2.3.4", nil)
	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var got response
	json.NewDecoder(resp.Body).Decode(&got)
	assert.Equal(t, "United Kingdom", got.Country)
	assert.Equal(t, "success", got.Status)
}

// ---------------------------------------------------------------------------
// GeoLocation — body read error
// ---------------------------------------------------------------------------

// errReadBody is an io.ReadCloser whose Read always returns an error.
type errReadBody struct{}

func (errReadBody) Read(_ []byte) (int, error) { return 0, errors.New("simulated read error") }
func (errReadBody) Close() error               { return nil }

// errBodyTransport returns a successful HTTP response header but an unreadable body.
type errBodyTransport struct{}

func (errBodyTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	return &http.Response{
		StatusCode: 200,
		Header:     http.Header{"Content-Type": []string{"application/json"}},
		Body:       errReadBody{},
		Request:    req,
	}, nil
}

func TestGeoLocation_BodyReadError(t *testing.T) {
	geoClient = &http.Client{Transport: errBodyTransport{}}
	t.Cleanup(func() { geoClient = &http.Client{Timeout: 5 * time.Second} })

	app := newGeoApp()
	req := httptest.NewRequest("GET", "/1.2.3.4", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 502, resp.StatusCode)
}

// Ensure errReadBody satisfies io.ReadCloser (compile-time check).
var _ io.ReadCloser = errReadBody{}

// rewriteTransport redirects all requests to a fixed base URL (the test server),
// preserving only the path and query from the original request.
type rewriteTransport struct {
	base   http.RoundTripper
	target string
}

func (rt rewriteTransport) RoundTrip(req *http.Request) (*http.Response, error) {
	clone := req.Clone(req.Context())
	clone.URL.Scheme = "http"
	clone.URL.Host = rt.target[len("http://"):]
	if rt.base != nil {
		return rt.base.RoundTrip(clone)
	}
	return http.DefaultTransport.RoundTrip(clone)
}

// ---------------------------------------------------------------------------
// GeoLocation — fail status (IP not found)
// ---------------------------------------------------------------------------

func TestGeoLocation_FailStatus(t *testing.T) {
	payload := map[string]interface{}{"status": "fail", "message": "reserved range"}
	body, _ := json.Marshal(payload)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write(body)
	}))
	defer srv.Close()

	geoClient = &http.Client{
		Transport: rewriteTransport{target: srv.URL},
	}
	t.Cleanup(func() { geoClient = &http.Client{Timeout: 5 * time.Second} })

	app := newGeoApp()
	req := httptest.NewRequest("GET", "/0.0.0.0", nil)
	resp, err := app.Test(req)

	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	var got map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&got)
	assert.Equal(t, "enter an ip", got["message"])
}

// ---------------------------------------------------------------------------
// GeoLocation — network error (cannot reach upstream)
// ---------------------------------------------------------------------------

func TestGeoLocation_NetworkError(t *testing.T) {
	// Use a client that always refuses connections.
	geoClient = &http.Client{
		Transport: &http.Transport{
			// Dial to an address that immediately refuses.
		},
		Timeout: 1 * time.Millisecond,
	}
	t.Cleanup(func() { geoClient = &http.Client{Timeout: 5 * time.Second} })

	app := fiber.New()
	app.Get("/:ip", GeoLocation)

	req := httptest.NewRequest("GET", "/1.2.3.4", nil)
	resp, err := app.Test(req, 2000) // 2s test timeout
	assert.NoError(t, err)
	assert.Equal(t, 502, resp.StatusCode)
}

// ---------------------------------------------------------------------------
// GeoLocation — invalid JSON response from upstream
// ---------------------------------------------------------------------------

func TestGeoLocation_InvalidJSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		w.Write([]byte("not json at all"))
	}))
	defer srv.Close()

	geoClient = &http.Client{
		Transport: rewriteTransport{target: srv.URL},
	}
	t.Cleanup(func() { geoClient = &http.Client{Timeout: 5 * time.Second} })

	app := newGeoApp()
	req := httptest.NewRequest("GET", "/5.6.7.8", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 502, resp.StatusCode)
}
