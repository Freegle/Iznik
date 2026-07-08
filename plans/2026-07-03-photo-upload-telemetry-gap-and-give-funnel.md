# Photo-upload telemetry gap + logged-out OFFER give-funnel drop-off

**Date:** 2026-07-03
**Source:** Loki analysis of the logged-out `/give` (OFFER) funnel
**Status:** diagnosis complete; two work items below

---

## TL;DR

- The logged-out OFFER funnel's only solid in-flow drop is **photos → details (~46%)**, and it is
  **mostly first-step qualification** (people who add no photo), **not** a broken step and **not**
  post-upload abandonment.
- A smaller **friction tail** (attempted a photo, no successful upload) is **real and mostly lost**
  (only 2 of 16 recovered), but **cannot be root-caused** because the photo-upload path is a
  **telemetry black box**.
- The earlier "55% abandon at the login modal" conclusion was **wrong** — an artifact of client
  `session_id` not being attached to write events. Corrected below.

---

## Findings (Loki, mobile give flow, 7d / 2d windows)

Mobile order is **give → photos → details → options → whereami** (photos is *first*, before details).

Logged-out funnel, distinct sessions:

| step | sessions | drop |
|---|---|---|
| photos (entry) | 919 | — |
| details | 495 | **−46%** |
| options | 360 | −27% |
| whereami | 356 | ~0% |

**photos → details is the biggest in-flow drop, and it's a photo-qualification filter:**

| photos-step cohort (2d) | uploaded a genuine photo (`POST /apiv2/image`, real TUS file) |
|---|---|
| proceeded to details | **65%** |
| dropped before details | **5%** |

- Uploading is near-deterministic for continuing: **94% of uploaders reach details.**
- Only **6/106** droppers uploaded then left → it is *not* post-upload abandonment.
- ~85% of droppers show **no photo interaction at all** (didn't open the picker) → low intent / not ready.

**Correction to the end-gate ("login modal") claim:** IP+time correlation shows **~83%** of logged-out
whereami-reachers actually post an offer (converters 92%, apparent-"abandoners" 69%). The client-side
"abandon" signal was misleading because **the message-create write is not tagged with the give
`session_id`** (only GET reads and image POSTs are). So the login/email gate is **not** losing half the
posters. Scrap that conclusion.

---

## The blocker: photo-upload failure is uninstrumented

For the friction tail (attempted photo, no upload, dropped):

- **14 of 16 are genuinely lost** (only 2 later uploaded/posted from the same IP).
- **No errors anywhere** — no upload error, timeout event, or 4xx/5xx; one unrelated `CLS` warning.
- **TUS server not in Loki** — no `job_name`/`service_name`/`filename` stream references
  tus/upload/image; `uploads.ilovefreegle.org` appears in **0** client logs.
- **Client emits no upload lifecycle events** — `uppy|tus|upload|xhr|multipart` over 2d = 0 real events.
- Only **success** (`POST /apiv2/image`) is observable; every failure mode (TUS stall, network drop,
  client watchdog) produces **no record**. The "Uppy timed out" signal is just a 30s modal-open timer
  (`OurUploader.vue`), takes no action, and isn't emitted as a usable event.

Consequence: the true photo-upload failure rate is **unmeasurable today**. My 15% is a sparse-interaction
lower bound. This is consistent with the known silent mobile TUS stall
(`project_photo_upload_stall_mobile`: full-res photos, no client resize, TUS over cellular) but
**cannot be proven** from telemetry.

---

## Work item 1 — instrument the upload path (prerequisite to everything)

Without this, the friction tail can't be measured or verified-fixed.

**The Uppy events are ALREADY hooked — they're just `console.log`-only, never shipped.**
`components/OurUploader.vue:411-500` handles `file-added`, `files-added`, `upload` (started),
`upload-progress`, `upload-success`, `error`, `upload-retry`, `upload-stalled`, `restriction-failed`,
`complete` — **every one is `console.log(...)`**, invisible server-side. The ONLY thing shipped is
`dashboard:modal-open` → after 30s → `Sentry.captureMessage('Uppy timed out')` (line 458), which is a
modal-open timer, not a real failure. So today we literally cannot tell **"opened the picker, never
selected a file"** from **"selected a file, upload failed"** — the disambiguating event (`file-added`)
is console-only.

**Fix is cheap — the client-log pipeline already exists.** `composables/useClientLog.js` exports
`action()` (→ POST `/apiv2/clientlog` → Loki `event_type=action`), already used across components
(`OurMessage.vue`, `SpinButton.vue`, …). In OurUploader.vue, replace/augment the `console.log`s with
`action('upload_…', {...})`:

- `file-added` → `upload_file_selected` (**this alone answers the "clicked but never picked" question**)
- `upload` → `upload_started`
- `upload-success` → `upload_succeeded`
- `error` → `upload_failed` {reason}
- `upload-stalled` → `upload_stalled`
- `restriction-failed` → `upload_rejected` {reason} (e.g. type/size)
- Each with: **file size (bytes), file type, `navigator.connection.effectiveType`**, elapsed ms,
  retry count, `session_id` (auto-added by the logger).
- **Make "Uppy timed out" actionable** — fire on a real stalled/aborted upload with a reason, not just
  modal-open-for-30s.
- **Ship the TUS server logs** (`uploads.ilovefreegle.org` / tusd, `TUS_UPLOADER` in nuxt.config.ts) to
  Loki as a new source so server-side stalls/aborts are visible and correlatable by session.

## Work item 2 — reduce the failure (matches the known bug)

- **Client-side resize/compress before TUS** — cap dimensions/bytes so multi-MB originals aren't pushed
  over cellular (server already downscales to 800px *after* upload in apiv1 `image.php`, too late).
- **Visible progress + real error/retry UX** instead of the silent watchdog.
- Consider **details-before-photos** ordering (A/B): photos is currently the *first* ask, before the
  user has invested anything, which sheds low-commitment visitors; asking them to describe the item
  first may lift photos→details.

---

## Repro / method notes (for whoever picks this up)

- Client funnel: `{source="client", event_type="page_view"}` → per-`session_id` route sets; logged-out
  = first give page has no `user_id`.
- Genuine photo = `{source="api", method="POST"} |= "/apiv2/image"` with `request_body.imgtype=="Message"`
  and `externaluid` = `freegletusd-…`; **carries `session_id`** (exact correlation).
- Completion must be **IP+time** matched to `POST|PUT /apiv2/message` (the write lacks `session_id`), or
  read from the `messages` DB — that's the clean way to get an exact completion rate.
- `count by (session_id)` hits Loki's 500-series cap; pull raw + dedupe in code. Big-regex
  `|~ "id1|id2|…"` filters time out — use indexed labels (`method`, `status_code`) and narrow line filters.
