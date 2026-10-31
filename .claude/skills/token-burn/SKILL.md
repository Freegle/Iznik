---
name: token-burn
description: Use when preparing or reviewing candidate projects for a period of abundant Claude tokens, when asked for "token burn" candidates or to fill plans/token-burn/, or when deciding whether a large piece of work should wait for that period.
---

# Token burn candidates

## Overview

For a limited period we will have far more Claude tokens than usual. During it, tokens are
cheap; Edward's decisions and review time are not. A candidate is a large, token-heavy build
written up so that it can run flat out with nobody there. Candidates live in
`plans/token-burn/`, one file each, indexed in that folder's `README.md`.

**Core principle: settle decisions now, write steps later.** Coding, test and debug loops are
most of the cost of a build. The step-by-step plan is regenerated when the build starts,
because master will have moved by then. What does not go stale is a decision with its reason.

## Does it qualify?

All three must hold. If you cannot say why in one sentence each, it is not a candidate.

1. **Token-heavy.** A human engineer would quote it in weeks, and most of those weeks are
   coding and debugging rather than deciding. Typical shapes: a feature across frontend,
   Go API and batch with tests at each layer; a sweep over many files (migration, parity
   audit, coverage raise, accessibility pass); a backtest or evaluation that runs many cases.
2. **Runs unattended.** Once the decisions are settled, no task needs Edward, production
   access, or an outside party to respond mid-build.
3. **Worth it at full price.** Say what changes for members, volunteers or the money. If the
   only argument is "we will have tokens", drop it.

Not candidates: config or copy changes; work that waits on a human or partner at each step;
anything that must iterate against production data or containers; research that ends in a
decision rather than code. Those are fine as ordinary work; they do not belong here.

## Harvesting ideas

Read `plans/future/`, unstarted parts of `plans/active/`, the docs, recent Discourse requests,
SysAdmin gaps, and the current conversation. If a plan already covers the area, absorb it or
link it from the candidate; never write a second plan for the same work.

## Writing a candidate

Copy `candidate-template.md` from this skill's folder to `plans/token-burn/<slug>.md`, then:

- **Ground every "what exists" line in a file you opened**, with its path. Write "unknown"
  rather than a guess. A write-up with no paths means you have not looked.
- **A decision has a reason.** Record each one the way `.claude/rules/conventions.md` does.
  A choice you made because nobody was there to ask is an open question, not a decision.
- **Open questions go to Edward once, as a batch,** with your recommended answer first
  (AskUserQuestion). If you are a subagent or running unattended, leave them in the file and
  say so; the parent session asks. A candidate is `ready` only when this section is empty.
- **Tasks are one PR each,** with their dependencies, and marked for which can run at the
  same time in separate worktrees. The first task must need nothing from outside. Do not
  write implementation steps, schemas beyond the columns a decision turns on, code, or test
  scaffolds. That is the build, and it is what the tokens are for.
- **Acceptance is what a reviewer checks to pass the work,** in plain words. A list of test
  types is not acceptance.
- **External prerequisites** are keys, API access, partner reports and account tiers. They
  have human latency, so they start now, not when the build does.
- **Review burden** says how many PRs Edward reads and what each must carry: screenshots or a
  walkthrough video for UI, raw production SQL for a migration, a docs page with `covers:`.
- **Add or update the row in `plans/token-burn/README.md`.** Status is `draft`, `ready`,
  `running` or `done`. Delete a `done` candidate's file, as `plans/README.md` says; git
  keeps it.

## When the tokens arrive

For each `ready` candidate: a worktree (use the `freegle-worktrees` skill), a fresh plan from
the candidate file (superpowers:writing-plans), then `ralph`. Push and let CI run the suites.
Run several candidates at once only when their tasks touch different areas of the code.

## Red flags

| Thought | Reality |
|---|---|
| "I'll pick the sensible option and note it" | That is an open question. Ask. |
| "A quick migration sketch will help later" | It will be regenerated. Stop at the decision. |
| "Edward can check each PR as it lands" | Then it is not unattended. Split out the part that is. |
| "It's clearly valuable, no need to say how" | Write the one sentence. If it will not come, drop it. |
| "The tests section lists Go, Vitest, Playwright" | That is not acceptance. Say what passing looks like. |
