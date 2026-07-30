# AI Support Helper — rebuild (de-anonymised, direct-access, Claude-Code-feel)

Goal: a standalone web app that feels like Edward talking to Claude Code — streamed
thinking/progress — that **serves support queries autonomously** (no human in the loop).
It **identifies the member first**, then investigates using **direct read-only** access
to DB + Loki + a live code checkout, through **purpose-built tools** that collapse common
investigation sequences into one call (fewer tokens, no timeouts). Keeps the chat UI and
cost display. Drives Claude via **the logged-in Claude Code session for testing** or an
**ANTHROPIC_API_KEY in prod** — architected so both work. Runs on **edge** in prod.

## What changes vs today

Today (`claude-agent-sdk/` + `support-tools/` + `ModSupportAIAssistant.vue`) is built
*around privacy*: the browser holds the JWT, executes fact-queries, and returns only
sanitised counts; a `query-sanitizer` + `pseudonymizer` keep PII from Anthropic.

**Remove entirely** (per instruction — no anonymisation in any form):
- `support-tools/query-sanitizer`, `support-tools/pseudonymizer`, `support-tools/mcp-interface`
  and their docker-compose services (`mcp-query-sanitizer`, `mcp-pseudonymizer`, …).
- The browser-executes-sanitised-facts indirection and all token mapping in the agent + UI.

**Keep / evolve:**
- The Claude-driving loop, streaming of thinking/tool events, and cost tracking (from `claude-agent-sdk/`).
- The chat interface + cost display (from `ModSupportAIAssistant.vue`), minus anonymisation.
- The 30-min code checkout auto-update.

## Architecture (target)

```
Browser (support web app, standalone page)
  1. Identify member first (email / id / name / appleid relay -> pick the user)
  2. Chat; streamed thinking + tool-call progress + running $ cost
        │  SSE (stream) + POST (ask)      caller must be mod/support/admin (JWT -> Go API)
        ▼
Backend container  freegle-support-ai   (evolve claude-agent-sdk/)
  - Claude driver (pluggable):
      * session mode  -> Claude Code CLI, ~/.claude mounted (testing, Max sub)
      * apikey mode   -> Anthropic API with ANTHROPIC_API_KEY (prod)
  - Read-only DB pool  (read-only MySQL user; edge -> prod read replica)
  - Loki read client   (LogQL; local :3100 / prod tunnel :3102, ns timestamps)
  - Live code checkout at /app/codebase (git pull every 30m) — Claude reads to understand behaviour
  - Tools (see below) — each = one investigation recipe, timeout-safe
```

## Tools (map the real support questions -> one-call recipes)

Derived from Jacky / D-A-J support emails. Each tool is read-only, bounded, and
returns real data (no PII stripping).

| Tool | Support question it answers | What it does (one call) |
|------|------------------------------|--------------------------|
| `identify_user` | appleid weirdo addresses; various email addresses | resolve email/id/name → user, **all** their emails (users_emails, incl. appleid relay), account status |
| `user_timeline` | "can't get in / oh dear something went wrong" | Loki logs for a user id (JSON-field, multi-pass), bounded window, newest-first |
| `decode_generic_error` | "oh dear something went wrong" | find the real backend error behind the generic message (Loki by user+time → status/stack) |
| `email_delivery` | not notified / no emails / unsubscribe | bounces, delivery log, email prefs + history |
| `chat_look` | mods: "look at chats for a pattern" | pull a user's chat threads/messages read-only (candidate to later expose to mods self-serve) |
| `message_status` | can't mark Taken / not in bulletin / doesn't appear | message + groups + collection + moderation + outcome |
| `login_session` | can't get into Freegle | session/login logs; ties to authMiddleware read-after-write 401s |
| `photo_upload` | can't load photos | image/upload logs + errors |
| `location_check` | maps put me in the wrong place | postcode/location + history (note: settings-change logging gap) |
| `device_check` | devices that don't play nicely | user-agent / app version / client from logs |
| `fixed_already` | is this a known/fixed bug? | date-aware git check: was a fix merged after the report date? |

(Exact LogQL/SQL come from the monitor-fsm technique extraction — folded in next.)

## Driver abstraction (session vs api-key)

One interface `runClaude({system, messages, tools, onEvent})` with two impls:
- `cli` — spawn Claude Code CLI (`claude -p --output-format stream-json …`), tools as MCP; uses ~/.claude. For local testing on this session.
- `api` — Anthropic Messages API streaming, tools as tool-use; uses ANTHROPIC_API_KEY. For edge/prod.
Selected by env (`SUPPORT_AI_DRIVER=cli|api`). Both emit the same event stream
(thinking / tool_call / tool_result / text / cost) so the UI is identical.

