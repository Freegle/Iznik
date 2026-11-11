# Candidate: <name>

Status: draft | ready | running | done
Last reviewed: YYYY-MM-DD

## Goal

One paragraph: what exists when this is done, and who it is for.

## Why it qualifies

- Token-heavy because: <one sentence; which layers or how many files>
- Unattended because: <one sentence; what needs nobody once decisions are settled>
- Worth it because: <one sentence; what changes for members, volunteers or the money>

## What exists today

Each line names the file you opened. Write "unknown" where you did not find out.

- `path/to/file` - what it holds or does.

## Decisions settled

Each with its reason, so a later builder does not reopen it.

- **Decision.** Reason.

## Open questions for Edward

Recommended answer first. Empty before the status becomes `ready`.

- Question? Recommended: ... because ...

## External prerequisites

Things with human latency: keys, API access, partner reports, account tiers. Who, by when.

- [ ] ...

## Tasks

One PR each. Dependencies by task number. `[parallel A]` marks tasks that can run at the same
time in separate worktrees; tasks sharing a letter touch the same area and run in sequence.

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | ... | - | A | iznik-batch |

## Acceptance

What a reviewer checks to pass the work. Plain words, no test types.

- ...

## Review burden

- PRs: N
- Each UI PR carries: screenshots or a walkthrough video
- Each migration PR carries: raw production SQL in the description
- Docs page(s) to add or update, with `covers:`:

## Out of scope

- ...

## Related plans

- `plans/...` - absorbed | linked | superseded
