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

The same model, coded in National TOMs vocabulary (~1/3 of English/Welsh councils score social value this way). Full research: `plans/2026-07-20-toms-social-value-mapping.md`; draft statement: `plans/2026-07-20-freegle-social-value-statement-toms-draft.md`.

| TOMs ref | Measure | Freegle source | Proxy (NT2022) |
|---|---|---|---|
| NT88 | Waste reduced through reuse (£ equiv) | tonnes x £1,019/t (the WRAP value) | £1/£ equiv (NT2022 pub.) |
| NT31 | CO2e savings | tonnes x 0.51 | £244.63/tCO2e (NT2022 pub.) |
| NT29 | Volunteering hours for community | mod actions x 1 min | **£17.48/h (live 2026)** |
| NT28 | In-kind donations to community (incl. equipment) | Free Shop, bulk clearances | £1/£ (live 2026) |
| NT117 | Environmental & biodiversity conservation | programme £ incl. time | £1/£ (live 2026) |
| NT27 | Vulnerable people supported | partnership programme spend | £1/£ invested (NT2022 pub.) |

Rules: (1) the framework is free ("a free resource" - Taskforce's own VCSE guide). Freegle's free Open Access account ("Freegle - Freegle Geeks", 2026-07-20) exposes ONLY the Light set (12) - **NT31/NT88/NT27 are NOT in it**; their proxies above are the published NT2022 figures (public pre-2022 PDFs are staler still - NT31 ~£67, do not use). Live-proxy extract: `plans/2026-07-20-toms-light-set-12-freegle-account.json`. (2) NT88+NT31 derive from the same tonnes - both claimable in TOMs but disclose the overlap. (3) Never add TOMs £ to the WRAP-based £ - same impact, different vocabulary. (4) Jobs theme (NT1-13) doesn't fit; keep jobs ratios as narrative. (5) Partner-claim mode is the strongest play: a council/contractor funding or routing goods through Freegle claims the units via their own (paid, full-measure-set) SVP account - each unit claimed once, and Freegle never needs the paid tier.

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
