# Support Helper — investigation flowcharts (ai-flower)

Design for turning the AI Support Helper from a free-roaming agent into a set of
**constrained investigation flowcharts** built on `ai-flower`
(`github:freegle/ai-flower`). Full flow set designed here first, then built.

## Why (the problem this solves)

The current helper is one big system prompt + a bag of tools, and it reasons freely.
That confabulates: e.g. it told support a member's posts were held on NewhamFreegle
because of a "replied to posts 16 miles apart" review flag — but that flag had
`reviewedat` set (reviewed and **cleared** in Dec 2025) and `ourPostingStatus` was NULL
on that group exactly like all their other groups. A knowledgeable interlocutor
(Edward) catches this. **Support volunteers cannot** — they don't read the code or the
DB. Piling more rules into the prompt is a losing battle.

A flowchart FSM fixes it structurally: the LLM may only take the **read-actions allowed
in the current state** and may only follow **defined transitions**. It is *forced* down
the correct investigation and cannot jump to a conclusion or cite something it never
looked up.

## Architecture

- **Engine**: `ai-flower` `WorkflowEngine`, LLM-driven, with the **`claude-code`
  adapter** (same runtime the helper already uses — session auth locally, API key on
  edge). `SQLiteStorage` for per-conversation instances (resumable, auditable).
- **Read-actions** = the forced lookups. Each is a small, named, typed function over the
  live DB / dump / Loki / Sentry (decomposed from today's coarse `query_dump`/`db_query`).
  A state lists exactly which it may call; the model cannot query anything else.
- **Router flow** classifies the incoming question into one sub-flow (or a constrained
  `GENERAL` fallback that keeps free-form tools for the long tail).
- **Vue editor**: embed `WorkflowEditor` in ModTools so **Edward authors/adjusts flows
  visually**; `WorkflowViewer` shows the support volunteer the live path through the
  flow (transparency + trust) instead of opaque "thinking".
- **Guardrail (engine-level)**: a terminal state's answer may only reference facts its
  states actually returned. No lookup for X ⇒ the flow cannot assert X. This is the
  structural anti-confabulation property.
- **Answer style stays**: plain English, name+email not ids, no table/column names — but
  now backed by a forced procedure, not hope.

## Read-action library (the forced lookups)

Shared across flows. Each returns a small, typed result; secrets redacted.

| Action | Input | Returns |
|---|---|---|
| `find_member` | email/name/id | member id, name, all addresses, login types |
| `resolve_users` | ids[] | id → name + email (batched; already baked into the dump) |
| `find_posts_and_holders` | member | recent posts, per-group collection (Pending/Approved), holder group(s) |
| `get_posting_status` | member, group | `ourPostingStatus` (trusted / NULL / MODERATED / PROHIBITED), added |
| `get_group_moderation` | group | does the group moderate all / new-member posts |
| `get_review_flag` | member, group | `reviewreason`, `reviewrequestedat`, `reviewedat` → **PENDING vs CLEARED** |
| `get_bounce_status` | member | `users.bouncing`, `bounces_emails.reason/permanent` |
| `get_email_delivery` | member, address? | `logs_emails` rows: SMTP status (250 = MX accepted ≠ fault), or **no row = never sent** |
| `get_addresses` | member | all emails incl. `@privaterelay.appleid.com`, `users_logins.type` |
| `get_login_history` | member | `users_logins`, `sessions`, login/session log lines |
| `get_reach` | member, offer | drive-time / distance from offer origin vs member location (rippling) |
| `get_held_reply` | member | `chat_messages.seenbyall=0 AND mailedtoall=0` (held reply) |
| `find_duplicate_chats` | member | duplicate `chat_rooms` for same two users / same item |
| `get_settings_history` | member | `audits` (new/old JSON) + legacy `logs` subtypes |
| `check_hard_delete` | id | orphaned FKs / login logs with no `users` row |
| `check_rippling_autojoin` | member | `logs` Group/Joined `text='Auto'` + `chat_messages` OOO-as-Interested |
| `get_sentry_error` | user/query | Sentry issues (`source:error-page-mount`, APIError) |
| `loki_by_trace` / `loki_user` | trace_id / userid | log lines (3-pass user search) |
| `git_fixed` | symptom, report-date | commits matching the symptom; only BEFORE the report = "already knew" |
| `read_chats_summary` | member | both sides of chats from the dump → pattern summary |
| `locate_component` | on-screen copy / error string / symptom | file(s) + **surface** (FD-web / ModTools / app / batch-email / AI-image) that produce it — forces "find the code behind this string" before theorising |
| `get_deploy_status` | surface | commit live on that surface vs `master` HEAD → distinguishes **fixed-but-not-shipped** from **not-fixed** (the "you said it was fixed but it still happens" case) |
| `git_recent_changes` | area, since | recent commits/PRs touching the area → regression detection ("worked before the upgrade" → what changed) |

## The flows

Notation per flow: `State [forced action(s)] → branch/condition`. Terminal states give a
plain-English cause + the concrete next step for the volunteer.

### 0. ROUTER (entry)
Classify the question into one flow below by matching symptom language, else `GENERAL`.
Never diagnoses — only routes.

### 1. POST_HELD — "why did my post go to Pending / back to moderation after I edited/reposted it?"
- `FindHeldPost [find_posts_and_holders]` → the post + the group holding it.
- `CheckPostingStatus [get_posting_status]` → **trusted** ⇒ `UNEXPECTED_APPROVE` (should
  auto-approve — dig into the specific post); **NULL/MODERATED/PROHIBITED** ⇒ next.
- `CheckGroupPolicy [get_group_moderation]` → does the group moderate posts / new members.
- `CheckReviewFlag [get_review_flag]` → **pending** (`reviewrequestedat` set AND
  `reviewedat` NULL) ⇒ contributing cause; **cleared** (`reviewedat` set) ⇒ the flow must
  state it is **NOT** the cause. *(This state is exactly what stops the 16-miles mistake.)*
- `Conclude` → "held because they aren't a trusted poster on <group> (every edit/repost is
  moderated); the <reviewreason> flag was reviewed and cleared on <date> and is not the
  cause." Next step: a mod approves it, or mark them a trusted poster on that group.

