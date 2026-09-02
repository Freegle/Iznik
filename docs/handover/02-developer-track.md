---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Developer: day 1, week 1, month 1

You are taking over writing and reviewing the code. This page is the order to do things in.
It stands on its own - you do not need the sysadmin track to do this job, though reading it
at some point will make you better at this one.

**Read [01-what-freegle-is.md](01-what-freegle-is.md) first if you have not.** It is short,
and it defines the words used below.

## Day 1

Four things. Nothing else.

### 1. Get your accounts

1Password first, because everything else is inside it, then GitHub, the volunteers' forum
and a normal member account on the live site. The list and how to get invited is
[04-accounts-and-access.md](04-accounts-and-access.md).

### 2. Use the live site as a member

Sign up on [ilovefreegle.org](https://www.ilovefreegle.org), post something you actually
want to give away, and reply to somebody else's post. Half an hour, and it will change how
you read the code. Freegle is not an abstract CRUD application; it is a thing people use to
get rid of a sofa.

### 3. Get the stack running locally

Follow the Installation and Running sections of the [root README](../../README.md), and
then the fast path in
[../developers/02-local-development.md](../developers/02-local-development.md).

Set expectations honestly: it is a large stack. About 29 containers, 15-19 GiB of RAM when
warm, and roughly 100 GB of disk for a first build. On Windows you want WSL2, not Docker
Desktop directly. First build is 10-15 minutes for the application containers after the
infrastructure is up. Watch it at `http://status.localhost:8081`.

If your machine cannot do that, there is a lightweight profile that runs only the frontend
against the live APIs. It is a real fallback, not a toy - but it talks to **real member
data**, so be careful what you click.

**When a step in the README is wrong, fix it.** See "The most valuable thing you can do"
below.

### 4. Run the tests

Four suites - Go, Laravel, Vitest, Playwright - all launched from the status dashboard at
`http://status.localhost:8081`, not from your host shell.

```
curl -X POST http://localhost:8081/api/tests/go
```

See [../developers/03-testing.md](../developers/03-testing.md). Getting all four green
locally is the real end of day 1, because from here on a red suite means *you*.

Two things to know about the seeded data: there is exactly one community,
**FreeglePlayground**, centred on **Edinburgh**, and the postcode that resolves is
**EH3 6SS**. Anything needing a valid UK location must use those or it will fail in ways
that look like a bug in your code.

## Week 1

### Ship something small, all the way out

A typo, a small bug, one test. The change does not matter; proving the pipeline does. Local
change, tests pass, branch, pull request, CI green, review, merge, and watch it reach
members. Once you have done that once, every later change is a variation on it.

Two rules that are not negotiable here, and they are in
[../developers/reference/coding-standards.md](../developers/reference/coding-standards.md):

- **Never skip a test or make coverage optional.** Fix the cause.
- **Never write off a failure as pre-existing or unrelated.** Investigate it. This project
  has been burned enough times that it is a hard rule rather than a preference.

And one that is about the humans: **only people merge pull requests.** Get it to "ready for
merge" and stop.

### The most valuable thing you can do this week

**Follow the getting-started documentation and fix everything that is wrong with it.**

You are the last person who will ever read this documentation without knowing the answers
already. That view is worth more than your first feature, and it expires in about two
weeks. Every wrong command, missing prerequisite and stale screenshot you fix now saves the
next person the same day you just lost.

Documentation rules, briefly: docs live in [`docs/`](../README.md) by audience; you update
the page in the **same** pull request as the behaviour change (there is a freshness check
that will fail your branch otherwise); developer docs point into the code rather than
restating it; screenshots are generated, never pasted. Full conventions in
[../maintaining-docs.md](../maintaining-docs.md).

### Learn the shape of the codebase

One repository, a handful of components. Read
[../developers/01-architecture.md](../developers/01-architecture.md) and then the deeper
[../developers/reference/architecture.md](../developers/reference/architecture.md).

The short version:

| Where | What |
|---|---|
| `iznik-nuxt3/` | The Nuxt frontend. The member site **and** ModTools **and** both mobile apps are all built from here |
| `iznik-server-go/` | The Go API. The only API - the old PHP one is gone |
| `iznik-batch/` | Laravel. **Owns the database schema** through its migrations, plus digests, notifications and scheduled jobs |
| `iznik-routing-go/`, `iznik-spatial-go/` | Travel times and nearest-neighbour geography |
| `status-nuxt/` | The development dashboard and test runner |

Each component has its own README, and each has a `CLAUDE.md` carrying real build and test
facts. Those are written as instructions to AI agents, so read them for facts rather than
prose - but the facts in them are current, because they get used.

### Three things that will confuse you in your first week

- **The database schema is Laravel migrations**, in `iznik-batch/database/migrations/`.
  There is a `schema.sql` in history; it is retired and lies. After adding a migration,
  rerun the test-database setup or your tests will run against the old schema.
- **Almost everything is per community.** A post can exist in several communities at once,
  with its own moderation state in each. A bug that "only happens sometimes" is very often
  a post in more than one community.
- **Names are historical.** The repository is called Iznik, `iznik-nuxt3/` runs Nuxt 4,
  and comments mentioning "v1" mean the retired PHP API. Do not try to make it consistent.

## Month 1

### Understand rippling out

This is the piece of Freegle that is genuinely unlike other systems and the source of the
most subtle bugs. A post starts local and reaches further over time if nobody nearby takes
it, and distance is measured in **minutes of driving, not miles**.

The rule to burn in: **the reach limit belongs to the receiving community, not to the
post.** Read [../members/rippling-out.md](../members/rippling-out.md) for what members see,
then [../developers/reference/rippling-algorithm.md](../developers/reference/rippling-algorithm.md)
for how it works, then the reasoning in
[06-decisions-and-rationale.md](06-decisions-and-rationale.md).

### Read the code paths behind the money

Not because you will change them often, but because they are the ones where a mistake is
expensive and slow to discover.
[../developers/reference/donations-and-gift-aid.md](../developers/reference/donations-and-gift-aid.md)
and [../developers/reference/ads.md](../developers/reference/ads.md). The single fact worth
carrying: **ads switch themselves off when donations pass a target**, so "no ads are
showing" in production is usually the system working.

### Learn what the outside world does to us

Freegle both consumes and publishes feeds with other organisations, and their changes
arrive without warning.
[../developers/reference/partner-integrations.md](../developers/reference/partner-integrations.md)
and [../developers/reference/external-services.md](../developers/reference/external-services.md).

### Work on more than one thing at once

Use worktrees. Each gets its own isolated Compose stack, ports and database, driven by the
`./freegle` CLI rather than `git worktree` directly:

```
./freegle worktree create feature-x
./freegle status
./freegle worktree remove feature-x
```

The isolation rules are strict and worth reading before you start, because bridging a
worktree to the main instance's database produces failures that make no sense:
[../developers/reference/worktrees.md](../developers/reference/worktrees.md).

### Get a feel for how work arrives

Three channels, and none of them is a formal backlog:

- **Members and moderators report problems** through support, which reaches the technical
  volunteers when it needs code.
- **Sentry** collects production errors, and an automated monitor can raise pull requests
  for recurring ones for a human to review.
- **The volunteers' forum** is where feature discussion actually happens.

Which means: **ask what the reporter's actual complaint is**, and check whether the
behaviour is intended before you fix it. A surprising proportion of reports are the system
working as designed and being confusing about it. The fix is often the wording.

### Where to go deeper

| Subject | Page |
|---|---|
| The v2 API and data model | [../developers/04-apis-and-data.md](../developers/04-apis-and-data.md) |
| Adding an API endpoint | [`iznik-server-go/API-GUIDE.md`](../../iznik-server-go/API-GUIDE.md) |
| Coding standards | [../developers/reference/coding-standards.md](../developers/reference/coding-standards.md) |
| Everything else, by subject | [../developers/README.md](../developers/README.md) |
| Why the architecture is like this | [06-decisions-and-rationale.md](06-decisions-and-rationale.md) |
| The human teams you will be emailing | [05-who-does-what.md](05-who-does-what.md) |

### What "good" looks like after a month

You can take a reported problem, reproduce it locally, work out which of the components
owns it, fix it with a test, get it reviewed and see it reach members - without asking
anybody where anything is. That is the whole job. Everything else is depth.
