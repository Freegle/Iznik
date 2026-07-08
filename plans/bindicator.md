# Bindicator — Research & Findings

**Date:** 2026-07-04
**Context:** Investigation into the "Bindicator" UK bin-collection lookup app, the
underlying scraping technology, the postcode→property identification problem, whether
Freegle's data/API can help, and the feasibility of a standalone/APK build.
**Status:** Research only. No production changes made. Evidence gathered live 2026-07-03/04.

---

## TL;DR

- **Bindicator** is an experimental (not-live) app that turns a UK postcode into bin
  collection dates. It wraps the open-source `uk_bin_collection` scraper library behind a
  Nuxt front-end + FastAPI microservice. Repo: `Freegle/bindicator` (private, marked
  *"Historical/experimental — not live"*).
- **The hard problem is not scraping — it's identifying the property.** Every one of the
  328 supported councils looks up bins by **UPRN** (Ordnance Survey property id), not by
  postcode. A postcode alone is insufficient; you must resolve postcode → house → UPRN.
- **Proven failure mode:** with no house selected, the app falls back to a hard-coded test
  UPRN and silently shows the **wrong property's** schedule.
- **Freegle cannot supply the UPRN.** Freegle's licensed data is Royal Mail **PAF**, whose
  identifier is **UDPRN** — a *different* system from OS **UPRN**. Verified against the live
  `iznik` DB and Royal Mail's own PAF spec. This is not a "field we forgot to store" — UPRN
  is simply not in PAF.
- **BinDays-API** (a third-party project) is the more elegant architecture for a phone app:
  the server sends HTTP *instructions*, the **client executes them from the user's own IP**,
  and posts the raw response back. This avoids IP-blocking and needs no browser on the device.
- **For a personal APK:** hard-code your own UPRN once and either call a hosted scraper API,
  or bundle a computed fortnightly rota for offline use.

---

## 1. What Bindicator is

An autonomously-built (2026-05-08) app; the original build-session transcript is no longer
on disk. Two components:

| Component | Tech | Role |
|---|---|---|
| Front-end + BFF | **Nuxt 3** (SPA, `ssr:false`), Bootstrap-Vue-Next, Pinia, Leaflet | postcode entry → results, coverage map |
| Scraper service | **Python FastAPI**, wraps `uk_bin_collection` | live-scrapes councils, normalises output |

- DB: dual-mode **SQLite** (dev) / **Neon Postgres** (prod). Tables: `councils`,
  `schedules` (6h cache), `bin_types`, `bin_type_aliases`.
- Deploy: Netlify (front-end) + fly.io (scraper) + Neon — all **configured but not live**.
- `bindicator.netlify.app` currently serves a *different* app (Ionic/Vite), not this Nuxt build.

## 2. Coverage

Computed from `councils.json` vs the ONS Local Authority District set:

| Metric | Value |
|---|---|
| Councils supported (`councils.json`) | **328** |
| Total UK LADs (waste collection authorities) | **361** |
| **Not supported** | **35 (~9.7%)** |
| All supported councils use lookup type | **`uprn`** (328/328) |

The 35 gaps have **no upstream scraper at all** (not just unseeded). Example: **Ribble Valley
(E07000124)** — see §6.

## 3. How the scraping works

Code is the open-source **`robbrad/UKBinCollectionData`** (`uk_bin_collection` on PyPI,
v0.166.1). Bindicator does **not** fork it — it `pip install`s it and loads each council's
`CouncilClass` dynamically. `councils.json` is just a curated GSS-code → scraper-class map.

- **Real-time:** every lookup hits the council's live website at query time. There is **no
  downloadable bin dataset** — the library ships *scrapers, not data*. (`tests/input.json` =
  334 fixtures with one test UPRN each, zero collection dates.)
- **Two fetch mechanisms** across the 328 supported councils:
  - **233 (71%)** plain HTTP (`requests`+BeautifulSoup) — fast.
  - **95 (29%)** drive a **real headless browser (Selenium)** — slow, needs Chrome.
- **Caching:** scraper-side Postgres cache (6h; off locally) + Nuxt-side SQLite cache (6h).

## 4. The core problem: postcode is not enough

- **235/328** scrapers consume a UPRN; **0/328** implement `get_addresses()`
  (postcode→address list). So the library cannot itself turn a postcode into the right UPRN.
- Bindicator never wired up a house-picker: `server/api/address.get.ts` returns
  `addresses: []` and `/scrape` falls back to the council's hard-coded **test UPRN**.

**Proof (Bolton, BL3 4RY), fetched live:**

