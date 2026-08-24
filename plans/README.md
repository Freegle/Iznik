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

- **`active/`** - plans for work in progress or partially done.
- **`in-progress/`** - a small set of actively-worked items.
- **`future/`** - research and ideas not yet started.
- **`reference/`** - setup guides and research notes kept for reference.
- **`design-review/`** - a one-time UX and accessibility review.
- Dated files at the top level (for example `2026-07-03-host-reboot-runbook.md`,
  `2026-07-08-edge-stage1-runbook.md`) are runbooks, diagnostics and one-off designs,
  named by date.

## Conventions

- Prefix a new dated plan or runbook with `YYYY-MM-DD-`.
- When a plan's work ships, or a canonical doc supersedes it, delete the plan (git keeps
  the history) rather than leaving it to rot.
- Keep polished, audience-facing documentation in [`../docs/`](../docs/README.md); use
  this directory for the messy thinking that gets you there.
