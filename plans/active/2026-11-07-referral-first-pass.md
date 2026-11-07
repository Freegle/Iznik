# Referral first pass: build plan and contracts

Source of decisions: `plans/token-burn/support-helper-flow-through.md` (main checkout). One PR. Status
table in `.claude-session.md` of the main checkout. Revised after an architecture review (2026-11-07).

## Decisions that shape the code (settled by Edward, 2026-11-07 unless noted)

- One bounded first pass per referral: facts gathered by CODE from the member's user dump, judgement
  from ONE tool-less model call. No tool loop, no review turn.
- The first pass never sends anything to anyone. It writes a brief; the referral mail to support carries it.
- **Nothing goes to the member from the case.** Support replies to the referring moderator, who deals with
  the member. The draft reply is written TO THE REFERRING MODERATOR (it may suggest what they could tell
  the member).
- **In ModTools the first pass (facts, draft, confidence, mail history, classification, summary) is
  Support/Admin only.** The referring moderator sees: case status, their own reason, the replies between
  them and Support. Enforced by the Go API with an explicit referrer response struct, not by the UI.
- **The referral mail keeps the full first pass and its Reply-To stays the community's volunteers
  address, as today** (Edward, 2026-11-07, chosen knowing a mail reply can quote the facts to that
  address). Do not change the Reply-To.
- Support and Admin see the case list. The referrer sees only their own case(s), never the list.
- The referrer replies only inside the ModTools case. Mail to them only notifies and links (Reply-To noreply).
- First pass runs on every referral. An optional reason typed by the referrer goes into the brief, as data.
- Brief has a FIXED shape (see "Brief JSON" below). Draft cites the fact section keys it used.
- Mail history (support@ and info@, two years) is STUBBED: an interface in the batch with an
  unconfigured implementation returning "not available: mailbox access is not set up yet". The brief and
  mail say so in one line.
- Screenshots: the referred chat's images (latest 5, excluding held/rejected messages) go to the model.
- Credit never runs out silently: the case shows the pool state at run time, and a pass that could not run
  says why on the case and in the mail, as a plain reason chosen from a fixed list.
- The entry point takes a member and a SOURCE (`{kind:"referral", chatid, referrerid, reason}` today;
  `{kind:"mail", ...}` later for support-mailboxes-fsm.md), not just a chat id.
- No member details, keys or secrets in commits, PR text, tests, logs or docs. Every new table has `id`.

## Decisions from the architecture review (reasons inline)

- **One case per (chat, referrer) while Open.** `support_cases.open_key = "<chatid>-<referrerid>"` while
  Open, NULL once Handled, with a unique index; set by code (MySQL refuses a stored generated column over
  a SET NULL foreign key). A second moderator referring the same chat gets their OWN case and never learns
  another case exists. The same moderator referring again gets their existing open case back, with a
  "Referred again" Referrer message carrying the new reason.
- **A second referrer's case reuses a recent brief**: if a Done case for the same chat is under 24 hours
  old, copy its brief and run id and mark Done without a model call. Reason: tokens, and the facts have not
  changed.
- **Go writes the case in one transaction and queues NO background task for it.** The case row IS the
  queue. Read back on `dbresolver.Write`. A duplicate-key error on open_key means "already open": return
  that case.
- **`support:first-pass` is the ONLY mailer for cases.** Every path that sends the referral mail does
  `UPDATE support_cases SET mailed_at=NOW() WHERE id=? AND mailed_at IS NULL` and spools only if that
  changed a row. The legacy `refer_to_support` task (no caseid; queued before deploy) keeps today's mail.
- **The command sweeps**: first pass disabled -> plain mail now, Skipped "turned off"; Pending older than
  60 minutes -> plain mail, Failed "the AI helper was busy"; Running older than deadline + 5 minutes ->
  plain mail, Failed "interrupted". It never runs the model on a case older than 60 minutes.
- **Claim one case, run it, then claim the next**, until none are Pending or the run has lasted 10
  minutes. `withoutOverlapping(15)` plus the PreventsOverlapping pattern. Reason: the helper runs one pass
  at a time; claiming several up front makes them look stuck.
