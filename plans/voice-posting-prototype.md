# Voice posting prototype

A voice-first way to compose a Freegle OFFER. On mobile the user can choose to
describe their item out loud instead of typing a form; we transcribe it and turn
their words into a post they can review and edit.

Demo route: **`/voicepost`**.

## Flow

```
 /give/mobile  ── A/B assign (mobile only) ──▶  voice cohort → /voicepost
      │                                          control      → /give/mobile/photos (typed form)
      ▼
 /voicepost:  [ Say it ]   [ Type it ] ── Type it ─▶ /give/mobile/photos (existing form)
      │ Say it
      ▼
   photo  ──▶  record (audio streams up while talking)  ──▶  Stop
                                                              │
                       POST /voicepost/chunk (buffer only)    │
                       POST /voicepost/finish ──▶ transcribe ONCE + copy-edit (Groq)
                                                              ▼
                                              review (editable title/desc, playback,
                                              consent) ──▶ post
```

## Design decisions

- **Choice, not forced voice.** The entry is a voice-vs-keyboard choice. "Type it"
  hands off to the existing typed compose flow unchanged.
- **Stream to hide upload latency; transcribe on stop.** Audio streams to the
  server in ~3s chunks *while the user talks* (`POST /voicepost/chunk` just buffers
  it), so when they stop there's almost nothing left to upload. Transcription then
  happens **once**, over the whole recording, on `POST /voicepost/finish`. This is
  simpler and cheaper than per-chunk re-transcription and sidesteps two problems:
  no transcript-ordering races, and no chunk-boundary word splitting (a word spoken
  across a boundary is wholly present in the concatenated file).
- **Groq, not real-time STT.** `whisper-large-v3-turbo` transcribes faster than
  real time at ~$0.0006/min. We deliberately avoided a WebSocket real-time engine
  (Deepgram ~$0.0077/min streaming, ~10× the cost) — the budget is "human latency,
  a few seconds after stop", not sub-second live captions. Not the browser Web
  Speech API either (quality + patchy support).
- **Copy-edit, not rewrite.** A small Groq chat model (`llama-3.1-8b-instant`,
  temperature 0.1) *lightly copy-edits* the transcript: fix capitalisation and
  punctuation, join fragments, drop filler — but keep the person's own words and
  personality ("Hiya", "my nan had it for years") and invent nothing. Plus a short
  plain title.
- **Ephemeral audio.** Buffered to a temp file, pruned quickly; the transcript is
  the durable artifact. Per the privacy policy a *retained* recording would be kept
  at most **90 days** then deleted — the prototype doesn't persist audio beyond the
  compose session.
- **Consent captured, playback deferred.** A "let the person collecting hear my
  voice" toggle is captured; the recipient-side player is intentionally not built.

## A/B experiment (mobile)

`composables/useComposeChoice.js` assigns each mobile user to `voice` or `control`
by a fixed per-user % bucket (`ROLLOUT_PCT`, default 0 = off; `?voice=1`/`?voice=0`
overrides for demos). `pages/give/mobile/index.vue` routes the `voice` cohort to
`/voicepost` and everyone else to the existing form. Exposure and completion are
recorded through the existing bandit endpoints (`$api.bandit.shown/chosen`, uid
`mobile-compose-voice`) so the `abtest` table accumulates comparable shown/action
rates per variant. To run the test, raise `ROLLOUT_PCT`.

## Files

Backend (Go, apiv2):
- `iznik-server-go/voicepost/voicepost.go` (+ `_test.go`) — `Chunk` (buffer) and
  `Finish` (transcribe once + copy-edit), session store, temp-file audio + prune.
- `iznik-server-go/router/routes.go` — `POST /voicepost/{chunk,finish}`.
- `docker-compose.yml` — `GROQ_API_KEY` passthrough to the dev `apiv2` service.

Frontend (Nuxt 3):
- `iznik-nuxt3/pages/voicepost.vue` — choice → photo → record → review → done.
- `iznik-nuxt3/composables/useComposeChoice.js` — experiment assignment + tracking.
- `iznik-nuxt3/pages/give/mobile/index.vue` — experiment gate at the mobile entry.
- `iznik-nuxt3/api/VoicePostAPI.js` + `api/index.js` — the `voicepost` client.
- `iznik-nuxt3/pages/privacy.vue` — §2.1 Groq transcription + 90-day retention.

Config (local only, not committed): `GROQ_API_KEY` in the worktree `.env`.

## Cost / latency

| | Groq turbo (used) | Deepgram Nova-3 streaming |
|---|---|---|
| After stop | ~2–3s (one transcribe + copy-edit) | ~1s (transcript already done) |
| Live captions | none (transcribe on stop) | <300ms word-by-word |
| Price/min | ~$0.0006 | ~$0.0077 |
| 100k posts | ~$30 | ~$230 |

## Verified

- Real TTS clip → accurate transcript → title "Old Wooden Kitchen Chair",
  description **verbatim** ("Hiya… Lovely bit of pine. My nan had it for years…").
- Full browser flow: choice → Say it → photo → record → review → Re-record →
  Type it → existing form. No console errors from the page.

## Not done / next steps

- Wire "post it" and the typed branch into real compose completion (and record the
  control-variant conversion there, not just voice).
- Persistent 90-day audio store + consent-gated recipient player (needs a new
  `messages_audio` mechanism; the attachment pipeline is image-only).
- In-memory session store is single-instance; production needs sticky routing or
  shared storage for the in-flight buffer.
- iOS Safari records `audio/mp4`; confirm Groq handling on-device.