### 2. NO_EMAILS — "not getting emails / notifications / unsubscribe"
- `CheckBounce [get_bounce_status]` → suppressed? why.
- `CheckDelivery [get_email_delivery]` → **SMTP 250 / queued** ⇒ recipient MX accepted it
  (junk-filter, greylisting) — **not a Freegle fault**; **no row** ⇒ genuinely not sent;
  **bounce** ⇒ address dead.
- `Conclude` accordingly (don't chase a 250 as a bug).

### 3. HELD_REPLY — "waiting to send / message hasn't been delivered yet"
- `ConfirmHeld [get_held_reply]`.
- `CheckReach [get_reach]` → out of reach by drive-time from the offer origin ⇒ intended
  nearest-first hold (even a local-group member can be out of reach).
- `Conclude` → reassure: not lost, distance-dependent (not a fixed 7 days); the "waiting"
  badge shown to the sender is a known bug being fixed.

### 4. DELAYED_OR_WRONG_ITEM — "notifications delayed / chat attached to the wrong item"
- `RuleOutEmail [get_email_delivery, get_bounce_status]` (250 = accepted, bouncing=0).
- `CheckDuplicateChats [find_duplicate_chats]` → duplicate conversation for the same pair/item.
- `Conclude`. (Big source fixed 3 Mar 2026 — fresh cases after that = something new.)

### 5. CANT_LOG_IN
- `CheckLogins [get_login_history]` → spurious 401 right after login = auth-check
  replication lag; Apple login lives in `users_logins(type='Apple')`.
- `Conclude`.

### 6. SOMETHING_WENT_WRONG — the amber error
- `ClassifySurface` (from the screenshot/description) → **full-page** (Nuxt `error.vue`)
  vs **toast** (APIError).
- Full-page: `get_sentry_error [source:error-page-mount / SSR stderr]`.
- Toast: `loki_by_trace [X-Trace-ID]` or `get_sentry_error [APIError]`.
- `Conclude`. (Go API 500s may be absent from logs unless the handler logged them.)

### 7. MERGE_PROMPT — "merge your accounts won't go away / verification email never arrives"
- `CheckDuplicate [get_addresses]` → genuine duplicate?
- `CheckEmailSent [get_email_delivery]` → **no row = nothing sent** (UI lied).
- `Conclude` → usually a stale logged-out session, not a real duplicate; fix = get them signed in.

### 8. ACCOUNT_MISSING — "no such user but they clearly used it"
- `CheckHardDelete [check_hard_delete]` → orphaned FKs / login logs with no user row.
- `Conclude` → purged; live data shows THAT, not who/how (needs the Yesterday backup).

### 9. REPLIES_I_NEVER_MADE — "getting replies to postings I never made / OOO answering strangers"
- `CheckAutojoin [check_rippling_autojoin]` → `logs text='Auto'` + OOO parsed as Interested.
- `Conclude`.

### 10. STALE_DEPLOY — "flickering page / Failed to fetch dynamically imported module _nuxt/*.js"
- `CheckSignature` (the module-fetch 500 from a decommissioned deploy cdnURL).
- `Conclude` → hard refresh; the reload loop-guard gap is a real code issue.

### 11. CHAT_PATTERN — "look at these chats" (a mod flags a member)
- `ReadChats [read_chats_summary]` → both sides from the dump.
- `Conclude` → summarise the pattern with quoted lines + dates (no-shows, aggression,
  scam scripting, same story across groups) for the mod to weigh.

### 12. SETTINGS_HISTORY — "did they change their postcode / email settings / when"
- `GetHistory [get_settings_history]` → `audits` / legacy `logs`.
- `Conclude` (say honestly if a change isn't logged).

### GENERAL (fallback)
Unclassified questions run a constrained free-form state with the full read-action set +
`git_fixed` + the answer-style guardrails — the current behaviour, but boxed and logged.

Cross-cutting techniques available inside any flow: **see the screenshot** (vision),
**correlate timestamps**, **`git_fixed` before/after the report date**.

## Coverage validation (2026-07-13) — real reports vs the flows

**Method.** Ran the actual support corpus through the flow set from two independent
sources: the monitor-FSM `discourse_bug` table (**364 real Discourse mod/support reports**,
feature-area tagged) and the **support mailbox** (~1 year, X-GM-RAW). Classified each by
what investigation it actually needs.

**Headline.** Real reports split into **three intent families**, and flows 1–12 above serve
only **Family A**, which is the *minority*. The majority are product-bug and
product-question traffic with **no first-class flow** — today they all fall through to
`GENERAL`, which is exactly the free-roaming state where the agent confabulates (it will
reach for a member-data "root cause" for what is really a code defect or a product policy).

### Family A — Member investigation (flows 1–12) — the minority
A specific member/post/chat/email is misbehaving. Real examples in the corpus: "message
took a week to arrive", inactive-member-still-emailed, unsubscribe didn't remove from group,
welcome email delayed after joining, held/stuck post, "can't open Freegle / can't log in".
These are genuine and the 12 flows handle them — but they are a small slice of the traffic.

### Family B — Bug triage / regression — the DOMINANT slice, no flow
A **feature** is broken or behaving wrong, often platform-specific, often *"still broken
after your fix"*. Representative (each is many rows): ModTools member-review ghost
("Ignore doesn't clear them"), pending posts missing / stuck / no action buttons,
related-members counter stuck on 1 (**recurs 6×, "spreading to more users"**), chat viewer
500 `Cannot read properties of undefined (turl)`, approved-members infinite scroll, badge
counts stuck (Images / Chat / iOS), Android zoom controls obscuring buttons (6 months open),
AI image generation (meaningless "Any" image, multi-item, British-English terms, spam
suppression), wanted-listing-received email uses OFFER wording, unified digest missing
Browse/cancel links + duplicate crosspost, repost dropdown ignores group selection, wanted
posts not auto-expiring ("despite fix deployed"), location polygon disappears on refresh,
add-member 409, standard-message DELETE 404, website won't load on desktop (CDN/CORS).

### Family C — Product question / policy / tuning — no flow, and not a member lookup
"How/why does X work", or a config/policy/tuning request. Examples: 2-owners requirement
rollout, add/remove worry-words ("kindling" + rationale), start auto-approve with Offers
only, is postcode required at registration, content-check sensitivity, remove the
intermediate iOS share dialog. The honest answer is a docs/code-locate explanation **or a
captured product request** — never a fabricated member-data cause.

### Coverage matrix (representative)

| Real report (source) | Family | Covered today? |
|---|---|---|
| "Message took a week to arrive" (mail) | A | ✅ DELAYED_OR_WRONG_ITEM |
| "Notifications not coming through" (mail) | A/B | ⚠️ NO_EMAILS if member-specific; **GAP** if the notification *feature* is broken |
| "Can't open Freegle" (mail) | A/B | ⚠️ CANT_LOG_IN or STALE_DEPLOY, else **GAP** (CDN/CORS load failure) |
| Related-members counter stuck on 1 (6×) | B | ❌ **GAP** — regression triage |
| Chat viewer 500 `turl` from Support Tools | B | ❌ **GAP** (SOMETHING_WENT_WRONG only classifies the *surface*, doesn't locate/track the bug) |
| Pending posts stuck / no buttons | B | ❌ **GAP** |
| iOS/Images/Chat badge count stuck | B | ❌ **GAP** — NOTIFICATIONS |
| Wanted-received email uses OFFER wording | B | ❌ **GAP** |
| Wanted posts not expiring "despite fix deployed" | B | ❌ **GAP** — deploy-lag / retest |
| AI image gen meaningless for "Any" | B | ❌ **GAP** |
| Location polygon disappears on refresh | B | ❌ **GAP** |
| "Add kindling as a worry word" + why | C | ❌ **GAP** — product/tuning capture |
| "How/when is the 2-owners rule implemented" | C | ❌ **GAP** — policy question |
| POST_HELD / held reply / merge prompt / OOO replies | A | ✅ flows 1–12 |

### What's missing (proposed additions)

1. **ROUTER becomes a 3-way triage first** — MEMBER_INVESTIGATION vs BUG_TRIAGE vs
   PRODUCT_QUESTION — *then* sub-routes to a specific flow. The top-level split is the
   single biggest gap; it stops the agent answering a broken-feature report with a member
   lookup.
2. **BUG_TRIAGE flow (new, dominant).** Symptom-first, structurally forced:
   `Locate [locate_component]` → `CheckShipped [get_deploy_status]` +
   `CheckHistory [git_recent_changes / git_fixed]` → `CheckErrors [get_sentry_error, loki]`
   → classify **{known-fixed (+ when it ships) / known-open / new / not-reproduced /
   works-as-designed}** → terminal. First-class **`CAPTURE_FOR_ENGINEERING`** terminal
   (structured repro: surface, steps, member/post ids, screenshot, Sentry/Loki refs) — the
   honest "this is a new bug, here's the captured ticket", which is precisely what prevents
   confabulating a member-data cause. Branches on **surface** (FD-web / ModTools / app /
   email-digest / AI-image) to pick the right code area + logs.
3. **NOTIFICATIONS flow (new).** High-volume, cross-surface: which counter/channel; the
   deleted-user count inflation (a known latent recurrence); iOS badge vs in-app count;
   push routing to the wrong app. Its own path because the investigation is specific.
4. **DEPLOY_LAG / RETEST handling.** The "you said it was fixed but it still happens"
   pattern (Jacky/Mod-John retests, wanted-not-expiring) — folded into BUG_TRIAGE's
   `get_deploy_status` gate. Strongest anti-confabulation case: the answer is a deploy check,
   never a member's moderation status.
5. **PRODUCT_QUESTION flow (new).** Answer from a docs/code-locate, or `CAPTURE` a
   product/tuning request; explicitly forbidden from inventing a member-data root cause.

### Scope honesty
For Families B and C the helper's realistic job is **triage + status + capture**, not always
*resolve* — and saying so IS the win. "I don't have a member-data answer; this is a product
bug — here is its status / here is the captured repro" is the correct terminal, and the
structural cure for the confabulation that motivated this whole redesign.

## Build plan (phases)

1. **Engine integration** — add `ai-flower` + `claude-code` adapter + `SQLiteStorage` to
   `claude-agent-sdk`; `server.js` runs an instance per conversation; stream the active
   state/edge to the client (progress becomes the flow path).
2. **Read-action library** — implement the ~23 forced lookups above from the existing
   tool code (decompose `query_dump`/`db_query`); each typed + tested. Includes the three
   bug-triage actions (`locate_component`, `get_deploy_status`, `git_recent_changes`) the
   coverage pass showed are needed.
3. **3-way ROUTER + one flow per family** — the MEMBER_INVESTIGATION / BUG_TRIAGE /
   PRODUCT_QUESTION triage, then POST_HELD (A), BUG_TRIAGE with `CAPTURE_FOR_ENGINEERING`
   (B, the dominant family — build it early), and PRODUCT_QUESTION (C), each with a
   regression test built from a real case above.
4. **Remaining flows** — member-investigation 2–12, NOTIFICATIONS, + GENERAL.
5. **Vue editor** — `WorkflowEditor` in ModTools for Edward to author/adjust; persist
   definitions (SQLite/JSON); `WorkflowViewer` shows the volunteer the live path.
6. **Guardrail** — engine check that a terminal answer only cites facts its states
   returned; audit every instance (who investigated whom, which flow, which lookups).

## Notes
- Flows are grown from the **test cases** (the mailbox sample + the cases in this
  session), not invented — each flow ships with the case that justifies it as a test.
- This supersedes the ever-growing system prompt; the prompt shrinks to per-state
  instructions + the answer-style guardrails.

## Appendix A — POST_HELD as a concrete ai-flower workflow

The exemplar, in the actual `ai-flower` definition format, showing how a flow and the
anti-confabulation gate (`CHECK_REVIEW_FLAG`) are expressed. The rest follow this shape.

```json
{
  "id": "post_held",
  "name": "Why is a post held / went to Pending",
  "initialState": "FIND_HELD_POST",
  "guardrails": "Talk to a non-technical support volunteer: no table/column/function names, no SQL. Name any person by name + email, never a bare id. You may only state a cause a lookup actually returned — never infer it from a flag that is not currently pending.",
  "states": {
    "FIND_HELD_POST": {
      "nodeType": "start",
      "description": "Find the Pending post and the group holding it.",
      "prompt": "Find the member's recent post(s) that are Pending and the group holding each. If none are Pending, this is NOT_ACTUALLY_HELD.",
      "readActions": ["find_posts_and_holders"]
    },
    "CHECK_POSTING_STATUS": {
      "nodeType": "agent",
      "description": "Is the member a trusted (auto-approved) poster on the holding group?",
      "prompt": "Look up the member's posting status on the holding group. Explicit trusted/non-moderated -> UNEXPECTED (should auto-approve). Otherwise (default/moderated/prohibited) their posts are moderated there -> CHECK_GROUP_POLICY.",
      "readActions": ["get_posting_status"]
    },
    "CHECK_GROUP_POLICY": {
      "nodeType": "agent",
      "description": "Does the group itself moderate posts / new members?",
      "prompt": "Check whether the group moderates posts. Context for why an untrusted poster is held here.",
      "readActions": ["get_group_moderation"]
    },
    "CHECK_REVIEW_FLAG": {
      "nodeType": "agent",
      "description": "Review flag: PENDING or already cleared?",
      "prompt": "Check the member's review flag on this group. It is a CURRENT cause only if a review is pending (requested and not yet reviewed). If it was reviewed/cleared, you MUST say it is NOT the cause and give the date it was cleared. Never blame a cleared flag.",
      "readActions": ["get_review_flag"]
    },
    "CONCLUDE": {
      "nodeType": "end",
      "description": "Plain-English cause + next step.",
      "prompt": "Plain English: held because the member is not a trusted poster on <group> (every post/repost is moderated), plus whether the group moderates generally. If a review flag exists but was cleared, say so and when. Next step: a mod approves it, or mark them a trusted poster on that group."
    },
    "UNEXPECTED": {
      "nodeType": "end",
      "description": "Trusted but still held — flag the specific post.",
      "prompt": "They ARE a trusted poster here, so this should have auto-approved. Report that and the post details for a mod; do not invent a cause."
    },
    "NOT_ACTUALLY_HELD": {
      "nodeType": "end",
      "description": "Nothing is Pending.",
      "prompt": "None of their posts are currently Pending. Say so plainly; note when the last was approved if useful."
    }
  },
  "transitions": [
    {"id": "t1", "from": "FIND_HELD_POST", "to": "CHECK_POSTING_STATUS", "trigger": "llm_decision", "condition": "A Pending post was found"},
    {"id": "t2", "from": "FIND_HELD_POST", "to": "NOT_ACTUALLY_HELD", "trigger": "llm_decision", "condition": "No post is Pending"},
    {"id": "t3", "from": "CHECK_POSTING_STATUS", "to": "UNEXPECTED", "trigger": "llm_decision", "condition": "Member is an explicit trusted/auto-approved poster"},
    {"id": "t4", "from": "CHECK_POSTING_STATUS", "to": "CHECK_GROUP_POLICY", "trigger": "llm_decision", "condition": "Member is not a trusted poster"},
    {"id": "t5", "from": "CHECK_GROUP_POLICY", "to": "CHECK_REVIEW_FLAG", "trigger": "llm_decision", "condition": "Group policy checked"},
    {"id": "t6", "from": "CHECK_REVIEW_FLAG", "to": "CONCLUDE", "trigger": "llm_decision", "condition": "Review-flag status established"}
  ]
}
```
