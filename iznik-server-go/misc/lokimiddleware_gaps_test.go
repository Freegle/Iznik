package misc

import (
	"bytes"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// findEntryBySource returns the first log entry whose labels.source matches,
// failing the test if none is found. NewLokiMiddleware writes two entries per
// request (source "api" from LogApiRequestFull, "api_headers" from
// LogApiHeaders) to the same file, so tests must select the right one.
func findEntryBySource(t *testing.T, dir string, source string) map[string]interface{} {
	t.Helper()
	entries := readAllLogEntries(t, dir)
	for _, e := range entries {
		if labelsOf(t, e)["source"] == source {
			return e
		}
	}
	require.Failf(t, "no log entry found", "source=%q among %d entries", source, len(entries))
	return nil
}

func newTraceHeaderApp(t *testing.T, dir string) *fiber.App {
	t.Helper()
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	t.Setenv("LOKI_ENABLED", "true")
	t.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{}))
	t.Cleanup(GetLoki().Flush)
	return app
}

func TestNewLokiMiddleware_TraceAndSessionHeadersPropagated(t *testing.T) {
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Get("/hello", func(c *fiber.Ctx) error { return c.SendString("world") })

	req := httptest.NewRequest("GET", "/hello", nil)
	req.Header.Set("X-Trace-Id", "trace-123")
	req.Header.Set("X-Session-Id", "session-456")
	req.Header.Set("X-Client-Timestamp", "2026-07-24T00:00:00Z")
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	assert.Equal(t, "trace-123", msg["trace_id"])
	assert.Equal(t, "session-456", msg["session_id"])
	assert.Equal(t, "2026-07-24T00:00:00Z", msg["client_timestamp"])
}

func TestNewLokiMiddleware_FreegleContextHeadersPropagated(t *testing.T) {
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Get("/hello", func(c *fiber.Ctx) error { return c.SendString("world") })

	req := httptest.NewRequest("GET", "/hello", nil)
	req.Header.Set("X-Freegle-Session", "fsess")
	req.Header.Set("X-Freegle-Page", "browse")
	req.Header.Set("X-Freegle-Modal", "post")
	req.Header.Set("X-Freegle-Site", "modtools")
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	assert.Equal(t, "fsess", msg["freegle_session"])
	assert.Equal(t, "browse", msg["freegle_page"])
	assert.Equal(t, "post", msg["freegle_modal"])
	assert.Equal(t, "modtools", msg["freegle_site"])
}

func TestNewLokiMiddleware_AbsentTraceHeaders_OmittedFromMessage(t *testing.T) {
	// Covers the false side of each "ok && value != ''" guard: none of the
	// optional trace/session/freegle keys should appear when the headers
	// are simply absent from the request.
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Get("/plain", func(c *fiber.Ctx) error { return c.SendString("ok") })

	req := httptest.NewRequest("GET", "/plain", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	for _, key := range []string{"trace_id", "session_id", "client_timestamp", "freegle_session", "freegle_page", "freegle_modal", "freegle_site"} {
		_, present := msg[key]
		assert.False(t, present, "unexpected key %q in message", key)
	}
}

func TestNewLokiMiddleware_PostBodyCaptured(t *testing.T) {
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Post("/create", func(c *fiber.Ctx) error { return c.SendString("created") })

	req := httptest.NewRequest("POST", "/create", bytes.NewBufferString(`{"name":"chair"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	body, ok := msg["request_body"].(map[string]interface{})
	require.True(t, ok, "request_body must be present for a POST")
	assert.Equal(t, "chair", body["name"])
}

func TestNewLokiMiddleware_GetRequest_NoBodyCaptured(t *testing.T) {
	// GET is not in the POST/PUT/PATCH capture list, so request_body must be
	// entirely absent even though logData only omits it when the map is
	// non-empty (an empty/never-set requestBody stays nil -> omitted).
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Get("/read", func(c *fiber.Ctx) error { return c.SendString("ok") })

	req := httptest.NewRequest("GET", "/read", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	_, present := msg["request_body"]
	assert.False(t, present)
}

func TestNewLokiMiddleware_EmptyUserRole_HeaderNotSet(t *testing.T) {
	// GetUserRole returning a non-nil pointer to an empty string must NOT set
	// X-User-Role — distinct from GetUserRole being nil entirely.
	dir := t.TempDir()
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	t.Setenv("LOKI_ENABLED", "true")
	t.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	empty := ""
	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		GetUserRole: func(c *fiber.Ctx) *string { return &empty },
	}))
	t.Cleanup(GetLoki().Flush)
	app.Get("/role-empty", func(c *fiber.Ctx) error { return c.SendString("ok") })

	req := httptest.NewRequest("GET", "/role-empty", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, "", resp.Header.Get("X-User-Role"))
}

func TestNewLokiMiddleware_NilUserRolePointer_HeaderNotSet(t *testing.T) {
	// GetUserRole configured but returning nil must also not set the header.
	dir := t.TempDir()
	resetLokiSingleton()
	t.Cleanup(resetLokiSingleton)
	t.Setenv("LOKI_ENABLED", "true")
	t.Setenv("LOKI_JSON_PATH", dir)
	t.Cleanup(func() { GetLoki().Drain(); GetLoki().Close() })

	app := fiber.New()
	app.Use(NewLokiMiddleware(LokiMiddlewareConfig{
		GetUserRole: func(c *fiber.Ctx) *string { return nil },
	}))
	t.Cleanup(GetLoki().Flush)
	app.Get("/role-nil", func(c *fiber.Ctx) error { return c.SendString("ok") })

	req := httptest.NewRequest("GET", "/role-nil", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, "", resp.Header.Get("X-User-Role"))
}

func TestNewLokiMiddleware_ResponseBodyCaptured(t *testing.T) {
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Get("/status", func(c *fiber.Ctx) error {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	})

	req := httptest.NewRequest("GET", "/status", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	respBody, ok := msg["response_body"].(map[string]interface{})
	require.True(t, ok, "response_body must be captured for a JSON response")
	assert.Equal(t, "Success", respBody["status"])
}

func TestNewLokiMiddleware_QueryParamsCaptured(t *testing.T) {
	dir := t.TempDir()
	app := newTraceHeaderApp(t, dir)
	app.Get("/search", func(c *fiber.Ctx) error { return c.SendString("ok") })

	req := httptest.NewRequest("GET", "/search?term=chair&page=2", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	GetLoki().Flush()
	msg := messageOf(t, findEntryBySource(t, dir, "api"))
	params, ok := msg["query_params"].(map[string]interface{})
	require.True(t, ok, "query_params must be present when the request has a query string")
	assert.Equal(t, "chair", params["term"])
	assert.Equal(t, "2", params["page"])
}