## Auth & safety
- Caller: mod/support/admin only — validate JWT against Go API (unchanged from today).
- DB user is **read-only** (GRANT SELECT); Loki is read-only. No writes anywhere.
- Bounded queries (time windows, LIMIT, label filters first) to avoid Loki/DB timeouts.

## Phases
1. Backend skeleton: driver abstraction (cli+api), SSE event stream, cost. Prove end-to-end with one trivial tool.
2. Tools: read-only DB pool + Loki client + the recipe tools above.
3. Web UI: identify-user-first + chat + streamed thinking + cost (evolve the Vue chat, drop anonymisation).
4. Rip out the pseudonymiser/sanitiser stack + compose services + doc.
5. Test locally via Chrome (WSL, GPU off) against live tunnels.
6. Edge deploy (api-key mode, read replica, Loki prod tunnel).

## Testing
- Local: Chrome in WSL with GPU off, driving the UI against live tunnels; `SUPPORT_AI_DRIVER=cli`.
- Debugging techniques sourced from monitor-fsm (Loki-by-user-id multi-pass, timeout discipline, date-aware git check).

## Recon findings (locked)

- **One Claude path, not two.** The live backend (`claude-agent-sdk/log-analysis.js`) drives Claude
  via `@anthropic-ai/claude-agent-sdk`'s `query()` — the Claude Code harness compiled into an npm lib
  (bundles its own `cli.js`), giving session-resume, streaming (`assistant`→tool_use/text, `result`),
  and cost (`total_cost_usd`/`usage`) for free. It uses the SAME auth path as the real `claude` CLI, so:
  - **session/testing mode**: bind-mount `~/.claude` into the container, leave `ANTHROPIC_API_KEY` unset →
    OAuth Max-subscription auth (this is NOT wired today — no `~/.claude` mount exists; add it).
  - **api/prod mode**: set `ANTHROPIC_API_KEY`.
  - Make it an explicit branch in `checkAuth()`/`entrypoint.sh` (`SUPPORT_AI_DRIVER`), not implicit env precedence.
- **Transport = SSE over one POST** (`onProgress(type,msg)` → `res.write("data: …")` → browser `fetch`+ReadableStream).
  Keep. Events: `thinking` / `tool` / `result` / `error`. Cost/usage ride the `result`.
- **Direct access (rip the proxies):** replace `createLokiTool`→`MCP_INTERFACE_URL` and
  `createDbQueryTool`→`PSEUDONYMIZER_URL` with an in-process **Loki `fetch`** (`http://loki:3100`, ns ts)
  and an in-process **read-only `mysql2` pool**. Codebase reading → the SDK's built-in Read/Grep/Glob via
  `allowedTools` (drop the dead hand-rolled `searchCodebase`). Keep the 30-min git-pull checkout.
- **Keep the SQL guardrails** from `pseudonymizer/db-query.js` (SELECT-only, table/column whitelist,
  forced LIMIT, block dangerous keywords) — fold into the backend; **delete** the tokenisation
  (`pseudonymizeDbRows`/token vault). On edge use a real **read-only MySQL grant** + read replica.
- **Delete:** `support-tools/{query-sanitizer,pseudonymizer,mcp-interface}`, their 3 compose services
  (docker-compose.yml ~1798-1894), `claude-agent-sdk/agent.js` (dead raw-SDK loop + browser-facts design),
  and all `SANITIZER_URL` frontend logic. `agent.js` also holds the old "browser executes canned fact
  queries" design — ensure it does NOT get reintroduced.
- **Keep:** `auth.js verifyModerator` (Go API `/session`, role ∈ {Moderator,Support,Admin}) — MORE important
  now that real PII is returned. Note the `apiv2.localhost` Traefik-hostname routing is worth revisiting.
- **Frontend contract barely changes:** `userId` was already sent un-pseudonymised in every request, so
  removing anonymisation collapses the 4-step send pipeline to "query + selectedUser.id". Surface token
  counts too (captured, currently unshown). Upgrade the single overwritten status line to a **transcript**.

## Tool recipes (concrete, from monitor-fsm / userdump / systemlogs)

**Centrepiece — `get_user_dump(userid, since?)`** → `GET /modtools/user/{id}/dump` (Support/Admin authed,
`iznik-server-go/userdump/`). ONE call streams a self-contained **SQLite DB of 69 user-linked tables**
(messages, both sides of every chat, memberships, spam flags, push tokens, digests, logs_emails, …) **+
the Loki 3-pass logs + Sentry issues**, secrets auto-redacted, default 90-day window (`include=db,loki,sentry`,
`since=Nd`). The AI then runs local SQL against the SQLite (a `query_dump` tool) — replaces dozens of
hand-written queries + two external round-trips, so it's the token/timeout win. Make this the default first move.

