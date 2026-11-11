# Candidate: the monitor FSM answers the geeks mailbox

Status: draft
Last reviewed: 2026-10-29

## Goal

Mail to geeks@ilovefreegle.org is read, sorted and acted on by the monitor FSM: machine mail is
turned into state or dropped, referrals and questions are answered in a conversation from the
mailbox when the FSM can answer them, and the rest reaches the CTO as a short summary with a
draft reply and the thread, not as twenty-six raw mails a day. Brief from Edward, 2026-10-29:
handle geeks@ conversationally where required, escalate to the CTO as appropriate, and check
the overlap with the other candidates.

## Why it qualifies

- Token-heavy because: a mail intake into the FSM (IMAP, threading, dedupe, idempotent replies), classification over a mailbox that is three quarters machine mail from a dozen senders with their own formats, a conversation state per thread, the escalation rules and the CTO digest, and a replay of six months of the mailbox to tune and prove it; the FSM also has to run on a server rather than a laptop for this to be always-on.
- Unattended because: the mailbox, its credentials and the FSM exist; the rules below are the decisions; the replay runs on recorded mail.
- Worth it because: geeks@ is about 26 messages a day, 72% machine (app store and Play release mail, Sentry, CircleCI), with roughly one support forward a day and the rest partners, vendors and volunteers; every one of them is read by one person today, and the ones that matter are buried in the ones that do not.

## What exists today

- The mailbox: `GEEKS_MAIL_USER` and `GEEKS_MAIL_PASS` in `.env` give read-only IMAP and SMTP (set up 2026-10-27); its 180-day profile is in the memory notes: about 1,900 Apple and Play mails, 600 Sentry, 100 CircleCI, support forwards about one a day.
- The monitor FSM (`monitor-fsm/README.md`): already reads Sentry and CircleCI through their APIs, fixes bugs by PR, answers Discourse questions, holds a report as `needs-detail` and asks for more, and has a dashboard and a database with a `discourse_draft` table that records every reply it sends. It does not read mail. `run-loop.sh` runs it on a laptop under a flock; it is not an always-on service and the memory notes say it needs heavy oversight.
- Refer to geeks: the support helper's `POST /api/refer-to-geeks` mails geeks@ with a referral text and the transcript, Reply-To support@ (`claude-agent-sdk/referral-email.js`), so a reply from geeks@ goes back to the support team.
- The support helper and the broker on the docker host: the always-on home for a service that reads a mailbox and spends Claude, with an audit trail.

## Overlap with the other candidates, checked

| Candidate | Overlap | Resolution |
|---|---|---|
| Discourse reply bot and single channel | Both answer questions in a conversation; the bot's rule is that reports anywhere else get a pointer to the channel | geeks@ stays as the channel for machines and outside parties, who cannot use Discourse. A volunteer who mails geeks@ with a question gets an answer and a pointer. The two share the dialogue engine and the retrieval over code, docs and wiki; build it once, in whichever lands first. |
| Support helper first pass | Referrals to geeks@ come from the helper; a reply goes to support@ | The FSM reads the referral mail, runs the helper's first pass on it (same entry point as the referral candidate), replies to support@ when confident, and escalates to the CTO when not. No second triage. |
| Incident response | Sentry, CircleCI and provider notices arrive as mail | The FSM already has Sentry and CI by API, so that mail is archived unread. Mail that no API gives (App Store review outcomes, Play policy notices, DMARC reports, certificate and domain notices, vendor billing) is turned into state and, where it is an incident trigger, handed to the incident path. |
| Deep code review | None | - |
| Infrastructure and broker | The FSM must run always-on and spend the shared pool | Run it on the docker host through the broker like the support helper; the oversight the memory notes require becomes the CTO digest plus the dashboard. |

## Decisions settled

