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
// binary, so the misc.GetLoki sync.Once initialises deterministically. Without
// this, whichever test runs first would freeze the singleton's enabled state.
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

func TestMarkerSetsHeaderAndPreservesResponse(t *testing.T) {
	app := fiber.New()
	app.Get("/test/:id", Marker(), func(c *fiber.Ctx) error {
		return c.Status(fiber.StatusTeapot).SendString("body-unchanged")
	})

	req := httptest.NewRequest("GET", "/test/123", nil)
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)

	// Logging is side-effect only: status and body pass through untouched.
	assert.Equal(t, fiber.StatusTeapot, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Equal(t, "body-unchanged", string(body))
	// External consumers can self-detect deprecation.
	assert.Equal(t, "true", resp.Header.Get("Deprecation"))
}

func TestMarkerLogsRoutePatternNotFilledPath(t *testing.T) {
	app := fiber.New()
	app.Get("/test/:id", Marker(), func(c *fiber.Ctx) error {
		return c.SendString("ok")
	})

	req := httptest.NewRequest("GET", "/test/999?webversion=2026-01-02T00:00:00Z", nil)
	req.Header.Set("User-Agent", "FreegleApp/9.9.9")
	_, err := app.Test(req, -1)
	assert.NoError(t, err)

	line := readTodaysLokiLine(t)
	assert.Contains(t, line, `"source":"deprecated_endpoint"`)
	// The route PATTERN, never the filled /test/999.
	assert.Contains(t, line, `"endpoint":"GET /test/:id"`)
	assert.NotContains(t, line, "/test/999")
	// Caller identity captured for chase-down.
	assert.Contains(t, line, "FreegleApp/9.9.9")
	assert.Contains(t, line, "2026-01-02T00:00:00Z")
}

// readTodaysLokiLine returns the last line of today's go-api log file.
func readTodaysLokiLine(t *testing.T) string {
	t.Helper()
	fname := filepath.Join(lokiDir, "go-api-"+time.Now().Format("2006-01-02")+".log")
	data, err := os.ReadFile(fname)
	assert.NoError(t, err)
	lines := strings.Split(strings.TrimSpace(string(data)), "\n")
	// Sanity: the entry is valid JSON.
	var entry map[string]interface{}
	assert.NoError(t, json.Unmarshal([]byte(lines[len(lines)-1]), &entry))
	return lines[len(lines)-1]
}