**Loki (only useful against PROD):**
- Access: **not** local `:3100` (user_id null there). Prod = Windows SSH tunnel **port 3102 at the WSL
  default-gateway IP**: `GW=$(ip route|awk '/^default/{print $3;exit}')`. ns timestamps. Confirm prod via
  `GET $LOKI/loki/api/v1/label/source/values` (prod has `email,incoming_mail,logs_table`).
- **3-pass user search** (user_id is a JSON body field, NOT a label — canonical impl `userdump/loki.go`):
  A `{app="freegle"} | json | user_id="<id>"`; B `{app="freegle"} |~ "(?i)<email>"`; C (per session_id
  harvested from A's api lines, cap 25) `{app="freegle",source="client"} | json | session_id="<sid>"`.
- **Count, don't dump** (metric/instant query): `sum by (subtype) (count_over_time({app="freegle",source=~"api|client"}[24h]))`.
- **Trace correlation**: `{app="freegle"} | json | trace_id="<uuid>"` (X-Trace-ID ties client→API→email→Sentry).
- **Timeout rule**: label filters first, tight windows (5–20m) for any `|~`/`|json`/text filter; localize
  timestamp with a cheap metric query before widening. A body `|=` across all sources over ~19h times out.

**DB (read-only; live tunnel db-live:$LIVE_DB_PORT, creds in .env / `docker inspect freegle-apiv2-live`):**
- **identify_user by email**: email is in `users_emails` not `users` — `JOIN users_emails ue ON ue.userid=users.id
  WHERE ue.email=?`. **Apple relay**: `@privaterelay.appleid.com` rows have NO flag; pattern-match the domain,
  real login is `users_logins.type='Apple'`.
- **support_snapshot(userid)** one-shot: users(age,lastaccess,bouncing,deleted,forgotten,settings) · users_logins(type,has_pw) ·
  users_emails(preferred,confirmed,bounced) · bounces_emails(reason,permanent) · memberships(role,collection,emailfrequency) ·
  spam_users · logs_emails(subject,status ×10) · users_push_notifications.
- **bounce/delivery decode**: `users.bouncing`=that it's suppressed; `bounces_emails.reason/permanent`=why;
  `logs_emails.status` free-text — SMTP 250 / "queued for delivery" = accepted by recipient MX, NOT a Freegle fault.
- **settings/audit history**: `audits` (Laravel Auditing, model `->save()` events, old/new JSON diff) OR legacy
  `logs` (type/subtype enums incl. PostcodeChange/MailOff/NewslettersOff/Bounce). Caveat: raw `DB::update` bypasses `audits`.
- SQL gotchas: quote `` `GROUPS` `` (reserved); no `LIMIT` inside `IN(...)` subquery.

**decode_generic_error("oh dear something went wrong")** — two distinct surfaces:
- Full-page (`error.vue`): Nuxt fatal boundary → Sentry `source:error-page-mount`+statusCode; SSR stderr/Loki `"SSR error on <method> <url>"`.
- Toast (`SomethingWentWrong.vue`): an `APIError` (failed backend call) → Sentry APIError exception, or trace via `trace_id` into Loki.
- Blind spot: Go API 500s are absent from the access log unless the handler logged before returning.

**fixed_already(symptom, report_date)** — `git log --oneline --since="<report_date - 14d>" -- <suspect path>`;
reason over subjects/diffs both ways (fix OR regression). Timing rule: only `commit_date < report_date` = "already
known"; `>= report_date` = a reactive fix that has since shipped, never "we already knew."

## Decisions (confirmed by Edward)
- **UI stays in ModTools, in the support section** — evolve `ModSupportAIAssistant.vue` in place (keep its
  tab/placement + chat markup + cost), strip anonymisation, make identify-user-first, upgrade the status line
  to a thinking transcript. NOT a standalone app.
- **The backend container runs on edge in prod**; the ModTools support UI calls it (as today, `AI_SUPPORT_URL`).
- **Reading chats is a legitimate part of debugging** and is in scope — `get_user_dump` already includes BOTH
  sides of every chat, so the AI can spot the patterns mods flag. (The separate future idea — mods self-serve
  with "magic once looked at, it disappears" — is NOT part of this build.)
- Read-only throughout (no writes); the AI investigates, the human acts.
