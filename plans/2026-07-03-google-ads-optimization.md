# Google Ads (Ad Grants) Deep Dive & Optimization Plan

**Date:** 3 July 2026
**Account:** FreegleAds (Google Ad Grants) — **Google Ad Grants** account ("We don't bill you"), USD, created March 2012.
**Author:** Claude deep-dive (account UI audit + 20-agent fact-checked research sweep).

---

## 1. Where the account is today (audited 3 Jul 2026, last-30-days = 3 Jun–2 Jul)

### Headline numbers
| Metric | Last 30 days | Lifetime (since 2012) |
|---|---|---|
| Spend (grant money, $0 real cost) | **$6,271** of ~$10,000 available (63%) | **$695,577** |
| Clicks | 5,670 | 854,658 |
| Impressions | 23,734 | 7,175,830 |
| CTR | 23.89% (grant floor is 5% — safe) | 11.91% |
| Avg CPC | $1.11 | $0.81 |
| Conversions | 10 (all Android app installs) | 237 |
| Account impression share | **< 10%** (rank-limited, not budget-limited) | — |

### Structure (3 campaigns, all Search, UK, English, no schedules/bid-adjustments)
| Campaign | Since | Budget | Bidding | 30d spend | CTR | What it is |
|---|---|---|---|---|---|---|
| Basic 2020 | Aug 2020 | $200/day (in a vestigial "$270 Shared Budget" used by only this campaign) | Maximise clicks | $3,521 | 17.4% | ONE ad group ("Ad group 1") with 100+ broad-match keywords of wildly mixed intent |
| Freecycle | Dec 2024 | $77.42/day | Manual CPC @ $2.00 cap | $1,262 | 10.7% | Conquesting Freecycle's brand (exact/phrase) + "alternatives" ad group |
| Freegle | Jul 2024 | $50/day | Maximise clicks | $1,489 | 53.5% | Own-brand defence incl. misspellings |

Budgets deliberately sum to $327.42/day = the $10k/month grant cap, but only ~$209/day actually spends — the $2 grant CPC cap and low ad rank throttle delivery, not budget.

### What the money actually buys (search-terms analysis, 30d, $5.2k attributable)
| Intent bucket | Spend | Share | Conversions |
|---|---|---|---|
| Competitor brand ("freecycle" + city variants, "trash nothing") | $1,726 | 33% | 1 |
| **Own brand (navigational — "freegle", "freegle login")** | $1,510 | 29% | 8 |
| Taker intent ("free stuff/furniture near me") | $981 | 19% | 0 |
| **Giver intent ("unwanted furniture", "give away free stuff")** | **$272** | **5%** | 0 |
| Junk (freebie-samples hunters, sell-intent, tip/dump queries) | ~$203 | 4% | 0 |
| Long tail (mixed, mostly taker) | $513 | 10% | 0 |

**The strategic problem in one line: 62% of spend buys navigational clicks from people who already know Freegle or Freecycle; only 5% buys the giver intent that actually creates supply.**

