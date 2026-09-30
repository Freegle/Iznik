---
last_reviewed: 2026-09-30
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/partnerships.vue
  - iznik-nuxt3/modtools/components/ModPartnership*.vue
  - iznik-nuxt3/modtools/composables/usePartnershipFormat.js
  - iznik-nuxt3/modtools/stores/partnerships.js
  - iznik-nuxt3/api/PartnershipsAPI.js
  - iznik-server-go/partnerships/**
  - iznik-server-go/authority/authority.go
  - iznik-batch/app/Console/Commands/Partnerships/**
  - iznik-batch/app/Services/PartnershipGroupsService.php
  - iznik-batch/app/Services/AuthorityStatsService.php
  - iznik-batch/app/Mail/Partnerships/**
---

# Partnerships

Councils sponsor Freegle. The **Partnerships** page in ModTools is where those deals live:
who is sponsoring us, which communities each deal covers, what it is worth, whether the money
has come in, and when it needs renewing. It is meant to hold everything the team's spreadsheet
held; if something is missing, that is a gap to fix here rather than a reason to keep the
spreadsheet.

It also generates the quarterly statistics spreadsheets councils receive.

## Who can see it

Members of the **Partnerships** team, plus Support and Admin. Add someone on the
[Teams](getting-started.md) page and the Partnerships entry appears in their left-hand
menu the next time their session refreshes.

The team's email address (set on the Teams page) is where the renewal reminders go.

## What a partnership is

A partnership is a deal with a **local authority**, not with a group. That matches how the
deal is actually done: a council pays once, and the sponsorship shows across every Freegle
community inside its boundary. The council search only offers councils - county, district,
unitary, metropolitan, London borough - not wards or constituencies.

Each deal records:

- the dates, and so how long it lasts ("This deal lasts 3 years");
- the value of the whole deal, and the price before any **bulk discount**, so we keep what we
  told the council even where it does not change what they pay;
- **where it is up to** (below);
- **whether they will renew**, as a traffic light - green, amber or red;
- the tagline, an optional description, a link and an uploaded **logo**, which is what
  members see;
- the **council contacts**, as many as needed, each marked as waste team, finance or other.
  Everyone listed gets the statistics;
- notes.

## Where a deal is up to

| Status | Meaning | Members see the sponsor? |
|---|---|---|
| Quoted | We have given them a price | No |
| Agreed in principle | They have said yes, but not confirmed | No |
| Confirmed | Confirmed | Yes |
| Paid | Confirmed and paid | Yes |
| Overdue | Confirmed, but the payment is late | Yes |

A deal shows to members as soon as it is confirmed rather than when the money arrives.
Councils often pay months late, and waiting for the payment used to stretch a year's
sponsorship into eighteen months.

**Show to members** can still hide a confirmed deal, for the council that asks not to be
named.

## Which communities a deal covers

A deal covers every community inside the council boundary, **including any set up later** -
a daily check picks those up, so nobody has to add them.

"Inside" means at least 5% of the community lies within the boundary, or the community covers
at least 5% of the council's area. A community that only grazes the edge, such as Southend
against Essex County, is not included. Each community shows the share of it that is inside,
and the statistics count that share.

When you create a deal, the communities are listed as soon as you pick the council, most-inside
first, and you can untick any to leave them out. Under **Details** you can also:

- **Leave out** a community. It stays left out; re-checking the boundary does not bring it
  back. It is listed as left out, with a button to put it back.
- **Add a community outside the boundary** - a council sometimes sponsors a neighbouring
  community. One added this way counts in full in the statistics.

Community names link to their Explore page.

Each covered community gets a sponsor entry, which is what members see. Editing the tagline,
description, link or logo on the partnership changes all of them at once.

## The money

| Field | Question it answers |
|---|---|
| **Value of the whole deal** | What did they agree to pay, across the whole term? |
| **Financial years** | How much of it belongs to which year? |
| **Invoices** | What have we billed, and what has actually come in? |

Multi-year deals are spread across the financial years they cover (1 April to 31 March), so
the income graph shows a three-year deal in three years rather than all in the year it was
signed. If the council pays in uneven instalments, override the split under **Details**; the
page warns if the split does not add up to the deal value.

The boxes at the top follow the stages: **Quoted**, **Agreed in principle**, **Confirmed**
(including paid and overdue), then **Received** from the invoices and **Still to come** -
confirmed money not yet received. **Overdue** appears only when there is some. Each deal's
details show what has been received against what is due.

Whether invoices and payments belong here or only in Xero is still to be settled with the
treasurer.

## The timeline and history

The **Timeline** has one row per council and a bar for each deal, coloured by where it is up
to. The dark tick on each bar is three months before it ends - the point to ask the council
about next year. Click a bar to open that deal.

Each deal's details list every deal we have had with that council, so you can see when they
have sponsored us and when they have not.

## Renewal reminders

The Partnerships team gets two emails about each confirmed deal, each with a link straight to
it:

- **three months before it ends** - time to ask about next year;
- **when it has ended with nothing agreed to follow it** - by then the council should have
  renewed and paid.

A deal already followed by another with the same council is not chased. Each deal is chased
once per reminder, so the daily check does not nag. Hidden deals are chased too.

## Council statistics

The bottom of the page generates the quarterly spreadsheet councils receive - membership,
weight reused, CO2 and financial benefit, gifts made, a per-community breakdown, shortlink
clicks, member stories and a postcode breakdown.

For a council we have a deal with, the per-community breakdown is exactly the communities the
deal covers, as listed on this page. For any other council they are worked out from the
boundary, and communities with almost no activity are dropped.

Building one takes a few minutes, so it runs in the background:

1. Pick the councils and the quarter, and press **Generate**.
2. The job appears in the table below as *Pending*, then *Running*.
3. When it says *Ready*, the spreadsheets are listed and you can download them.

You do not have to keep the page open. If one council fails the others still come through,
and the reason is shown against the job.

## Under the covers

- Sponsor entries are written to `groups_sponsorship`, the same table the member site reads.
- Everything the page adds lives in tables prefixed `partnerships`.
- `partnerships:reminders` runs daily, twice: once for the three-month warning, once with
  `--ended` for deals that ran out.
- `partnerships:sync-groups` runs daily and keeps each live deal's communities in line with
  its council boundary.
- `partnerships:stats:run` picks up queued statistics jobs every minute.
- `partnerships:import-sponsorships` brings sponsorships set up by hand before this page
  existed onto it, as each council's history. It links the existing sponsor entries rather
  than copying them, so members see no change. Run it with `--dry-run` first; a sponsor whose
  name is not a council name needs `--map="Name=<authority id>"`.
