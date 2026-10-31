# Plans

This directory is a working area for **plans, designs, research and runbooks** produced
while building Freegle. It is scratch space for work in flight, not polished
documentation.

> Looking for documentation on how Freegle works (for members, moderators, developers or
> ops)? That lives in [`../docs/`](../docs/README.md), not here.

Obsolete plans (work that has shipped, or been superseded by a canonical doc) are removed
periodically so this stays a picture of current and future work, not a graveyard. Git
history keeps the removed ones if you ever need them.

## Structure

- **`active/`** - work in progress or partially done.
- **`future/`** - research and ideas not yet started.
- **`token-burn/`** - large builds written up to run flat out when Claude tokens are abundant (see the `token-burn` skill).
- **`reports/`** - longer research reports that end in a recommendation.
- Files at the top level are designs, research notes and option papers that are still live.
  Most are dated `YYYY-MM-DD-`; a few older ones are not.

Pruned on 2026-10-28: everything shipped, superseded by a `docs/` page, or decided against was
removed (about 120 files, including the `in-progress/`, `reference/`, `design-review/`,
`research_notes/` and screenshot folders). Where a doc or skill linked to a removed plan, the
link now names the commit that still holds it.

## Conventions

- Prefix a new dated plan or runbook with `YYYY-MM-DD-`.
- When a plan's work ships, or a canonical doc supersedes it, delete the plan (git keeps
  the history) rather than leaving it to rot.
- Keep polished, audience-facing documentation in [`../docs/`](../docs/README.md); use
  this directory for the messy thinking that gets you there.
