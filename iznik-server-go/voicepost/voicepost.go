// Package voicepost implements a voice-first way to compose a Freegle post.
//
// The browser records the user describing their item and streams the audio to
// the server in small chunks *while they are still talking*, so by the time they
// stop the recording is already buffered on the server and there is no big upload
// to wait for. Only then - once - do we transcribe the complete recording with
// Groq Whisper. The transcript is used verbatim as the post description: we do
// NOT run any LLM rewrite of what they said, because in practice it confabulated
// details the speaker never mentioned (invented provenance, condition, etc.). We
// use the LLM for one narrow job only - extracting a short item name for the
// title.
//
// Design decisions (see docs spec):
//   - Groq for transcription (whisper-large-v3-turbo) and, separately, a small
//     fast llama model used ONLY to name the item for the title. One GROQ_API_KEY.
//   - Transport is plain chunked HTTP, not a WebSocket to a real-time STT engine.
//     We stream to hide upload latency, not to get sub-second live captions, so a
//     few seconds of "human latency" after the user stops is the budget.
//   - Transcription happens ONCE, on stop, over the whole buffered recording. So
//     there are no per-chunk re-transcription costs, no transcript-ordering races,
//     and no chunk-boundary word splitting (a word spoken across a boundary is
//     wholly present in the concatenated file).
//   - The buffered audio is a temp file kept only briefly then pruned; the
//     transcript is the durable artifact. Per the privacy policy a *retained*
//     recording would be kept at most 90 days (voiceRetentionPolicyDays); this
//     prototype does not persist audio beyond the compose session.
package voicepost

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/gofiber/fiber/v2"
)

// Retention for the temporary audio files and in-memory sessions. The audio is
// only needed for the life of a single compose flow; anything older is stale.
const sessionRetention = 30 * time.Minute

// voiceRetentionPolicyDays is the maximum time a *retained* voice recording may
// be kept before deletion, as stated in the privacy policy. The prototype does
// not yet persist audio beyond the compose session (see package doc), so this is
// the ceiling a future persistent store must honour rather than a value enforced
// here today.
const voiceRetentionPolicyDays = 90

// session holds the server-side buffer for one in-progress voice post: audio
// chunks are appended to a temp file as they stream in.
type session struct {
	mu        sync.Mutex
	id        string
	path      string
	createdAt time.Time
}

var (
	store   = map[string]*session{}
	storeMu sync.Mutex
)

// dir returns the directory used for temporary audio files, creating it if needed.
func dir() string {
	d := os.Getenv("VOICEPOST_DIR")
	if d == "" {
		d = filepath.Join(os.TempDir(), "voiceposts")
	}
	_ = os.MkdirAll(d, 0o755)
	return d
}

// newID returns a short random hex id used for both the session and the filename.
func newID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// prune removes sessions (and their audio files) older than the retention window.
// Called opportunistically on each new session so we never accumulate stale files.
func prune() {
	storeMu.Lock()
	defer storeMu.Unlock()
	cutoff := time.Now().Add(-sessionRetention)
	for id, s := range store {
		if s.createdAt.Before(cutoff) {
			_ = os.Remove(s.path)
			delete(store, id)
		}
	}
	// Belt and braces: sweep any orphaned files on disk too.
	entries, _ := os.ReadDir(dir())
	for _, e := range entries {
		info, err := e.Info()
		if err == nil && info.ModTime().Before(cutoff) {
			_ = os.Remove(filepath.Join(dir(), e.Name()))
		}
	}
}

func getSession(id string) *session {
	storeMu.Lock()
	defer storeMu.Unlock()
	return store[id]
}

func newSession() *session {
	prune()
	id := newID()
	s := &session{
		id:        id,
		path:      filepath.Join(dir(), id+".webm"),
		createdAt: time.Now(),
	}
	storeMu.Lock()
	store[id] = s
	storeMu.Unlock()
	return s
}

