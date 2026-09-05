---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Developer orientation

This is a **map, not a manual**. The code and the reference docs at the repo root are the
source of truth. These pages exist to help you get oriented quickly and then point you at
the right file, README or reference, rather than restating things that would drift out of
date.

If a page here ever disagrees with the code, the code wins - and the page is a bug to fix.

**Just joined?** Read [../getting-started/README.md](../getting-started/README.md)
first. It covers what Freegle is, why the architecture is the way it is, who the human
teams are, and what to do in your first day, week and month. Come back here once you
have the stack running.

## Start here

1. **[Architecture and codebase map](architecture.md)** - the components, how they fit
   together, and where things live.
2. **[Local development](local-development.md)** - getting a working environment,
   running the stack, and worktrees for parallel work.
3. **[Testing](testing.md)** - the four test suites and how to run them.
4. **[APIs and data](apis-and-data.md)** - the v2 API, how the frontend talks to
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
| Third-party services we depend on | [./reference/external-services.md](./reference/external-services.md) |
| Partner integrations (TrashNothing, LoveJunk, and others) | [./reference/partner-integrations.md](./reference/partner-integrations.md) |
| Advertising | [./reference/ads.md](./reference/ads.md) |
| Donations and Gift Aid | [./reference/donations-and-gift-aid.md](./reference/donations-and-gift-aid.md) |
| The mobile apps | [./reference/mobile-app.md](./reference/mobile-app.md) |
| Electricals and reuse reporting | [./reference/electricals.md](./reference/electricals.md) |
| Matched-posts email | [./reference/matched-posts-email.md](./reference/matched-posts-email.md) |
| Getting a first reply in | [./reference/first-reply.md](./reference/first-reply.md) |
| Unsubscribing from email (List-Unsubscribe) | [./reference/unsubscribe.md](./reference/unsubscribe.md) |
| Mail deferrals and suppression | [./reference/mail-deferrals.md](./reference/mail-deferrals.md) |
| Donation asks in email (Stripe, wallets) | [./reference/donation-asks-in-email.md](./reference/donation-asks-in-email.md) |
| Notification chase-up email | [./reference/notification-chaseup-email.md](./reference/notification-chaseup-email.md) |
| Browser testing with Chrome DevTools | [./reference/browser-testing.md](./reference/browser-testing.md) |
| Worktrees / parallel instances | [./reference/worktrees.md](./reference/worktrees.md) |
| TrashNothing / LoveJunk integration | [./reference/trashnothing.md](./reference/trashnothing.md) |
| SEO: how posts get found | [./reference/seo.md](./reference/seo.md) |
| ModTools AI Support Helper | [./reference/ai-support-helper.md](./reference/ai-support-helper.md) |
| Chat prompts (Freegle's tappable questions) | [./reference/chat-prompts.md](./reference/chat-prompts.md) |
| Google Play technical quality (DEX, memory, zero-tap) | [./reference/play-technical-quality.md](./reference/play-technical-quality.md) |

Each component also has its own README (`iznik-nuxt3/README.md`, `iznik-server-go/README.md`,
`iznik-batch/README.md`, `iznik-routing-go/README.md`, `iznik-spatial-go/README.md`), and
per-component `CLAUDE.md` files carry real build and test facts (they are written as
instructions to AI agents, so read them for facts, not prose).