| UPRN source | UPRN | Result | Correct? |
|---|---|---|---|
| App fallback (test UPRN) | `100010886936` | **Thursday** rota | ❌ that UPRN is in **BL1 5PQ** |
| Real BL3 4RY (from uprn.uk) | `100010880854` | **Friday** rota | ✅ matches independent research |

The app confidently shows a schedule — for the wrong house.

## 5. Verified live data (evidence the pipeline works for supported councils)

- **Bolton** (Verint "Empro" JSON API): real July-2026 dates; Friday rota — grey (general)
  fortnightly, burgundy+green fortnightly offset, beige (paper/card) 4-weekly.
- **South Oxfordshire** (Verj.io "Binzone" eForm): OX3 8GH → single property
  *The Oaks, Old Road* (UPRN `10033026995`), Monday fortnightly rubbish/recycling.
- **Note:** both councils' *seeded test UPRNs were wrong* (Bolton's is BL1 5PQ; South Oxon's
  `10033002851` is a Didcot OX11 property) — reinforcing §4.
- Collection day/week **varies street-by-street within a council**, not just council-wide.

## 6. The PR3 2NX boundary case

- Postcodes.io resolves **PR3 2NX → Ribble Valley (E07000124)**, parish of Chipping.
- Property: **Weavers Field, Loud Bridge, Chipping.** The district boundary with
  **Preston (E07000123)** runs along the **River Loud**, ~130 m from the postcode centroid —
  i.e. the property may physically sit in **Preston**, while the centroid says Ribble Valley.
- **Ribble Valley is unsupported** (no scraper). **Preston IS supported.** So resolving the
  correct **UPRN** (which carries the true authority) matters more than the postcode centroid.
- Ribble Valley's own site (Jadu CMS) is **plain-HTTP scrapable** (address search →
  per-record collection day + rota colour + PDF calendar) — a new collector is feasible.

## 7. Can we piggyback on Freegle's PAF? (UDPRN ≠ UPRN)

**Short answer: yes for a free address *picker*, no for the UPRN the bins need.**

- Freegle exposes a **public, no-auth** PAF lookup:
  - `GET /apiv2/location/typeahead?q=<postcode>` → location id + centroid lat/lng
  - `GET /apiv2/location/{id}/addresses` → house-number-level addresses
  - Rate-limited per-IP at HAProxy; no key required.
- **But it returns `UDPRN`, not `UPRN`.** Verified three ways:
  1. Live `iznik` DB: `information_schema` query for any `%uprn%` column → **0 rows**;
     `paf_addresses` carries only `udprn`.
  2. Royal Mail's **CSV PAF spec**: 16 fields, `UDPRN` at field 12, **no UPRN**. The
     "enhanced" variant adds Organisation/Address Keys — still no UPRN.
  3. `PAF.php` parses **all** PAF fields (incl. `udprn`) — nothing is being dropped.
- **Why:** UPRN is an Ordnance Survey / GeoPlace (**AddressBase**) identifier — a separate,
  separately-licensed product. PAF's native id is UDPRN. No free UDPRN↔UPRN crosswalk exists.
- **The bridge you *do* have:** the UDPRN is the join key. **OS AddressBase Premium**
  contains both UPRN and the UDPRN cross-reference, and is **free to public-sector bodies
  under the PSGA** — Freegle may qualify. That would let Freegle's existing PAF be enriched
  with UPRNs legitimately, in bulk.

## 8. Getting a UPRN: the options

| Source | Key? | Gives | Verdict |
|---|---|---|---|
| **OS Places API** | free key | postcode → address **+ UPRN** | Best "works anywhere" path |
| **uprn.uk** | none | postcode → UPRNs (as map markers, weak labels) | No-key stopgap; works today |
| **OSM `ref:GB:uprn`** | none (offline) | 6.47M objects (~16% of ~40M); only ~13% paired with a postcode; uneven (BL3 4RY had 0) | Too patchy for "anywhere" |
| **OS Open UPRN + ONS UPRN directory** | none (offline, OGL) | complete UPRN↔postcode↔coords | Complete but **no address text** |
| **Freegle PAF** | none | address + **UDPRN** (not UPRN) | Address picker only |

## 9. Building an offline / APK version

Real-time scraping means the app **cannot work offline** and **cannot run the scraper on the
phone** (needs network + the council site up + a browser for 29% of councils). Viable shapes:

1. **Thin client → hosted scraper API** (what Bindicator assumes). Works anywhere; needs
   connectivity. IP-blocking risk if the server scrapes at scale.
2. **Offline per-property bundle.** Pre-compute *one* address's fortnightly rota rule
   (collection day + parity) server-side, ship it in the APK, generate future dates on-device
   with zero network. Realistic for a personal build; doesn't generalise.

## 10. BinDays-API — the better architecture for an app

