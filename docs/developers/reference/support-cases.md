---
last_reviewed: 2026-11-07
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
---

# Support cases and the first pass

When a moderator refers a chat to Support, a **support case** is opened. The AI helper then makes one
bounded **first pass** over the member's data and the chat, and Support gets a mail that already holds
the findings. This page is the whole flow across the Go API, the batch and ModTools. The helper's own
part is in [ai-support-helper.md](ai-support-helper.md), under "First pass on a referral". For
moderators, see [Refer a chat to Support](../../moderators/managing-members.md#refer-a-chat-to-support).

The decisions behind it, and the contracts between the pieces, are in
`plans/active/2026-11-07-referral-first-pass.md` while that plan exists. This page points into the code.

## The flow

1. **Refer.** In ModTools, `ModChatFooter.vue` has Refer to Support, with an optional "What do you need
   help with?" box. It calls `POST /api/chatrooms` with `action: ReferToSupport` and the reason.
2. **Open a case.** `handleReferToSupport` in `iznik-server-go/chat/chatroom.go` writes the case in one
   transaction and answers with its id. It queues no background task: the case row is the queue. The
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
  again" message carrying the new reason.
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

The command runs every minute, `withoutOverlapping(15)` (`routes/console.php`). Each run:

- **Sweeps first.** First pass off: every Pending case becomes Skipped, "The first pass is turned off",
  and gets the plain mail. Pending for more than `max_age_minutes` (60): Failed, "The AI helper was busy".
  Running for longer than the helper wait plus a grace period: Failed, "The first pass was interrupted".
  It never runs the model on a case older than the limit.
- **Claims one case, runs it, then claims the next,** until none are Pending or `run_minutes` (10) has
  passed. The helper does one pass at a time, so claiming several up front would make them look stuck.
- **Retries.** A busy helper (429) puts the case back to Pending and does not count an attempt. Any other
  failure counts, and the third fails the case (`MAX_ATTEMPTS`). Missing key and lockdown refusals fail at
  once, because trying again cannot help.
- **Reuses a recent brief.** If a Done case for the same chat is under 24 hours old, its brief is copied
  and the case is Done with no model call. The facts have not changed and the tokens are saved.
- **Caps each referrer.** At most `per_referrer_per_day` (10) model passes per moderator a day. Beyond that
  the case is Skipped, "The first pass limit for today was reached", with the plain mail.
- **Never throws.** A case that cannot be run gets a plain reason and the plain mail.

## The fixed plain reasons

A case's `firstpass_error`, and the mail, carry one of the constants in `FirstPassService.php` and nothing
else. Upstream error text is never stored. The helper's 502 codes map to them
([ai-support-helper.md](ai-support-helper.md)). The reasons: turned off, helper busy, out of credit, took
too long, data could not be read during a lockdown, helper not set up on this server, daily limit reached,
interrupted, helper failed. A pass that could not run also records the pool state on the case, so credit
never runs out silently.

## Who sees what

- **Support and Admin** see the case list, and the full case: the brief (summary, classification,
  confidence, the six fact sections, anything unusual, what could not be found, the draft and its link
  warnings, the pool state), the rating, the chat, and the thread. They can mark it Handled or reopen it.
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
- **Thread mail.** A Support message mails the referrer, and a referrer message mails the support
  address. The author is never mailed their own message. These mails (`SupportCaseMessageMail`) carry the
  message and the case link, with Reply-To the no-reply address, and nothing from the first pass. Replies
  are made in ModTools.

## The brief

The brief is JSON with a fixed shape and a version number (`BriefFormatter.php` turns it into the mail
text; the helper builds it). Facts are always the same six sections in the same order. A section with
nothing to say has an empty list and a note such as "not collected" or "not available", never silence.
The draft cites the fact keys it used, and any key the code did not build is dropped.

The **mailbox history** (past mail with support and info) is a stub. `MailboxHistory` is an interface and
the bound implementation (`UnconfiguredMailboxHistory`) returns "not available: mailbox access is not set
up yet". The brief and the mail say so in one line, so absence is never read as "no history".

## Replay, for evaluation

`support:first-pass-replay` reruns the first pass over recent finished cases to measure tokens, time and
conclusions. Its limits are deliberate:

- Five cases by default, at most 20, one at a time.
- It refuses to start while any case is Pending or Running, and stops if one appears.
- It writes nothing to cases, runs or mail.
- Its report lists ids only, and says the model saw today's data, not the data at referral time. A
  difference from the original brief may be the data, not the model.

## Retention

`support:purge-cases` runs daily. It deletes Handled cases more than two years old and clears the brief on
any case more than one year old. A case is also deleted with its member, as the helper's run records are.
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
