---
last_reviewed: 2026-11-02
covers:
  - iznik-nuxt3/composables/useReuseBenefit.js
  - iznik-nuxt3/components/StatsImpact.vue
  - iznik-nuxt3/pages/stats/[[groupname]].vue
  - iznik-nuxt3/pages/stats/authority/[[id]].vue
  - iznik-nuxt3/modtools/components/ModImpact.vue
  - iznik-nuxt3/modtools/components/ModDashboardImpact.vue
  - iznik-batch/app/Support/ReuseBenefit.php
  - iznik-batch/app/Services/CPIService.php
  - iznik-batch/app/Console/Commands/Authority/AuthorityStatsCommand.php
---

# Impact figures on the stats pages

The public stats page (`/stats`, or `/stats/<community>`) and the council pages
(`/stats/authority/<id>`) show three headline figures: tonnes reused, the benefit in
pounds, and tonnes of CO2 saved. The maths is in `composables/useReuseBenefit.js`; the
figures are drawn by `components/StatsImpact.vue`.

Two other places show the same benefit, worked out the same way:

- The ModTools dashboard impact card (`modtools/components/ModDashboardImpact.vue`, drawn by
  `ModImpact.vue`).
- The quarterly council spreadsheet (`php artisan authority:stats`), whose maths is in
  `iznik-batch/app/Support/ReuseBenefit.php`. Its footnote is in cell A14.

The stats page totals cover the 12 complete months before the current one, or the financial
year asked for with `?financialYear=FY2024`, from the first day to the last.

## Tonnes and CO2

Tonnes come from the `Weight` dashboard series, which estimates the weight of each item
given away. CO2 is 0.51 tonnes per tonne reused, from WRAP. It is a physical quantity, so
it is not adjusted for inflation.

## Benefit

The benefit is the sum of two parts, both in current prices:

1. **The benefit of reuse**: £711 a tonne in 2011 prices, from WRAP's Benefits of Reuse
   tool, uprated by CPI from 2011 (`getBenefitPerTonne`).
2. **Fly-tipping clear-up costs avoided**: about £1 for each item given away, at 2025
   prices (`calculateFlyTipSaving`). It is counted on gifts, the `Outcomes` series, which
   counts offers taken and wanteds received. A footnote under the figure says how much of
   the benefit this part is.

### Where the fly-tipping figure comes from

A Defra statistician analysed Freegle's 2019 data for us in 2020, using a method statement
and an R script (`Freegle_fly_tipping.R`) kept with Freegle's records. It made three
assumptions:

- **Share who fly-tip: 1.95%.** A 2018 Keep Britain Tidy survey of 1,000 Londoners found 26,
  21, 16 and 15 people had fly-tipped a TV, an oven, a mattress or a sofa in the previous two
  years. The mean is 19.5 people in 1,000, which is 1.95%.

  The method statement's text says 17.55% to 21.45%. Those figures are ten times too high:
  they treat 19.5 people in 1,000 as 19.5%. The R script divides by 1,000 and uses 1.95%,
  so the statement's £167,910 result is right. Only the percentages in its text are wrong.
- **People who fly-tip would have fly-tipped what they gave away.** The script picked that
  share of the people who offered items in 2019 at random and counted their items.
- **Each fly-tip costs £29 to clear.** That is Defra's standard cost for both "other single
  item" and "car boot load or less" (ENV24 fly-tipping statistics, notes tab). Defra based it
  on council data from 2003 to 2006, so we uprate it from the average CPI of those four
  years.

On the 289,944 items offered in 2019, the analysis gave £167,910 for one run and a mean of
£162,697 over 10,000 runs. At exactly 1.95% the same data gives £163,963. The unit test
`reproduces the 2019 result in its own prices` checks this.

Two choices here differ from the 2019 analysis:

- We uprate the £29 for inflation. The analysis did not, so its result is in 2003-06 prices.
- We count gifts, not offers. An offer nobody took was not kept out of a fly-tip by Freegle.

## Inflation

The prices use the ONS CPI annual averages (series D7BT, 2015=100) from 2003. Any later year
is priced at the latest year held, and the WRAP value is never priced below its 2011 level.

- `data:update-cpi` (`CPIService`) refreshes the `cpi_annual_data` config row monthly, keeping
  2003 onwards. The council spreadsheet and the Go item impact endpoint read that row.
- When the row is missing, PHP falls back to `ReuseBenefit::FALLBACK_CPI_DATA` (2003 to 2025).
  If the row lacks the 2003-06 years, the fly-tipping base comes from that table.
- The web pages use their own copy, `CPI_DATA` in `useReuseBenefit.js` (2003 to 2025). Add each
  new year to it, and to the PHP table, when ONS publishes it in January.
- The Go fallback, `iznik-server-go/item/reusebenefit.go`, runs from 2011 to 2024. The Go code
  does not use fly-tipping.
