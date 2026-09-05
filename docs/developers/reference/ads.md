---
last_reviewed: 2026-09-05
owner: Freegle dev team
covers:
  - iznik-nuxt3/components/ExternalDa.vue
  - iznik-nuxt3/components/OurGoogleDa.vue
  - iznik-nuxt3/components/OurPlaywireDa.vue
  - iznik-nuxt3/components/OurPrebidDa.vue
  - iznik-batch/app/Console/Commands/Donation/UpdateAdsTargetCommand.php
  - iznik-batch/app/Services/WhatJobsService.php
  - iznik-nuxt3/components/JobsDaSlot.vue
---

# Adverts

Freegle is free to use and free to join. It costs money to run. Adverts are one of the two
ways that money arrives, the other being [donations](donations-and-gift-aid.md). The two
are wired together, which is the most important thing on this page.

## Ads switch themselves off when members donate

A scheduled job, `donations:update-ads-target`
(`iznik-batch/app/Console/Commands/Donation/UpdateAdsTargetCommand.php`), compares
donations received in the last 24 hours against a target held in the `config` table
(`ads_off_target_max`). It writes back two values:

| Config key | Meaning |
|---|---|
| `ads_off_target` | How much is still needed today |
| `ads_enabled` | `0` once today's target is met, `1` otherwise |

So on a good day the site shows no adverts at all. When you are looking at production and
see no ads, that is very often the reason, not a bug. Check the `config` table before
debugging an ad provider.

## The component layout

Everything goes through one dispatcher.

```mermaid
flowchart TD
    A[ExternalDa.vue<br/>chooses a provider] --> B[OurPrebidDa<br/>header bidding]
    A --> C[OurPlaywireDa<br/>managed ad partner]
    A --> D[JobsDaSlot<br/>job listings as an ad unit]
    A -.-> E[OurGoogleDa<br/>Google AdSense, not in use]
```

Pages never embed a provider directly; they use `ExternalDa`. If you add a provider, add it
behind that dispatcher.

**`OurGoogleDa` is not in use.** AdSense was switched off and the module that provides its
component is commented out in `iznik-nuxt3/nuxt.config.ts`, so the branch cannot render.
The file and `GOOGLE_ADSENSE_ID` are still in the tree; do not take that as evidence the
route works.

**Terms worth knowing.** *Header bidding* (Prebid) asks several advertisers to bid for the
space before the page asks Google to fill it, so the space sells for more. *GPT* is Google
Publisher Tag, Google's script for filling ad slots. *House ads* are our own content shown
when nothing sells.

## Job listings

`JobsDaSlot` fills some advert slots with paid job listings rather than banner adverts. The
listings come from **WhatJobs**, synced by `integrations:sync-whatjobs`
(`iznik-batch/app/Services/WhatJobsService.php`) into the `jobs` table, from which the API
serves them by location.

Things to know before touching it:

- **Listings below a minimum cost per click are not ingested at all** (`MINIMUM_CPC`). That
  floor is duplicated in four places - the ingest service, the `Job` model that serves
  them, the Go API and the retired V1 code - and they must move together.
- **Listings older than a week are dropped** (`MAX_AGE_DAYS`).
- **The sync builds a new table and renames it into place**, and refuses to do so if the
  new table has fewer than half the rows of the old one. This exists because a partial feed
  failure once emptied the live table. If jobs go stale, look for that refusal in the logs
  before assuming the feed is fine.
- **A gate skips the rebuild when the feed has not changed**, but never for more than
  twenty hours, so the freshness check that watches the `jobs` table still fires if the
  gate itself breaks.
- Jobs are geocoded on ingest and the `jobs` table is also the geocode cache, so a wrong
  placement does not heal by itself.

## Behaviour that trips people up


- **Prebid has a 2 second bid timeout and a 31 second refresh.** A slot that appears to do
  nothing for half a minute is normal.
- **If GPT is blocked** (ad blocker, tracking protection) the components fall back after
  about a second rather than hanging. Most developers have a blocker installed, so the
  local experience is usually the fallback path, not the real one.
- **Playwire picks a unit type from the computed maximum height of the slot**, so a CSS
  change to a container can silently change which advert format is requested.
- **Slots must be destroyed on unmount.** The components call `destroySlots` /
  `destroyUnits` from both `onBeforeUnmount` and `onBeforeRouteLeave`, because
  `onBeforeUnmount` is not reliably called on route change. Leaked slots produce adverts
  that reappear on the wrong page.
- **Ad visibility logging is one-shot per slot** and is known to under-report on Safari and
  Firefox. Do not treat those counts as a measurement of what members saw.

## Rules

- Adverts must never appear in the moderator app (ModTools).
- Adverts must not be placed where they can be mistaken for a Freegle post.
- Provider ids are configuration (`PLAYWIRE_PUB_ID`, `PLAYWIRE_WEBSITE_ID`, and the
  WhatJobs feed urls in `freegle.whatjobs.*`), never hardcoded. See
  [external-services.md](external-services.md).
