---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Handover

This section exists for one situation: **someone new is taking over, and the people who
built the thing may not be around to ask.**

Everything here is written for a competent technical person who knows nothing about
Freegle. Jargon is explained the first time it is used. Where a decision looks strange,
there is a reason, and the reason is written down rather than left for you to guess.

## The two tracks

There are two jobs, and they may be two different people.

| Track | You are | Start here |
|---|---|---|
| **Developer** | Writing and reviewing code, fixing bugs, shipping features | [02-developer-track.md](02-developer-track.md) |
| **Sysadmin / ops** | Keeping the live service up, deploying, responding when it breaks | [03-sysadmin-track.md](03-sysadmin-track.md) |

Each track stands on its own. You do not need to read the other one to do your job,
though a day spent reading it will make you better at yours.

**Both tracks should read [01-what-freegle-is.md](01-what-freegle-is.md) first.** It is
short, and almost every technical decision on the platform only makes sense once you
know what the service is trying to do.

## The pages

| Page | What it covers |
|---|---|
| [01-what-freegle-is.md](01-what-freegle-is.md) | The service, the people who use it, the words we use for things |
| [02-developer-track.md](02-developer-track.md) | Developer: day 1, week 1, month 1 |
| [03-sysadmin-track.md](03-sysadmin-track.md) | Sysadmin: day 1, week 1, month 1 |
| [04-accounts-and-access.md](04-accounts-and-access.md) | Every account and credential that exists, who holds it, how you get it |
| [05-who-does-what.md](05-who-does-what.md) | The human teams. Freegle is mostly volunteers, and a lot of "the system" is people |
| [06-decisions-and-rationale.md](06-decisions-and-rationale.md) | Why the architecture is the way it is. Read before you change anything big |

## How to use this section

Three things, in order, based on what actually works when people join a project:

1. **Follow the getting-started steps and fix what is wrong.** The
   [root README](../../README.md) should take you from nothing to a running local system.
   If a step is wrong, out of date, or missing, **fix it as you go**. You are the last
   person who will ever see this documentation with fresh eyes, and that view is worth
   more than your first feature. This is the single highest-value thing you can do in
   week 1.
2. **Ship something small and real in week 1.** A typo fix, a small bug, a test. The
   point is not the change; it is proving you can get a change from your machine through
   review and CI and out to members. Once you have done it once, everything after is a
   variation.
3. **Do not try to read everything.** This section is an index into authoritative
   sources, not a replacement for them. When it points into the code, the code is the
   truth and the page is a map.

## Where the rest of the documentation is

This section is deliberately thin. It tells you what exists and where to look. The
detail lives in the audience sections:

- [../developers/](../developers/README.md) - how the code works, per subsystem
- [../ops/](../ops/README.md) - how production runs
- [../moderators/](../moderators/README.md) - what volunteer moderators do
- [../members/](../members/README.md) - what members see

## Coverage

If you were handed this section and asked "is everything covered?", this is the map.

| Topic | Where |
|---|---|
| What the site does | [01-what-freegle-is.md](01-what-freegle-is.md) |
| Technology and components | [01-what-freegle-is.md](01-what-freegle-is.md), [../developers/reference/architecture.md](../developers/reference/architecture.md) |
| Hosting | [../ops/production.md](../ops/production.md) |
| Sysadmin duties | [../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md) |
| CI/CD | [../ops/02-deployment-and-ci.md](../ops/02-deployment-and-ci.md) |
| External services | [../developers/reference/external-services.md](../developers/reference/external-services.md) |
| Partner integrations | [../developers/reference/partner-integrations.md](../developers/reference/partner-integrations.md) |
| Diagrams | [01-what-freegle-is.md](01-what-freegle-is.md), [../ops/production.md](../ops/production.md), [../developers/reference/donations-and-gift-aid.md](../developers/reference/donations-and-gift-aid.md) |
| Mobile apps | [../developers/reference/mobile-app.md](../developers/reference/mobile-app.md) |
| Ads | [../developers/reference/ads.md](../developers/reference/ads.md) |
| Donations and Gift Aid | [../developers/reference/donations-and-gift-aid.md](../developers/reference/donations-and-gift-aid.md) |
| Spam and abuse | [../ops/reference/spam-and-abuse.md](../ops/reference/spam-and-abuse.md) |
| Other teams | [05-who-does-what.md](05-who-does-what.md) |
| Why it is built this way | [06-decisions-and-rationale.md](06-decisions-and-rationale.md) |
| Accounts and credentials | [04-accounts-and-access.md](04-accounts-and-access.md) |