// Chunk handles POST /api/voicepost/chunk.
//
// The audio chunk is the raw request body (application/octet-stream). Query params:
//   - session: the session id; omit on the first chunk and the server creates one.
//   - seq:     client-side sequence number (informational; chunks are appended in
//     arrival order - the client sends them serially so order is preserved).
//
// We do NOT transcribe here. Streaming exists purely to get the audio onto the
// server while the user is still talking, so there is nothing to upload when they
// stop. The response is JSON: {"session": "..."}.
//
// No authentication: like image upload, this happens during the give/post flow
// before the user has necessarily logged in. The audio is transient and unlinked.
//
// @Summary Stream a chunk of voice-post audio into the server buffer
// @Tags VoicePost
// @Accept octet-stream
// @Produce json
// @Param session query string false "Session id (omit on first chunk)"
// @Param seq query int false "Chunk sequence number"
// @Success 200 {object} map[string]interface{}
// @Router /voicepost/chunk [post]
func Chunk(c *fiber.Ctx) error {
	if os.Getenv("GROQ_API_KEY") == "" {
		return fiber.NewError(fiber.StatusServiceUnavailable, "Voice posting is not configured (GROQ_API_KEY missing)")
	}

	sid := c.Query("session")
	var s *session
	if sid == "" {
		s = newSession()
	} else {
		s = getSession(sid)
		if s == nil {
			// Session expired or unknown - start a fresh one so the client can recover.
			s = newSession()
		}
	}

	body := c.Body()
	if len(body) > 0 {
		s.mu.Lock()
		f, err := os.OpenFile(s.path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
		if err == nil {
			_, _ = f.Write(body)
			_ = f.Close()
		}
		s.mu.Unlock()
	}

	return c.JSON(fiber.Map{"session": s.id})
}

// FinishResult is the payload returned when a voice post is finalised.
type FinishResult struct {
	ID          string `json:"id"`
	Transcript  string `json:"transcript"`
	Title       string `json:"title"`
	Description string `json:"description"`
}

// Finish handles POST /api/voicepost/finish. The client calls this once the user
// has stopped talking and the final chunk has been sent. We transcribe the whole
// buffered recording once and return the transcript verbatim as the description;
// the LLM is used only to extract a short item name for the title. The audio file
// is left in place to be pruned later.
//
// @Summary Finalise a voice post: transcribe once, return the transcript as the description with an extracted item title
// @Tags VoicePost
// @Accept json
// @Produce json
// @Param session query string true "Session id"
// @Success 200 {object} FinishResult
// @Router /voicepost/finish [post]
func Finish(c *fiber.Ctx) error {
	if os.Getenv("GROQ_API_KEY") == "" {
		return fiber.NewError(fiber.StatusServiceUnavailable, "Voice posting is not configured (GROQ_API_KEY missing)")
	}

	sid := c.Query("session")
	if sid == "" {
		sid = c.FormValue("session")
	}
	s := getSession(sid)
	if s == nil {
		return fiber.NewError(fiber.StatusNotFound, "Unknown or expired voice-post session")
	}

	s.mu.Lock()
	path := s.path
	s.mu.Unlock()

	transcript, err := transcribe(path)
	if err != nil || strings.TrimSpace(transcript) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "We couldn't hear anything in that recording - please try again.")
	}

	// The description is the transcript verbatim - we never let the LLM rewrite it,
	// because it confabulated details the speaker never said. The LLM is used only
	// to extract a short item name for the title.
	return c.JSON(FinishResult{
		ID:          s.id,
		Transcript:  transcript,
		Title:       itemTitle(transcript),
		Description: transcript,
	})
}

// --- Groq calls -------------------------------------------------------------

// groqBase is the OpenAI-compatible Groq API base. Overridable in tests.
var groqBase = "https://api.groq.com/openai/v1"

func transcribeModel() string {
	if m := os.Getenv("VOICEPOST_TRANSCRIBE_MODEL"); m != "" {
		return m
	}
	return "whisper-large-v3-turbo"
}

func titleModel() string {
	if m := os.Getenv("VOICEPOST_TITLE_MODEL"); m != "" {
		return m
	}
	return "llama-3.1-8b-instant"
}

