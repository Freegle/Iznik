---
last_reviewed: 2026-07-09
owner: Freegle dev team
---

# Developer orientation

This is a **map, not a manual**. The code and the reference docs at the repo root are the
source of truth. These pages exist to help you get oriented quickly and then point you at
the right file, README or reference, rather than restating things that would drift out of
date.

If a page here ever disagrees with the code, the code wins - and the page is a bug to fix.

## Start here

1. **[Architecture and codebase map](01-architecture.md)** - the components, how they fit
   together, and where things live.
2. **[Local development](02-local-development.md)** - getting a working environment,
   running the stack, and worktrees for parallel work.
3. **[Testing](03-testing.md)** - the four test suites and how to run them.
4. **[APIs and data](04-apis-and-data.md)** - the v2 API, how the frontend talks to
   it, the data model, and recipes for common changes.

## The authoritative reference docs

Read these directly; the pages above link into them rather than copy them:

| Topic | Doc |
|-------|-----|
| System architecture, containers, profiles | [./reference/architecture.md](./reference/architecture.md) |
| Coding standards | [./reference/coding-standards.md](./reference/coding-standards.md) |
| Adding a Go v2 API endpoint | [../../iznik-server-go/API-GUIDE.md](../../iznik-server-go/API-GUIDE.md) |
| Database read/write split | [../ops/reference/database-read-write-split.md](../ops/reference/database-read-write-split.md) |
| Index hygiene and schema parity | [../ops/reference/database-index-hygiene.md](../ops/reference/database-index-hygiene.md) |
| Logging and observability | [../ops/reference/logging.md](../ops/reference/logging.md) |
| Spatial services (plain English) | [./reference/spatial-servers.md](./reference/spatial-servers.md) |
| Rippling out algorithm | [./reference/rippling-algorithm.md](./reference/rippling-algorithm.md) |
| Browser testing with Chrome DevTools | [./reference/browser-testing.md](./reference/browser-testing.md) |
| Worktrees / parallel instances | [./reference/worktrees.md](./reference/worktrees.md) |
| TrashNothing / LoveJunk integration | [./reference/trashnothing.md](./reference/trashnothing.md) |
| SEO: how posts get found | [./reference/seo.md](./reference/seo.md) |
| ModTools AI Support Helper | [./reference/ai-support-helper.md](./reference/ai-support-helper.md) |

Each component also has its own README (`iznik-nuxt3/README.md`, `iznik-server-go/README.md`,
`iznik-batch/README.md`, `iznik-routing-go/README.md`, `iznik-spatial-go/README.md`), and
per-component `CLAUDE.md` files carry real build and test facts (they are written as
instructions to AI agents, so read them for facts, not prose).
