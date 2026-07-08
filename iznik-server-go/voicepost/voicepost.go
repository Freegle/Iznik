// Package voicepost implements a voice-first way to compose a Freegle post.
//
// The browser records the user describing their item and streams the audio to
// the server in small chunks *while they are still talking* (rather than one big
// upload at the end). As each chunk arrives the server re-runs Groq Whisper over
// the audio-so-far and hands the growing transcript back, so the words build up
// on screen with only a few seconds of latency. When the user stops, a final
// transcription plus a quick Groq chat pass tidies the raw transcript into a
// short item title and a friendly description that keeps the person's own words
// and charm.
//
// Design decisions (see docs spec):
//   - Groq is used for BOTH transcription (whisper-large-v3-turbo) and the tidy-up
//     (a small fast llama model). One GROQ_API_KEY. Groq turbo transcribes faster
//     than real time and costs ~$0.0006/min, so re-transcribing the accumulated
//     audio on every chunk is still fractions of a penny per post.
//   - Transport is plain chunked HTTP, not a WebSocket to a real-time STT engine.
//     "Human latency, a few seconds" is the budget, so we don't need (or want to
//     pay for) sub-second streaming recognisers like Deepgram.
//   - The streamed audio is written to a temp file and kept only briefly (the
//     in-flight compose buffer is pruned within the hour). The transcript is the
//     durable artifact. Per the privacy policy, a retained voice recording is
//     kept for at most 90 days (voiceRetentionPolicyDays) and then deleted; this
//     prototype does not yet persist audio beyond the compose session, so that
//     ceiling is a forward-looking commitment rather than something enforced here.
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

// Re-transcribe at most this often per session, so a burst of chunks can't queue
// up a pile of Groq calls. Chunks normally arrive every few seconds anyway.
const minTranscribeInterval = 2500 * time.Millisecond

// session holds the server-side state for one in-progress voice post. Audio is
// appended to a temp file; the latest transcript is cached so chunk responses are
// instant even while a Groq call is in flight.
type session struct {
	mu             sync.Mutex
	id             string
	path           string
	createdAt      time.Time
	transcript     string
	lastTranscribe time.Time
	transcribing   bool
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
//     arrival order — the client sends them serially so order is preserved).
//
// The response is JSON: {"session": "...", "transcript": "..."} where transcript
// is the best transcript we have so far (it grows and sharpens as more audio
// arrives). Partial-transcription failures are swallowed — we just return the last
// good transcript and try again on the next chunk.
//
// No authentication: like image upload, this happens during the give/post flow
// before the user has necessarily logged in. The audio is transient and unlinked.
//
// @Summary Stream a chunk of voice-post audio and get the transcript so far
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
			// Session expired or unknown — start a fresh one so the client can recover.
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

	// Kick off a transcription of the audio-so-far if we're not already doing one
	// and enough time has passed. This runs synchronously for a simple, ordered
	// response, but is cheap (Groq turbo is sub-second for short clips).
	s.mu.Lock()
	due := !s.transcribing && time.Since(s.lastTranscribe) >= minTranscribeInterval
	if due {
		s.transcribing = true
	}
	path := s.path
	s.mu.Unlock()

	if due {
		text, err := transcribe(path)
		s.mu.Lock()
		s.transcribing = false
		s.lastTranscribe = time.Now()
		if err == nil && strings.TrimSpace(text) != "" {
			s.transcript = text
		}
		s.mu.Unlock()
	}

	s.mu.Lock()
	transcript := s.transcript
	s.mu.Unlock()

	return c.JSON(fiber.Map{
		"session":    s.id,
		"transcript": transcript,
	})
}

// FinishResult is the payload returned when a voice post is finalised.
type FinishResult struct {
	ID          string `json:"id"`
	Transcript  string `json:"transcript"`
	Title       string `json:"title"`
	Description string `json:"description"`
}

// Finish handles POST /api/voicepost/finish. The client calls this once the user
// has stopped talking and the final chunk has been sent. We do one last full
// transcription (so the complete audio is captured, not just up to the last
// partial pass), then a Groq chat pass to tidy the transcript into a title and
// description. The audio file is left in place to be pruned later.
//
// @Summary Finalise a voice post: full transcript + tidied title/description
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
	cached := s.transcript
	s.mu.Unlock()

	// Final, authoritative transcription of the whole file.
	transcript, err := transcribe(path)
	if err != nil || strings.TrimSpace(transcript) == "" {
		// Fall back to the best partial we streamed if the final pass hiccups.
		transcript = cached
	}
	if strings.TrimSpace(transcript) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "We couldn't hear anything in that recording — please try again.")
	}

	s.mu.Lock()
	s.transcript = transcript
	s.mu.Unlock()

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

// transcribe sends the audio file to Groq's Whisper endpoint and returns the text.
// A truncated (mid-recording) webm file decodes fine up to its last complete
// cluster, so this works on the growing partial file as well as the final one.
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

// summarise turns a raw spoken transcript into a short item title and a friendly
// description, preserving the poster's own words and charm. Any failure falls
// back to using the transcript itself so the user is never blocked.
var summarise = func(transcript string) (string, string) {
	key := os.Getenv("GROQ_API_KEY")
	if key == "" {
		return fallbackTitle(transcript), transcript
	}

	system := "You help someone give away an item for free on Freegle, a UK reuse community. " +
		"They spoke a description of their item out loud and it was transcribed. " +
		"Turn the transcript into a short catchy TITLE (just a few words naming the item, no 'OFFER:' prefix) " +
		"and a warm, friendly DESCRIPTION for other freeglers to read. " +
		"Keep the person's own words, voice and charm. Lightly tidy filler words, false starts and obvious " +
		"transcription slips, and fix the item name if it was clearly mis-heard, but do NOT rewrite it into " +
		"corporate or marketing language, and do NOT invent any details (condition, size, colour, collection " +
		"arrangements) that they did not say. Use British English. " +
		"Respond ONLY with JSON: {\"title\": \"...\", \"description\": \"...\"}."

	reqBody, _ := json.Marshal(map[string]interface{}{
		"model": summariseModel(),
		"messages": []map[string]string{
			{"role": "system", "content": system},
			{"role": "user", "content": "Transcript:\n\n" + transcript},
		},
		"temperature":     0.4,
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
