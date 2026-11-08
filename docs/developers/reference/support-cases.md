---
last_reviewed: 2026-11-08
owner: Freegle dev team
covers:
  - iznik-server-go/supportcase/**
  - iznik-server-go/chat/chatroom.go
  - iznik-server-go/test/supportcase_test.go
  - iznik-batch/app/Console/Commands/Support/**
  - iznik-batch/app/Services/Support/**
  - iznik-batch/app/Mail/Chat/ReferToSupportMail.php
  - iznik-batch/app/Mail/Chat/SupportCaseMessageMail.php
  - iznik-batch/tests/Feature/SupportCases/SupportFirstPassTest.php
  - iznik-batch/database/migrations/2026_11_07_000001_create_support_cases_tables.php
  - iznik-nuxt3/modtools/pages/support/cases/[[id]].vue
  - iznik-nuxt3/modtools/components/ModSupportCaseList.vue
  - iznik-nuxt3/modtools/components/ModChatFooter.vue
  - iznik-nuxt3/api/SupportCasesAPI.js
  - iznik-server-go/supportcase/casestore/casestore.go
  - iznik-server-go/test/supportcase_work_test.go
---

# Support cases and the first pass

When a moderator refers a chat to Support, a **support case** is opened. The AI helper then makes one
bounded **first pass**, an investigation of the question the moderator is asking, using the member's data and the chat, and Support gets a mail that already holds
the findings. This page is the whole flow across the Go API, the batch and ModTools. The helper's own
part is in [ai-support-helper.md](ai-support-helper.md), under "First pass on a referral". For
moderators, see [Refer a chat to Support](../../moderators/managing-members.md#refer-a-chat-to-support).

The decisions behind it, and the contracts between the pieces, are in
`plans/active/2026-11-07-referral-first-pass.md` while that plan exists. This page points into the code.

## The flow

1. **Refer.** In ModTools, `ModChatFooter.vue` has Refer to Support, with an optional "What do you need
   help with?" box. It calls `POST /api/chatrooms` with `action: ReferToSupport` and the reason.
2. **Open a case.** `handleReferToSupport` in `iznik-server-go/chat/chatroom.go` writes the case in one
   transaction and answers with its id. Only a moderator (Moderator, Support or Admin) who can see the chat
   may refer it, and a Group chat cannot be referred: a referral spends a model pass and opens a thread
   that mails Support, so it is not open to members. It queues no background task: the case row is the queue. The
   reason is capped at 2000 characters. The case starts `Open`, with first pass `Pending`.
3. **First pass.** `support:first-pass` (`iznik-batch/app/Console/Commands/Support/`) picks the case up,
   builds the request (`FirstPassService`), calls the helper and stores the brief.
4. **Mail.** The same command sends the referral mail to Support, with the brief, or with the plain reason
   there is none.
5. **Case page.** Support and Admin read the first pass in ModTools and reply. The referring moderator sees
   the replies and replies back. Both sides are mailed a pointer, not the first pass.

## One case per chat and referrer

`support_cases.open_key` is `<chatid>-<referrerid>` while the case is Open and NULL once Handled, with a
unique index. It is set by code, not generated, because MySQL refuses a generated column over a foreign
key with SET NULL. So:

- The same moderator referring the same chat again gets their existing open case back, and a "Referred
  again" message carrying the new reason. Within two minutes, with no new reason, it is treated as a
  double-click: the same case comes back and nothing is mailed.
- A second moderator referring that chat gets their **own** case and never learns another exists.
- A Handled case reopens when its referrer posts. If another open case for the same pair exists, that
  post is refused with 409.

The member is chosen from the chat: for a member-to-moderator chat, the member; for a member-to-member
chat, the participant who is not the referrer (none if the referrer is neither); for a
moderator-to-moderator chat, none. With no single member, the pass is chat-only and says so.

## `support:first-pass` is the only mailer

Every path that sends a case's referral mail runs
`UPDATE support_cases SET mailed_at = NOW() WHERE id = ? AND mailed_at IS NULL` and spools the mail only
if that changed a row. So running the command twice, or two runs overlapping, mails once. The old
`refer_to_support` background task survives only for rows queued before the deploy, which have no case id
and get the old plain mail (`handleReferToSupport` in `ProcessBackgroundTasksCommand.php` returns at once
for a case).

The command runs every minute, `withoutOverlapping(25)` (`routes/console.php`). Each run:

- **Sweeps first.** First pass off: every Pending case becomes Skipped, "The first pass is turned off",
  and gets the plain mail. Pending for more than `max_age_minutes` (60): Failed, "The AI helper was busy", unless an earlier failure already left a reason, which is kept.
  Running for longer than the helper wait plus a grace period: Failed, "The first pass was interrupted".
  It never runs the model on a case older than the limit.
- **Claims one case, runs it, then claims the next,** until none are Pending or `run_minutes` (10) has
  passed. The helper does one pass at a time, so claiming several up front would make them look stuck.
- **Retries.** A busy helper (429) puts the case back to Pending and does not count an attempt. A failure
  where the helper answered (any of its 502 codes, or an answer with no brief) is final at once: a
  deadline, a bad answer or an empty credit pool would happen again a minute later and spend the model and
  the member's dump again. Only a call the helper never answered (no connection, a timeout, another 5xx)
  is retried, and the third such failure fails the case (`MAX_ATTEMPTS`).
- **Reuses a recent brief, when the referral asks nothing new.** If a Done case for the same chat is under
  24 hours old, the new case has no reason or the same reason, and no visible chat message is newer than
  that brief, its brief is copied and the case is Done with no model call. Otherwise the model runs: a
  brief written for another question, or an older chat, would mislead. A reuse spends nothing, so it is
  checked before the cap.
- **Caps each referrer.** At most `per_referrer_per_day` (10) model passes per moderator a day, counted
  from run rows. Every attempt that may have spent tokens writes one, a failed one as an Error row with
  no tokens. Beyond the cap the case is Skipped, "The first pass limit for today was reached", with the
  plain mail.
- **Mails as it goes.** Finished cases are mailed once at the start of a run and after each case, each in
  its own try, so a case finished early is not held until the run ends. The mail is built before
  `mailed_at` is claimed, and the claim is put back if spooling fails.
- **A case whose chat is gone** (deleted, so `chatid` is NULL) is Skipped, "The chat is no longer there",
  and still mailed, with the reason and the case link but no chat link.
- **Forgetting a member** clears their support briefs, case reasons, case thread bodies and AI run analysis
  and transcripts.
- **A backlog check** watches the referral mail, so a mail that is built but not sent is noticed.
- **Never throws.** A case that cannot be run gets a plain reason and the plain mail.

## The fixed plain reasons

A case's `firstpass_error`, and the mail, carry one of the constants in `FirstPassService.php` and nothing
else. Upstream error text is never stored. The helper's 502 codes map to them
([ai-support-helper.md](ai-support-helper.md)). The reasons: turned off, helper busy, out of credit, took
too long, ran out of steps, data could not be read during a lockdown, helper not set up on this server (a missing or refused dump key shows as this), daily limit reached,
interrupted, helper failed, chat no longer there, helper could not be reached (a helper that cannot be reached
goes back to Pending without counting an attempt), and "The first pass was cleared after a year" (retention). A pass that could not run also records the pool state on the case, so credit
never runs out silently.

## Waiting for Support: the red count

A case is **waiting for Support** when it is Open, its first pass has finished (Done, Failed or Skipped),
and the newest message in its thread is not from Support. So a new case counts once its referral mail can
go, a Support reply clears it, and the referrer writing back, referring again or reopening a Handled case
counts it again. The rule is one SQL condition, `AwaitingSupportSQL` in
`iznik-server-go/supportcase/casestore/casestore.go`, used by both of these, so they always agree:

- **The work count.** `GET /session` carries `work.supportcases` for Support and Admin, and adds it to the
  red total, so it beeps like other work. Everyone else gets 0, including the referring moderator. ModTools
  shows it on the Support menu item and on the Cases tab of Support Tools. Support Tools opens on Cases
  while any are waiting, unless a lockdown is on or the link names a member or a tab.
- **The list.** Each row carries `awaiting`, shown as a "Waiting for Support" badge, and
  `status=Waiting` lists only those cases.

A case whose first pass is still Pending or Running is not counted, because its referral mail has not gone
yet. The sweep fails any first pass left Pending for more than an hour, so nothing waits uncounted for long.

The Cases tab starts with a short note on what it is (chats moderators referred, the first pass, and that
replies go to the moderator, never the member). The referring moderator's view of their case starts with
the same kind of note from their side.

## Who sees what

- **Support and Admin** see the case list, and the full case: the brief (question, answer, classification,
  confidence, checks, open questions, related cases, the draft and its link warnings, the pool state), the rating, the chat, and the thread. They can mark it Handled or reopen it.
- **The referring moderator** sees only their own case: its status, their reason, and the replies between
  them and Support. The Go API builds that view from an explicit struct and column list (`Get` in
  `iznik-server-go/supportcase/supportcase.go`), so the first pass is not in the response at all, not merely
  hidden by the page. They must still be able to see the chat. Anyone else gets a 404, and the role check
  comes before the row lookup.
- **The referral mail** keeps the full first pass. Its Reply-To stays the community's volunteers address,
  as it was before; this was a deliberate choice, made knowing a mail reply can quote the facts to that
  address. Do not change it without asking.
- **Nothing goes to the member from a case.** Support replies to the referring moderator through the case,
  and the moderator deals with the member. The draft is written to the moderator, and may suggest what to
  tell the member.
- **Thread limits.** Each author may post at most 10 messages per case per hour; beyond that the API
  answers 429.
- **Thread mail.** A Support message mails the referrer, and a referrer message mails the support
  address. The author is never mailed their own message. These mails (`SupportCaseMessageMail`) carry the
  message and the case link, with Reply-To the no-reply address, and nothing from the first pass. Replies
  are made in ModTools.

## The brief

The brief is JSON with a fixed shape and a version number, currently 2 (`BriefFormatter.php` turns it into
the mail text; the helper builds it). It holds:

- the **question**, one sentence: what the moderator needs answered;
- the **answer**, for a Support volunteer: the conclusion in one plain sentence, then a few bullets;
- the **classification**: Bug, Usage, Suggestion, Incident or Other;
- the **confidence**, High, Medium or Low, with one line of reason;
- the **checks**: what was looked at, one line each;
- up to three **open questions**, usually none;
- the **related cases**: ids of recent cases that are the same problem;
- the **draft** to the referring moderator, with any link warnings.

The helper investigates the one question rather than listing what is known about the member, so there are
no fixed fact sections. Version 1 was never deployed, so the code reads version 2 only.

The request carries what the helper needs to place the case in time and among others. `source.referredAt`
is when the case was opened, so logs are searched around then. `recentCases` is up to 20 cases from the
last 14 days, not this one, each with its id, date, classification, question and the latest Support reply
(cut to 600 characters). With these, one incident behind many referrals is answered once and the later
cases point at the earlier ones.

A pass takes about two minutes and a few thousand output tokens, most of the input coming from the prompt
cache. Both are measured on real referrals.

## Replay, for evaluation

`support:first-pass-replay` reruns the first pass over recent finished cases to measure tokens, time and
conclusions. Its limits are deliberate:

- Five cases by default, at most 20, one at a time.
- It refuses to start while any case is Pending or Running, and stops if one appears.
- It writes nothing to cases or mail. Each pass is recorded as a run (kind FirstPass, no asker, query
  "Replay of support case N", tokens but no transcript), so its spend is visible and counts against
  nobody's daily cap.
- Its report lists ids only, and says the model saw today's data, not the data at referral time. A
  difference from the original brief may be the data, not the model.

## Retention

`support:purge-cases` runs daily. It deletes Handled cases more than two years old, and Open cases with no
activity for two years. It clears the brief on any case more than one year old without counting that as
activity, and clears the analysis and transcript on first pass runs over a year old. A deleted case takes
its first pass runs with it. A case is also deleted with its member, as the helper's run records are.
The stored transcript has image bytes removed and is capped. No request or response body is logged.

## Settings

By name; values live in the environment, never in the repository. The batch reads
`freegle.support.first_pass` (`SUPPORT_FIRST_PASS_ENABLED`, default off, and the timing and cap settings),
all listed with their defaults in [deployment-switches.md](deployment-switches.md). The helper and the Go
API need `USERDUMP_INTERNAL_KEY`, and both sides of the helper's guard need `UX_INTERNAL_SECRET`.

## Tests

`iznik-server-go/test/supportcase_test.go` (including the referrer's raw JSON lacking every first pass
field, and the 404s), `iznik-batch/tests/Feature/SupportCases/SupportFirstPassTest.php`,
`claude-agent-sdk/first-pass.test.js`, and the Vitest specs for the case page, the list and the refer
modal under `iznik-nuxt3/tests/unit/`.
