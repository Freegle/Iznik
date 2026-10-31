# Candidate: an always-on Discourse reply bot, and the single channel for reports

Status: draft
Last reviewed: 2026-10-28

## Goal

A bot that lives on Freegle's Discourse and answers volunteers' general questions about how
Freegle works: its policies, its rules, what the platform does and why. It reads the code, the
docs, the volunteer wiki and Discourse itself, and nothing else: no database, no member data,
no mail, nothing confidential. It is friendly and conversational, and it asks questions until
it is sure what the person means before it answers. Brief from Edward, 2026-10-28. It overlaps
the monitor FSM's question-answering a little, but it is a different and separate thing: the FSM
fixes bugs and answers one-shot factual questions as a side effect; this bot holds a dialogue.

John Carroll's draft "Support Interface Evolution" (version 0.1, received 2026-10-28) adds the
second half of the job. He counts at least seventeen ways people contact the CTO, most needing
several rounds to extract the detail, and proposes one technical channel with AI-assisted intake:
the assistant classifies a report as a major incident, a bug, an improvement suggestion or a
usage issue; gathers what is missing by asking (steps, expected and actual behaviour, screenshots,
frequency, impact, device and OS, with help finding them); writes a structured report; and the
lists of bugs and suggestions are open for anyone to see. His draft then hands the lists to human
teams (a reviewing team to prioritise and reply, an incident team) and he says himself that
nobody is available to staff them. In this candidate the bot is that assistant, and the system,
not a team, does the prioritising and the replying: a bug goes to the monitor FSM, which already
fixes bugs by test and PR and already holds a thin report as `needs-detail`; a suggestion goes to
the UX ideas generator, where evidence rather than a vote decides it; a usage issue gets an
answer with a citation, or a case for the support helper when it is about one member.

## Why it qualifies

- Token-heavy because: it is a new service (ingest Discourse, retrieve across four sources, hold multi-turn state per thread, post), the intake dialogue for four report types with a structured report at the end and hand-offs to the FSM, the ideas generator and the support helper, an open bugs-and-suggestions page generated from GitHub and the FSM's state, plus an evaluation harness run over months of past Discourse threads, plus the safety tests that prove it never reaches confidential data.
- Unattended because: every source it reads is public or already reachable (the repo, `docs/`, the wiki API, the Discourse API as AI_Edward), and the broker already carries the Claude side; the only human step is the policy decisions below.
- Worth it because: volunteers ask the same questions about rules and features over and over, the people who know the answers are the ones with the least time, and a question answered well in public is answered for everyone who searches later.

## What exists today

- `monitor-fsm/README.md` "Answering questions" and `monitor-fsm/src/discourse.ts`: triage classifies posts as bug, question, feature request or off-topic; an unanswered question gets a research sub-agent that reads `docs/` and the running code and posts two to four sentences, quoted and threaded, with no human approval; a reading-grade check throws back anything that reads like documentation; one reply per post, never twice. That is one-shot: it never asks a clarifying question and never follows a thread.
- Discourse access: the AI_Edward account (moderator, trust level 1) through an `Api-Key` header from `~/profile.json`; `/admin/*` is invisible to it and it cannot edit others' wiki posts. House rules in memory: replies short and plain, credited to AI_Edward by the account not by a prefix, fix replies carry a "Technical details" link, never promise to report back.
- `docs/` is the canonical "how it works" for members, moderators, developers and ops, with `covers:` front matter; `plans/` is scratch and must not be a source.
- The volunteer wiki is MediaWiki 1.43 at `wiki.ilovefreegle.org` with the standard API (`api.php`, checked 2026-10-28), so pages can be searched and read without scraping.
- The AI support helper (`docs/developers/reference/ai-support-helper.md`) is the house pattern for a Claude service with tools and a run record, but it reads member data on purpose; this bot must not share its tools.
- The Claude broker on the docker host (`docs/ops/runbooks/claude-broker.md`) is how production services reach Claude without holding a token.
- Unknown: how many Discourse posts a month are general questions rather than bug reports; the triage data in the FSM's database can say.

## Decisions settled

