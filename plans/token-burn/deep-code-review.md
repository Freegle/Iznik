# Candidate: a deep review of the whole codebase, with every finding proved by a test

Status: draft
Last reviewed: 2026-10-29

## Goal

Read every area of the running code the way the chase-up mails were read on 2026-10-29, when a
session found real faults in them: adversarially, against what the code is supposed to do for
the member, and prove each finding with a failing test before anyone is asked to look at it.
The output is a ranked list of verified bugs and worthwhile improvements, each with its
reproduction, and small fix PRs for the ones that are clearly right. Brief from Edward,
2026-10-29: "clearly you could do a deep review and come up with bugs or improvements."

## Why it qualifies

- Token-heavy because: it is a sweep over three codebases (Go API, Laravel batch, Nuxt web and ModTools) reading whole areas rather than diffs, an adversarial pass per area, a reproduction test for every candidate finding, a verifier that re-derives each one from scratch, and fix PRs with coverage; tokens are the whole cost and the result scales with how much is read.
- Unattended because: the inputs are the code, the docs that say what it should do, and the test suites; no step waits on a person except the final triage of what to fix now.
- Worth it because: the chase-up mails had been running for months with faults nobody had reported; the same is true of other mail, of edge cases in chat and moderation, and of anything members do not complain about because they just leave. A review that proves its findings is the cheapest way to find them.

## What exists today

- The review tooling in the repo: the `code-review` skill at effort levels (diff-based), `migration-parity-audit` and `ast-behavior-audit` (for migrations), the monitor FSM's adversarial review of its own fixes (`monitor-fsm/README.md`, evidence and review states), and the house rule that a fix is proved by a failing test first (`docs/developers/reference/coding-standards.md`).
- The chase-up family, where the first faults were found: `iznik-batch/app/Console/Commands/Message/ChaseUpCommand.php`, `Chat/ChaseupExpectedCommand.php`, `Chat/ChaseupModsCommand.php`, `Notification/ChaseUpNotificationsCommand.php`, `Mail/ChaseAdminCommand.php`, with the mail templates beside them; commit `a540080e66` (2026-10-29) is the first fix from that review.
- `docs/` with `covers:` front matter: for most areas there is a page saying what the code should do, which is what the review reads against. Where there is no page, the review says so, and that is a finding too.
- The four suites and their coverage: a reproduction test lands in the suite that owns the code, and the coverage report shows the areas nobody has tested, which is where to read first.
- The UX telemetry and experiments framework: for member-facing flows, the funnels say where people drop out, which points the review at the code behind the drop.
- Unknown: how long a whole-area read takes in tokens; the first area sets the rate.

## Decisions settled

- **No finding without a failing test.** A reviewer's claim is a hypothesis; it becomes a finding only when a test reproduces it against the current code. Style, naming and "could be simpler" are improvements, listed separately and never mixed with bugs.
- **Two independent reads per area, then a verifier.** A second reviewer who has not seen the first's output reads the same area; the verifier takes the union and tries to break each item. This is the pattern the monitor FSM already uses for its own fixes, and it is what keeps the list honest.
- **Read against the documented behaviour, not against taste.** The docs page for the area, the Rules and the member-facing copy define correct; where they disagree with the code, the disagreement is the finding and a person decides which is right.
- **Fix only the clearly right ones; list the rest.** A fix PR is opened when the test fails, the fix is local, and nothing in the docs or the conventions argues the other way. Everything else goes on the list with its reproduction for Edward to triage.
- **Mail and anything timed first.** Chase-ups, digests, reminders, notifications: code that runs on a schedule and that nobody watches is where faults live longest.
- **No finding about the direction or the group model.** The review reads what the code does today; what it should do tomorrow is other candidates' business.

## Open questions for Edward

- Which areas first? Recommended: scheduled mail and notifications, then chat (send, hold, review, delivery), then moderation actions, then the member flows the telemetry marks as leaky, then the Go API's permission checks endpoint by endpoint.
- What may be fixed without asking? Recommended: anything where a failing test proves behaviour contradicts the docs or the copy, the fix is under about fifty lines, and the PR carries the test; everything else is listed.
- How many PRs a day can you read? Recommended: five, batched by area with one summary comment per batch, so the review paces itself to you rather than flooding.
- Do improvements (performance, duplication, dead code) get PRs or only a list? Recommended: a list, with the top ten as PRs at the end if the bug work finishes early.

## External prerequisites

- [ ] Nothing outside the repo.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Harness: a per-area brief (what to read, the docs page, the suite that owns it), the two-reader-plus-verifier flow, the finding record with reproduction, and a results page; run on the chase-up area to calibrate against the faults already found | - | A | monitor-fsm or scripts, docs |
| 2 | Scheduled mail and notifications: every command in `Mail/`, `Message/`, `Chat/`, `Notification/`, `Digest*`, their services and templates | 1 | B | iznik-batch tests, fix PRs |
| 3 | Chat: send, hold, review, delivery, push, mail reply, TrashNothing paths, in Go and Laravel | 1 | C | iznik-server-go, iznik-batch tests, fix PRs |
| 4 | Moderation actions and member state: approve, reject, hold, spam, ban, merge, delete, and what each leaves behind | 1 | D | iznik-server-go, iznik-batch tests, fix PRs |
| 5 | Member flows the telemetry marks: give, ask, reply, outcome, join, settings, in the web app and the app's shared code | 1 | E | iznik-nuxt3 tests, fix PRs |
| 6 | Go API permission checks, endpoint by endpoint against who may see or change what | 1 | F | iznik-server-go tests, fix PRs |
| 7 | Triage page and close-out: the ranked list with reproductions, what was fixed, what is listed, what the docs lacked | 2 to 6 | A | docs |

Tracks B to F run at once in separate worktrees; each produces its own batch of PRs and list entries.

## Acceptance

- Every bug on the list has a test that fails on master and is named in the entry; a reader can run it.
- Every fix PR carries its reproduction test, passes the suites, and keeps coverage.
- The chase-up calibration run finds the faults already fixed in `a540080e66` and its siblings without being told about them.
- Improvements are listed apart from bugs, with a one-line reason each.
- No more PRs are open at once than the agreed daily number, and each batch has one summary comment.
- The close-out names every area read, every area not read, and every docs page the review found missing or wrong.

## Review burden

- PRs: many small ones, paced to the daily number; expect dozens over the window
- Each UI PR carries: a screenshot where the fix is visible
- Each migration PR carries: raw production SQL in the description (rare; a review fix should not need schema changes)
- Docs page(s) to add or update, with `covers:`: the page for each area whose behaviour the fix changes, in the same PR, as the freshness check requires

## Out of scope

- Rewrites, refactors for their own sake, and dependency upgrades.
- Anything the other candidates own (support, incidents, the read model, the group model).
- Security findings beyond what the permission pass turns up; a separate security review is its own thing.

## Related plans

- `monitor-fsm/README.md` - the adversarial review and evidence pattern this reuses.
- `plans/2026-09-29-perf-backlog.md` - performance improvements found by measurement live there, not here.
