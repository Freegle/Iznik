---
last_reviewed: 2026-08-05
owner: Freegle dev team
covers:
  - claude-agent-sdk/support-agent.js
  - claude-agent-sdk/tools.js
  - claude-agent-sdk/server.js
  - claude-agent-sdk/auth.js
  - claude-agent-sdk/device-summary.js
  - iznik-nuxt3/stores/mobile.js
  - iznik-nuxt3/composables/useClientLog.js
  - claude-agent-sdk/referral-mjml.js
  - claude-agent-sdk/referral-email.js
  - iznik-nuxt3/modtools/components/ModSupportAIAssistant.vue
---

# AI Support Helper

The AI Support Helper lets **Support** and **Admin** volunteers investigate a member's
problem by asking questions in natural language, in the ModTools support section. It
drives the Claude Code harness (`@anthropic-ai/claude-agent-sdk`'s `query()`) with a set
of direct-access tools over Freegle's production data, and streams the thinking, tool
calls and answer back to the browser.

> **Design note — no anonymisation.** An earlier version kept PII in the browser and only
> sent counts/booleans to Claude. That was removed. Support volunteers can already access
> confidential member data and reading chats/logs is legitimate debugging, so the tool has
> **direct, de-anonymised** read access. The controls below instead guard against
> *accidental* damage, against *other* (non-support) mods gaining access, and against
> hostile **member data** (prompt injection), and record an **audit trail** of every
> investigation.

## Architecture

```
ModTools (support section)                 Backend container (ai-support-helper)
ModSupportAIAssistant.vue                  server.js  → support-agent.js → tools.js
  │  identify member first                   │
  │  POST /api/log-analysis  (SSE) ──────────┤ verify caller is Support/Admin (auth.js
  │  Authorization: Bearer <mod JWT>         │   → Go API /api/session)
  │  { query, userId }                       │ audit(session) then run query():
  │                                          │   Claude Agent SDK, read-only tools,
  │  ◄── data: {type:'thinking'|'tool'|      │   codebase checkout at /app/codebase
  │        'status'|'result'|'error'} ───────┘
  ▼
  renders streamed transcript + cost/tokens (answer sanitised with DOMPurify)
```

One `query()` code path serves both auth modes (see below); everything else is identical.

## Claude auth: three modes, one code path

`driverMode()` (in `auth.js`) picks the mode from the environment:

- **subscription** — `CLAUDE_CODE_OAUTH_TOKEN` set (a token from `claude setup-token`). Uses a
  Max/Pro subscription and is **headless** - no interactive login and no `~/.claude` mount - so
  the helper can be driven by an automation/subagent.
- **api** — only `ANTHROPIC_API_KEY` set. Metered API billing. Production/edge. No `~/.claude` mount.
- **session** — a read-only `~/.claude` credential mount (a logged-in Claude subscription).
  Testing only. `docker-compose.yml` mounts **just** `.credentials.json`, not the whole
  `~/.claude` (so a prompt-injected read cannot reach memory/transcripts).

**`CLAUDE_CODE_OAUTH_TOKEN` wins when both are set** - this is a headless background job, so it
should run on the subscription rather than metered API spend (consistent with Community News'
`CommunityNewsResearchService`). Because the SDK/CLI bills the API whenever `ANTHROPIC_API_KEY`
is present (the reason `monitor-fsm/run-loop.sh` unsets it), `preferSubscriptionToken()` removes
the key at startup so `query()` actually authenticates with the subscription; `entrypoint.sh`
does the same at the shell level and reports which mode is active on startup.

## Tools

Built in `tools.js` (`buildTools(ctx)`), exposed as an in-process MCP server, plus the
SDK's `Read`/`Grep`/`Glob` confined to the codebase checkout:

| Tool | Purpose |
|------|---------|
| `identify_user` | resolve an email / name / id to a member (must be done first) |
| `get_user_dump` + `query_dump` | pull a per-user SQLite dump (~69 tables + Loki + Sentry, secrets redacted) via the Go API and run SQL against it locally |
| `db_query` | ad-hoc **read-only** SQL against the live DB (guarded — see below) |
| `loki_search` | 3-pass `user_id` JSON-field search, or raw LogQL (window capped) |
| `sentry_search` | recent Sentry issues across the nuxt3/go/capacitor/modtools projects |
| `discourse_search` | search the community forum (bug reports cite topic numbers) |
| `code_history_search` / `git_fixed_already` | grep git history to see if an issue is already fixed |

The investigation playbook (held chat replies, duplicate conversations, purged accounts,
rippling auto-joins, stale-deploy chunks, etc.) lives in the system prompt in
`support-agent.js`.

### What the user dump does and does not contain

`get_user_dump` is served by `iznik-server-go/userdump`, and `?since=` (default 90 days) is
a real bound, not a hint:

- **Chat membership is complete** — every room the member is in. **Message bodies are
  windowed** to the rooms active inside `since`. A moderator is in the roster of every
  Mod2Mod and User2Mod chat on their groups (one real admin: 18,664 rooms, of which 332
  had any activity in 90 days), and pulling every message for all of them could not finish
  inside the caller's timeout — so that member could not be investigated at all.
- **Loki logs are clamped to 30 days** whatever `since` says, because production Loki
  rejects any `query_range` longer than `30d1h` outright.
- **Loki collection runs in value order under a time budget** (`userdump/loki.go`):
  indexed `user_id` label first, then the slim unlabelled sources and email passes
  (each `|=`-prefiltered before any `| json`/regex, in 15-day halves), then two-leg
  session lookups, and finally `api_headers` — the ~67GB/7d firehose — newest-first in
  budget-capped 1.5-day slices. Anything the caps drop is recorded in `_sections` as
  `loki_bounds`. The same prefilter-before-parse rule applies to every LogQL the helper
  or `systemlogs` builds.
- Anything the dump had to bound is recorded in its **`_sections`** table with
  `status='warning'` and a note. Read it before concluding "there is nothing there" — an
  empty table can mean *not collected*, not *did not happen*.
- The helper downloads the dump with **`format=framed`** (see
  `iznik-server-go/userdump/frame.go`): the server flushes a progress frame per section
  plus a 15s heartbeat during long sections, so the prod API LB's 50s idle timeout never
  cuts a slow build the way the silent `format=raw` stream was cut. The client verifies
  the end frame's byte count and SHA-256, and aborts only on 90s of *inactivity* rather
  than a fixed overall deadline.

## Device summary panel

`GET /api/device-summary?userId=` (`server.js`) is a deterministic, no-AI view shown as
soon as a member is selected: their recent devices (browser/OS/viewport, freshness badge)
built from client `session_start` telemetry in Loki (`device-summary.js`). Telemetry is
**best-effort** — the `/clientlog` POST is an unauthenticated fire-and-forget fetch that
ad/tracker blockers routinely eat, and app builds from before client logging never send
it — so a member can be fully active with zero device sessions. When that happens the
endpoint falls back to the member's newest `source="api"` Loki line (`lastApiActivity`)
and the panel says so explicitly, rather than showing a bare "no sessions" that misreads
as "not active".

**Freshness** answers two different questions. For the **web** it is the age of the loaded
bundle (`build_date`): more than a couple of days old means a refresh will update them. For
the **app** it is a version comparison of the member's installed version against
`MOBILE_VERSION` in `iznik-nuxt3/config.js`, which is hand-bumped in step with each app
release. Either input missing yields `unknown`, which shows no badge rather than a wrong one.

**Where the app version comes from.** Only the native app knows its installed version, and
only after Capacitor's `App.getInfo()` returns — long after the client-logging plugin starts.
So the app logs `session_start` **twice** for one session: once immediately (no app version
yet), then again from `stores/mobile.js` `logAppSession()` once `App.getInfo()` and
`Device.getInfo()` have answered. Both carry the same `session_id`, so `dedupeSessions()`
merges them into one record — keeping the session count honest and making the app version
independent of the order Loki returns the lines in.

## Refer to geeks

When a volunteer gets as far as they can, **Refer to geeks** (in
`ModSupportAIAssistant.vue`, shown as soon as there is a conversation) hands the whole
investigation over by email, so nobody has to retype the story.

`POST /api/refer-to-geeks` (Support/Admin gated and audited, like everything else) takes
the member, the device summary, every message, the running totals and the volunteer's
**referral text** — which is required, because a transcript with no statement of what the
volunteer wants doing about it is not a referral. It emails `GEEKS_EMAIL`
(`geeks@ilovefreegle.org`) with **Reply-To set to the referring volunteer**, so a reply
goes back to the person who actually saw the problem.

Every referral gets a short reference — `SR-XXXXX`, generated **server-side** so the client
cannot choose or reuse one. It appears in the subject line, the email body, an
`X-Freegle-Support-Referral` header, the audit trail, and back in the UI for the volunteer
to quote. It is deliberately brief (eight characters, from an alphabet with `0/1/O/I/L`
removed) so it can lead a subject line without crowding out what the referral is about, and
survive being read aloud or retyped.

**No money appears in the email.** The helper runs on a Claude subscription, so the SDK's
`total_cost_usd` is a notional list price nobody is charged (see `billableCostUsd` in
`auth.js`). Token counts are kept — they say how much work the investigation was — but the
dollar figures are neither sent nor rendered.

The email is **MJML** (`referral-mjml.js` builds it, `referral-email.js` compiles and
sends it) and deliberately mirrors the helper's own screen — same header, member chip,
device cards and green-you/blue-assistant chat bubbles — so reading the mail feels like
looking at the tool. Two things follow from that:

- **`referral-mjml.js` has no `require` at all.** CI runs the `claude-agent-sdk` specs
  with no `node_modules`, so everything worth testing lives in the dependency-free module
  and only the MJML compile and the SMTP send need libraries.
- **Message bodies are the HTML the browser already rendered** —
  `DOMPurify.sanitize(marked(...))`, the exact markup that was on screen. That is what
  makes the email a faithful copy rather than a second, subtly different rendering.
  `stripUnsafeHtml()` is a *second* belt over that already-sanitised HTML (script/style/
  frame elements, inline handlers, `javascript:` URLs), not the primary sanitiser.
  Everything else — names, emails, the referral text — is escaped, so a volunteer's typed
  `<script>` shows as visible text and never as markup.

SMTP defaults to the local **mailpit** (`SUPPORT_SMTP_HOST`), so a referral sent while
developing lands at `mailpit.localhost` and never reaches the real geeks list; edge/prod
points `SUPPORT_SMTP_*` at a real relay.

## Security controls

- **Caller gate (`auth.js`)** — only `Support` and `Admin` systemroles may use it; a plain
  `Moderator` or `User` gets 403. The JWT is validated against the Go API `/api/session`
  with a fail-closed timeout, and the caller's id/email are threaded through for the audit.
- **Read-only DB, fail closed (`tools.js` `dbSelect`)** — a dedicated connection runs
  `SET SESSION TRANSACTION READ ONLY, max_execution_time = 15000` **before** the query;
  no root fallback outside localhost/percona.
- **Query guard (`guardSelect`)** — `SELECT`/`WITH` only, single statement, SQL comments
  stripped first (defeats `INTO/**/OUTFILE`), a denylist of write/DoS keywords, a denylist
  of auth-secret **tables** (`sessions`, `users_logins`, `config`, …) and **columns**
  (`credentials`, `token`, `password`, …), and a hard cap on the `LIMIT` value.
- **Prompt-injection defence (`support-agent.js`)** — the system prompt marks everything
  tools return (chat text, names, log lines) as **data, never instructions**; tools are
  read-only (`disallowedTools: Write/Edit/Bash`), file reads are confined to
  `additionalDirectories: [CODEBASE]`.
- **XSS defence (`ModSupportAIAssistant.vue`)** — the answer is markdown that may quote
  member-supplied text verbatim, so `marked()` output is run through `DOMPurify.sanitize`
  before `v-html`.
- **Narrowed credential mount (`docker-compose.yml`)** — session mode exposes only the
  Claude OAuth credential file, not the whole `~/.claude`.
- **Audit trail (`tools.js` `audit()`)** — every session and every `db_query` /
  `get_user_dump` / `query_dump` logs who (mod id/email) investigated whom (target user)
  with what, to stdout (`SUPPORT_AUDIT …`, shippable to Loki) and, durably, to
  `AUDIT_LOG_PATH` on the `ai-support-audit` volume.

## Files

- **Backend**: `claude-agent-sdk/` — `server.js` (SSE endpoint + CORS + auth gate),
  `support-agent.js` (`query()` orchestration + system-prompt playbook), `tools.js`
  (direct-access tools + guards + audit), `auth.js` (Support/Admin verification),
  `Dockerfile` / `entrypoint.sh`.
- **Frontend**: `iznik-nuxt3/modtools/components/ModSupportAIAssistant.vue`.
- **Compose**: the `ai-support-helper` service in `docker-compose.yml` (profile `backend`).