- **Separate from the monitor FSM.** Its own service, its own state, its own account or at least its own name on Discourse, so a volunteer can tell "the bot that explains" from "the bot that fixes". It may read the FSM's triage so the two never answer the same post.
- **Sources are allowlisted, not blocklisted.** The repo at a pinned commit, `docs/`, the wiki API and Discourse. No database connection string, no Loki, no mail, no Sentry, no ModTools API. The tests prove the process has no credential for any of them.
- **Dialogue first.** When a question could mean more than one thing, or names a feature loosely, it asks one short question and waits. It answers only when it can name the page, wiki article or code it is answering from.
- **It says what it is.** Every first reply in a thread says it is an automated helper, and every answer says where the answer came from.
- **One channel, and the bot says so.** Reports raised anywhere else get one reply pointing at the channel, as John's draft proposes; he expects pushback, and the answer to it is that the channel replies within minutes and the others do not.
- **No human reviewing team is assumed.** Prioritising and replying are the system's job (the FSM for bugs, the experiments loop for suggestions, this bot and the support helper for usage), with people sampling. John's draft reaches the same place by noting the team cannot be staffed.
- **Suggestions are measured, not voted on.** A suggestion becomes a candidate for the UX experiments loop; the open list shows what was tried and what the evidence said. This replaces the draft's optional community voting step.
- **The open lists are generated, never maintained by hand.** Bugs from GitHub issues and the FSM's record, suggestions from the ideas store, each with status and a one-line reason.
- **It never states policy it cannot cite.** Where the rules and the code disagree, it says so and asks a human to decide, rather than picking one.

## Open questions for Edward

- Which Discourse categories does it watch, and does it reply only when asked (a mention, or a category for questions) or to any thread that looks like a question? Recommended: reply only when mentioned or in a named questions category for the first month, then widen.
- Is Discourse the single channel for volunteers, with the site's own contact path the channel for members, and everything else answered with a pointer? Recommended: yes; the seventeen channels John counts are the cost, and two is the number.
- Its own account or AI_Edward? Recommended: its own account with a plain name and a profile that says what it is, so its replies are not read as Edward's.
- How many clarifying turns before it stops? Recommended: two; then it summarises what it understood and hands over.
- May it quote the wiki and docs at length, or only link? Recommended: short quotes with the link.
- Which Claude account pool does it spend? Recommended: the shared Freegle pool through the broker, like the support helper; it is always-on, so not the burn pool.

## External prerequisites

- [ ] A Discourse account for the bot with an API key, trust level enough to reply in the chosen categories. Owner: Edward.
- [ ] A read-only wiki API user if the wiki ever restricts anonymous reads (it does not today).

## Tasks

| # | Task | Depends on | Parallel track | Threshold |
|---|------|-----------|----------------|-----------|
| 1 | Retrieval over the four sources: repo at a pinned commit, `docs/`, wiki API, Discourse search; one interface, cited results | - | A | new service |
| 2 | Thread state and dialogue policy: understand, ask, answer, hand over; per-thread memory; the two-turn limit | - | B | new service |
| 3 | Discourse ingest and posting: watch, mention detection, quoted threaded replies, one reply per post, backoff | 2 | B | new service |
| 3a | Intake: classify a report as incident, bug, suggestion or usage; ask for what is missing (with help finding device and OS); write the structured report; hand off to the FSM (bug, with the report attached as the `needs-detail` answer), the UX ideas store (suggestion) or the support helper (usage about one member); incident goes to the incident candidate's path | 2, 3 | B | new service, monitor-fsm, iznik-batch |
| 3b | The open lists: a public page of bugs and suggestions with status, generated from GitHub, the FSM record and the ideas store; linked from every reply | 3a | C | new service or status-nuxt |
| 4 | Evaluation harness: replay past question threads, score "asked when it should", "answered with a citation", "stayed out of bugs"; nobody is ground truth, so report agreement with what volunteers eventually said | 1, 2 | C | new service |
| 5 | Safety tests: no credential, no network path to DB, mail, Loki or ModTools; a reply never contains a member name or email it did not see in the thread | 1, 3 | A | new service |
| 6 | Deploy on the docker host through the broker; ops runbook; developer reference page | 3, 5 | B | docker-compose, docs |

## Acceptance

- Asked an ambiguous question in a test category, the bot asks one clarifying question, and after the answer replies with a citation to a docs page, wiki page or code.
- Asked something it cannot cite, it says so and hands over.
- A bug report with no steps gets one question per missing item until the report is complete, then appears in the FSM's queue with the structured report and in the open list; a suggestion appears in the ideas store and the open list; a usage report gets an answer with a citation.
- Its process has no database, mail, Loki, Sentry or ModTools credential, and the tests fail if one is added.
- It never replies to a post the monitor FSM has classified as a bug.
- Every reply passes the same reading-grade check the FSM uses.
- The replay harness over the last six months shows where it would have asked and where it would have answered, with the misses listed.

## Review burden

- PRs: 8, in a new service directory plus small changes in monitor-fsm
- Each UI PR carries: none, no UI; the Discourse replies in the test category are the screenshots
- Each migration PR carries: none expected (state in the service's own store)
- Docs page(s) to add or update, with `covers:`: ops runbook for the service; developer reference page

## Out of scope

- Fixing bugs or opening PRs (the monitor FSM).
- Anything about a specific member or a specific moderation case.
- Replying by email or in ModTools.

## Related plans

- `monitor-fsm/README.md` - linked; the question-answering step there stays as it is.
- `plans/reports/Multi source support intake design.md` - linked; that is member support, this is volunteer questions.
