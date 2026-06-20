# Freegle Support AI — Diagnosis Playbook & Data-Dump Adequacy

**Date:** 2026-06-15
**Purpose:** Give an AI support tool (and future-me) a *leg up* on the support queries that get escalated to third line (`support@ilovefreegle.org` → geeks). For each common problem type: what data to pull, where it lives, the decision logic, and whether it can be answered without a human. Pairs with the **user data dump** (`GET /api/modtools/user/:id/dump`, branch `feat/user-support-dump`) which packages a per-user SQLite DB + Loki + Sentry.

**How this was built:** analysed **115 escalated `support@` threads** from Edward's Sent folder, Jan–Jun 2026 (read-only IMAP). Categorised, then cross-referenced every diagnostic need against (a) the dump's exported tables and (b) the apiv2 (`iznik-server-go`) code map. Legacy `iznik-server` (PHP) deliberately excluded. Diagnostic column/table existence validated against the live schema (Laravel migrations are source of truth).

> Scope note: this is about **diagnosing individual user problems**. A large fraction of escalations are *not* per-user data problems at all — they are "known bug, fix pending" (best served by a date-aware git fix-check) or product feedback / policy / mod-conduct (needs a human). Those are called out explicitly.

---

## 0. The decision flow (every report)

This is the spine — categories and queries below are *in service of* this flow.

```
support email in
      │
      ▼
1. INVESTIGATE WITH THE USER'S DATA  (dump / live snapshot §3)
   exhaust what the data can tell you about THIS user
      │
      ▼
2. IS IT A BUG, OR A QUESTION?
      │
      ├── QUESTION ───────────────▶ ANSWER FROM THE CODE
      │                              work out the real behaviour from the
      │                              codebase (apiv2 etc.) and explain it.
      │
      └── BUG
            │
            ▼
        3. INTELLIGENT ANALYSIS OF RECENT COMMITS (≈ last 14 days)
           read the recent commit window and reason about relevance —
           NOT a literal error-string grep. Look BOTH ways:
             • a commit that FIXES what they describe  → "fixed in <x>, update/refresh; live since <date>"
             • a commit that CAUSED what they describe (regression) → "recent change broke this; <fix status>"
            │
            ├── tied to a relevant commit ─▶ ANSWER (with the fix/cause + timing)
            │
            └── nothing relevant ─────────▶ PROBABLY A REAL BUG → ESCALATE TO A HUMAN
```

Three honest outcomes: **answer a question from code**, **answer a bug by tying it to a recent commit (fix or cause)**, or **escalate a probable new bug**. The data-investigation step (1) always happens first — even an escalation should carry what the data already showed.

Timing discipline (§7a) is intrinsic here: "recent window" + "fix vs cause" only makes sense relative to the report date. A commit *after* the report can be the reactive fix (resolution context) or, if it predates the symptom's onset, the cause.

---

## 1. Problem taxonomy (115 threads, Jan–Jun 2026)

Ordered by rough frequency. "Self-serviceable" = could an AI with read access to the dump + a date-aware git fix-check resolve or correctly triage it *without* a human.

