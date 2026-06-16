# User Support Data Dump — design

**Date:** 2026-06-15
**Branch:** `feat/user-support-dump`
**Component:** `iznik-server-go` (apiv2)

## Goal

A single support/admin-only Go API endpoint that gathers **everything we hold about
one user** — every database table with a relation to that user, their Loki logs, and
their Sentry errors — into a **per-user SQLite database** and **streams it back over the
API** with a live progress + ETA indicator. It is a pure data dump; it does not
interpret anything. It exists so common support investigations can be answered by
querying one self-contained artifact instead of hand-running ad-hoc DB / Loki / Sentry
queries.

## Endpoint

```
GET /api/modtools/user/:id/dump        (also /apiv2/...)
```

- **Auth:** logged in AND `user.IsAdminOrSupport(myid)`, OR a configured third-party API
  key. If `USERDUMP_API_KEY` is set, a request presenting it via the `X-Dump-Key` header
  or `?key=` is authorised with no login (constant-time compare; disabled when blank).
  Otherwise 401 (anon / bad key) / 403 (logged in, wrong role).
- **Params:**
  - `format` — `framed` (default, progress + ETA, see protocol) or `raw` (plain `.sqlite`
    body for `curl -o user.db`, no progress).
  - `include` — CSV of `db,loki,sentry` (default all). Lets a caller skip slow external
    sources.
  - `since` / `until` — optional bounds (RFC3339 or `<n>d`) for the time-ranged sources
    (Loki, Sentry, logs). Default: DB tables all-time; Loki/Sentry last 90 days
    (retention-bound anyway).
- Validates the target user exists (404 otherwise).
- Writes an audit `logs` row: which support user dumped which target user, when.

## Streaming framing protocol (`format=framed`)

The response body is a stream of length-prefixed frames. Fixed 5-byte header:

| Bytes | Meaning |
|-------|---------|
| 0     | frame type: `0x01` progress · `0x02` data · `0x03` end |
| 1..4  | uint32 big-endian payload length |
| 5..   | payload |

- **Progress (`0x01`)** — UTF-8 JSON, emitted as each section completes:
  `{"phase":"collect","section":"chat_messages","status":"done","done":12,"total":40,`
  `"percent":30.0,"elapsed_ms":8200,"eta_ms":19000,"rows":42}`. `eta_ms` is computed from
  weighted progress (Loki/Sentry weighted higher than a small table) — approximate by
  design.
- **Data (`0x02`)** — a chunk of the finished SQLite file (<=64 KiB). Concatenate all
  data payloads in order to reconstruct `user-<id>.sqlite`.
- **End (`0x03`)** — UTF-8 JSON trailer: `{"bytes":N,"sha256":"...","warnings":[...]}`.

Implemented with fasthttp `SetBodyStreamWriter` so frames flush during the slow build.
A reference decoder client lives in `iznik-server-go/userdump/client/` (renders a
progress bar + ETA, writes the `.db`).

## SQLite artifact

- Driver: `modernc.org/sqlite` (pure Go, **no cgo** — apiv2 runs `go run`, no gcc).
- Built to an `os.CreateTemp` file, deleted after streaming. Nothing persisted server-side.
- One table per data domain. Tables are created **dynamically from the source columns**
  (a generic `*sql.Rows` -> SQLite copy), so the dump tracks the live schema and needs no
  hand-maintained column lists. Numeric/blob affinity mapped from MySQL column types.
- Two meta tables let a consumer discover the dump with one query:
  - `_meta(key,value)` — userid, generated_at, include, warnings count.
  - `_sections(name,status,rows,note,ms)` — every section attempted + outcome.

## Collectors (each isolated; a failure becomes a warning, never aborts the dump)

### 1. DB collector
Runs a list of parameterized extraction specs over `database.DBConn`, a **superset of the
V1 `User::export()`**, explicitly adding what V1 skips: `messages_outcomes/promises/
reneged/edits/drafts/likes/by`, `logs_emails`, `email_tracking(+clicks/images)`,
`users_push_notifications`, `users_phones`, `users_active`, `audits`, `polls_users`,
`users_related`, `merges`, `microactions`, `users_comments` (subject + author),
`users_banned`, `spam_users`, sessions, etc.

