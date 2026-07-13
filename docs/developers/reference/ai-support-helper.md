---
last_reviewed: 2026-07-13
owner: Freegle dev team
covers:
  - claude-agent-sdk/support-agent.js
  - claude-agent-sdk/tools.js
  - claude-agent-sdk/server.js
  - claude-agent-sdk/auth.js
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

## Claude auth: two modes, one code path

`support-agent.js` (`driverMode()`) picks the mode from the environment:

- **api** — `ANTHROPIC_API_KEY` set. Production/edge. No `~/.claude` mount.
- **session** — a read-only `~/.claude` credential mount (a logged-in Claude subscription).
  Testing only. `docker-compose.yml` mounts **just** `.credentials.json`, not the whole
  `~/.claude` (so a prompt-injected read cannot reach memory/transcripts).

`entrypoint.sh` reports which mode is active on startup.

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