### Defects found (each is an action in §4)
1. **Conversion tracking is dead.** All five website conversion actions ("Register with Website", "Want an Item", "Give an Item", "Ask for Item", "Donate") show **Inactive** — the tags no longer fire (likely lost in a site rebuild). Only "Freegle (Android) installs" (created Aug 2022) still records. The account is optimizing blind, and ≥1 meaningful conversion/month is an Ad Grants compliance expectation — a live suspension risk flagged by multiple sources.
2. **Keyword garbage burns real budget.** "free samples without surveys" spent **$691/30d** (broad-matching into freebie-hunter queries), "websites to sell stuff locally" **$504** (sell intent — Freegle is free), plus recycling-centre/tip queries ("recycling near me" family, ~$650 combined) with zero conversions ever.
3. **Grant-policy violations sitting live:** enabled single-word keywords "recycling", "freecycle" (single-word keywords are banned in Ad Grants except own-brand/exception list; "free stuff"-style overly-generic keywords are Google's own cited ban example — several live here).
4. **One RSA carries $3.5k/month** (Basic 2020 has a single active RSA, strength "Average") — plus a zombie Expanded Text Ad from pre-2022 still serving. 7 of 9 active RSAs rate **Poor**.
5. **Live typos in brand ads:** "Find Homes for All Your **Uwanted** Stuff" appears in two Freegle-brand RSAs.
6. **Everything lands on the bare homepage** — no intent-matched landing pages, no UTM discipline visible in final URLs.
7. **Brand leakage across campaigns:** Basic 2020 broad keywords buy "freegle", "freegle login", "freegle uk/london" clicks that belong in (cheaper, better-copy) brand campaign; "freecycle" broad in Basic 2020 duplicates the Freecycle campaign.
8. **Brand under-defended:** Freegle wins only **63.3% impression share on its own brand**; **freecycle.org shows on 27.4% of "freegle" auctions** and outranks Freegle 41.9% of the time when both show. (Freecycle very likely runs its own $10k Ad Grant.)
9. **Rogue asset:** an app asset "**Snaply: Sell stuff simply**" (a third-party selling app!) attached at account level since Aug 2022.
10. **"Rotate ads indefinitely"** set on Freegle & Freecycle campaigns (never show best ad preferentially).
11. **Nobody home:** last substantive change 17 Feb 2025 (a volunteer); ~39 change-history entries in 2 years. Auto-apply recommendations: off (good). Google-AI auto-assets: on (added a structured snippet + "Find A Location" sitelink).
12. AI Max exists in the account UI but is not enabled (correct for now — see §3.6).

---

## 2. What changed in Google Ads since this account was last built (fact-checked)

- **Consent Mode v2** (mandatory UK/EEA since Mar 2024; from **15 Jun 2026 it is the sole authority** — no consent signal = zero data, not even modelled). Any new conversion tracking must ship with CMP + Consent Mode v2 or it will record nothing.
- **Ad Grants + Smart Bidding:** accounts created **on/after 22 Apr 2019** must use conversion-based Smart Bidding; pre-2019 accounts (like this one, 2012) may keep $2-capped manual/Max-clicks. Smart Bidding (Maximise Conversions) **removes the $2 CPC cap** — the main lever to fix <10% impression share — but it needs working conversion data first.
- **Performance Max opened to Ad Grants accounts (Jan 2025**, Search+Maps inventory; rollout still uneven — verify eligibility in-account). PMax now supports up to 10,000 negative keywords (Mar 2025) and search-term visibility. Claims that PMax is exempt from the 5% CTR rule are **unverified folklore** — don't plan around them.
- **AI Max for Search (May 2025):** opt-in keywordless matching + AI text. Documented risk of competitor-brand impression blowout (one audit: 69% of impressions); if ever tried, start with Final URL Expansion OFF and brand exclusions ON.
- **Location targeting:** "Presence or Interest" is the only default since 2023 — campaigns should be explicitly switched to **"Presence only"** for a physically-local service.
- **Search terms report** hides low-volume queries (since Sep 2022) — negative mining is harder; do it monthly, not "eventually".
- **GA4 April 2026 changes:** key-event schema tightened; first-click attribution removed; 30-day lookback. Relevant when wiring GA4→Ads imports.
- **AI Overviews** now intercept a large share of informational-query clicks (direction confirmed, magnitude debated). Favour branded/navigational and transactional local queries; don't buy "what is recycling"-shaped informational traffic.

---

## 3. Strategy

### 3.1 Measurement first (everything else is gated on this)
Recreate conversion tracking around Freegle's real funnel, as **website conversion actions fired from iznik-nuxt3** (Google tag or GA4-imported key events):
- **Primary:** `signup_completed`, `post_offer_completed` (giver — the money event), `reply_sent` (taker engagement).
- **Secondary:** `post_wanted_completed`, `donation_completed`, app-store outbound clicks.
- Ship with **Consent Mode v2** four-signal compliance (ad_storage, analytics_storage, ad_user_data, ad_personalization) — hard-enforced since 15 Jun 2026.
- Keep the Android-install action but mark it secondary so it stops being the only signal.
- *(Code specifics — where the old tags went and where new events belong — from the codebase scan: see §7.)*

### 3.2 Giver-first keyword strategy
Weight spend toward **givers** (supply creators): Olio's own strategy confirms supply is the binding constraint in reuse platforms; takers arrive organically once inventory exists.
- New giver themes: "get rid of [sofa/furniture/washing machine/wardrobe]", "give away furniture", "unwanted furniture collection free", "declutter house", "house clearance free alternative", "moving house get rid of stuff", "donate furniture local pickup", "furniture too good to throw away".
- Keep taker terms that convert to *members* ("free furniture near me" etc.) but at lower priority, and cut pure-freebie-hunter phrasing.
- Explicit negatives: samples, surveys, sell/cash intent, council-tip/HWRC intent, food-bank/благотворительность mismatches, jobs, "login" (route to brand), dating.

### 3.3 Brand defence & competitor conquesting
- Keep own-brand campaign; fix typos; add "freegle login/app/website" phrase coverage; goal ≥85% brand impression share (from 63%).
- Keep the Freecycle campaign (it's legitimate conquesting, CTR 9-15% is fine for competitor terms) but tighten: kill duplicated broad "freecycle" in Basic 2020, add "alternatives to freecycle"-style copy angles (already present), and stop paying $1.93 for queries like "freecycle wirral" from the *wrong* campaign.
- Freegle vs Freecycle UK search interest is ~6:1 (Trends: 73 vs 12) — conquesting + "alternative" positioning matters because confused searchers demonstrably land on Freecycle.

### 3.4 Restructure (target: 5 campaigns, single-intent ad groups)
1. **Brand** (exact/phrase own-brand + misspellings + login/app modifiers)
2. **Competitors** (Freecycle / Trash Nothing / Olio-adjacent, with "switch/alternative" copy)
3. **Give — core mission** (giver intents, themed ad groups: furniture / appliances / house-move / general declutter)
4. **Get** (taker intents: free stuff / free furniture / item-specific)
5. **Recycle-intercept** (optional, small: "throw away X for free", "X disposal" where the pitch is "don't tip it — freegle it"; strict negatives for council/commercial intent)
- Each ad group: ≥2 RSAs, 8-10 headlines, pinned brand line, sitelinks (Find/Give/App/How it works), callouts, structured snippets. Ad rotation: Optimise. Location: Presence-only. Grant compliance: ≥2 ad groups/campaign, ≥2 sitelinks, no single-word/generic keywords, pause QS≤2.
- **Do NOT delete Basic 2020 history wholesale** — migrate its few working themes, then wind it down. (It also holds the account's QS history.)

### 3.5 Bidding ladder
1. Now: stay Maximise Clicks (grant-legal for this pre-2019 account) while tracking is rebuilt.
2. When ≥~30 conv/30d flow: **Maximise Conversions** (uncaps the $2 CPC ceiling → directly attacks the <10% impression share).
3. Later (≥30-50 conv/30d/campaign): consider tCPA per campaign. Never share one budget across Smart-Bidding campaigns blindly.

### 3.6 Utilization growth (63% → ~90%)
Sequence: hygiene (§4 P0) → measurement (P1) → restructure + new giver inventory (P2) → Smart Bidding uncapping (P3) → **PMax-for-Grants test** (Search+Maps; verify in-account eligibility) as the final utilization filler. AI Max stays OFF until brand exclusions + landing pages are solid.

### 3.7 Landing pages (site work, not ads work)
- `/give` and `/find` already exist as sitelink targets; build/dedicate intent pages: "Give away furniture" (per-category), "Free stuff near you" and let ads land there instead of the homepage. Match H1 to query theme; LCP <2.5s, INP <200ms.
- Add UTM conventions per campaign/ad group so GA4 attribution is legible.

### 3.8 Optional: tiny paid account (real money)
Grant ads run in a second-tier auction and App campaigns are **not** grant-eligible. A ~£100-300/month paid account could cover: (a) App install campaigns (both stores), (b) top-of-page insurance on the 5-10 highest-value giver terms during Jan/spring peaks. Decision for later — the grant is nowhere near maxed yet.

### 3.9 Seasonal calendar
January (post-Christmas declutter), **Feb-April (spring-clean peak — biggest)**, Aug-Sep (pre-school-year moves). Raise budgets/coverage in those windows; use March as the credible move-month peak.

### 3.10 LLM-era visibility (GEO/AEO) & AI-assisted ads operations
*(From a second 17-agent research pass with 12-claim adversarial fact-check — full briefing in session scratchpad `ads-data/llm-briefing.md`.)*

**Context, sized honestly:** 54% of UK adults now use AI chatbots (Ofcom, Apr 2026), heavily age-skewed young; AI Overviews sit on a growing share of Google UK queries; **ads inside AI Overviews/AI Mode are NOT live in the UK yet** (pilot excludes UK), so no Grant-account change is needed for that today. Only 37.9% of AI Overview citations now come from top-10 organic results (Ahrefs) — classic rank is no longer the citation gatekeeper. Charity sector shows the steepest ranking-page decline of 16 sectors but among the smallest traffic hits so far. Watch the "search cliff" pattern (brand searches ↑ while sessions flat = AI answering on-SERP).

**Freegle's AI-visibility gaps (verified):**
- **Wikipedia article is stale and self-contradictory** (infobox 2.6M members vs body citing Jan 2021 data; last substantive update years old) while Freecycle's is mature with 40 refs. Wikipedia is a top-stable LLM citation source → highest-leverage cheap fix.
- Visible and well-placed in UK "alternatives/apps" framing (MSE April 2026, Which? sofa guide, AlternativeTo #2) but **invisible in plain disposal-intent content** ("how do I get rid of a sofa for free") — exactly the phrasing AI assistants generate.
- Olio has overtaken on scale (4.5M+ UK MAU) and generates continuous fresh press — a compounding training-data recency disadvantage.
- Reddit presence unverified (research tooling gap); Reddit matters for Perplexity/Google-AIO citations, much less for ChatGPT (Wikipedia-led) or Gemini.

**GEO actions ranked (effort→impact):**
| # | Action | Tag |
|---|---|---|
| 1 | Fix/update the Wikipedia article (neutral editor, COI rules; current member/group/tonnage figures + council/MSE/Which? citations) | off-site |
| 2 | Build "How to get rid of [sofa/furniture/electricals] for free in the UK" page family + honest "Freegle vs Freecycle vs Olio vs Marketplace" comparison page — short single-claim paragraphs + real data tables (GEO-SFE study: +17.3% citation lift from structure alone) | content |
| 3 | Short "how Freegle works" YouTube video — YouTube is now the single most-cited AI Overview domain (5.6% of citations, +34%/6mo) | off-site |
| 4 | GA4: enable the native "AI Assistants" channel (May 2026) **plus** custom regex segment (native list currently misses Claude and Perplexity); track brand-search-vs-sessions divergence | site-code |
| 5 | Google Business Profile audit for active groups (feeds AI Mode local answers — now 36% of Google self-citations, down from 98%, still worthwhile) | off-site |
| 6 | Genuine engagement in UK Reddit threads on decluttering/free stuff (scoped: helps Perplexity/AIO, not Gemini/ChatGPT) | off-site |
| 7 | Bing Webmaster indexation check — cheap insurance only (ChatGPT's Bing dependency is outdated: 8-26% overlap now) | site-code |

**Explicitly do NOT bother:** llms.txt (SE Ranking 300k-domain study: no citation benefit; Google has disavowed it); new schema.org markup *as a GEO investment* (Ahrefs controlled test: no positive AI-citation effect — keep existing markup for classic SEO; put facts in visible rendered text instead); Bing-first SEO; chasing top-10 rank purely for citations.

**LLM-assisted ads operations (the automation stack for a volunteer-run account):**
- **Read-only first:** Google's official Ads MCP server (Oct 2025) + **Explorer Access** API tier (instant, free, 2,880 ops/day, no write capability) → conversational reporting on search terms/QS/spend, weekly waste-flagging (e.g. ">$50/30d, 0 conversions" rule with a protected never-block brand list). This fits Freegle's existing batch/monitor infrastructure naturally.
- **Write automation only later** via Basic access (review-gated, currently backlogged) — and given Grant-compliance sensitivity, keep keyword/ad mutations human-approved.
- **Legal/time-sensitive:** Google Ads ToS changed **1 Jul 2026** — advertisers are now explicitly responsible for reviewing/approving all AI-generated/assisted campaign content. Assign a named reviewer + weekly cadence. Also verify the AI-content self-declaration mechanics (Mar 2026 requirement) against Google's own docs.
- Keep auto-apply recommendations OFF; never auto-accept "Ask Advisor" suggestions; never let an LLM inject competitor brand names into ad copy (trademark ≈11% of disapprovals).
- Note: this research pass claimed "Smart Bidding now mandatory for all grants / manual CPC removed" — **contradicted by the first pass's fact-check (Apr-2019 account-creation cutoff still operative) and by this very account running Manual CPC live today.** Treat pre-2019 accounts (like this one) as still manual-eligible until Google says otherwise in-product.
- No named charity anywhere runs published LLM-automated Ad Grants management — Freegle writing up its results would be a first-mover story (PR/citation value in itself).

---

## 4. Execution plan

### P0 execution log (live changes made 3 Jul 2026, authorized by Edward)
| Change | Status |
|---|---|
| 42 junk/policy-risk keywords paused in Basic 2020 (verified all 42 now "Paused"): free-samples family, sell-intent, recycling-centre/tip/e-waste family, single-word grant-banned ("recycling", "freecycle" broad), brand-leak ("freegle uk", "freegle london") | ✅ done |
| 18 campaign-level negative keywords added to Basic 2020 (sample(s), survey(s), sell(ing), sale, cash, job(s), dating, login, council, tip, dump, landfill, hwrc, skip hire) — campaign had ZERO negatives before | ✅ done |
| "Uwanted" typo fixed in both Freegle-brand RSAs (root cause: correct spelling was 91 chars > 90 limit; resolved with "and"→"&", 89 chars) | ✅ done |
| Rogue "Snaply: Sell stuff simply" third-party app asset removed (account level, present since Aug 2022) | ✅ done |
| Ad rotation → "Optimise: prefer best performing" on Freegle + Freecycle campaigns ("2 campaigns updated") | ✅ done |
| Location options → Presence-only on all 3 campaigns (UK target unchanged) | ✅ done (delegated to opus agent, verified per-campaign + spot-checked) |
| Basic 2020 → individual US$200/day budget; vestigial "$270 Shared Budget" removed (was used by 0 campaigns) | ✅ done |
| 2nd RSA added to Basic 2020/Ad group 1 (10 headlines/4 descriptions, giver-leaning copy, Google's AI-prefill cleared first) — status Eligible | ✅ done |
| Legacy ETA "Don't be a tosser \| Online dating for stuff" paused (after new RSA live) | ✅ done |
| Redundant-keyword recommendations (30) | ⏸ held for human review per agreement |

Notes:
- Google showed a "Confirm it's you" identity interstitial on several saves — skippable until **17 Jul 2026**, after which someone with account credentials must complete verification.
- **UI trap:** while loading Settings pages, an unprompted "Turn on AI Max?" dialog appeared twice with keyboard focus defaulted onto the accept button. Cancelled both times; verified AI Max remains off. Anyone working in this account manually should watch for this.
- New RSA starts at "Poor" ad strength (Google wants 15 headlines + pinning satisfied); P2 copy work will address ad strength across all campaigns properly.
- Chrome crashed once mid-session and was restarted (no data loss).

### P0 — Hygiene, this week (1-2 hrs in the UI, no code)
| # | Action | Detail |
|---|---|---|
| 1 | Pause junk keywords | "free samples without surveys", "free stuff no surveys", "free mail", "new free", "websites to sell stuff locally", "second hand goods for sale", recycling-centre set ("recycling near me", "recycle near me", "recycling center near me", "recycling", "tv disposal near me", e-waste family) |
| 2 | Remove grant-illegal keywords | single-word "recycling", "freecycle" (broad, in Basic 2020), review "trash nothing" duplication |
| 3 | Add account-level negative list "Junk" | sample(s), survey(s), sell, selling, cash, price, worth, jobs, login (except Brand), dating, council, tip opening times, skip hire |
| 4 | Fix "Uwanted" typo in 2 Freegle-brand RSAs | also drop/replace "Online dating for stuff" headline experiment |
| 5 | Delete rogue "Snaply" app asset | account-level App asset, added Aug 2022 |
| 6 | Ad rotation → Optimise | Freegle + Freecycle campaigns |
| 7 | Location targeting → "Presence only" | all 3 campaigns |
| 8 | Apply "remove redundant keywords" recs (30) after review | the UI already queues them |
| 9 | Dissolve vestigial "$270 Shared Budget" | single-campaign shared budget adds risk for Smart Bidding later |
| 10 | Add 2nd RSA to Basic 2020 ad group(s) | never leave $3.5k/mo on one creative |

### P1 — Measurement (code + tags, 1-2 weeks)

**✅ DIAGNOSIS COMPLETE (3 Jul 2026, live production test):**
| Suspect | Verdict |
|---|---|
| `GTM_ID` unset in Netlify | **CLEARED** — production runtime config has `gtm.id = GTM-KJ5FSZK4`, enabled; gtm.js loads after cookie consent |
| Consent Mode update never fires | **CLEARED** — CookieYes's hosted banner fires `consent update` (observed all-denied pre-consent → `G111` all-granted after acceptance; page_view + consent pings reach Google) |
| GTM container missing the conversion tags | **✅ CONFIRMED ROOT CAUSE** — the published GTM-KJ5FSZK4 container contains only URL/referrer variables + a Conversion Linker: **zero AW tags, zero custom-event triggers, zero conversion labels**. A live test push of `{event: 'Give an Item'}` (consent granted, GTM loaded) produced no conversion ping. The site's five events have fired into the void since the container went live with the Jul 2024 GTM module. |

The Ads conversion tag is **AW-951442748** (decoded from the conversion labels; action IDs 6831467899/…902/…905/…911).

**✅ FIXED (3 Jul 2026):** Edward imported `plans/2026-07-03-gtm-import-GTM-KJ5FSZK4.json` (4 custom-event triggers + 4 AW-951442748 conversion tags) and published. **End-to-end verified live**: test `Give an Item` event → `googleadservices.com/pagead/conversion/951442748/` beacon fired. Real users pick up the new container within the 15-min gtm.js cache TTL. Expect the four website conversion actions to flip Inactive→active in the Ads UI within ~24-48h, and first real conversions to appear this week (note: my verification pings carry no ad-click attribution, so they won't record as conversions — no data pollution).

Remaining measurement notes:
- Ads has five website actions but the site fires four event names — check which of "Want an Item"/"Ask for Item" is the QxhuCP… label (action ID 6831467902) and pause/remove the orphaned twin, or repurpose it for reply-tracking below.
- **Watch out:** conversions will initially reflect the events' current premature timing (wizard page-mount, pre-submit clicks) — inflated vs true completions. The step-2 code work below fixes that; don't switch bidding to Maximise Conversions until it lands.

Then:
2. Move/add conversion events at the success-confirmed hook points (§7 table): `signup_completed` (incl. social + inline-post signups), `post_offer_completed`, `post_wanted_completed`, `reply_sent`, `donation_completed`.
3. Fix the Consent Mode gap if confirmed: fire `gtag('consent','update',…)` from the CookieYes callback (or enable CookieYes's native Google-Consent-Mode integration); confirm all four v2 signals in Tag Assistant.
4. Add UTM/gclid conventions per campaign (none exist today — §7).
5. Decide GA4: either stand up a fresh GA4 property for key-event import, or keep conversions on the Google-Ads-tag-in-GTM path only (simpler; GA4 was reverted in 2023 and never re-added).
6. Watch the "Inactive" actions flip to "Recording"; sanity-check vs server-side signup counts for 2 weeks before any bidding change.

### P2 — Restructure (after P1 baselines, ~2-4 weeks)
1. Build the 5-campaign structure (§3.4) with fresh giver-intent ad groups and 2 RSAs each.
2. Write RSAs against the differentiator: *100% free both sides, no fees, no resale-quality bar, local collection, charity, planet* ("Save Time, Money & The Planet" already tests well at 50%+ brand CTR).
3. Sitelinks: Give Stuff / Find Stuff / Get the App / How Freegle Works; refresh callouts; image assets per theme.
4. Landing pages per giver theme (site work in parallel).
5. Wind Basic 2020 down as new campaigns take traffic.

### P3 — Bidding & scale (once ≥30 conv/30d)
1. Maximise Conversions on Give + Get campaigns → monitor CPC uncap and impression share.
2. Budget rebalance toward Give; target ≥90% grant utilization.
3. PMax-for-Grants pilot (strict negatives, brand exclusions) for remaining headroom.
4. Seasonal budget calendar (§3.9). Quarterly negative-mining cadence (search terms hide low-volume queries — monthly is better).

### P4 — LLM-era workstream (parallel to P1-P3, mostly non-ads work)
1. **Week 1:** Wikipedia article fix (recruit neutral editor); assign named AI-content reviewer per 1 Jul 2026 Google ToS change; GA4 AI-assistant channel + custom regex segment.
2. **Weeks 2-6:** disposal-intent content family + comparison page (feeds both AI citations AND the P2 ad landing pages — same pages serve both); "how Freegle works" YouTube video.
3. **Ongoing:** stand up read-only Ads reporting via Google's Ads MCP server on Explorer Access → weekly LLM-drafted search-term waste report, human-approved before any account change; GBP audit; scoped Reddit presence.
4. **Skip:** llms.txt, schema-for-GEO, Bing-first work (all fact-checked as non-levers).

### Governance
- Named owner + 30-min monthly checklist (search terms → negatives; CTR vs 5% floor; conversion actions still Recording; recommendations triage — never auto-apply).
- Keep auto-apply OFF; review Google-AI auto-assets monthly (it already injected 2).
- **New (legal, from 1 Jul 2026):** named human reviewer signs off all AI-generated/assisted ads content weekly; verify AI-content self-declaration requirements against Google's own docs.
- Complete Google's identity verification before **17 Jul 2026** (currently skippable interstitial; becomes blocking).

---

## 5. What success looks like (12 weeks)
| Metric | Now | Target |
|---|---|---|
| Tracked website conversions | 0 | ≥150/mo (signups+posts+replies) |
| Giver-intent share of spend | 5% | ≥30% |
| Own-brand impression share | 63% | ≥85% |
| Grant utilization | 63% | ≥85-90% |
| Junk/violation keywords live | dozens | 0 |
| Active RSAs Poor-rated | 7/9 | ≤2, none carrying >$1k/mo |

---

## 6. Open questions for Edward
1. Who should own the monthly cadence (volunteer Kate again, staff, or automate parts via API + a monitor job)?
2. Appetite for a small **paid** account for App campaigns / peak-season insurance (§3.8)?
3. Landing-page work competes with other iznik-nuxt3 priorities — schedule P2 pages alongside or after measurement?
4. ~~Donations: "Donate" conversion existed once — is donation growth a goal for this account or purely membership/supply?~~ **Answered (Edward, 3 Jul):** donations are NOT an ads goal. Most donations happen via the Stripe modal from existing users mid-flow (e.g. after marking an item taken); the PayPal /donate page is minor; cold donor acquisition isn't credible spend for Freegle. Action: set the "Donate" conversion action to **Secondary** in the Ads account so it never steers bidding; no donation event work in the site code.

---

## 7. Codebase findings on tracking (from repo scan)

**The conversion-event code never left the codebase — its delivery mechanism did.** The five "Inactive" conversion actions map to GTM `trackEvent` calls that are still present in iznik-nuxt3:

| Google Ads action | Code | Problem |
|---|---|---|
| Register with Website | `components/LoginModal.vue:397-404` (label `EcEMCPvav7kZELy618UD`), called at `:430` | Fires on submit-click **before** signup resolves; **never fires for Facebook/Apple/Google signups** |
| Give an Item | `pages/give/index.vue:122-129` | Fires on **page mount of wizard step 1**, not on completed post |
| Want/Ask for Item ("Find an Item") | `pages/find/index.vue:124-131` | Same premature page-mount pattern |
| Donate | `components/DonationButton.vue:137-146` | Fires on PayPal click, before payment completes |
| (Reply to post) | — | **No event exists anywhere** in the reply/chat flow |

**Root-cause candidate #1 — `GTM_ID` env var.** The GTM module is only registered at build time if `process.env.GTM_ID` is set (`nuxt.config.ts:309`, runtimeConfig at `:380-392`). `GTM_ID` is **absent from every env file in the repo** (.env, .env.example, netlify.toml, docker-compose) — it lives (or lived) only in the Netlify build dashboard. If unset there, `$gtm` is undefined and every trackEvent call silently no-ops. **First diagnostic: check Netlify build env for `GTM_ID`.**

**Root-cause candidate #2 — Consent Mode update never fires.** Consent Mode **v2 defaults are correctly implemented** (`nuxt.config.ts:684-702`: all-denied defaults incl. `ad_user_data`/`ad_personalization`, `ads_data_redaction`, `url_passthrough`) — but **no `gtag('consent','update',…)` exists anywhere in the repo**. The CookieYes flow (`checkCookieYes()` → `postCookieYes()`, `nuxt.config.ts:832-969`) only gates ad-script loading; it never updates consent state — unless CookieYes's externally-hosted banner.js does it via its dashboard's Google-Consent-Mode feature. If it doesn't, `ad_storage` stays denied forever and (since 15 Jun 2026, consent = sole authority) conversions record nothing. **Verify live with Tag Assistant/GTM Preview on production.**

**Also relevant:**
- The AW-conversion-tag ↔ trigger mapping lives in the **GTM container config** (web console), not in the repo — audit it there (labels above are the join key).
- GA4 was added and reverted the same afternoon (3 Aug 2023: `d6a76d434` → `be591a7a1`); no GA4 property is wired today, so "GA4 key-events import" from §3.1 needs a fresh GA4 setup or direct Google-Ads-tag conversions instead.
- Matomo was removed from the frontend on 2 Jul 2026 (`d7bc725dc`) — that commit explicitly left GTM trackEvent calls untouched.
- **Zero UTM/gclid handling** exists in the app — no capture, persistence, or passthrough. Build alongside the new events.

**Correct hook points for the rebuilt events (success-confirmed, not click-time):**
| New event | Where |
|---|---|
| `signup_completed` | `stores/auth.js:290-319` — success path of `signUp()` (after `fetchUser()` at `:297`); covers the only signup entry point incl. the social paths that currently never track |
| `post_offer_completed` / `post_wanted_completed` | `composables/useCompose.js:308-415` (`freegleIt()`) after `composeStore.submit()` succeeds at `:319` — the true wizard completion shared by give & find; also fires `signup_completed` variant when `params.newuser` is set (anonymous-post inline signups) |
| `reply_sent` | `composables/useReplyToPost.js:89-91` — beside the existing internal `action('reply_to_post_success')` telemetry |
| `donation_completed` | move from button-click to PayPal completion callback |

---

## Appendix: data sources
- Account UI audit 3 Jul 2026 (campaigns/keywords/search terms/ads/conversions/billing/change history/auction insights/assets/recommendations); CSVs in session scratchpad (`ads-data/keywords.csv`, `ads-data/searchterms.csv`).
- 20-agent research workflow with adversarial fact-check pass (14 claims verified; corrections listed in the briefing) — `ads-data/research-briefing.md`.
- Notable fact-check kills: "£0.85 charity CPC / cheapest vertical" (refuted), "PMax exempt from 5% CTR" (unverified folklore), "90-98% utilization benchmark" (vendor testimonial), "May 2026 record property month" (false — base-effect artifact).
