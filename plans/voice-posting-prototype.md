# Voice posting prototype

A voice-first way to compose a Freegle OFFER. On mobile the user can choose to
describe their item out loud instead of typing a form; we transcribe it and turn
their words into a post they can review and edit.

Demo route: **`/voicepost`**.

## Flow

```
 /give/mobile ── A/B assign (mobile only) ──▶ variant cohort → /voicepost
      │                                        control        → /give/mobile/photos (typed form)
      ▼
 /voicepost:  photo (PhotoUploader, has its own Skip)
      │
      ▼
   choose:  [ Say it ]        [ Type it ] ──▶ /give/mobile/details (typed form; photo kept)
      │ Say it
      ▼
   record (audio streams up while talking) ──▶ Stop
        POST /voicepost/chunk  (buffer only)
        POST /voicepost/finish (transcribe ONCE + copy-edit, Groq)
      ▼
   review (editable title/desc, playback, consent) ──▶ post
```

Photo first, then the choice. The photo lives in the compose store, so "Type it"
carries it straight into the existing typed form (`/give/mobile/details`).
`/voicepost` always shows the choice; the experiment only controls whether the
real give flow routes users here at all.

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
- **No playback to others, so no consent question.** The recipient-side player was
  never built, and the first run showed nobody opted in anyway (0 of 3 posts ticked
  the box). Asking for consent to something that cannot happen is just friction and
  a promise to keep, so the toggle is gone: recordings are never played to anyone
  else, and the review screen simply says so. If a player is ever built, the consent
  question has to come back with it.

## A/B experiment (mobile)

Two things are measured, both through the existing bandit endpoints
(`$api.bandit.shown/chosen`, so the `abtest` table accumulates comparable rates):

1. **Exposure** (uid `mobile-compose-variant`): whether we showed the choice
   variant vs the existing form, and whether they completed a post.
   `composables/useComposeChoice.js` assigns each mobile user to `variant` or
   `control` by a fixed per-user % bucket (`ROLLOUT_PCT`, default 0 = off;
   `?voice=1`/`?voice=0` overrides for demos), and `pages/give/mobile/index.vue`
   routes the `variant` cohort to `/voicepost`, everyone else to the typed form.
   To run the test, raise `ROLLOUT_PCT`.

   **Logged-in only.** The bucket hashes the user id, so logged-out users have
   nothing stable to hash. They are excluded from the experiment entirely (no
   exposure recorded, typed flow unchanged) — see `canBucket()`. They used to be
   enrolled and all hashed `voice:0` → bucket 45 → control, every time, which is
   what invalidated the first run (see Results).
2. **Method** (uid `mobile-compose-method`): of those shown the choice, whether
   they picked `voice` or `keyboard` (recorded on the choice screen).

## Instrumentation (full funnel)

The bandit counters above are just the headline A/B rates. For the full picture,
rich per-session events go through `useClientLog` (`action(name, ctx)` →
`POST /clientlog`, auto-tagged with `session_id` / `user_id` / `url` / timestamp),
so every stage and dimension can be cross-tabulated per session. All events are
prefixed `voicepost_`:

| Event | When | Key context |
|---|---|---|
| `voicepost_entry` | give flow assigns the experiment (active only) | `variant`, `is_mobile` |
| `voicepost_choice_shown` | choice screen shown | `has_photo` |
| `voicepost_method_chosen` | Say it / Type it tapped | `method`, `has_photo` |
| `voicepost_record_start` | recording begins | – |
| `voicepost_record_error` | mic / record failure | `reason` (permission_denied / no_microphone / unsupported / other) |
| `voicepost_transcribed` | review reached | `duration_s`, `transcript_chars`, `title_chars`, `desc_chars`, `rerecord_count` |
| `voicepost_finish_error` | transcription / finish failed | `reason`, `duration_s` |
| `voicepost_played_recording` | first playback of their own recording | – |
| `voicepost_rerecord` | Re-record tapped | `count` |
| `voicepost_posted` | "post it" | `title_edited`, `desc_edited`, `desc_char_delta`, `had_photo`, `played_back`, `rerecord_count`, `seconds_on_review` |
| `voicepost_review_abandoned` | left review without posting | `has_photo`, `rerecord_count` |

Together these answer: how often it was shown, the voice-vs-keyboard split,
drop-off and errors through the funnel, the consent-to-play rate, whether people
trusted the words (edited vs posted as-is, and by how much), and the time/effort
they spent - i.e. whether the feature is actually working.

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

## Results: first run (11–17 July 2026, 10% rollout)

**The headline A/B question was not answered — the run was invalid.** Do not quote
the `abtest` rates for `mobile-compose-variant` from this period (they read control
27.8% vs voice 18.5%). Two independent flaws:

- **Arms measured at different funnel points.** Control recorded *zero* conversions
  until `307ec9eb5` (14 Jul) while its `shown` counter had been climbing since the
  11th, so its rate is a real numerator over an inflated denominator. Voice's
  pre-fix conversions were recorded at review-finish, before the post existed.
- **Logged-out users could never be voice** (fixed here). 403 of 1769 mobile entries
  (23%) were logged out and every one landed in control, so control carried a
  population voice could not contain.

Both are fixed, but **the counters must be reset before the next run** or the old
contaminated totals keep dominating.

What *is* readable, from the `voicepost_` clientlog funnel (Loki, ~14d retention):

| Stage | Count |
|---|---|
| entry (mobile) | 1769 (1677 control / 92 voice) |
| choice shown | 87 |
| method chosen | 85 — **58 keyboard / 27 voice** |
| record start | 16 |
| transcribed | 15 |
| **posted** | **3** |

- **Only ~1/3 pick voice when offered it.** The cheapest, least ambiguous result,
  and it depends on none of the broken counters.
- **Mic permission is a wall**: all 6 `record_error`s were `permission_denied`,
  ~22% of everyone who chose voice.
- **Nobody wanted playback**: `consent_play_voice` false on all 3 posts → the
  consent question has been removed (see Design decisions).
- Recordings are short (median ~11s). The transcript-verbatim fix (`a22fad7d2`)
  is confirmed working: description length matches transcript length after 11 Jul,
  versus the LLM inventing 200+ chars from a 41-char transcript before it.

Three posts is far too little to conclude anything about completion rate.

## Not done / next steps

- **Reset the `abtest` rows for `mobile-compose-variant` / `mobile-compose-method`
  before re-running**, otherwise the invalid first-run totals swamp the new data.
- Persistent 90-day audio store + a recipient player (needs a new `messages_audio`
  mechanism; the attachment pipeline is image-only). This is what would bring back
  the consent question — it must not ship without it.
- In-memory session store is single-instance; production needs sticky routing or
  shared storage for the in-flight buffer.
- iOS Safari records `audio/mp4`; confirm Groq handling on-device.