- **429 BUSY**: back to Pending without counting an attempt. Other failures count; 3 attempts -> Failed.
- **One deadline in the helper covers dump and model** (`FIRST_PASS_DEADLINE_MS`, default 300000). An
  AbortSignal reaches `fetchUserDump` (which also gains a key-header mode). Batch timeout = deadline + 30s
  (`SUPPORT_FIRST_PASS_TIMEOUT`, default 330).
- **A dedicated dump key**: Go also accepts `USERDUMP_INTERNAL_KEY` (in addition to the partner's
  `USERDUMP_API_KEY`) via `X-Dump-Key` only, never `?key=`. The helper reads it for the first pass only and
  never passes it into the interactive agent's environment. The helper sends `X-Freegle-Case: <id>` so the
  Go log and the audit line carry the case. Lockdown (export held) and other dump refusals map to their
  own reasons.
- **Member choice**: User2Mod -> user1. User2User -> the participant who is not the referrer; if the
  referrer is neither, NULL. Mod2Mod -> NULL. A NULL member runs a chat-only pass that says "No single
  member to look up" in could_not_find.
- **Roles on a case**: Support/Admin always get the full view. A message's role is Referrer when the
  author is the case's referrer (even if they are Support), else Support. A Referrer message notifies the
  support address; a Support message notifies the referrer (never the author themself).
- **The referrer must still be able to see the chat** (`canSeeChatRoom`) to read or post; otherwise 404.
  Role checks come before the row lookup on GET, POST and PATCH; non-allowed callers get 404.
- **Per-referrer cap**: at most 10 model passes per referrer per day; beyond it the case is Skipped
  "limit reached" with the plain mail. Reason: one moderator must not be able to spend the shared pool.
- **Draft safety**: every URL in the draft is checked; links that are not to a known Freegle page are
  listed under "Check before sending" whether or not the codebase checkout exists. Cited fact keys not in
  the code-built facts are dropped. Reason capped at 2000 chars in Go.
- **PII handling**: the stored transcript has image bytes removed and is capped; no request or response
  body is ever logged; `firstpass_error` and background-task errors store a fixed reason, not upstream text.
- **Retention**: a daily `support:purge-cases` deletes Handled cases older than 2 years and clears `brief`
  on cases older than 1 year. A case is deleted with its member (FK cascade), as runs are.
- **Evaluation command is constrained**: `support:first-pass-replay` defaults to 5, max 20, one at a time,
  refuses to start while any case is Pending or Running and stops if one appears, writes nothing to cases,
  runs or mail, and its report says the model saw today's data, not the data at referral time.
- App version is not in the logs (`device-summary.js`); the brief says "not available", never "none".

## Brief JSON (contract between helper, batch, Go and ModTools; version 1)

```
{
  "version": 1,
  "summary": "two or three sentences",
  "classification": "Bug" | "Usage" | "Suggestion" | "Incident",
  "confidence": { "level": "High" | "Medium" | "Low", "reason": "one line" },
  "facts": [   // always these six keys, in this order, built by CODE
    { "key": "member",          "title": "Member",                 "lines": ["..."], "note": "optional" },
    { "key": "posts_and_chats", "title": "Recent posts and chats", "lines": [...] },
    { "key": "bans_and_merges", "title": "Bans and merges",        "lines": [...] },
    { "key": "device",          "title": "Device and app version", "lines": [...] },
    { "key": "errors",          "title": "Recent errors",          "lines": [...] },
    { "key": "mail_history",    "title": "Past mail with support and info", "lines": [...] }
  ],
  "unusual": ["..."],
  "could_not_find": ["..."],
  "draft": { "to": "referrer", "text": "...", "facts_used": ["member", ...], "link_warnings": ["..."] },
  "pool": { "fiveHour": 0-100 | null, "sevenDay": 0-100 | null } | null,   // set by the batch from quota
  "images": { "sent": n, "skipped": n }
}
```

