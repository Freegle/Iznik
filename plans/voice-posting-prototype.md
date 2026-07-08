# Voice posting prototype

A voice-first way to compose a Freegle OFFER. Instead of uploading a photo and
filling in a form, the user taps a big microphone, describes their item out loud,
and we turn their words into a post they can review and edit.

Built as a self-contained demo route: **`/voicepost`**.

## How it works

```
 Browser                         apiv2 (Go)                       Groq
 ───────                         ──────────                       ────
 MediaRecorder ──chunk (webm)──▶ append to temp file ──audio──▶  Whisper (turbo)
   (~4s slices)  POST /voicepost/chunk   re-transcribe so-far  ◀──transcript
   live transcript ◀── {transcript} ─────┘
        │
   user taps Stop
        │
        └──POST /voicepost/finish──▶ final transcribe + tidy-up ─▶ Whisper + Llama
                                    ◀── {title, description} ◀──── (JSON)
   review screen (editable) ──▶ post
```

- **Streaming, not one big upload.** The audio is streamed to the server in ~4s
  chunks *while the user is still talking*. Each chunk triggers a re-transcription
  of the audio-so-far, and the growing transcript is streamed back on the chunk
  response, so words build up on screen. When they stop, it's essentially already
  transcribed — only a ~1s tidy-up pass remains.
- **Server-side transcription with Groq.** We deliberately did **not** use a
  real-time streaming STT engine (Deepgram etc.) — those are priced for sub-second
  latency we don't need. The budget is "human latency, a few seconds", so Groq's
  batch Whisper (`whisper-large-v3-turbo`, faster than real time) re-run per chunk
  is the right fit. We also did not use the browser's Web Speech API — quality
  isn't good enough and it isn't consistently supported.
- **Tidy-up keeps the charm.** A small fast Groq chat model (`llama-3.1-8b-instant`)
  turns the raw transcript into a short title and a friendly description,
  preserving the person's own words and voice, without inventing details.
- **Ephemeral audio.** The streamed audio is written to a temp file and pruned
  quickly; the transcript is the durable artifact. Per the privacy policy a
  *retained* voice recording would be kept at most **90 days** then deleted — the
  prototype does not persist audio beyond the compose session.
- **Consent captured, playback deferred.** The review screen has a "Let the person
  collecting hear my voice description" toggle. It is captured but the recipient-
  side player is intentionally **not** built yet.

## Files

Backend (Go, apiv2):
- `iznik-server-go/voicepost/voicepost.go` — `Chunk` + `Finish` handlers, Groq
  transcription + tidy-up, in-memory session store, temp-file storage + prune.
- `iznik-server-go/voicepost/voicepost_test.go` — handler unit tests (Groq stubbed).
- `iznik-server-go/router/routes.go` — registers `POST /voicepost/chunk` and
  `POST /voicepost/finish` (under both `/api` and `/apiv2`).
- `docker-compose.yml` — passes `GROQ_API_KEY` through to the dev `apiv2` service.

Frontend (Nuxt 3):
- `iznik-nuxt3/pages/voicepost.vue` — the `/voicepost` page (record → live
  transcript → review → done), MediaRecorder capture, serialized chunk upload.
- `iznik-nuxt3/api/VoicePostAPI.js` + `api/index.js` — the `voicepost` API client.
- `iznik-nuxt3/pages/privacy.vue` — new section 2.1 on voice descriptions, Groq,
  and 90-day retention.

Config (local only, not committed):
- `GROQ_API_KEY` in the worktree `.env` (gitignored).

## Cost

A typical item description is ~15–45s. Groq `whisper-large-v3-turbo` is ~$0.0006/min.
Because we re-transcribe the growing audio each chunk, a 60s note transcribes roughly
6–7 audio-minutes in total — still **fractions of a penny per post** (~$0.004). The
tidy-up is a few hundred tokens on an 8B model, also negligible. At 100k posts the
whole feature is on the order of **tens of pounds**.

If cost ever matters, transcribe only on `finish` (one pass) instead of per chunk —
that trades the live transcript for ~10× lower spend.

## Verified

- Real speech (TTS "wooden kitchen chair" clip) → Groq transcript accurate →
  tidy-up produced title **"Wobbly Pine Kitchen Chair"** with the charm intact.
- Full browser flow on `/voicepost` (dev-local): record → live state → stop →
  finish → editable review (title, description, playback, raw transcript, consent
  + 90-day/privacy copy) → "post it" confirmation. No console errors.

## Not done / next steps

- Wire "post it" into the real compose flow (photo + community + submit) instead
  of the demo confirmation.
- Build the consent-gated recipient audio player and the persistent 90-day audio
  store + prune job (needs a new `messages_audio`-style mechanism — the existing
  attachment pipeline is image-only).
- Session store is in-memory (single instance); production behind a load balancer
  needs sticky routing or shared storage for the in-flight buffer.
- iOS Safari records `audio/mp4`; verify Groq handling on-device.