// transcribe sends the buffered audio file to Groq's Whisper endpoint and returns
// the text. It is a package var so tests can stub it.
var transcribe = func(path string) (string, error) {
	key := os.Getenv("GROQ_API_KEY")
	if key == "" {
		return "", fmt.Errorf("GROQ_API_KEY not set")
	}

	audio, err := os.ReadFile(path)
	if err != nil {
		return "", fmt.Errorf("read audio: %w", err)
	}
	if len(audio) == 0 {
		return "", nil
	}

	var buf bytes.Buffer
	w := multipart.NewWriter(&buf)
	part, err := w.CreateFormFile("file", "audio.webm")
	if err != nil {
		return "", err
	}
	if _, err := part.Write(audio); err != nil {
		return "", err
	}
	_ = w.WriteField("model", transcribeModel())
	_ = w.WriteField("response_format", "json")
	_ = w.WriteField("language", "en")
	_ = w.WriteField("temperature", "0")
	_ = w.Close()

	req, err := http.NewRequest("POST", groqBase+"/audio/transcriptions", &buf)
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+key)
	req.Header.Set("Content-Type", w.FormDataContentType())

	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		return "", fmt.Errorf("groq transcribe status %d: %s", resp.StatusCode, string(rb))
	}

	var out struct {
		Text string `json:"text"`
	}
	if err := json.Unmarshal(rb, &out); err != nil {
		return "", fmt.Errorf("decode transcription: %w", err)
	}
	return strings.TrimSpace(out.Text), nil
}

// itemTitle asks Groq for a short plain name of the item described in the
// transcript - just the item, nothing invented. It deliberately does NOT touch
// the description: the transcript is used verbatim there, because letting the LLM
// rewrite what was said confabulated details the speaker never mentioned. Any
// failure falls back to the first few words of the transcript so the user is
// never blocked.
var itemTitle = func(transcript string) string {
	key := os.Getenv("GROQ_API_KEY")
	if key == "" {
		return fallbackTitle(transcript)
	}

	system := "Someone recorded themselves describing an item they are giving away for free, " +
		"or looking for, on Freegle, a UK reuse community. From their words, give a short plain " +
		"name for the item itself: 2-5 words, British English, using their own words. No 'OFFER:' " +
		"or 'WANTED:' prefix, no marketing adjectives, and invent NOTHING (no condition, size, " +
		"colour, brand or backstory they did not say). If they mention several items, name the main " +
		"one. Respond ONLY with JSON: {\"title\": \"...\"}."

	reqBody, _ := json.Marshal(map[string]interface{}{
		"model": titleModel(),
		"messages": []map[string]string{
			{"role": "system", "content": system},
			{"role": "user", "content": "Transcript:\n\n" + transcript},
		},
		// Zero temperature: we want the literal item name, not a creative one.
		"temperature":     0,
		"response_format": map[string]string{"type": "json_object"},
	})

	req, err := http.NewRequest("POST", groqBase+"/chat/completions", bytes.NewReader(reqBody))
	if err != nil {
		return fallbackTitle(transcript)
	}
	req.Header.Set("Authorization", "Bearer "+key)
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return fallbackTitle(transcript)
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		return fallbackTitle(transcript)
	}

	var out struct {
		Choices []struct {
			Message struct {
				Content string `json:"content"`
			} `json:"message"`
		} `json:"choices"`
	}
	if err := json.Unmarshal(rb, &out); err != nil || len(out.Choices) == 0 {
		return fallbackTitle(transcript)
	}

	var parsed struct {
		Title string `json:"title"`
	}
	if err := json.Unmarshal([]byte(out.Choices[0].Message.Content), &parsed); err != nil {
		return fallbackTitle(transcript)
	}
	title := strings.TrimSpace(parsed.Title)
	if title == "" {
		return fallbackTitle(transcript)
	}
	return title
}

// fallbackTitle derives a rough title from the first few words of the transcript.
func fallbackTitle(transcript string) string {
	words := strings.Fields(transcript)
	if len(words) == 0 {
		return "Item to give away"
	}
	if len(words) > 8 {
		words = words[:8]
	}
	return strings.Join(words, " ")
}