`BadgerHobbs/BinDays-API` (C#, **AGPL-3.0**) solves the same problem with a fundamentally
different, more phone-appropriate design.

- **Server sends instructions, client executes them.** The API returns either a
  `ClientSideRequest` (URL/method/headers/body + `RequestId`) or the final data. The **client
  runs the HTTP request from the user's own IP** and posts back a `ClientSideResponse`; the
  server parses it and returns the next instruction. A `RequestId` state machine walks through
  multi-step flows (e.g. extract auth token from step 1's headers → use in step 2).
- **Benefits:** requests come from the user's residential IP → **avoids IP blocks,
  rate-limits, CAPTCHAs**; collector logic updates server-side with no app release; API is
  stateless (no DB/browser).
- **Sidesteps the UPRN/PAF problem entirely** — collectors walk each council's *own*
  postcode→address picker and use the council's native address ids.
- **How it handles SPA / JS councils:** it does **not** run a browser at runtime. At
  *authoring* time an AI agent uses **Playwright** to capture the SPA's network traffic (HAR),
  then writes a C# collector that **replays the underlying API/XHR calls**. Dynamic bits
  (tokens, CSRF, `__VIEWSTATE`, cookies, redirects) are threaded through the step machine via
  `Headers`/`Metadata`/`FollowRedirects`.
- **Limits:** cannot handle councils that need genuine in-browser JS execution (JS-computed
  tokens, anti-bot JS challenges, CAPTCHAs) unless the algorithm is reproduced in C#; the
  captured flows are **brittle** to site changes (mitigated by an AI auto-repair CI pipeline).
- **Ecosystem:** `BinDays-App` (Android), `BinDays-Client`, `BinDays-HomeAssistant`.

**Contrast:**

| | uk_bin_collection (Bindicator) | BinDays |
|---|---|---|
| Where scraping runs | Server (every request) | **Client / user's IP** |
| Browser at runtime | Selenium+Chrome for 29% | **None** (replays reverse-engineered API) |
| Live-JS-only councils | Yes (real browser) | No |
| Runtime footprint | Heavy | Tiny (HTTP from the phone) |
| Address/UPRN | Needs external UPRN | Uses council's own address picker |
| Licence | (library, permissive) | **AGPL-3.0** |

## 11. Recommendations / decision points

1. **If continuing Bindicator's own stack:** wire up a real house-picker. The cleanest
   "works anywhere" path is **OS Places API** (free key → address + UPRN). Fixes the
   wrong-property bug in §4.
2. **If building a phone app:** the **BinDays client-executes-from-device model** is the right
   architecture (no server IP-blocking, no on-device browser). Note the AGPL obligation before
   reusing its code.
3. **For a quick personal APK (your address):** look up your UPRN once (uprn.uk /
   findmyaddress.co.uk), pick the one for the *correct* council (Preston vs Ribble Valley for
   PR3 2NX), and either call a hosted scraper or bundle a computed rota offline.
4. **Freegle-specific opportunity:** investigate whether Freegle qualifies for **free OS
   AddressBase Premium under the PSGA**. If so, Freegle's existing PAF (UDPRN) could be
   enriched to UPRN in bulk — turning Freegle into a genuine postcode→UPRN source.
5. **Ribble Valley gap:** a plain-HTTP collector is feasible; upstream contribution to
   `UKBinCollectionData` would close it for everyone.

---

## Appendix A — Dev environment notes

- **Nuxt 3.21 + Vite 7 dev bug:** with `ssr:false`, the vite-node IPC socket options are never
  published, so every render 500s with *"Vite Node IPC socket path not configured."* Fix
  (applied locally, reversible): `experimental: { viteEnvironmentApi: true }` in `nuxt.config.ts`.
- Local run: `npm run dev` (front-end :3000) + `uvicorn main:app` (scraper :8000).

## Appendix B — Sources

- Bindicator: `Freegle/bindicator` (private)
- Scrapers: <https://github.com/robbrad/UKBinCollectionData>
- BinDays: <https://github.com/BadgerHobbs/BinDays-API> · App/Client/HomeAssistant siblings
- Royal Mail CSV PAF spec: <https://www.poweredbypaf.com/wp-content/uploads/2020/03/New-CSV-File-Specifications-2018-004.pdf>
- UDPRN vs UPRN: <https://www.owenboswarva.com/blog/post-addr2.htm>
- OS AddressBase Premium (Delivery Point / Type 28): <https://docs.os.uk/os-downloads/products/addresses-and-names-portfolio/addressbase-premium-islands/addressbase-premium-islands-technical-specification/structured-data-types/delivery-point-address-type-28-record>
- postcodes.io, uprn.uk, OSM/Overpass, taginfo (`ref:GB:uprn`)
