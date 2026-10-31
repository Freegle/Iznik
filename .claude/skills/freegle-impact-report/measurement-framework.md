# Freegle Social Impact Measurement Framework

*How Freegle measures, values and reports its social and environmental impact. Designed to be operable by the Freegle team and credible to councils, ReLondon and funders.*

---

## 1. Theory of change

**Inputs** -> a free online platform; ~450 volunteer moderators; ~500 local communities; a small paid team; a cash budget of ~£100k/year; partnerships with councils, housing, charities.

**Activities** -> peer-to-peer reuse matching (offers & wanteds); local moderation; the Councils & Partnerships programme; National Reuse Day; the Brighton Free Shop; targeted outreach to underserved communities.

**Outputs** (counted) -> items exchanged; tonnes reused; successful exchanges; members; volunteer actions.

**Outcomes** -> waste diverted & carbon avoided; households save money / furniture poverty eased; councils avoid disposal cost & fly-tipping; people more connected and less isolated; volunteers gain purpose and skills; local reuse/repair economy fed.

**Impact** -> reuse normalised above recycling; more sustainable, resilient, cohesive communities across the UK.

The single load-bearing measured quantity is **weight of items reused**. Everything else is either counted directly (members, exchanges) or derived from weight (carbon, value, LA savings).

## 2. The measurement model: Wayne's five pillars

For each pillar: the metric, the recommended valuation method and £ proxy (with source), the data source, and whether Freegle already holds the data.

### Pillar A - Unique selling point (the frame, not a number)
- **Metric:** cost-effectiveness ratio (value delivered / cash input) and reach (members, communities, geography).
- **Method:** £ WRAP value of reuse / annual cash income.
- **2025:** £11.1m / ~£100k = **~£110:1** (cash basis); ~£6:1 if volunteer time is costed.
- **Data:** dashboard (value) + accounts (income, volunteer hours). *Have value; need confirmed income/volunteer hours.*

### Pillar B - Money saved to local authorities (LEAD)
- **Metric:** £ avoided waste-disposal cost = tonnes reused x disposal cost per tonne.
- **Proxy:** WRAP UK Gate Fees 2024-25: £121/t standard EfW, £164/t bulky; landfill tax £126/t; + £80-150/t collection where applicable; + fly-tipping clearance (Defra).
- **2025:** 10,910 t x £121-164 = **£1.3-1.8m**.
- **Data:** dashboard tonnes (national + per council). *Have.*

### Pillar C - Waste avoided & carbon (social/environmental value)
- **Metric:** tonnes reused; tonnes CO2e avoided; £ benefit of reuse.
- **Proxy:** WRAP Benefits of Reuse: 0.51 tCO2e/t; £711/t (2011) x CPI = ~£1,019/t. Optionally value carbon at DESNZ £260/tCO2e (2025) = a separate societal figure.
- **2025:** 10,910 t; 5,564 t CO2e; £11.1m benefit; ~£1.4m carbon at DESNZ prices.
- **Data:** dashboard. *Have. To strengthen: tonnes by material category.*

### Pillar D - Local economy & jobs
- **Metric:** household £ saved; jobs-intensity of reuse vs alternatives; contribution to circular-economy GVA/jobs targets.
- **Proxy/evidence:** WRAP - UK reuse saves households ~£1bn/yr; sofa saving ~£940 vs new. WRAP/Green Alliance - reuse supports 8-20 jobs/1,000 t vs 0.1 for landfill. ReLondon/Valpak - London circular economy to 515k jobs and £24.2bn GVA by 2030.
- **Freegle framing:** demand-and-supply engine for the local reuse economy; keeps goods & money local; feeds furniture-reuse charities and repair schemes that employ people.
- **Data:** national tonnes + (Phase 2) a beneficiary survey for per-household £ saved. *Partially have; survey needed for a Freegle-specific household-saving figure.*

