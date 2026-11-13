---
last_reviewed: 2026-11-13
owner: Freegle dev team
covers:
  - iznik-server-go/chat/chatroom.go
  - iznik-server-go/test/chat_test.go
  - iznik-batch/app/Console/Commands/Support/**
  - iznik-batch/app/Services/Support/**
  - iznik-batch/app/Mail/Chat/ReferToSupportMail.php
  - iznik-batch/resources/views/emails/mjml/chat/refer-to-support.blade.php
  - iznik-batch/tests/Feature/Support/ReferToSupportTest.php
  - iznik-batch/database/migrations/2026_11_13_000001_drop_support_cases_tables.php
  - iznik-nuxt3/modtools/components/ModChatFooter.vue
  - iznik-nuxt3/modtools/pages/support/refer/[[id]].vue
---

# Refer to Support

When a moderator refers a chat to Support, Support gets **one mail**. It comes from the volunteers
address of the community the referral is for, says who referred which chat and why, and carries an
AI **first pass**: a bounded investigation of the question the moderator is asking. Support replies
by email, and the reply reaches that community's moderators as a normal chat in ModTools.

Nothing is stored about a referral except the queued task that carries it. There is no case, no
thread in ModTools and no count of referrals waiting. This page is the whole flow across the Go API,
the batch and ModTools. The helper's own part is in [ai-support-helper.md](ai-support-helper.md),
under "First pass on a referral". For moderators, see
[Refer a chat to Support](../../moderators/managing-members.md#refer-a-chat-to-support).

## The flow

1. **Refer.** In ModTools, `ModChatFooter.vue` has Refer to Support, with an optional "What do you
   need help with?" box. It calls `POST /api/chatrooms` with `action: ReferToSupport` and the reason.
2. **Queue.** `handleReferToSupport` in `iznik-server-go/chat/chatroom.go` checks the moderator may
   refer the chat, chooses the community, and queues a `refer_to_support` row in `background_tasks`.
   It answers at once. The reason is capped at 2000 characters.
3. **First pass and mail.** `support:first-pass` (`iznik-batch/app/Console/Commands/Support/`) takes
   the task, asks the helper for the first pass (`FirstPassService`), and sends `ReferToSupportMail`
   to the support address, with the brief, or with the plain reason there is none. Sending the mail
   is what marks the task processed.
4. **Reply.** Support replies to the mail. It goes to the community's volunteers address, which
   the incoming mail code turns into a chat to that community's moderators.

## Who may refer, and a double-click

Only a moderator (Moderator, Support or Admin) who can see the chat may refer it, and a Group chat
cannot be referred: a referral spends a model pass and mails Support, so it is not open to members.

The same moderator referring the same chat with the same reason within two minutes is a
double-click: nothing more is queued and Support gets one mail. A different reason, or a later
click, is a new referral and a new mail.

## Which community it comes from

The mail is From and Reply-To `<nameshort>-volunteers@` the group domain, named "<Community>
Volunteers". `referGroup` in `chatroom.go` chooses the community when the referral is queued:

- **A member-to-mods chat** has a community of its own, and that is used.
- **A member-to-member chat** has none. It is a community the referrer moderates that a
  participant belongs to, which is also what lets the referrer see the chat. With several: the
  community of the latest post the chat is about, then one both participants belong to, then the
  one a participant joined most recently.
- **No community fits** (Support or Admin referring a chat outside their own communities): the mail
  comes from the no-reply address and Reply-To is the referrer's own email, so a reply still reaches
  someone.

The member the referral is about is chosen from the chat too: for a member-to-mods chat, the
member; for a member-to-member chat, the participant who is not the referrer (none if the referrer
is neither); for a chat between moderators, none. With no single member, the pass is chat-only.

## What the mail holds

`ReferToSupportMail` is MJML (`emails/mjml/chat/refer-to-support.blade.php`), with a plain text part
that says the same. It holds:

- the referring volunteer, the community, the member and the chat, by name and id;
- the moderator's reason;
- a button to the chat (`/support/refer/<chatid>`) and one to the member in Support Tools;
- the first pass: the question, the answer, classification and confidence, open questions, a
  suggested reply to the volunteer with any link warnings, what was checked, and how much of the AI
  credit pool was used;
- or one line saying there is no first pass, and why.

It **never holds what was said in the chat**. Support read that in ModTools, through the button, so
member messages do not sit in the Support mailbox. The first pass is about a member and does go in
the mail, and a reply by email can quote it to the volunteers address; this was a deliberate choice.

## `support:first-pass`

The command runs every minute, `withoutOverlapping(25)` (`routes/console.php`), and holds its own
lock. `queue:background-tasks` leaves `refer_to_support` rows alone, because a pass can take minutes
and would hold every other task up. Each run takes the waiting referrals oldest first, one at a
time, until none are left or `run_minutes` (10) has passed. For each:

- **The chat is gone:** mailed at once, "The chat is no longer there", with no chat link.
- **First pass off:** mailed at once, "The first pass is turned off".
- **Waited more than `max_age_minutes` (60):** mailed without a pass, with the reason an earlier
  attempt left, or "The AI helper was busy". The model is never run on a referral this old.
- **Over the cap:** at most `per_referrer_per_day` (10) model passes per moderator a day, counted
  from run rows. Beyond it the referral is mailed with "The first pass limit for today was reached".
- **Otherwise the helper is asked.** A busy helper (429) or one that cannot be reached leaves the
  referral waiting, counts no attempt, and ends the run. A failure where the helper answered (any
  of its 502 codes, or an answer with no brief) is mailed at once with its reason: a deadline, a bad
  answer or an empty credit pool would happen again a minute later and spend the model and the
  member's dump again. A call the helper never answered properly (a timeout, another 5xx) is tried
  again on a later run, and the third start is the last (`MAX_ATTEMPTS`). A start is counted before
  the helper is asked, so a run that is killed mid-pass still counts.

Every referral therefore ends in exactly one mail, within about an hour whatever the helper is
doing. The mail is built first, then the task is claimed (`processed_at`), then the mail is spooled,
and a failure after the claim puts it back. A finished brief is kept on the task until its mail has
gone, so a mail that fails is sent by the next run without another pass. Once the mail has gone, the
moderator's reason and the brief are taken out of the task row, because they are about a member and
nothing reads them again.

A task that names no chat or no referrer is marked failed and mails nothing. A monitoring check
(`support:first-pass` in `ScheduledOutcomeRegistry`) breaches when a referral is still unprocessed
after 70 minutes.

## The fixed plain reasons

The mail, the task's `error_message` and a run row's error carry one of the constants in
`FirstPassService.php` and nothing else. Upstream error text is never stored. The helper's 502 codes
map to them ([ai-support-helper.md](ai-support-helper.md)). The reasons: turned off, helper busy,
out of credit, took too long, ran out of steps, data could not be read during a lockdown, helper not
set up on this server (a missing or refused dump key shows as this), daily limit reached,
interrupted, helper failed, chat no longer there, and helper could not be reached.

## The brief

The brief is JSON with a fixed shape and a version number, currently 2 (`BriefFormatter.php` turns
it into the mail's parts, its text and the run's markdown; the helper builds it). It holds:

- the **question**, one sentence: what the moderator needs answered;
- the **answer**, for a Support volunteer: the conclusion in one plain sentence, then a few bullets;
- the **classification**: Bug, Usage, Suggestion, Incident or Other;
- the **confidence**, High, Medium or Low, with one line of reason;
- the **checks**: what was looked at, one line each;
- up to three **open questions**, usually none;
- the **draft** to the referring moderator, with any link warnings.

The helper investigates the one question rather than listing what is known about the member, so
there are no fixed fact sections. The request carries `source.referredAt`, when the referral was
queued, so logs are searched around then.

A pass takes about two minutes and a few thousand output tokens, most of the input coming from the
prompt cache.

## What is kept

- **The run.** Every attempt that may have spent tokens writes a `support_ai_runs` row of kind
  FirstPass: tokens, cost, model, pool state, and on success the brief as markdown and the
  transcript. It is what the daily cap counts, and it shows with the helper's other runs in SysAdmin.
- **Retention.** `support:purge-runs` runs daily and clears the analysis and transcript of first
  pass runs more than a year old. Forgetting a member clears them on every run about that member.
  The stored transcript has image bytes removed and is capped. No request or response body is logged.
- **Nothing else.** The mail is in the Support mailbox. The task row keeps the chat, referrer,
  member and community ids.

## Settings

By name; values live in the environment, never in the repository. The batch reads
`freegle.support.first_pass` (`SUPPORT_FIRST_PASS_ENABLED`, default off, and the timing and cap
settings), all listed with their defaults in [deployment-switches.md](deployment-switches.md). The
helper and the Go API need `USERDUMP_INTERNAL_KEY`, and both sides of the helper's guard need
`UX_INTERNAL_SECRET`.

## Tests

`iznik-server-go/test/chat_test.go` (the `TestReferToSupport*` tests: who may refer, the task that is
queued, the community chosen, the double-click), `iznik-batch/tests/Feature/Support/ReferToSupportTest.php`,
`claude-agent-sdk/first-pass.test.js`, and the Vitest specs for the refer modal and the refer page
under `iznik-nuxt3/tests/unit/`.
