package deprecation

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

var lokiDir string

// TestMain enables Loki to a temp dir BEFORE any GetLoki() call in this test
// binary, so the misc.GetLoki sync.Once initialises deterministically.
func TestMain(m *testing.M) {
	dir, err := os.MkdirTemp("", "deprecation-loki")
	if err != nil {
		panic(err)
	}
	lokiDir = dir
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	code := m.Run()
	os.RemoveAll(dir)
	os.Exit(code)
}

func TestMarkerSetsHeadersAndPreservesResponse(t *testing.T) {
	app := fiber.New()
	app.Get("/test/:id", Marker("GET /test/:id", "2026-08-01"), func(c *fiber.Ctx) error {
		return c.Status(fiber.StatusTeapot).SendString("body-unchanged")
	})

	req := httptest.NewRequest("GET", "/test/123", nil)
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)

	// Logging is side-effect only: status and body pass through untouched.
	assert.Equal(t, fiber.StatusTeapot, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Equal(t, "body-unchanged", string(body))
	// RFC 8594 headers so external consumers can self-detect.
	assert.Equal(t, "true", resp.Header.Get("Deprecation"))
	assert.Equal(t, "2026-08-01", resp.Header.Get("Sunset"))
}

func TestMarkerLogsPassedEndpointNotFilledPath(t *testing.T) {
	app := fiber.New()
	app.Get("/msg/:id", Marker("GET /msg/:id", "2026-08-01"), func(c *fiber.Ctx) error {
		return c.SendString("ok")
	})

	req := httptest.NewRequest("GET", "/msg/999?webversion=2026-01-02T00:00:00Z", nil)
	req.Header.Set("User-Agent", "FreegleApp/9.9.9")
	_, err := app.Test(req, -1)
	assert.NoError(t, err)

	line := readTodaysLokiLine(t)
	assert.Contains(t, line, `"source":"deprecated_endpoint"`)
	// The registered pattern, never the filled /msg/999.
	assert.Contains(t, line, `"endpoint":"GET /msg/:id"`)
	assert.NotContains(t, line, "/msg/999")
	// Caller identity captured for chase-down.
	assert.Contains(t, line, "FreegleApp/9.9.9")
	assert.Contains(t, line, "2026-01-02T00:00:00Z")
}

func TestListAndGetDeprecatedExposeTheRegistry(t *testing.T) {
	// Registering via Marker is what populates the registry — the set and the
	// logging are the same act, so they can't drift.
	Marker("DELETE /widget/:id", "2026-09-15")

	found := false
	for _, e := range List() {
		if e.Endpoint == "DELETE /widget/:id" {
			assert.Equal(t, "2026-09-15", e.Sunset)
			found = true
		}
	}
	assert.True(t, found, "List() should contain a Marker()'d endpoint")

	// GetDeprecated serves the same registry as JSON for the batch monitor.
	app := fiber.New()
	app.Get("/deprecated", GetDeprecated)
	resp, err := app.Test(httptest.NewRequest("GET", "/deprecated", nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var entries []Entry
	body, _ := io.ReadAll(resp.Body)
	assert.NoError(t, json.Unmarshal(body, &entries))
	var got *Entry
	for i := range entries {
		if entries[i].Endpoint == "DELETE /widget/:id" {
			got = &entries[i]
		}
	}
	assert.NotNil(t, got, "GET /deprecated should list the registered endpoint")
	if got != nil {
		assert.Equal(t, "2026-09-15", got.Sunset)
	}
}

func readTodaysLokiLine(t *testing.T) string {
	t.Helper()
	fname := filepath.Join(lokiDir, "go-api-"+time.Now().Format("2006-01-02")+".log")
	data, err := os.ReadFile(fname)
	assert.NoError(t, err)
	lines := strings.Split(strings.TrimSpace(string(data)), "\n")
	var entry map[string]interface{}
	assert.NoError(t, json.Unmarshal([]byte(lines[len(lines)-1]), &entry))
	return lines[len(lines)-1]
}
