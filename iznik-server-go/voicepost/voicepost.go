// Package voicepost implements a voice-first way to compose a Freegle post.
//
// The browser records the user describing their item and streams the audio to
// the server in small chunks *while they are still talking*, so by the time they
// stop the recording is already buffered on the server and there is no big upload
// to wait for. Only then - once - do we transcribe the complete recording with
// Groq Whisper and run a quick Groq copy-edit that punctuates and lightly tidies
// their words into a title and description without rewriting them.
//
// Design decisions (see docs spec):
//   - Groq for BOTH transcription (whisper-large-v3-turbo) and the copy-edit (a
//     small fast llama model). One GROQ_API_KEY.
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
// buffered recording once, then run a Groq copy-edit to punctuate and lightly
// tidy the transcript into a title and description. The audio file is left in
// place to be pruned later.
//
// @Summary Finalise a voice post: transcribe once, then tidy into title/description
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

	title, description := summarise(transcript)

	return c.JSON(FinishResult{
		ID:          s.id,
		Transcript:  transcript,
		Title:       title,
		Description: description,
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

func summariseModel() string {
	if m := os.Getenv("VOICEPOST_SUMMARISE_MODEL"); m != "" {
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

// summarise lightly copy-edits a raw spoken transcript into a short item title
// and a tidied description, keeping the poster's own words. Any failure falls
// back to using the transcript itself so the user is never blocked.
var summarise = func(transcript string) (string, string) {
	key := os.Getenv("GROQ_API_KEY")
	if key == "" {
		return fallbackTitle(transcript), transcript
	}

	system := "You are lightly copy-editing what someone said out loud while giving away an item for free " +
		"on Freegle, a UK reuse community. Return their OWN words, just cleaned up - this is a copy-edit, " +
		"NOT a rewrite. Do NOT paraphrase, summarise, reword or add anything.\n" +
		"For the DESCRIPTION: keep their exact wording, phrasing and personality (including chatty openers like " +
		"'Hiya' and personal details like 'my nan had it for years'); fix capitalisation and punctuation; join " +
		"fragments into coherent sentences; and drop only obvious filler, false starts and stutters. You may " +
		"correct a word the speech-to-text clearly misheard, but change as little as possible and invent NOTHING " +
		"(no condition, size, colour or collection details they didn't say).\n" +
		"For the TITLE: a short plain name for the item, 2-5 words, using their words - no marketing adjectives " +
		"they didn't use, no 'OFFER:' prefix.\n" +
		"Use British English. Respond ONLY with JSON: {\"title\": \"...\", \"description\": \"...\"}."

	reqBody, _ := json.Marshal(map[string]interface{}{
		"model": summariseModel(),
		"messages": []map[string]string{
			{"role": "system", "content": system},
			{"role": "user", "content": "Transcript:\n\n" + transcript},
		},
		// Low temperature: we want a faithful copy-edit, not a creative rewrite.
		"temperature":     0.1,
		"response_format": map[string]string{"type": "json_object"},
	})

	req, err := http.NewRequest("POST", groqBase+"/chat/completions", bytes.NewReader(reqBody))
	if err != nil {
		return fallbackTitle(transcript), transcript
	}
	req.Header.Set("Authorization", "Bearer "+key)
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return fallbackTitle(transcript), transcript
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		return fallbackTitle(transcript), transcript
	}

	var out struct {
		Choices []struct {
			Message struct {
				Content string `json:"content"`
			} `json:"message"`
		} `json:"choices"`
	}
	if err := json.Unmarshal(rb, &out); err != nil || len(out.Choices) == 0 {
		return fallbackTitle(transcript), transcript
	}

	var parsed struct {
		Title       string `json:"title"`
		Description string `json:"description"`
	}
	if err := json.Unmarshal([]byte(out.Choices[0].Message.Content), &parsed); err != nil {
		return fallbackTitle(transcript), transcript
	}
	title := strings.TrimSpace(parsed.Title)
	desc := strings.TrimSpace(parsed.Description)
	if title == "" {
		title = fallbackTitle(transcript)
	}
	if desc == "" {
		desc = transcript
	}
	return title, desc
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
