---
paths:
  - "monitor-fsm/**"
---

# Traps in monitor-fsm

What it does is in [`monitor-fsm/README.md`](../../monitor-fsm/README.md). This page is the
behaviour that has surprised people.

## It looks idle when it is broken

The driver exits cleanly on several fatal conditions, so "nothing happened" is the same
observable state as "nothing needed doing". Check these before concluding it is simply quiet:

- The API key is out of credit. The loop runs and does nothing.
- The first brain call of a lap can return only "Prompt is too long", and the run continues past
  it as though it had an answer.
- A state file that has been truncated to zero bytes kills every run at startup.
- Discourse answers a 429 with the wait in the response body, not in a `Retry-After` header. A
  caller that reads only the header gives up while Discourse is still refusing, the action
  throws, and the lap carries on with no topics and no reporter confirmations.

## A `[skip ci]` commit at master's head reads as a red master

The docs screenshot bot commits to master with `[skip ci]`. CircleCI still opens a pipeline for
the commit and cancels it, and the cancellation lands on the commit as `ci/circleci:
build-and-test` in state `error` with the description "Your CircleCI tests were canceled". The
commit's combined state is then `failure`, so anything that judges master by its head commit's
statuses calls master red while the last real run was green. On 2026-10-16 the monitor did that
in three laps running and spent six to eight minutes of each in `FIX_MASTER_CI` with nothing to
fix. `check_master_ci` now walks back to the newest commit that really ran (`src/master-ci.ts`);
do the same anywhere else master's CI is read from GitHub statuses.

## A later post on a held topic is the same report

A topic held `needs-detail` waits for its reporter. A later post on it saying "still happening"
classifies as a `retest`, and the work router used to offer that post for a fix on its own, while
the fix step rightly refused it, so one lap went router, fix step, verify step once a minute until
its 40-step cap (2026-10-16, topic 10257). The router now leaves alone any post whose topic is
held for detail or already dealt with by this lap's fix batch.

## `PARSE_ONLY` does not stop where you expect

It does not halt at the work router: tool nodes continue past it and the run walks into
dispatching fixes. If you want a scan with no side effects, confirm that from the run log rather
than from the flag.

## Laps without draining what the previous step left

The fix step and the verify step can lap without draining results still in flight, so work is
re-decided while its outcome is still arriving.

It also opens pull requests, runs its own adversarial review, and leaves them open when that
review fails. An open request from it is not a reviewed one.

## Delegates inherit a hard-coded path

Delegate briefs name the main checkout, so a delegate writes there rather than into the worktree
the run is using. Anything that spawns a sub-run needs its working directory passed explicitly.

A worker that wants the main checkout's test container copies its test file into the main checkout and
can die before copying it back. On 2026-09-29 a 29-line change to
`MessageIllustrationsServiceTest.php` that had sat modified in the main checkout for three days was
one of these: a temporary test deliberately asserting the bug, left behind by a worker whose own
folder had since been cleaned up. So a modified file in the main checkout that nobody remembers
editing may be a stray from a worker; search the transcripts under `~/.claude/projects/-tmp-monitor-fsm-*`
for its path before treating it as someone's work, or before discarding it.

## It stops the containers of any worktree whose branch has a green pull request

`check_my_open_pr_ci` (`monitor-fsm/src/actions/index.ts`, "release the worktree's container
stack") walks `git worktree list` in the main checkout and, for every open pull request whose
head branch is checked out in a sibling worktree and whose checks are green, runs `docker stop`
on that worktree's compose containers. "My" pull requests are every open one under the GitHub
identity the loop runs as, which is the same identity every session here pushes with, so a
worktree you made by hand for your own pull request counts. The stop is logged to
`/tmp/freegle-monitor/debug.log` as `stopped N worktree container(s) at <path>` and nowhere
else; from the worktree it looks like someone ran `docker stop` on the whole stack, and a
percona kept in memory comes back empty, so the test database has to be seeded again.

The only thing that holds it off is a live process whose working directory is inside the
worktree. Commands run one at a time from a Claude session leave nothing behind between them, so
a browser pass, a test run through the status API, or a watch loop that calls into the worktree
is not protection. Park one while you need the stack:

```bash
(cd /home/edward/FreegleDocker-<name> && exec -a ux-worktree-guard sleep 7200) &
```

Seen 2026-10-17: a screenshot pass against the ux-experiments stack died at 15:04 UTC when
#1780 went green, with the loop's log the only record of why.

## Replies are posted, not queued for approval

Question answers (`persist_question_answers`), reporter questions and `post_discourse_reply_draft`
all post straight to Discourse, as the fix replies always did. A human does not approve them first
(Edward, 2026-10-10). The gates are the only brake: confidence, the plain-English scorer, a
non-empty quote, and "nothing posted twice" (any live `discourse_draft` row for the post, sent or
not, stops a second reply).

A failed post leaves the row unposted, so a question with an unsent row is NOT offered again by
`list_unanswered_questions`; `post_pending_discourse_drafts` (in `LOAD_STATE`) is what retries it.
`SKIP_DISCOURSE_POSTS=1` fails every post closed and the rows wait. To flush by hand:
`npx tsx scripts/post-pending-drafts.ts` from `monitor-fsm` (`--dry-run` lists first).

## Some reports hold a post id, not a post number

`discourse_bug.post` is meant to be the post's number in its topic, but some rows hold the Discourse
post id instead (10236/69259, 10255/69394 and 10248/69449 on 2026-10-26). Nothing fails when the row is
written. A reply threaded under that "post" goes to the wrong place or is refused, and a link to
`/t/<topic>/<post>` lands at the end of the thread. `chase_waiting_reports` only posts when the
original post can be fetched to quote, and notes the rest as unchaseable. Do the same before posting
under any stored report.

## See also

- `.claude/rules/dev-containers.md` - the worktree isolation this keeps escaping.
- `.claude/rules/tests-and-ci.md` - reading a red build before asking it to fix one.
