# Candidate: the support helper runs first, before a person sees a referral

Status: draft
Last reviewed: 2026-10-28

## Goal

Two steps, in order. First, inside the framework we have: when a moderator presses **Refer to
Support** on a chat, the AI support helper makes its first pass before the support team sees
the case, so what reaches them is the referral plus a summary of what happened, the facts the
helper gathered, and a first-draft reply, not a bare link to a chat. Second, in the
no-communities thought experiment, the same first pass feeds the new one-page work queue, so a
referral is one more row with the system's work already done and one action on top. Brief from
Edward, 2026-10-28, with the standing rule: design for token minimisation, as the helper's
information gathering already does.

## Why it qualifies

- Token-heavy because: it touches the Go referral endpoint, the batch background task, the helper service (a new headless entry point and a run-record shape), ModTools (a referral queue and the row in the groupless home), the referral mail, and tests at every layer, then the same again folded into the experiment branch; and the quality work is an evaluation over past referrals.
- Unattended because: every input exists (the referral task, the user dump, the helper's tools, the broker); the choices below are few and nothing waits on a person mid-build.
- Worth it because: third-line support runs through about fourteen escalations a month straight to Edward and the support team works from a mailbox; a first pass with the facts gathered turns each case from an investigation into a read-and-send, and it is the step that lets support work with nobody at the desk.

## What exists today

- `iznik-server-go/chat/chatroom.go` `handleReferToSupport`: the ModTools button (`ModChatFooter.vue`) queues a `refer_to_support` background task with the chat id and the referrer; `iznik-batch/app/Console/Commands/Queue/ProcessBackgroundTasksCommand.php` `handleReferToSupport` sends `App\Mail\Chat\ReferToSupportMail`, a plain-text mail to support with "please review the chat at /support/refer/<id> and reply to this email". Nothing else happens; the helper is not involved.
- `docs/developers/reference/ai-support-helper.md`: the helper is a Node service (`claude-agent-sdk/`) that a Support volunteer invokes by hand from ModTools (`ModSupportAIAssistant.vue`) for a named member; it starts from a per-user SQLite dump built by `iznik-server-go/userdump` (about 70 tables, Loki, Sentry, secrets redacted, collected concurrently under a time budget) and queries that locally, which is how it keeps tokens down: the facts are gathered by code, and the model reasons over a compact dump rather than fishing with tools. It records each run (`run-record.js`) with cost and tokens, and has **Refer to geeks** (`POST /api/refer-to-geeks`) for the next escalation.
- The helper runs on the docker host through the claude-broker (`docs/ops/runbooks/claude-broker.md`); it already has the credentials and the audit path this needs.
- `plans/reports/Multi source support intake design.md`: the wider design for support intake across mail, Discourse and ModTools; this candidate is the ModTools referral slice of it, done first because the trigger and the helper both exist.
- In the no-communities experiment (`plans/token-burn/automated-review-with-no-communities.md`, PR #1576), "a member's message to Freegle gets an automatic reply at once; a volunteer may add one", and the ModTools home is one list of things the system already did. A referral belongs on that list.
- The support team's reply to John Carroll's support-process proposal (22 October 2026, from the support mailbox) lists what hurts today, and each item maps onto this candidate: referrals arrive in Gmail unthreaded while ModTools threads them, so a conversation is hard to follow; a reply cannot include the volunteer's ModTools address without the original mail being cut off, so they forward by hand; volunteers often do not reply at all; the AI tool does not take screenshots, which is what Edward usually asks for; it has told them it is out of credit; its suggested replies have been wrong and are always re-worded to sound human; more problems are reported about ModTools than about the member site; and they ask whether the "one channel" would replace the local team's contact address. John's own summary (25 October) is that his proposal was a set of suggestions about Edward's workload, not a design, and that nobody is available for the review team it needed.
- Unknown: how many referrals a month, and how many the helper's first pass could close without a person. The background task table and the support mailbox can say.

## Decisions settled

- **Facts by code, judgement by model, once.** The first pass is: build the user dump (code), run one bounded helper pass over it with a fixed brief (summary, facts that matter, draft reply, confidence, what it could not find), store the run record. No open-ended tool loop, no second call unless the pass says it needs one specific thing. This is the token rule the helper already lives by.
- **The person always sees the first pass, and the first pass never sends.** The draft reply is for the support volunteer to send, edit or discard. Nothing goes to the member or the moderator from the first pass itself.
- **The referral mail carries the result; it does not replace the queue.** Mail stays the support team's habit, so the mail gets the summary and draft at the top and the link below. A ModTools referrals list shows the same, so whichever they open, it is there.
- **Reuse, do not duplicate.** The same run record, the same helper service, the same dump; the groupless step changes where the row is shown, not what is computed.
- **The first pass includes the member's prior mail with support@ and info@** (Edward, 2026-10-28): what they wrote, what was said back, and when, fetched by code (read-only IMAP, matched on the member's known addresses), summarised into the brief, and shown to the volunteer as dated headers and short excerpts. The same service feeds the Thanks section of the finance dashboard candidate, so it is built once.
- **The case lives in ModTools, threaded, with replies both ways.** A referral is a case with the chat, the first pass, the support volunteer's reply and the referring volunteer's reply in one thread, so Gmail's flat inbox stops being the workspace. Mail still notifies and can still be answered, but the thread of record is the case. This is what makes it fit the no-communities world: a case is between a member, a referring volunteer and Freegle, with no local team address in the loop.
- **Screenshots go into the first pass.** Images attached to the chat or the case are passed to the model with the dump (the helper already uses a vision-capable model for images elsewhere), because "send me a screenshot" is the first thing a person asks.
- **Credit never runs out silently.** The helper spends the shared pool through the broker; the case shows the pool's state, and a pass that cannot run says so on the case rather than failing in the volunteer's face.
- **Every draft says where it came from, and every send is rated.** The first pass cites the dump facts it used; the volunteer's edit-or-send is recorded (the rating component exists), so the evaluation in task 4 learns from what they changed.
- **The first pass also classifies.** Bug, usage issue, suggestion or incident, as John Carroll's draft proposes: a bug goes to the monitor FSM with the structured report; a suggestion to the ideas store; an incident to the incident candidate's path; a usage issue stays as the case. The support team's own job in his draft, answering usage issues and feeding back trends, is the case plus the "what people care about" source in the UX loop.
- **Confidence is shown, never hidden.** A first pass that could not find the member, or found conflicting facts, says so at the top in one line.

## Open questions for Edward

- How far back does the mailbox history go, and does it include mail the member sent to a moderator team address? Recommended: two years, support@ and info@ only; team addresses are a different data-protection question.
- What does "all relevant info" mean for the support team: the whole dump summary, or a fixed set (member, recent posts and chats, bans and merges, device and app version, recent errors)? Recommended: the fixed set plus "anything unusual the helper noticed", because a fixed shape is what makes the mail scannable.
- Should the first pass run for every referral, or only when the referrer adds a reason? Recommended: every referral; the reason, when given, goes into the brief.
- Who can see the ModTools referrals list? Recommended: Support and Admin, the same gate as the helper today.
- Does the referring volunteer reply inside the case in ModTools, by mail, or both? Recommended: both, with the case as the record; the support team asked for volunteers to reply at all, so the lowest-friction path wins.
- Does the first pass also run for support mail that arrives without a referral (the mailbox)? Recommended: no, not in this candidate; that is the intake report's scope.
- In the groupless step, does the helper's draft reply go to the member automatically when confidence is high and no volunteer acts within a day? Recommended: not yet; show it, measure how often a volunteer sends it unchanged, decide after.

## External prerequisites

- [ ] A count of referrals a month from `background_tasks` on production, read-only, and the support team's view of what they look up for each. Owner: Edward or whoever runs the measurement.
- [ ] Read-only IMAP credentials for support@ and info@ (and giftaid@ for the finance use), held as secrets on the batch host. Owner: Edward.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Helper: a headless first-pass entry point taking a chat id and referrer, building the dump, running one bounded pass with the fixed brief, writing a run record with cost; tests with recorded dumps | 1a | A | claude-agent-sdk |
| 1a | Mailbox history service: read support@ and info@ by IMAP for a member's known addresses, cache headers and a short excerpt per member with an expiry, expose it to the dump and to ModTools; shared with the finance dashboard's Thanks section | - | A | iznik-batch, iznik-server-go/userdump |
| 2 | Batch: the referral task calls the first pass (with a timeout and a fallback to the plain mail), then sends the mail with summary, facts, draft and confidence at the top | 1 | B | iznik-batch |
| 3 | ModTools: Support-gated cases: each referral as a thread (chat, first pass, replies from support and from the referring volunteer), with actions (reply to member, reply to volunteer, copy draft, mark handled); mail notifications that link back; the existing `/support/refer/<id>` page becomes the case page | 1 | C | iznik-nuxt3/modtools, iznik-server-go, iznik-batch |
| 3a | Screenshots and attachments from the chat or the case passed into the first pass; pool state shown on the case | 1, 3 | C | claude-agent-sdk, iznik-nuxt3/modtools |
| 4 | Evaluation: replay the last N referrals through the first pass; a support volunteer rates summary and draft; token and time per pass reported | 1 | A | claude-agent-sdk |
| 5 | Groupless fold-in, on the experiment branch: a referral is a row in the one-page work queue with the first pass inline; the automatic reply to the member names what happens next | 1, 3 | D | iznik-nuxt3/modtools (experiment branch), iznik-batch |
| 6 | Docs: the helper reference page, the moderator guide for Refer to Support, the ops note on cost | 2, 3 | B | docs |

## Acceptance

- A referral from ModTools produces, within a few minutes, a mail to support whose first screen says what the case is, the facts that matter, the member's prior mail with support and info as dated headers and excerpts, a draft reply and a confidence line; the chat link is still there.
- The same referral appears in the ModTools referrals list with the same content and three actions.
- A first pass uses one model call in the normal case; the run record shows tokens and cost, and the evaluation reports the average.
- When the helper is down or slow, the plain referral mail still goes out and says the first pass was skipped.
- On the experiment branch, a referral is a row in the one-page home with the first pass inline, and nothing about it waits for a person.
- No first pass ever sends anything to a member or a moderator.

## Review burden

- PRs: 6 (5 on master, 1 on the experiment branch)
- Each UI PR carries: screenshots or a walkthrough video of the referral mail and the list
- Each migration PR carries: raw production SQL in the description (a referrals table or columns on the run record, if the list needs them)
- Docs page(s) to add or update, with `covers:`: `docs/developers/reference/ai-support-helper.md`, the moderator page that mentions Refer to Support, the broker runbook if cost reporting changes

## Out of scope

- Support mail that arrives without a referral, Discourse, and the wider intake design.
- Letting the first pass reply to anyone.
- Changing what the helper may read.

## Related plans

- `plans/reports/Multi source support intake design.md` - linked; this is its first slice.
- `plans/token-burn/automated-review-with-no-communities.md` - task 5 lands there.
- `plans/token-burn/modtools-by-role.md` - the referrals list is a class in that home list.