| # | Category | Approx share | Typical root causes | Self-serviceable |
|---|----------|--------------|---------------------|------------------|
| 1 | **Login / Access** | high | Login-redesign regressions (OAuth buttons broken / "email already in use"); wrong domain (`.com` test domain has Google OAuth disabled); stale app/code version; browser cache; user typing their *email-provider* password not their Freegle password; old ModTools URL (`goldencaramel`); password-reset email not received; limbo/deleted account blocking re-login | **Partial** — data tells you method/state; novel auth bugs need a human |
| 2 | **Email delivery** | high | Recipient-side filtering (Hotmail/Outlook "queued for delivery" = *accepted*; Tiscali/TalkTalk greylisting; BT delays); group sending-domain DKIM off; bounce suppression (`bouncing=1`); per-group frequency bug (joining a new group resets to "immediately"); unwanted newsletters (settings); limbo account still emailed; auto-repost digest flooding (by design); Discourse mail suppression + sync lag | **Partial→Yes** — most are data-readable; recipient-MX behaviour is explainable |
| 3 | **Chat / Messaging** | high | FD reply button regression; chat input/Send pushed off-screen on short viewports; HTML entities unescaped in plain-text emails; chat linked to wrong post; push not arriving (FCM send OK, device delivery fails post-Android-upgrade); TN cross-platform delivery | **Partial** — mostly known bugs / platform issues |
| 4 | **Account deletion / GDPR** | high | In-app "Delete my account" button generates a templated email (App/Play Store compliance) → manual surge; limbo-state bugs (still emailed, can't re-login, "old posts" gone after reactivation); GDPR erasure vs spammer retention (legitimate interest); accidental deletion | **Partial** — state is readable; final GDPR/policy calls need a human |
| 5 | **JS / App error** | high | Capacitor `#entry` module-specifier crash; screen-orientation plugin; map `d.find is not a function` 500; ad banner overlapping Save; cookie popup double-click; location-remove button; settings not saving on app; Samsung-tablet keyboard jump; normal users shown ModTools UI; usernames shown as membership numbers; gallery photo upload | **Partial** — almost all "known bug": symptom → date-aware git check → advise |
| 6 | **Website-redesign feedback** | medium | New unified digest email (thumbnails too small / not clickable / posts mixed by location); floating menu obstruction; shallow message pane on narrow viewport; right-click-image disabled | **Yes (triage)** — acknowledge + route; rarely needs data |
| 7 | **Scam / Impersonation / Spam** | medium | System-generated pseudonym confusion (one contact, several names → "scammer?"); phishing targeting volunteers; "is this a scammer" judgement; impersonation | **Partial** — can surface flags/age/history; judgement is human |
| 8 | **Missing communities / Location** | medium | App bug not listing all communities when posting (known, fix pending app release); can't remove secondary location; missing community | **Partial** — membership readable; app-release status not |
| 9 | **Group / Mod admin** | medium | Moderator-conduct complaints; ModTools access errors; support-tool gaps (wrong TN flag hides "add email"; can't edit volunteer posts); report routing; `/jobs` visibility; Discourse SSO link | **No (mostly)** — judgement / elevated actions |
| 10 | **Donation / Payment** | low | Monthly donation option missing from page | Partial |
| 11 | **Other / internal** | low | Misrouted admin mail, credential sharing, feature requests | n/a |

**Two meta-findings that dominate:**
1. **"Known bug, fix pending" is the single most common resolution** across categories 1/3/5/8. The highest-leverage signal is **not** the data dump — it's **git history** (queried live and *date-aware*; git-only by decision — see §6.1/§7a). Git reliably answers only "*has a fix for this symptom already shipped, and when?*"; it cannot match literal error strings or surface human workarounds, so those cases fall through to a human.
2. **The first thing support asks for is almost always app version + browser + viewport** (via the "Note to support" footer or whatsmybrowser.org). Have this ready up-front (see §6).

---

## 2. Does the dump have the right data? — Adequacy matrix

The dump exports **69 user-linked tables** + Loki (3-pass) + Sentry (5 projects). Verdict: **excellent for per-user diagnosis; the gaps are all "global / external / cross-platform" data that a per-user dump can't contain by design.**

| Diagnostic need | In dump? | Where | Notes / gap |
|---|---|---|---|
| Login methods (native vs Google/FB/Yahoo/Apple) | ✅ | `users_logins.type`, `.credentials`, `.lastaccess` | Complete |
| Email confirmed | ✅ | `users_emails.validated` | Complete |
| Sessions / last login | ✅ | `sessions` (1000 rows; `series`/`token` redacted), `users.lastaccess` | Complete |
| Account state (active/limbo/forgotten) | ✅ | `users.deleted`, `users.forgotten` | Complete. **But:** once `forgotten`, data is wiped — can't diagnose a *purged* account (matches thread "deleted account can't be retrieved") |
| Bounce / suppression | ⚠️ partial | `users.bouncing`, `users_emails.bounced` | **GAP: `bounces`/`bounces_emails` (the bounce *reason*/permanence) are NOT in the dump.** You see *that* it bounced, not *why* |
| Exim delivery log | ✅ | `logs_emails` (20k cap; `status`, `subject`, `to`) | Complete for the channel |
| Laravel email tracking (opens/clicks/bounce) | ✅ | `email_tracking` + `_clicks`/`_images` | Covers Laravel-sent mail only, **not the V1 daily digest** |
| Per-group email frequency | ✅ | `memberships.emailfrequency` (-1 imm / 0 never / 24 daily) | Complete |
| Global email prefs | ✅ | `users.relevantallowed`, `.newslettersallowed`, `.settings` JSON, `.onholidaytill` | Complete |
| Digest send history | ✅ | `users_digests` | Complete |
| Spammer / impersonation flags | ✅ | `spam_users.collection/reason`, `users_comments`, `users_banned`, `memberships.reviewrequestedat` | Complete |
| Chat (both sides) | ✅ | `chat_rooms`, `chat_roster`, `chat_messages` (both directions), `chat_messages_held` | Complete; `refmsgid` links chat→post |
| Push tokens | ✅ | `users_push_notifications.type/apptype/lastsent` | **GAP: no FCM delivery receipts anywhere** (only `lastsent` + Laravel Loki lines). Inherent platform gap |
| Group membership + history | ✅ | `memberships`, `memberships_history` | Complete |
| User location data | ✅ | `users_approxlocs`, `users_nearby`, `isochrones_users`, `locations_excluded`, `users.settings.mylocation` | **GAP: the `locations` reference table (geometry, name) is NOT dumped** — resolving a `locationid`→name needs the live API |
| Posts + moderation status | ✅ | `messages`, `messages_groups.collection/spamtype/heldby/contentcheck_*`, `messages_history`, outcomes/promises | Complete |
| App/build version | ⚠️ partial | `users_builddates` + Loki `source="client"` lines | Derivable, not a clean field — see §6 |
| Client-side JS errors | ✅ (via Loki/Sentry) | Loki pass C (session-keyed), `sentry_issues` (5 projects incl. `capacitor`, `nuxt3`) | Good |

**Global / external data the dump deliberately can't hold (must be fetched live):**
- **Group config** — sending-domain DKIM, moderation on/off, average mod delay, group name from `groupid`. Needed for several email-delivery diagnoses. *Live API / DB only.*
- **Discourse state** — not in MySQL at all; queried live via Discourse REST API. Needed for "not receiving Discourse emails", suppression resets, and the "known bug on Discourse" matches. *Discourse API only.*
- **"Known bug" check** — git history only (§6.1), queried live and date-aware. Not Discourse/Sentry. (Sentry issues *are* in the per-user dump for that user's own errors, but git is the sole bug-status source.)
- **TrashNothing cross-platform state** — TN-side delivery/accounts. *Inherent gap.*
- **FCM delivery receipts** — not stored. *Inherent gap; infer from `lastsent` + Loki.*

**Recommended dump additions (small, high-value):**
1. Add `bounces_emails` (filtered by the user's `users_emails.id`) → unlocks bounce-reason diagnosis.
2. Add a resolved `locations` slice for the user's referenced `locationid`s (name + type) → self-contained location diagnosis.
3. Add a denormalised `groups` slice for the user's `memberships.groupid`s (name, moderated flag, sending domain) → self-contained membership/email diagnosis.

---

## 3. First-line tool: the "support snapshot"

Before any category-specific work, pull one snapshot. Either pull the dump and run these on the SQLite, or hit the live DB / API. **Validated query shape** (column names confirmed against live schema):

```sql
SET @uid := <USERID>;
-- ACCOUNT STATE
SELECT id, TIMESTAMPDIFF(DAY,added,NOW()) age_days, lastaccess, bouncing,
       deleted IS NOT NULL AS in_limbo, forgotten IS NOT NULL AS purged,
       newslettersallowed, relevantallowed, onholidaytill, settings
  FROM users WHERE id=@uid;
-- LOGIN METHODS  (native vs OAuth; has_pw distinguishes password accounts)
SELECT type, (credentials IS NOT NULL) AS has_pw, lastaccess
  FROM users_logins WHERE userid=@uid;
-- EMAILS (confirmed? bounced?)
SELECT id, preferred, (validated IS NOT NULL) AS confirmed, bounced
  FROM users_emails WHERE userid=@uid;
-- LATEST BOUNCE REASON  (NB: not in dump yet — live DB only)
SELECT be.date, be.reason, be.permanent
  FROM bounces_emails be JOIN users_emails ue ON ue.id=be.emailid
  WHERE ue.userid=@uid ORDER BY be.id DESC LIMIT 3;
-- MEMBERSHIPS + per-group email frequency
SELECT groupid, role, collection, emailfrequency, eventsallowed, volunteeringallowed
  FROM memberships WHERE userid=@uid;
-- SPAMMER / IMPERSONATION
SELECT collection, reason, added FROM spam_users WHERE userid=@uid;
-- RECENT EXIM DELIVERY LOG
SELECT timestamp, subject, status FROM logs_emails WHERE userid=@uid ORDER BY id DESC LIMIT 10;
-- PUSH TOKENS
SELECT type, apptype, lastsent FROM users_push_notifications WHERE userid=@uid;
```

Live API equivalents (mod/support-authed): `GET /api/user/{id}?modtools=true` (state, bouncing, spammer, memberships, emailfrequency, lastpush), `GET /api/user/{id}/logins`, `GET /api/user/{id}/emailhistory`, `GET /api/modtools/email/user/{id}`, `GET /api/user/{id}/chatrooms`.

---

## 4. Per-category investigation playbooks

Each: **identify → pull → decide → resolve**. Tables/endpoints from the apiv2 map.

### 4.1 Login / Access
- **Identify:** "can't log in / blank screen / email already in use / Google button gone".
- **Pull:** `users_logins.type` (+ `has_pw`); `users_emails.validated`; `users.deleted/forgotten`; `sessions.lastactive`; the domain they used; app/browser version (§6).
- **Decide (flow):**
  1. `forgotten` set → account purged; can't recover → explain, offer fresh signup.
  2. `deleted` set (limbo) → re-login *should* reactivate; if it errors → **known limbo re-login bug** (recurred 2026) → escalate/restore.
  3. Only OAuth rows, no Native `has_pw` → user is typing a password that doesn't exist → tell them to use the Google/FB button (or set a password). *(Yahoo-password thread.)*
  4. Native account, password-reset email not arriving → jump to **Email delivery** (check `bouncing`/`validated`).
  5. Domain = `*.com` test domain → Google OAuth disabled there → use `.org`.
  6. Old URL (`goldencaramel`) or old build (§6) → send to current `modtools.org` / update app / hard-refresh.
  7. None of the above + OAuth flow returns "login failed" → likely the **login-redesign regression**; date-aware git check (has a fix shipped since they wrote in?) → escalate if open.
- **Self-serviceable:** partial. 1/3/5/6 fully; 2/7 are escalations.

### 4.2 Email delivery / "not receiving emails"
- **Identify:** "missing emails", "delayed", "stopped getting alerts".
- **Pull:** `users.bouncing`; latest `bounces_emails.reason/permanent`; `users_emails.validated`; `logs_emails.status` (recent); `memberships.emailfrequency`; `users.relevantallowed/newslettersallowed/onholidaytill`; recipient domain; group moderation delay (live).
- **Decide:**
  1. `bouncing=1` → mail suppressed. Look at bounce reason: mailbox-full/temporary vs permanent. Resolution = clear bounce (`handleUnbounce`, admin) after user confirms mailbox OK.
  2. `validated` NULL → never confirmed → resend confirmation.
  3. `emailfrequency=0` (never) on the group, or `relevantallowed=0`, or `onholidaytill` in future → settings, not a fault → adjust (via impersonation).
  4. **Joined a new group recently and now flooded** → per-group frequency defaulted to "immediately" (known bug) → set group `emailfrequency` to match their others.
  5. `logs_emails.status` shows accepted / "queued for delivery" / SMTP 250 → **mail left Freegle; it's recipient-side** (Hotmail/Outlook junk, Tiscali/BT greylisting). Tell them to check spam; for whole-domain delays it's MX rate-limiting (dev reduces send rate). *Do not chase as a Freegle bug.*
  6. Group sending-domain DKIM off → infra fix (group admin), not user data.
  7. Discourse emails specifically → Discourse suppression + Freegle-account→ModTools→Discourse sync; needs Discourse API + admin suppression reset.
- **Self-serviceable:** mostly yes for 1–5 (read + explain, toggle via impersonation); 6/7 need admin/infra.

### 4.3 Chat / Messaging
- **Pull:** `chat_rooms` (both sides), `chat_messages` (content, `refmsgid`, `platform`, `seenbyall`, `mailedtoall`), `users_push_notifications` (+`lastpush`), Loki push lines, app version.
- **Decide:** blank/empty message bodies → check `chat_messages.message` actually empty in DB (rendering vs data). Reply-button-does-nothing / input disappears → **known FD chat regression(s)** (viewport Send-button + TN reply button) → date-aware git check + offer workaround (use profile message; scroll/landscape). Push not arriving but `lastpush` recent + Loki shows FCM send OK → device-side (Android battery optimisation / post-upgrade) → advise "Unrestricted" battery; escalate if Android-15-specific. Wrong-post chat linkage → `refmsgid` mismatch → platform bug, escalate.
- **Self-serviceable:** partial (mostly known bugs / device).

### 4.4 Account deletion / GDPR
- **Pull:** `users.deleted/forgotten` (+ timestamps); `spam_users.collection`; posts via `messages`; `logs_emails` (still being emailed?).
- **Decide:** limbo + still receiving email → **known limbo-email bug** → stop group email, escalate. Accidental deletion + "old posts gone after reactivation" → known reactivation bug → restore/escalate. Templated "Delete my Freegle Account" email from the app → the App/Play-Store-compliance button → (future) auto-confirm deletion for clean accounts, route edge cases to human. Confirmed spammer requesting erasure → GDPR legitimate-interest allows retaining the spammer flag/reason → **human policy sign-off**.
- **Self-serviceable:** partial; deletions can be automated for clean accounts; spammer/GDPR calls stay human.

### 4.5 JS / App error
- **Pull:** app version + device/OS + browser + viewport (§6); `sentry_issues` (`capacitor`/`nuxt3`); Loki client lines (session-keyed); recent git commits.
- **Decide:** per §0 — do an **intelligent read of the recent commit window (≈14 days)**, reasoning about relevance (not error-string matching). Tie the symptom to a commit that **fixes** it (→ update/refresh; live since <date>) or **caused** it (recent regression → fix status). If nothing recent is relevant → probable new bug → reproduction + screenshot + a human. Literal-error cases (`d.find is not a function`) won't surface from commit messages → human.
- **Self-serviceable:** partial — catches recently-fixed and recently-caused; genuinely-new bugs escalate.

### 4.6 Website-redesign feedback
- **Decide:** acknowledge, explain design intent, log to the dev queue. Known sub-bugs: digest thumbnails not clickable (fix queued), posts mixed by location (the "rippling out" work). Right-click-image, shallow pane, floating menu → forward with viewport details.
- **Self-serviceable:** yes for triage; no code data needed.

### 4.7 Scam / Impersonation / Spam
- **Pull:** `users` name/pseudonym history + join method/date; `users.added` (age); location; `spam_users`; `chat_messages` content for scam signals.
- **Decide:** "one contact, several names" → **system auto-generates a pseudonym** for some signup routes; user later set a display name → *not* a scam indicator. Phishing targeting a volunteer → identify + advise; no system data. "Is X a scammer" → surface age/flags/chat tone, but the **judgement is human**.
- **Self-serviceable:** partial.

### 4.8 Missing communities / Location
- **Pull:** `memberships` (all groups + `collection=Approved`); app vs web; `users_approxlocs`/secondary locations.
- **Decide:** membership shows the groups but only one appears when posting **in the app** → **known app bug** (communities not listed; fixed, pending app release) → advise web workaround. Can't remove secondary location → known remove-button bug (fixed) → confirm fixed/escalate.
- **Self-serviceable:** partial.

### 4.9 Group / Mod admin — *mostly human*
Moderator-conduct complaints (needs cross-platform account resolution incl. TN, mod-side chat via Support Tools, complaints procedure), report routing, support-tool gaps (wrong TN flag hides "add email" — detectable, fix needs action), volunteer-post edits (elevated access). Surface the data; **escalate the decision.**

---

## 5. What stays human (don't pretend otherwise)
- Moderator-conduct complaints and any inter-member dispute.
- Final GDPR erasure decisions, especially with a spammer flag.
- Novel bugs with no *already-shipped* git fix → reproduction + code fix.
- Vague reports with no screenshot/version → can only issue the standard triage questions (§6).
- Product/policy decisions (AI-image ethics, design direction, feature requests).
- Anything needing an elevated *write* action (unbounce, clear suppression, restore account, edit volunteer post, fix a TN flag) — AI proposes, human (or a gated tool) executes.

---

## 6. Cross-cutting tooling to build (highest leverage first)

1. **Intelligent analysis of recent commits — git only** (decision 2026-06-15: git only, *no* Discourse/Sentry registry). For a report classified as a bug, read the **recent commit window (≈ last 14 days)** over the umbrella repo (paths `iznik-nuxt3`/`iznik-server-go`/`iznik-batch`) and **reason about relevance** — this is an LLM judgement over commit subjects + diffs, **NOT** a literal error-string grep (which fails outright, e.g. `d.find is not a function` appears in no commit message).
   - **Look both ways:** a commit that **fixes** the reported symptom (→ "fixed in <x>; update/hard-refresh; live since <date>") *and* a commit that **caused** it — a recent regression (→ "a recent change broke this; <fix status / now reverting>"). The "caused" direction is often the more useful one for a *fresh* report ("it worked last week").
   - **Date discipline (§7a):** "recent" and "fix vs cause" are only meaningful relative to the report date. A commit after the report may be the reactive fix (resolution context, never "we already knew"); a commit just before the symptom's onset is a cause candidate.
   - **What it can't do → human:** (a) literal error strings aren't matchable; (b) bugs not described in any commit ("communities not listed when posting") won't surface; (c) a real bug with no related commit at all → that's the **escalate** branch, which is correct, not a failure. Git-only trades recall for simplicity and zero extra infrastructure; the safety net is that "nothing relevant found" routes to a human, not to a wrong answer.
   - **Evidence it must be date-aware:** §7b re-checked real matches and found every historical one was a *reactive* fix (commit after report). Date-blind matching would have falsely told all of them "known bug, already fixed".
2. **Device/version extractor** — parse the "Note to support" email footer + `users_builddates` + Loki `source="client"` lines into a clean {app version, platform, browser, viewport}. Removes the single most common round-trip ("please send a whatsmybrowser link").
3. **Group-config lookup** (live) — `groupid` → {name, moderated?, sending domain + DKIM, avg mod delay}. Closes the email-delivery gaps the per-user dump can't.
4. **Discourse API access** — for the *specific* "not receiving Discourse emails" / suppression-status cases only (not as a bug registry — that's git-only). Not in MySQL.
5. **Dump enrichment** — add `bounces_emails`, a resolved `locations` slice, and a `groups` slice (see §2) so the SQLite is self-contained.
6. **Email-log interpreter** — encode the SMTP-semantics rule ("queued for delivery"/250 = *accepted, recipient-side*) so the AI stops treating accepted mail as a Freegle fault. This one rule caused repeated back-and-forth in the Hotmail threads.

---

## 7. Live validation — DONE (2026-06-15, prod, read-only)

Ran the §3 snapshot against the live DB (2.86M users) for one real case per top category. **Every diagnosis resolved from data the dump contains** — confirming the core claim.

| Case (live userid) | Snapshot signal | Diagnosis the logic produced | Self-serviceable? |
|---|---|---|---|
| Bouncing — `<userid-A>` | `bouncing=1`, email `validated=NULL`, `bounces_emails.reason = "<bounce reason redacted>"` (permanent), `logs_emails` shows repeated bounces, member wants daily digest (`emailfrequency=24`) | **Typo'd email domain** (`<redacted>`→`<redacted>`); confirmation mail bounced too, hence unconfirmed → tell user to correct address | **Yes** |
| Limbo — `<userid-B>` | `deleted IS NOT NULL`, `forgotten IS NULL`, logins `Google`(no pw)+`Link`(pw), email confirmed | Account in grace-period limbo → re-login via Google reactivates; if it errors → known limbo bug → escalate | Partial |
| Spammer — `<userid-C>` | `spam_users.collection='Spammer'`, reason free-text reading like a mod note | Confirmed-spammer flag present → apply policy; **caution: `reason` is free-text, interpret don't trust literally** | Partial |
| OAuth-only — `<userid-D>`, `<userid-E>` | logins = `Google` only, `any_pw=0` | No password exists → "use the Google button / set a password via reset" (the Yahoo-password thread) | **Yes** |

Gotchas found doing it live (encode in any tool): `GROUPS` is a reserved word in MySQL 8 (quote/rename aliases); `LIMIT` is not allowed inside an `IN (...)` subquery (use a JOIN/derived table). Snapshot runs are read-only `SELECT`s; keep output to flags/types (no raw PII).

**Next step:** wire §6.1 (date-aware git fix-check) and §6.2 (device/version extractor) as the first two tools and re-test against live cases.

### 7b. Date-aware re-check of the git matches (2026-06-15) — overturns the earlier "feasibility"
I re-ran the symptom→commit match **with real dates** (thread date from the Sent mail vs commit date). Result: **not one match was a pre-existing-at-time-of-report bug.**

| Symptom | Thread date | Fix commit | Verdict |
|---|---|---|---|
| `#entry` / entryImportMap | 28 May | `72364748d` 31 May | **Reactive** (fix 3 days after report) |
| digest photo clickable | 29–31 May | `b9a704ee9` 12 Jun | **Reactive** (~2 weeks after) |
| limbo / deletion | 30 Mar + 22–27 May | `d6199e5e8` 27 May, `625f4ca05` 2 Jun | **Reactive** (weeks–months after) |
| screen-orientation | 16/18 Jan | plugin added 15 Jan, completed `3a56d66dd` 19 Jan | **Ambiguous→reactive** (report between partial & completing commit) |
| `d.find is not a function` | 10 Mar | *no git match* | git can't find literal error strings |
| communities-not-listed | 4/6 Jun | *no reliable git match* | not in a matchable commit message |

Lesson: a date-blind git match would have told all six users "known bug, already fixed" — wrong for all of them. Git is only safe for the *live* "has a fix shipped since?" question, never for "we already knew."

### 7a. ⚠️ Timing / causality rule (git-only registry)
A symptom matching a git commit does **NOT** prove it was a *pre-existing* known bug. The commit may have been the **response to that very report**. So matching must be time-aware:
- For a **historical thread**: only call it "already known/fixed" if `commit_date < email_date`. If `commit_date >= email_date`, it was a *reactive* fix (the report caused it) — useful as resolution context, but it was not knowable in advance.
- For a **live incoming query**: any commit newer than the query timestamp is, by definition, not prior knowledge — at best it's "a fix landed since you wrote in." Never present a post-query commit as "this is a known issue."
- Practical: compare the **commit date** against the **report/query date** and label each match `already-shipped` (commit before query — safe to cite), `reactive` (commit after report — context only, never "we knew"), or `none`. The §7b re-check did this and found all historical matches were reactive.

---

## 8. Architecture — build it inside the monitor-FSM framework

Support shares almost all of its **logic** with `monitor-fsm` (the triage loop for Sentry + Discourse), and the building blocks already exist there:
- `git_log_today` / a recent-commit reader → the §0 step-3 "intelligent recent-commit analysis" (extend window to ≈14 days).
- `search_code` → the "answer a question from the code" path.
- `DIAGNOSE_BUG → REPRODUCE_BUG → REVIEW_REPRODUCTION → IMPLEMENT_FIX → VERIFY` → the pipeline once a report is a real, fixable bug.
- Classification + a **"defer when external info is needed"** state → maps to **escalate-to-human** (and "ask the reporter for a screenshot/version").
- Reply-draft / compose actions → the support-reply analogue.

**The data dump is shared, not support-specific.** The FSM could (and should) use the per-user dump too whenever a Sentry/Discourse issue is tied to a user — same data, same snapshot (§3), same diagnostic queries. So the dump is a *common capability*, not a differentiator.

**The real difference is the interaction model / deployment, not the logic or the data:**
- **Support → an interactive web tool (the goal).** A person queries it on demand and gets an answer back in a UI: request/response, low-latency, single-case, stateless per query. It pulls the user's data, runs the §0 flow, and returns an answer or an escalation. (Email is the *first* source; the eventual product is the interactive tool.)
- **Monitor-FSM → a background script Edward runs** to keep watch on Discourse/Sentry: an autonomous, stateful iteration loop (iteration timestamps, coverage gate, PR-creation gate) that runs unattended and produces PRs/reply-drafts.

Consequences of that split: the support tool relaxes the FSM's PR-gate and iteration-state machinery (a query needn't produce a PR — often it's "explain", "adjust a setting via impersonation", or "escalate"); it needs an **identity-resolution front door** (email/name → userid, incl. TrashNothing-side and multiple addresses) that the background loop doesn't; and it must cope with a **vaguer, non-technical channel** (no stack trace/version), so identity + device/version extraction (§6.2) and "ask for more info" matter more.

Net: share the diagnosis logic, git/code actions, and the data dump with the FSM; package it as an **interactive, on-demand, stateless web query tool** (with identity resolution and a reply/escalate output) rather than an unattended background loop.

---

*Source threads: 115 `support@ilovefreegle.org` Sent items, Jan–Jun 2026 (read-only IMAP; raw mail kept local in `/tmp/support_mail`, anonymised here). apiv2 = `iznik-server-go`; schema source = `iznik-batch/database/migrations`. Legacy `iznik-server` excluded per scope.*