### Pillar E - Community co-benefits: resilience, cohesion, wellbeing
- **Metric:** % of members reporting stronger community connection / reduced isolation; volunteering wellbeing.
- **Proxy (Green Book / HACT, WELLBY-based):** sense of belonging to neighbourhood ~£7,975/person/yr; reduced loneliness ~£8,100/person/yr; volunteering ~£3,400/person/yr; WELLBY £13,000/point.
- **Evidence:** Berkeley/Stanford study - gift-economy networks build community identification and generosity more than buy/sell sites.
- **Data:** **needs a beneficiary survey** to establish the % experiencing each outcome before monetising. Until then, quote proxies illustratively only. *Need.*

## 3. The consolidated impact model (how it rolls up)

```
              tonnes reused (measured)  =  10,910 t   [the spine]
                     |
   +-----------------+--------------------+----------------------+
   |                 |                    |                      |
 x 0.51         x £1,019/t          x £121-164/t            counted
 = CO2e         = reuse value       = LA disposal saving    separately:
 5,564 t        £11.1m              £1.3-1.8m               404,220 exchanges
   |                                                        256,535 new members
 x £260/tCO2e (DESNZ)                                       4.84m memberships
 = ~£1.4m societal carbon value
```

**Reporting rule:** present the three monetary lenses (reuse value, carbon value, LA saving) as **distinct benefits to distinct beneficiaries** (members, society, councils). Never sum them into one "total benefit" - that would be double-counting the same tonnes.

## 4. Valuation methods reference (which to use when)

| Outcome type | Method | Source |
|---|---|---|
| Reuse economic/environmental benefit | WRAP Benefits of Reuse tool | WRAP |
| Carbon (societal) | DESNZ carbon values (£260/tCO2e, 2025) | DESNZ 2023 |
| LA cost savings | WRAP gate fees + landfill tax + Defra fly-tipping | WRAP/HMRC/Defra |
| Wellbeing/cohesion/loneliness | WELLBY (£13,000) + HACT UK Social Value Bank | HM Treasury / HACT |
| Volunteering | HACT / State of Life (~£3,400/yr) | HACT |
| Jobs & GVA | WRAP/Green Alliance ratios; ReLondon targets | WRAP/Green Alliance/ReLondon |
| Procurement-compatible scoring | National TOMs / Social Value Model (PPN 06/20) | Social Value Portal |
| Overall headline ratio | SROI (8 principles, 4 adjustments) | Social Value International |

## 4b. National TOMs expression (procurement-compatible annex)

The same model, coded in National TOMs vocabulary (~1/3 of English/Welsh councils score social value this way). The licensing and free-tier findings from the research are in 4c below (the full measure list and sources are in git history at `3e65b6af25`, `plans/2026-07-20-toms-social-value-mapping.md`); draft statement: `plans/2026-07-20-freegle-social-value-statement-toms-draft.md`.

| TOMs ref | Measure | Freegle source | Proxy (NT2022) |
|---|---|---|---|
| NT88 | Waste reduced through reuse (£ equiv) | tonnes x £1,019/t (the WRAP value) | £1/£ equiv (NT2022 pub.) |
| NT31 | CO2e savings | tonnes x 0.51 | £244.63/tCO2e (NT2022 pub.) |
| NT29 | Volunteering hours for community | mod actions x 1 min | **£17.48/h (live 2026)** |
| NT28 | In-kind donations to community (incl. equipment) | Free Shop, bulk clearances | £1/£ (live 2026) |
| NT117 | Environmental & biodiversity conservation | programme £ incl. time | £1/£ (live 2026) |
| NT27 | Vulnerable people supported | partnership programme spend | £1/£ invested (NT2022 pub.) |

