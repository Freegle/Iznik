---
last_reviewed: 2026-08-08
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/partnerships.vue
  - iznik-nuxt3/modtools/components/ModPartnership*.vue
  - iznik-nuxt3/modtools/stores/partnerships.js
  - iznik-nuxt3/api/PartnershipsAPI.js
  - iznik-server-go/partnerships/**
  - iznik-batch/app/Console/Commands/Partnerships/**
  - iznik-batch/app/Mail/Partnerships/**
---

# Partnerships

Councils sponsor Freegle. The **Partnerships** page in ModTools is where those deals live:
who is sponsoring us, which communities each deal covers, what it is worth, whether the money
has come in, and when it needs renewing.

It also generates the quarterly statistics spreadsheets councils receive.

## Who can see it

Members of the **Partnerships** team, plus Support and Admin. Add someone on the
[Teams](01-getting-started.md) page and the Partnerships entry appears in their left-hand
menu the next time their session refreshes.

The team's email address (set on the Teams page) is where the renewal reminders go.

## What a partnership is

A partnership is a deal with a **local authority**, not with a group. That matches how the
deal is actually done: a council pays once, and the sponsorship shows across every Freegle
community inside its boundary.

When you create a deal, the covered communities are worked out from the council boundary
automatically. You can see them under **Details**, add ones the boundary missed, and remove
ones that should not be included.

Each covered community gets a sponsor entry, which is what members see. Editing the tagline,
description or link on the partnership changes all of them at once.

> A deal only shows to members once it is marked **Agreed**. Until then it is a conversation,
> not an advert, so nothing appears on the site.

## The money

Three separate things are tracked, because they answer different questions:

| Field | Question it answers |
|---|---|
| **Value of the whole deal** | What did we agree, across the whole term? |
| **Financial years** | How much of it belongs to which year? |
| **Invoices** | What have we billed, and what has actually been paid? |

Multi-year deals are the reason the middle row exists. A three-year deal is spread evenly
across the three financial years it covers (1 April to 31 March), so the income graph shows
it as three bars rather than all in the year it was signed.

If the council pays in uneven instalments - most of it up front, say - override the split
under **Details** and enter the real figures. The page warns you if the split does not add up
to the deal value. Clearing the split puts it back to spreading the money evenly.

Invoices are recorded separately, so **Invoiced** and **Paid** on the summary row tell you
what is still outstanding.

## Renewal reminders

Three months before a sponsorship ends, the Partnerships team gets an email about it, with a
link straight to the deal. Each deal is only chased once, so the daily check does not nag.

Deals nearing their end are also flagged on the page itself, both in the banner at the top and
as a **Renewal due** badge in the list.

## Council statistics

The bottom of the page generates the quarterly spreadsheet councils receive - membership,
weight reused, CO2 and financial benefit, gifts made, a per-community breakdown, shortlink
clicks, member stories and a postcode breakdown.

Building one takes a few minutes, so it runs in the background:

1. Pick the councils and the quarter, and press **Generate**.
2. The job appears in the table below as *Pending*, then *Running*.
3. When it says *Ready*, the spreadsheets are listed and you can download them.

You do not have to keep the page open - come back later and the finished spreadsheets are
still there. If one council fails (a boundary that no longer exists, say) the others still
come through, and the reason is shown against the job.

## Under the covers

- Sponsor entries are written to `groups_sponsorship`, the same table the member site reads,
  so nothing else had to change to show councils to members.
- Everything the page adds lives in tables prefixed `partnerships`.
- Reminders come from `partnerships:reminders`, run daily; the spreadsheets are built by
  `partnerships:stats:run`, which picks up queued jobs every minute.
