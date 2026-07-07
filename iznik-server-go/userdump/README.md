# userdump — support data dump

Support/Admin-only endpoint that gathers **everything we hold about one user** into
a per-user SQLite database and streams it back. It is a pure data export for support
investigations — it does not interpret anything.

## Endpoint

```
GET /api/modtools/user/:id/dump
```

Authorised either by a logged-in Support or Admin user (`user.IsAdminOrSupport`), or by
a third-party API key — if `USERDUMP_API_KEY` is set, a request presenting it via the
`X-Dump-Key` header or `?key=` is allowed without any login. The key grants full dump
access, so treat it as a secret; the feature is disabled when the env var is empty.
Returns 401 (anonymous / bad key), 403 (logged in but not support/admin), 404 (no such
user).

Query params:

| Param     | Default        | Meaning |
|-----------|----------------|---------|
| `format`  | `framed`       | `framed` = progress+ETA framed stream; `raw` = plain `.sqlite` body |
| `include` | `db,loki,sentry` | CSV of sources to include |
| `since`   | 90 days ago    | RFC3339 or `<n>d`; bounds Loki/Sentry/time-ranged data |
| `until`   | now            | RFC3339 |

## What's in the dump

A SQLite database with one table per data domain, created dynamically from the live
schema (so it always matches the current columns). Plus two discovery tables:

- `_meta(key, value)` — `userid`, `generated_at`, `include`, `warnings` count.
- `_sections(name, status, rows, note, ms)` — every section attempted and its outcome
  (`done` / `warning` / skipped), so you can see at a glance what was collected.

Sources:

- **db** — a superset of the V1 GDPR export: every user-linked table (identity,
  memberships, posts and their child tables, **both sides of every chat**, moderation,
  logs, email tracking, donations, newsfeed/stories/community, location/activity, …).
- **loki_logs** — the user's Loki logs. `user_id` is a JSON field (not a label), so it
  is filtered after `| json`. Three passes: by `user_id` across all sources; by each
  email address (full text); and client-side logs via `session_id`s harvested from the
  api logs (client logs carry no user id).
- **sentry_issues** — Sentry issues affecting the user, by `user.id` and `user.email`
  across the Freegle projects. Requires `SENTRY_AUTH_TOKEN`.

### Excluded / redacted

Secrets are withheld even from support, replaced with `[REDACTED]` (the row still
shows, so its existence is visible): any column named like `password|secret|token|
credential|apikey|privatekey`, plus `sessions.series`. Large binary blobs (image data
etc.) become `[BINARY len=N]` so the dump stays small.

## Framed streaming protocol (`format=framed`)

The body is a sequence of length-prefixed frames. 5-byte header = 1 type byte + uint32
big-endian payload length, then the payload:

| Type | Name     | Payload |
|------|----------|---------|
| 0x01 | progress | JSON `{phase, section, status, done, total, percent, elapsed_ms, eta_ms, rows, message}` |
| 0x02 | data     | a chunk of the SQLite file (concatenate all to rebuild it) |
| 0x03 | end      | JSON `{bytes, sha256, warnings}` |

Progress frames are emitted as each section completes, carrying a live ETA (weighted —
Loki/Sentry count for more than a small table).

## Usage

Framed, with progress + ETA, via the reference client:

```bash
python3 userdump/client/userdump_client.py --base http://localhost:8192 \
    --jwt "<support-jwt>" --user 12345 --out user-12345.sqlite
sqlite3 user-12345.sqlite "SELECT name, status, rows FROM _sections ORDER BY rows DESC"
```

Plain download (no progress):

```bash
curl -s -o user-12345.sqlite \
    "http://localhost:8192/api/modtools/user/12345/dump?format=raw&jwt=<support-jwt>"
```

Example support queries against a dump:

```sql
-- everything we know exists for this user
SELECT name, rows FROM _sections WHERE rows > 0 ORDER BY rows DESC;
-- full conversation history (both sides)
SELECT date, userid, message FROM chat_messages ORDER BY date;
-- recent server-side activity
SELECT ts, source, line FROM loki_logs ORDER BY ts_ns DESC LIMIT 50;
-- errors that hit this user
SELECT project, last_seen, title FROM sentry_issues ORDER BY last_seen DESC;
```

## Environment

- `LOKI_URL` — Loki base (already set for apiv2; default `http://loki:3100`).
- `SENTRY_AUTH_TOKEN`, `SENTRY_ORG_SLUG` (default `freegle`) — Sentry REST API.
- `SENTRY_API_BASE` — overrides the Sentry API root (tests only).
- `USERDUMP_API_KEY` — optional third-party access key (blank = disabled).