Rules: (1) the framework is free ("a free resource" - Taskforce's own VCSE guide). Freegle's free Open Access account ("Freegle - Freegle Geeks", 2026-07-20) exposes ONLY the Light set (12) - **NT31/NT88/NT27 are NOT in it**; their proxies above are the published NT2022 figures (public pre-2022 PDFs are staler still - NT31 ~£67, do not use). Live-proxy extract: `plans/2026-07-20-toms-light-set-12-freegle-account.json`. (2) NT88+NT31 derive from the same tonnes - both claimable in TOMs but disclose the overlap. (3) Never add TOMs £ to the WRAP-based £ - same impact, different vocabulary. (4) Jobs theme (NT1-13) doesn't fit; keep jobs ratios as narrative. (5) Partner-claim mode is the strongest play: a council/contractor funding or routing goods through Freegle claims the units via their own (paid, full-measure-set) SVP account - each unit claimed once, and Freegle never needs the paid tier.


## 4c. TOMs licensing and the free tier (research of 2026-07-20, folded in 2026-10-28)

### 1. Is TOMs free? Yes — with one nuance

- The **framework itself is free**. The National Social Value Taskforce's own guide states: *"The National TOMs is a free resource"*, and the framework PDF is mirrored without any paywall on many council sites (Richmond, Southampton, Stroud, Lancashire, the LGA itself). It was developed in 2017 by the Taskforce, sponsored by the LGA, with Social Value Portal (SVP) as steward.
- The widely-repeated claim that it's under a **Creative Commons licence could not be verified at primary source** — no reachable official page states an actual CC variant. What is solidly confirmed is "free to download and use". (The Taskforce site nationalsocialvaluetaskforce.org was unreachable; that's where a formal licence statement would live.)
- **What costs money**: SVP's commercial "TOM System" platform — bid management, dashboards, and crucially **independent third-party verification of reported figures** ("included as standard with every membership"). Free users self-attest; paying members get their numbers checked.
- **Free middle tier**: SVP's **"Open Access TOM System"** is explicitly free for **Public Sector and VCSE organisations** — Freegle qualifies. It's registration-gated (app.socialvalueportal.com) and includes the measure workbook. **This is the recommended route to get the current proxy £ values**, which are otherwise only published in dated PDFs.
- **Recommendation: pay nothing.** Use the free framework + register for the Open Access tier to get current proxy values. Paid membership only becomes relevant if a funder ever demands SVP-verified figures — and a council partner that's already an SVP member can often run the validation from their side.

#### Framework drift to be aware of (it has moved on since the 2021 PDF)

| Edition | What changed |
|---|---|
| NT2017–2021 | 5 themes, ~20 outcomes, 38–48 core measures (the version most council PDFs mirror) |
| **NT2022** (1 Jun 2022) | Restructured into **Light (18) / Core (40) / Non-core (~280)** measures; all proxy values re-based; new Environment measures incl. **NT88 (waste reduced through reuse)** — see below |
| 2024 update | Guidance streamlined; "social dimensions" added to NT1 (local jobs) and NT18/19 (local spend); Open Access tier expanded |
| Oct 2025 | "**Global TOM System**" launched (international extension, not a UK reissue). SVP progressively rebranding "National TOMs" → "**Social Value TOM System™**" |

Also distinct: the UK Government **Social Value Model (PPN 06/20 → PPN 002, new model from 1 Oct 2025)** is a separate, government-owned framework for **central** government procurement. TOMs is the local-authority-world framework. The NHS has its own Social Value Model, but NHS bodies (e.g. East of England Ambulance Service) map it onto bespoke TOMs — so TOMs fluency travels.

#### Is there precedent for a charity (not a bidder) using TOMs?

Yes — explicitly endorsed by the framework's own stewards. The Taskforce's *"Getting the most from Social Value: a step-by-step guide for SMEs and VCSEs"* tells VCSEs to measure what they already do against TOMs **independent of any bid**, and even suggests VCSEs substitute a certified SROI multiplier for the default £1 proxy on £-invested measures. The Open Access tier being open to VCSEs reinforces this.

---

### 5. Verified in Freegle's own Open Access account (2026-07-20)

Freegle is now registered (account "Freegle - Freegle Geeks", Freemium tier, app.socialvalueportal.com). Findings from inside the account:

**The free tier is the "Light measure set (12)" ONLY.** The new-record flow offers no other measure set, and the trial record's picker is locked to these 12. Current live proxy values (extracted from the account's own calculator API + its 7-page guidance PDF, generated 20 Jul 2026):