A section with nothing to say has `lines: []` and a `note` ("not collected", "none in the last 30
days", "not available"), never an empty silence.

## Helper request and response (`POST /internal/support-first-pass`)

Request (from the batch):
```
{ "caseid": 1, "memberid": 2 | null,
  "source": { "kind": "referral", "chatid": 3, "referrerid": 4, "reason": "..." | null },
  "chat": { "id": 3, "type": "User2Mod"|"User2User"|"Mod2Mod", "group": {"id": 5, "name": "..."} | null,
            "messages": [ {"id": 9, "date": "ISO", "from": "member"|"moderator"|"other"|"system",
                           "type": "...", "text": "..."} ] },          // last 50, oldest first
  "images": [ {"name": "...", "mime": "image/jpeg", "data": "<base64>"} ],   // up to 5
  "mailHistory": { "available": false, "reason": "...", "entries": [
                    {"date": "ISO", "mailbox": "support"|"info", "direction": "in"|"out",
                     "subject": "...", "excerpt": "..."} ] } }
```
Response 200: `{ "brief": <Brief JSON without pool>, "usage": {inputTokens, outputTokens,
cacheCreation, cacheRead, durationMs}, "costUsd": n, "model": "...", "driver": "...",
"quota": {fiveHour, sevenDay} | null, "quotaBefore": {...} | null, "transcript": "<JSON string, no image
bytes, capped>" }`. Errors: 400 `{error}`; 403 (guard); 429 `{error:"BUSY"}`; 502 `{error, code}` where
`code` is one of `DEADLINE`, `CREDIT`, `MODEL`, `DUMP_LOCKDOWN`, `DUMP`, `BAD_OUTPUT`.

The batch maps outcomes to these plain reasons (and nothing else) for `firstpass_error` and the mail:
"The first pass is turned off", "The AI helper was busy", "The AI helper is out of credit",
"The first pass took too long", "The member's data could not be read during a lockdown",
"The AI helper is not set up on this server", "The first pass limit for today was reached",
"The first pass was interrupted", "The AI helper failed".

## Flow

1. ModTools `ModChatFooter.vue` "Refer to Support": the confirm modal gains an optional "What do you need
   help with?" box (max 2000). `POST /api/chatrooms {id, action:"ReferToSupport", reason?}`.
2. Go `handleReferToSupport` (chat/chatroom.go): after the existing `canSeeChatRoom` check, choose the
   member, then in one transaction insert `support_cases` (status Open, firstpass_status Pending,
   open_key). Duplicate open_key -> fetch that case on the write connection, add a Referrer message
   "Referred again" (plus the reason) and queue `support_case_message`. Response
   `{ret:0, status:"Success", caseid}`. No `refer_to_support` task for new referrals.
3. Batch `refer_to_support` task: only legacy rows (no caseid) arrive; exactly today's plain mail.
4. Batch command `support:first-pass` (every minute, runInBackground, withoutOverlapping(15)): sweep as
   above, then claim-run-claim. For a claimed case: per-referrer cap; recent-brief reuse; build the
   request (chat text excluding held/rejected messages, images fetched by the batch the same way the Go
   chat API builds chat image URLs, mail history from the MailboxHistory service); call the helper with
   `X-Freegle-Internal-Secret` (pattern: `UxPromptService::askHelper`). 200 -> insert `support_ai_runs`
   (kind FirstPass, modid = referrer, userid = member, query "First pass for support case N", analysis =
   brief as markdown, transcript, tokens, cost, model, driver, quota before/after), set brief (with
   `pool` from quota), firstpass_runid, Done, then mail with the brief. Failure -> reason, Error run if the
   helper ran, mail without the brief. Never throws out of the loop.
5. Helper route (claude-agent-sdk): `internalGate`, `express.json({limit:'20mb'})`, `internalHandler`.
   Dump via `X-Dump-Key: USERDUMP_INTERNAL_KEY` + `X-Freegle-Case`, include `db,loki,sentry`, since 30d;
   on failure `db` only; on failure chat-only. Facts by code from the dump tables (read `_sections` so
   "not collected" is never "none"). One tool-less model call (pattern `runThemesQuery`, `outputFormat`
   json_schema, images via `images.js buildPrompt`); fixed system prompt with the trust-boundary wording
   and reply style from `prompt.js`; draft to the referring moderator. Validate output; drop invented
   fact keys; link check. Unlink the dump. Audit `first_pass` with the case id.
6. Mail to support (`ReferToSupportMail`, plain text, subject and Reply-To unchanged): first screen =
   case number, summary, classification, confidence, the six fact sections, anything unusual, could not
   find, the draft ("Draft to the referring volunteer, not sent"), pool state; then the case link
   `{mod site}/support/cases/{caseid}` and the existing chat link `/support/refer/{chatid}` (kept, tests
   assert it). Without a brief: today's body, "First pass skipped: <reason>" and the case link.
7. Case thread notifications (`support_case_message` task {caseid, messageid}; fast): Support -> referrer
   mail with the message and the case link, "Reply in ModTools", Reply-To noreply. Referrer -> Support
   mail to support_addr with the message and the case link. No brief content in either.
8. Go case API (new package `iznik-server-go/supportcase/`, routes in `router/routes.go` on /api and
   /apiv2, swagger annotations and regenerated swagger):
   - `GET /supportcases?status=Open|Handled|All&before=<id>&limit=` Support/Admin only. Row: id, created,
     status, chatid, member {id, displayname}, referrer {id, displayname}, classification, confidence
     level, summary (first 200 chars), firstpass_status, message count, last activity.
   - `GET /supportcases/:id` Support/Admin: full (brief, run id, firstpass status and error, messages,
     member, referrer). Referrer (who can still see the chat): `{id, status, created, chatid, reason,
     messages}`, with no firstpass fields at all, from an explicit struct and
     column list. Anyone else: 404.
   - `GET /supportcases?chatid=N` (Support/Admin): newest case for a chat, for the old refer page.
   - `POST /supportcases/:id/messages {body, draftused?}` body 1..10000; role per the rule above; queues
     `support_case_message`. A Handled case reopens (open_key restored; if another open case for the same
     pair exists, 409) when the referrer posts.
   - `PATCH /supportcases {id, status:"Open"|"Handled"}` Support/Admin only; Handled sets open_key NULL,
     handledby, handled_at.
   - Rating reuses `PATCH /supportai/runs` on the case's run id. `supportai` Run/List gain `kind`.
   - `userdump` also accepts `USERDUMP_INTERNAL_KEY` (header only).
   - Traps: fiber.NewError for refusals; every request field on the struct; read-after-write on
     `dbresolver.Write`; check every Scan error.
9. ModTools:
   - `api/SupportCasesAPI.js` registered in `api/index.js` (both places); `ChatAPI.referToSupport(chatid,
     reason)` returns the response.
   - Refer modal: optional reason; on success the notice links to the case. Keep `data-test="refer-sent"`
     and `"refer-failed"`.
   - Case page `modtools/pages/support/cases/[[id]].vue`, gate `mod`. Support/Admin: header, first pass
     (sanitised markdown via the DOMPurify + marked pairing), the six fact sections, unusual, could not find,
     confidence, classification badge, draft with Copy and "Use as reply" (prefills the reply box; sending
     records Unchanged or Edited), link warnings, pool state, tokens, rating (`ModSupportAIRating`), "View
     chat" (`ModChatModal`), Mark handled / Reopen, thread, reply box. Referrer: status, their reason,
     thread, reply box. No brief.
   - No id: the list (Support/Admin), modelled on `ModSysAdminSupportAI.vue` paging; also a "Cases" tab
     appended to the Support page. `/support/refer/<chatid>` keeps working and links to the newest case for
     that chat when one exists. The SysAdmin AI Helper list shows each run's kind.
10. Evaluation: `support:first-pass-replay` as constrained above; JSON report of per-pass tokens,
    duration, classification, confidence, could-not-find count, and averages; ids only.

## Schema

Migration pair `2026_11_07_000001_create_support_cases_tables` (already written and applied in this
worktree): `support_cases`, `support_case_messages`, `support_ai_runs.kind`. Read it for columns.

## Config and env (one owner per file: the helper builder owns compose and the env examples)

- Batch `config/freegle.php` block `freegle.support.first_pass`: `enabled` (`SUPPORT_FIRST_PASS_ENABLED`,
  EnvSetting::flag, default false), `timeout` (`SUPPORT_FIRST_PASS_TIMEOUT`, 330), `per_referrer_per_day`
  (10), `max_age_minutes` (60), `run_minutes` (10); helper url and secret reuse
  `freegle.ux.prompts.helper_url` and `internal_secret`. All via `EnvSetting::env`.
- Helper env (compose `ai-support-helper` environment block): `USERDUMP_INTERNAL_KEY`,
  `FIRST_PASS_MODEL` (falls back to SUPPORT_AI_MODEL), `FIRST_PASS_DEADLINE_MS` (300000).
- Go env (compose `apiv2` and `apiv2-live`): `USERDUMP_INTERNAL_KEY`.
- `.env.example` and `.env.background.example` document all of them.

## Testing rules for every builder

- Run suites ONLY through `/tmp/claude-1000/-home-edward-FreegleDockerWSL/b5ec0b61-03d9-45da-a581-59f164720f24/scratchpad/suite.sh <go|laravel|vitest> "<filter>"`
  (it serialises Go and Laravel, which must never start together; a Laravel run can wait 10-40 minutes).
  Laravel filters are class/method names, not paths. Go filters are -run regexps.
- claude-agent-sdk: `node --test` in the worktree's `claude-agent-sdk`, AND once with `node_modules`
  hidden (CI has none): every new `*.test.js` and everything it requires must load with Node built-ins
  only (stub heavy modules via `Module._load` as `support-agent.test.js` does).
- 90%+ coverage on touched modules. Tests assert behaviour, including refusals: the referrer's raw JSON
  lacks brief, userid, member, firstpass_error, run id and handledby; a non-referrer moderator gets 404 on
  GET, POST and PATCH; Support sees all; double referral returns the same case; second referrer gets a
  separate case.
- Never edit files outside your area; never run setup-test-database.sh; never touch the main checkout or
  main stack; never `docker cp` into the batch container; do not commit (the parent commits).

## Decisions from the adversarial review (2026-11-07)

- **Only a moderator may refer a chat, and not a Group chat** (Moderator, Support or Admin systemrole, and able to see the
  chat). Reason: a referral now opens a case and spends a model pass, and a case lets its referrer mail
  Support; the button has only ever been in ModTools, so no member path is lost. Case messages are capped
  at 10 per author per case per hour.
- **A failure where the helper answered is final.** Only a helper that never answered is retried (up to
  3 times). Reason: a deadline, a bad answer or an out-of-credit error happens again a minute later and
  re-spends the model and the member's dump each time.
- **The dump has its own budget inside the deadline** (full 120s, database only 60s, then chat only),
  so a slow dump gives a thinner brief rather than a failed pass.
- **A brief is reused only when the new referral asks nothing new**: no reason or the same reason, and no
  newer visible chat message. Reason: a brief written for another question or an older chat misleads.
- **The referral mail is built before mailed_at is claimed, and finished cases are mailed after each
  case**, not at the end of the run. A case whose chat is gone is Skipped and still mailed.
- **Retention covers the run rows too**: FirstPass run rows lose their analysis and transcript after a
  year and go with their case; Open cases idle for two years are deleted.
- **Replays are recorded as runs** (kind FirstPass, no asker), so their spend is visible.
- **Not changed: the dump key is readable through /proc by the interactive helper agent**, which runs as
  the same user. That agent already reads the same member data through its own read-only tools and the
  database credentials, and has no tool that can send anything out, so the key adds no new reach.
  Separating it would mean a second container or user for the first pass.
