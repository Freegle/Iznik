---
last_reviewed: 2026-09-02
owner: Freegle dev team
covers:
  - .env.example
  - iznik-batch/config/freegle.php
  - iznik-nuxt3/nuxt.config.ts
---

# External services

Everything Freegle depends on that is not our code. If one of these fails, part of Freegle
fails, so it is worth knowing what they are and how badly each one matters.

Two rules apply throughout:

- **No key, id or secret is ever written in code or in these docs.** They come from
  environment variables. Where they are actually held is in
  [../../getting-started/accounts-and-access.md](../../getting-started/accounts-and-access.md).
- **We prefer to self-host** where a dependency is heavily used, for cost and for control.
  The reasoning is in
  [../../getting-started/decisions-and-rationale.md](../../getting-started/decisions-and-rationale.md).

## What would break the site

These are load-bearing. A failure here is visible to members within minutes.

| Service | What it does for us | If it fails |
|---|---|---|
| **Netlify** | Hosts and deploys the two frontends (ilovefreegle.org, modtools.org) | The websites go down; the API and mail keep running |
| **Our own servers** | Database cluster, API, batch, mail, spatial, routing | See [../../ops/production.md](../../ops/production.md) |
| **Google OAuth** | "Sign in with Google", the most used sign-in route | Those members cannot sign in; email/password still works |
| **Facebook and Apple sign-in** | The other two social sign-in routes (`LoginModal.vue`) | As above, per provider |
| **Stripe** | Card donations | Donations stop; ads stay on ([donations-and-gift-aid.md](donations-and-gift-aid.md)) |
| **PayPal** | The other donation route | As above |

## What would degrade the site

Visible, annoying, not fatal.

| Service | What it does |
|---|---|
| **Google Maps / Places** | Address and place lookup in parts of the frontend |
| **Google Cloud Vision** | Checks uploaded photos for unsuitable images |
| **Google Perspective** | Scores text for abuse, feeding moderation |
| **Google Gemini** | The AI features (support helper, classification experiments) |
| **Firebase Cloud Messaging** | Push notifications to the apps (`GOOGLE_PUSH_KEY`) |
| **MaxMind** | Turns an IP address into a rough location, used in anti-abuse |
| **Playwire** | Advert delivery ([ads.md](ads.md)) |
| **WhatJobs** | The job listings that fill some advert slots ([ads.md](ads.md)) |
| **CookieYes** | The cookie consent banner |
| **Google Tag Manager** | Analytics tags, only when `GTM_ID` is set |
| **Trustpilot** | Review link |

## Self-hosted rather than bought

These look like external services on other sites. We run them.

| Service | Instead of | Where |
|---|---|---|
| **Place search** | A paid geocoding API | Part of the spatial service. An index of named UK places built from OpenStreetMap data, held in memory and reloaded without a restart |
| **OSM tile server** | A paid map tile service | Edge tier (`tile-server` container) |
| **tusd** | An upload service | Edge tier. Uploads are resumable; identifiers look like `freegletusd-*` |
| **weserv** | Cloudinary or similar | Image resizing and delivery (`IMAGE_DELIVERY`) |
| **Loki + Grafana** | A hosted log service | [../../ops/monitoring-and-logging.md](../../ops/monitoring-and-logging.md) |
| **Discourse** | A hosted forum | `discourse.ilovefreegle.org`, the volunteers' forum |
| **Postfix** | A bulk mail provider | About 200,000 messages a day; see [../../ops/production.md](../../ops/production.md) |
| **Embedding sidecar** | A paid embeddings API | `embedding-sidecar` container (`EMBEDDING_SIDECAR_URL`). Turns text into vectors for moderation checks and for the item grouping on [electricals.md](electricals.md). Every caller treats it as optional and falls back when it is absent |

## In the code but not in use

These have not been removed from the tree, so their presence proves nothing. Do not read
a key in `.env.example`, or a component that still compiles, as evidence that we pay for
something or that a route works.

| Thing | Status |
|---|---|
| **Mapbox** | Not used. Travel times and isochrones are computed in-house and map tiles come from our own tile server. The code and `MAPBOX_KEY` remain |
| **Google AdSense** | Not used. `OurGoogleDa.vue` is still in the tree and `GOOGLE_ADSENSE_ID` is still read, but the AdSense module is commented out in `nuxt.config.ts`, so the component cannot render |
| **Yahoo sign-in** | Not used. Sign-in is Google, Facebook, Apple, or email and password |
| **Uploadcare** | Retired. Image handling is purely tusd plus weserv; any Uploadcare branch you find is dead |

## Development and delivery


| Service | What it does |
|---|---|
| **GitHub** | Code, pull requests, issues |
| **CircleCI** | Runs the test suites; auto-merges master to `production` on success |
| **Coveralls** | Test coverage reporting (`COVERALLS_REPO_TOKEN`) |
| **Sentry** | Error tracking, and the input to monitor-fsm |
| **Google Play, Apple App Store** | Four app listings; see [mobile-app.md](mobile-app.md) |
| **1Password** | Where the credentials for all of the above live |

## Partner organisations

Feeds and syndication with other reuse and volunteering organisations are a separate
subject: [partner-integrations.md](partner-integrations.md).

## Configuration, in one place

Frontend values that reach the browser are declared in `runtimeConfig.public` in
`iznik-nuxt3/nuxt.config.ts`. Anything there is **public by definition** - it is served to
every visitor - so only publishable keys belong in it (a Stripe *publishable* key, an
advert publisher id). Server-side secrets go in `.env` (development, see `.env.example`) and
`.env.background` (production batch, see `.env.background.example`), and in the batch tier
are read through `iznik-batch/config/freegle.php` rather than `env()` at the point of use.
