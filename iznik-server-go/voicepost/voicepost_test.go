package voicepost

import (
	"encoding/json"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// testApp wires just the two voicepost routes onto a bare Fiber app so the
// handlers can be exercised without the full API/DB stack.
func testApp() *fiber.App {
	app := fiber.New()
	app.Post("/api/voicepost/chunk", Chunk)
	app.Post("/api/voicepost/finish", Finish)
	return app
}

// TestChunkAndFinish walks the happy path: a first chunk allocates a session and
// returns the transcript so far, and finish returns the transcript verbatim as
// the description with an LLM-extracted item title. The Groq calls are stubbed
// via the package-level transcribe/itemTitle vars.
func TestChunkAndFinish(t *testing.T) {
	os.Setenv("GROQ_API_KEY", "test-key")
	defer os.Unsetenv("GROQ_API_KEY")

	origT, origIT := transcribe, itemTitle
	defer func() { transcribe = origT; itemTitle = origIT }()
	transcribe = func(path string) (string, error) {
		return "hiya i've got an old chair going free", nil
	}
	itemTitle = func(transcript string) string {
		return "Old Chair"
	}

	app := testApp()

	// First chunk with no session -> the server allocates one.
	req := httptest.NewRequest("POST", "/api/voicepost/chunk", strings.NewReader("fake-audio-bytes"))
	req.Header.Set("Content-Type", "application/octet-stream")
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var chunkResp struct {
		Session string `json:"session"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&chunkResp))
	assert.NotEmpty(t, chunkResp.Session)

	// Finish the same session: this is where transcription happens.
	freq := httptest.NewRequest("POST", "/api/voicepost/finish?session="+chunkResp.Session, nil)
	fresp, err := app.Test(freq, -1)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, fresp.StatusCode)

	var fin FinishResult
	assert.NoError(t, json.NewDecoder(fresp.Body).Decode(&fin))
	assert.Equal(t, chunkResp.Session, fin.ID)
	assert.Equal(t, "Old Chair", fin.Title)
	// The description is the transcript verbatim - no LLM rewrite.
	assert.Equal(t, "hiya i've got an old chair going free", fin.Description)
	assert.Equal(t, "hiya i've got an old chair going free", fin.Transcript)
}

// TestChunkRequiresKey verifies the endpoint refuses to run when unconfigured.
func TestChunkRequiresKey(t *testing.T) {
	os.Unsetenv("GROQ_API_KEY")
	app := testApp()
	req := httptest.NewRequest("POST", "/api/voicepost/chunk", strings.NewReader("x"))
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusServiceUnavailable, resp.StatusCode)
}

// TestFinishUnknownSession verifies a stale/unknown session id is a 404.
func TestFinishUnknownSession(t *testing.T) {
	os.Setenv("GROQ_API_KEY", "test-key")
	defer os.Unsetenv("GROQ_API_KEY")
	app := testApp()
	req := httptest.NewRequest("POST", "/api/voicepost/finish?session=does-not-exist", nil)
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusNotFound, resp.StatusCode)
}

// TestFallbackTitle covers the transcript-derived title used when the LLM item
// extraction is unavailable.
func TestFallbackTitle(t *testing.T) {
	assert.Equal(t, "Item to give away", fallbackTitle(""))
	assert.Equal(t, "one two three", fallbackTitle("one two three"))
	assert.Equal(t, "one two three four five six seven eight",
		fallbackTitle("one two three four five six seven eight nine ten"))
}