- **Three outcomes, nothing else.** Every thread ends as archived (machine mail with an API twin, or noise), answered (a reply from geeks@, with the thread's state recorded), or escalated (a digest entry for the CTO with the summary, the draft reply, the thread, and why the FSM stopped). A thread the FSM cannot place is escalated, never left.
- **Conversation is allowed with known kinds of correspondent, with a round limit.** Support referrals, volunteers, partners asking for facts, vendors asking routine questions: up to two rounds asking for what is missing, then an answer or an escalation. Members are not written to from geeks@; a member matter goes to the support helper's case.
- **Escalate on money, commitment, law, security, press, and doubt.** Anything that would spend, promise, concede, disclose, or that the FSM cannot classify with confidence, goes to the CTO with a draft. The CTO's reply is sent from geeks@ by the FSM once approved, so the correspondent sees one mailbox.
- **Idempotent and auditable.** Every reply is recorded before it is sent (the same table as Discourse replies), threads are keyed by Message-ID, and a restart never sends twice.
- **The CTO digest replaces the inbox.** One mail a day (or on demand) listing escalations with drafts, plus a line per archived class with counts, so the inbox can be left unread.
- **Machine mail is state, not reading.** Release outcomes update the app release record, DMARC reports feed the mail-health page, provider notices with dates become calendar items; none of it is summarised to a person unless it is an incident trigger.

## Open questions for Edward

- Which correspondents may the FSM reply to without a person seeing the reply first? Recommended: support@ referrals and known volunteers; partners and vendors get drafts in the digest for the first month, then the ones that were sent unchanged move to automatic.
- Where does the escalation land: a daily digest mail to cto@, a page in the FSM dashboard, or both? Recommended: both, with the digest linking to the page where the reply is approved.
- Does the FSM move to the docker host as part of this? Recommended: yes; it is the prerequisite for always-on, and the oversight becomes the digest and dashboard rather than watching a laptop.
- What does the FSM do with mail in a language or tone it cannot handle (threats, legal)? Recommended: escalate immediately with no reply.

## External prerequisites

- [ ] The FSM's host: the docker host, through the broker, with its database on a volume. Owner: Edward (a production container, so approved and recreated by him).
- [ ] The geeks@ credentials moved from the laptop `.env` to the host's secrets.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Mail intake: IMAP reader with thread keys, dedupe, a recorded six-month corpus for tests, and the three-outcome record | - | A | monitor-fsm |
| 2 | Classifier and archive rules: per-sender formats for the machine mail, state writers (release record, DMARC, notices), measured against the corpus | 1 | A | monitor-fsm |
| 3 | Conversation: the dialogue engine shared with the Discourse bot, the two-round limit, replies from geeks@ via SMTP, recorded before sending | 1 | B | monitor-fsm |
| 4 | Referral path: a geeks@ referral runs the support helper's first pass and replies to support@ or escalates | 1 | B | monitor-fsm, claude-agent-sdk |
| 5 | Escalation: the CTO digest and the approval page, the escalate-on rules, sending the approved reply | 1 | C | monitor-fsm dashboard |
| 6 | Always-on: the FSM on the docker host through the broker, with its database and the mailbox secrets; runbook | - | D | docker-compose on the host, docs/ops |
| 7 | Replay and proof: the six-month corpus through the whole path, outcomes compared with what was actually done, and the misses listed | 2 to 5 | A | monitor-fsm tests |

## Acceptance

- Over the replayed corpus, every mail ends as archived, answered or escalated; nothing is left; the archived classes match the known machine senders.
- A support referral gets a first-pass reply to support@ within the FSM's interval when confident, and an escalation with a draft when not.
- An escalation appears in the digest and on the page with the thread, the summary and the draft; approving it sends from geeks@ once.
- A restart mid-run sends no duplicate reply.
- The FSM runs on the host through the broker with no laptop involved, and the dashboard shows the mailbox state.

## Review burden

- PRs: 7, in monitor-fsm and the host compose
- Each UI PR carries: screenshots of the dashboard page and a digest
- Each migration PR carries: none expected in the production database (the FSM has its own)
- Docs page(s) to add or update, with `covers:`: `monitor-fsm/README.md`, an ops runbook for the FSM on the host, the AI support helper page for the referral loop

## Out of scope

- Reading cto@ or support@ themselves; those are the mailbox-history service in the support candidate.
- Writing to members from geeks@.
- Replacing Sentry or CircleCI integration with their mail.

## Related plans

- `plans/token-burn/discourse-reply-bot.md` - shares the dialogue engine and retrieval.
- `plans/token-burn/support-helper-flow-through.md` - the referral first pass.
- `plans/token-burn/incident-response-automation.md` - where incident triggers from mail go.