Anchor id sets are gathered first, then dependent specs use `IN (...)`:
- chat room ids = `chat_rooms WHERE user1|user2 = ?` UNION `chat_roster WHERE userid = ?`
- message ids  = `messages WHERE fromuser = ?`
- email_tracking ids = `email_tracking WHERE userid = ?`

**Chats include both sides** of every room the user is in (all `chat_messages` for the
anchored chat ids), because support needs the full conversation.

Tables absent from the live schema are skipped (noted); specs that error are recorded as
warnings. Large/unbounded tables (`logs`, `messages_by`) are capped with a generous
`ORDER BY id DESC LIMIT` and the cap is noted in `_sections`.

### 2. Loki collector (`loki.go`)
`user_id` is a **JSON field**, not a Loki label (verified against live Loki). Strategy:
- A: `{app="freegle"} | json | user_id="<id>"` — api, api_headers, email, incoming_mail,
  chat_reply, vector_search.
- B: per email address: `{app="freegle"} |~ "(?i)<email>"`.
- C: harvest `session_id`s from A's api lines, then
  `{app="freegle", source="client"} | json | session_id="<sid>"` — client-side behaviour,
  which carries no user_id (this is the piece previously missed).

Merged + deduped into a `loki_logs(ts, source, line)` table. Reads `LOKI_URL`. Loki
unreachable -> warning, dump still completes.

### 3. Sentry collector (`sentry.go`)
For projects `go, nuxt3, php, capacitor, modtools`, query the Sentry REST API
`/projects/<org>/<project>/issues/?query=user.id:<id>` and `user.email:<email>` (org slug
`SENTRY_ORG_SLUG`, default `freegle`; bearer `SENTRY_AUTH_TOKEN`). Issues -> `sentry_issues`;
latest event per issue (capped) -> `sentry_events`. No token / failure -> warning.

`SENTRY_AUTH_TOKEN` (+ `SENTRY_ORG_SLUG`) must be added to the apiv2 service env in
`docker-compose.yml` (currently only in `.env`).

## Privacy & safety

- Strictly support/admin gated (same check as `systemlogs` / `aiimage.ListReview`).
- Read-only: SELECTs + external GETs only; never writes app data (except the audit row).
- **Secrets excluded** even from support, replaced with `[REDACTED]` (row still shown):
  any column whose name matches `password|passwd|secret|token|credential|apikey|
  api_key|privatekey`, plus `sessions.series`. So `users_logins.credentials`, OAuth
  `secret`s, `sessions.token`, and push tokens are redacted.
- Large binary blobs (image `data`, etc.) are replaced with `[BINARY len=N]` so the dump
  stays small while recording that the data exists.
- Intentionally contains heavy PII (emails, IPs, addresses, both-sides chat content) —
  that is the point of a support tool, and why it is role-gated and never persisted.

## Testing (`iznik-server-go/test/userdump_test.go`)

The status-API runner only runs `./test/...`, so all tests live there.
- Auth: anon -> 401; normal user -> 403.
- `format=raw` build: seed a Support user + a target with a post, a two-sided chat,
  a membership, logs; dump; re-open the returned `.sqlite` and assert
  `users/messages/chat_rooms/chat_messages/memberships/_meta/_sections` tables + row
  counts (both chat sides present).
- Framed protocol: decode the 5-byte-framed stream; assert progress frames carry `eta_ms`,
  data frames reconstruct a valid SQLite, end frame `sha256` matches.
- Redaction: seed `users_logins` Native credentials; assert the column is `[REDACTED]`.
- Loki path: point `LOKI_URL` at an `httptest` server returning canned Loki JSON;
  assert `loki_logs` populated and the dump still completes when Loki errors.
- Sentry path: point the Sentry API base at `httptest`; assert `sentry_issues` populated;
  assert no-token -> warning, dump completes.

## Out of scope (v1)

- No async queue / persistence (single synchronous streamed request).
- No UI button (API only; consumed via the reference client or `curl`).
- No cross-user aggregation.