| Ref | Theme | Measure | Unit | Proxy (2026) |
|---|---|---|---|---|
| NT1 | Work | Local people employed or retained | FTE | £44,992.42 (localised by project) |
| NT3 | Work | Long-term unemployed recruited | FTE | £51,561 |
| NT9 | Work | Accredited training for new employees | weeks | £347 |
| NT10 | Work | New apprentices | weeks | £309.73 |
| NT11 | Work | Support for unemployed into work | session-hrs × attendees | £110.99 |
| NT8 | Work | Support for students at local institutions | volunteering hrs | £17.48 |
| NT18 | Economy | Spend with local supply chain | £ | shows £0 until project local-multiplier configured |
| NT15 | Economy | Expert support to VCSEs/SMEs | expert hrs | £106.34 |
| **NT28** | Community | Donations/in-kind to community projects | £ invested (incl. equipment) | **£1/£** |
| **NT29** | Community | Volunteering for community projects | volunteering hrs | **£17.48** |
| NT33 | Planet | Miles driven in zero-emission vehicles | miles | £0.047 |
| **NT117** | Planet | Environmental & biodiversity conservation | £ invested incl. time | **£1/£** |

Full definitions/evidence requirements: the portal export `plans/2026-07-20-toms-light-set-12-freegle-account.json`, removed on 2026-10-28 and in git history at `3e65b6af25`. Note the themes are now Work / Economy / Community / Planet (the Innovation theme is gone from this set), and codes have moved on from the PDFs (NT117 is new).

**Critical gap: NT31 (carbon) and NT88 (waste reuse) are NOT in the free tier** — Freegle's two headline environmental measures sit in the Core set, which this account cannot select. Other account limits: no account CSV export, cannot download the full TOM System guide (per-set guidance PDF is available), not eligible for tender bidding.

**What this means for the strategy:**
1. **Community measures — use the portal.** NT29 volunteering at the live £17.48/h proxy values Freegle's ~6,868 moderator hours at **~£120k** (up from ~£102k on the stale £14.80). NT28 (in-kind £, incl. equipment) and NT117 (environmental £ invested) are usable per-programme.
2. **Environment headline — three options.** (a) Quote the published NT2022 framework proxies (£244.63/tCO2e) from the free PDFs, disclosing vintage — the framework itself is free to cite. (b) Ask SVP for Core-set access — the account page explicitly invites VCSEs to "get in touch" for more measure sets; worth one email before concluding it costs money. (c) Keep the environment story in Freegle's existing WRAP/DESNZ framing and use TOMs coding only for the community/volunteering layer.
3. **The partner route is unaffected and is the strongest play.** Councils/contractors with paid SVP memberships have the full measure set including NT31/NT88 in *their* accounts — Freegle supplies the evidenced quantities (tonnes, CO2e, hours, per council area) and the partner claims them against their own commitments. Freegle never needs the paid tier for that.

## 5. Assurance & credibility (what makes it critique-proof)

1. **Count conservatively.** Weight is recorded only on confirmed outcomes and is a known under-estimate. Use WRAP factors, not higher in-house estimates.
2. **One tonnage flows through everything.** Carbon, value and LA savings all derive from the same measured weight - internally consistent.
3. **No stacking.** The three monetary lenses are reported separately by beneficiary, never summed.
4. **Apply SROI adjustments** (deadweight, attribution, displacement, drop-off) for any headline SROI figure; disclose them.
5. **Survey before monetising co-benefits.** Wellbeing proxies are shown illustratively until a beneficiary survey establishes the % experiencing each outcome.
6. **Disclose data lineage.** State that figures come from Freegle's own platform data and name every external factor/source.
7. **Seek independent assurance** for the published SROI (Social Value UK / a third party).

## 6. Operating cadence

- **Continuous:** dashboard captures weight, exchanges, members daily (national + per group/council).
- **Annual:** compile the calendar-year impact report (this template) from the dashboard; refresh external factors (gate fees, carbon values, landfill tax).
- **Periodic (Phase 2+):** beneficiary survey (annual or biennial); assured SROI (every 1-2 years); per-partner reports on demand.
