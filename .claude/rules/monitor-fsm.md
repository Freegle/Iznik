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

## See also

- `.claude/rules/dev-containers.md` - the worktree isolation this keeps escaping.
- `.claude/rules/tests-and-ci.md` - reading a red build before asking it to fix one.
